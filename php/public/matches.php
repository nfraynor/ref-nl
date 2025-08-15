<?php
require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';
include 'includes/header.php';
include 'includes/nav.php';
include 'components/referee_dropdown.php';

$assignMode = isset($_GET['assign_mode']);
$pdo = Database::getConnection();

function buildQueryString(array $overrides = []): string {
    return http_build_query(array_merge($_GET, $overrides));
}

$referees = $pdo->query("
    SELECT 
        uuid, 
        first_name, 
        last_name, 
        grade
    FROM referees 
    ORDER BY first_name
")->fetchAll();

// Fetch matches
// Build dynamic SQL with optional date filters
$whereClauses = [];
$params = [];

// --- START: Role-based permission logic for initial query ---
$userRole = $_SESSION['user_role'] ?? null;
$userDivisionIds = $_SESSION['division_ids'] ?? [];
$userDistrictIds = $_SESSION['district_ids'] ?? [];

$allowedDivisionNames = [];
$allowedDistrictNames = [];
$loadInitialMatches = true; // Flag to control if the initial query runs

if ($userRole !== 'super_admin') {
    // Fetch allowed division names
    if (!empty($userDivisionIds) && !(count($userDivisionIds) === 1 && $userDivisionIds[0] === '')) {
        $placeholders = implode(',', array_fill(0, count($userDivisionIds), '?'));
        $stmtDiv = $pdo->prepare("SELECT name FROM divisions WHERE id IN ($placeholders)");
        $stmtDiv->execute($userDivisionIds);
        $allowedDivisionNames = $stmtDiv->fetchAll(PDO::FETCH_COLUMN);
    }

    // Fetch allowed district names
    if (!empty($userDistrictIds) && !(count($userDistrictIds) === 1 && $userDistrictIds[0] === '')) {
        $placeholders = implode(',', array_fill(0, count($userDistrictIds), '?'));
        $stmtDist = $pdo->prepare("SELECT name FROM districts WHERE id IN ($placeholders)");
        $stmtDist->execute($userDistrictIds);
        $allowedDistrictNames = $stmtDist->fetchAll(PDO::FETCH_COLUMN);
    }

    if (empty($allowedDivisionNames) || empty($allowedDistrictNames)) {
        $loadInitialMatches = false;
        $matches = []; // Ensure matches is empty if not loading
    } else {
        // Add permission-based WHERE clauses
        $divisionPlaceholders = implode(',', array_fill(0, count($allowedDivisionNames), '?'));
        $whereClauses[] = "m.division IN ($divisionPlaceholders)";
        foreach ($allowedDivisionNames as $name) $params[] = $name;

        $districtPlaceholders = implode(',', array_fill(0, count($allowedDistrictNames), '?'));
        $whereClauses[] = "m.district IN ($districtPlaceholders)";
        foreach ($allowedDistrictNames as $name) $params[] = $name;
    }
}
// --- END: Role-based permission logic ---

// Pagination settings
$matchesPerPage = 50;
$currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($currentPage - 1) * $matchesPerPage;

// Existing filters from _GET parameters
if (!empty($_GET['start_date'])) {
    $whereClauses[] = "m.match_date >= ?";
    $params[] = $_GET['start_date'];
}
if (!empty($_GET['end_date'])) {
    $whereClauses[] = "m.match_date <= ?";
    $params[] = $_GET['end_date'];
}

// Map multi-select filters to real columns
$multiFilters = [
    'division'          => 'm.division',
    'district'          => 'm.district',
    'poule'             => 'm.poule',
    'location'          => 'm.location_address',     // <-- was 'm.location'
    'referee_assigner'  => 'm.referee_assigner_uuid' // <-- match real column
];

foreach ($multiFilters as $key => $col) {
    if (!empty($_GET[$key]) && is_array($_GET[$key])) {
        $vals = array_values(array_filter($_GET[$key], fn($v) => $v !== ''));
        if ($vals) {
            $ph = implode(',', array_fill(0, count($vals), '?'));
            $whereClauses[] = "$col IN ($ph)";
            foreach ($vals as $v) $params[] = $v;
        }
    }
}

$whereSQL = $whereClauses ? ('WHERE ' . implode(' AND ', $whereClauses)) : '';

// MAIN QUERY (uses positional placeholders only; LIMIT/OFFSET are also '?')
$sql = "
SELECT
  m.uuid,
  m.match_date,
  m.kickoff_time,
  m.division,
  m.district,
  m.poule,
  m.expected_grade,

  -- assignments for precompute
  m.referee_id,
  m.ar1_id,
  m.ar2_id,
  m.commissioner_id,

  th.team_name AS home_team,
  ta.team_name AS away_team,

  ch.club_name AS home_club,
  ca.club_name AS away_club,

  -- match-level venue fields
  m.location_address,
  m.location_lat,
  m.location_lon,

  -- assigner username (optional)
  u.username AS referee_assigner_username,
  m.referee_assigner_uuid
FROM matches m
LEFT JOIN teams th ON m.home_team_id = th.uuid
LEFT JOIN clubs ch ON th.club_id     = ch.uuid
LEFT JOIN teams ta ON m.away_team_id = ta.uuid
LEFT JOIN clubs ca ON ta.club_id     = ca.uuid
LEFT JOIN users u  ON m.referee_assigner_uuid = u.uuid
{$whereSQL}
ORDER BY m.match_date ASC, m.kickoff_time ASC
LIMIT ? OFFSET ?
";

// COUNT for pagination (no need for joins)
$countSql = "SELECT COUNT(*) FROM matches m {$whereSQL}";

if ($loadInitialMatches) {
    // total count
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalMatches = (int)$countStmt->fetchColumn();
    $totalPages = (int)ceil($totalMatches / $matchesPerPage);

    // fetch page
    $stmt = $pdo->prepare($sql);
    $pageParams = array_merge($params, [$matchesPerPage, $offset]); // positional for LIMIT/OFFSET
    $stmt->execute($pageParams);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $matches = [];
    $totalMatches = 0;
    $totalPages = 0;
}

// --- START: Pre-computation for Conflict & Availability for initial load ---
$refereeSchedule_initial = [];
if ($loadInitialMatches) {
    foreach ($matches as $mrow) {
        $match_uuid = $mrow['uuid'];
        $match_date_str = $mrow['match_date'];
        $kickoff_time_str = $mrow['kickoff_time'];

        foreach (['referee_id', 'ar1_id', 'ar2_id', 'commissioner_id'] as $role_key) {
            if (!empty($mrow[$role_key])) {
                $ref_id = $mrow[$role_key];
                if (!isset($refereeSchedule_initial[$ref_id])) {
                    $refereeSchedule_initial[$ref_id] = [];
                }
                $refereeSchedule_initial[$ref_id][] = [
                    'match_id'        => $match_uuid,
                    'match_date_str'  => $match_date_str,
                    'kickoff_time_str'=> $kickoff_time_str,
                    'role'            => $role_key,
                    // no more location_uuid; use address if you need it:
                    'location_address'=> $mrow['location_address'] ?? null
                ];
            }
        }
    }
}

$refereeAvailabilityCache_initial = [];
if ($loadInitialMatches && !empty($referees)) {
    $allRefereeIds_initial = array_map(fn($ref) => $ref['uuid'], $referees);
    if (!empty($allRefereeIds_initial)) {
        $ph = implode(',', array_fill(0, count($allRefereeIds_initial), '?'));
        $unavailabilityStmt = $pdo->prepare("
            SELECT referee_id, start_date, end_date
            FROM referee_unavailability
            WHERE referee_id IN ($ph)
        ");
        $unavailabilityStmt->execute($allRefereeIds_initial);
        while ($row = $unavailabilityStmt->fetch(PDO::FETCH_ASSOC)) {
            $rid = $row['referee_id'];
            $refereeAvailabilityCache_initial[$rid]['unavailability'][] = $row;
        }

        $weeklyStmt = $pdo->prepare("
            SELECT referee_id, weekday, morning_available, afternoon_available, evening_available
            FROM referee_weekly_availability
            WHERE referee_id IN ($ph)
        ");
        $weeklyStmt->execute($allRefereeIds_initial);
        while ($row = $weeklyStmt->fetch(PDO::FETCH_ASSOC)) {
            $rid = $row['referee_id'];
            $refereeAvailabilityCache_initial[$rid]['weekly'][$row['weekday']] = $row;
        }
    }
}
// --- END: Pre-computation ---

?>
    <div class="container-fluid">
        <div class="content-card">
            <h1>Matches</h1>
            <script src="/js/referee_dropdown.js"></script>
            <?php if (isset($_GET['saved'])): ?>
                <div class="alert alert-success">Assignments saved successfully.</div>
            <?php endif; ?>

            <?php if ($assignMode): ?>
                <a href="matches.php?<?= buildQueryString(['assign_mode' => null]) ?>" class="false-a btn btn-sm btn-secondary-action mb-3">Disable Assign Mode</a>
                <button type="button" id="suggestAssignments" class="btn btn-sm btn-main-action mb-3">Suggest Assignments</button>
                <style>
                    #suggestionProgressBar {
                        width: var(--progress-width, 0%);
                        transition: width 0.2s ease-in-out;
                        height: 16px;
                    }
                </style>
                <div id="suggestionProgressBarContainer" class="progress mt-2 mb-3" style="display: none;">
                    <div id="suggestionProgressBar" class="progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
                <p id="suggestionProgressText" class="mt-2" style="display: none;"></p>
                <button type="button" id="clearAssignments" class="btn btn-sm btn-destructive-action mb-3">Clear Assignments</button>
            <?php else: ?>
                <a href="matches.php?<?= buildQueryString(['assign_mode' => 1]) ?>" class="btn btn-sm btn-warning-action mb-3">Enable Assign Mode</a>
            <?php endif; ?>
            <a href="export_matches.php?<?= buildQueryString([]) ?>" class="btn btn-sm btn-info-action mb-3 ms-2">Export to Excel (CSV)</a>
            <a href="assign_assigner.php" class="btn btn-sm btn-primary-action mb-3 ms-2">Assign Assigner</a>

            <form method="POST" action="bulk_assign.php">
                <?php if ($assignMode): ?>
                    <button type="submit" class="btn btn-main-action sticky-assign-button">Save Assignments</button>
                <?php endif; ?>
                <div class="table-responsive-custom">
                    <table class="table table-bordered">
                        <thead>
                        <tr>
                            <th>
                                Date
                                <div class="d-flex flex-column mt-1">
                                    <div class="d-flex flex-column gap-1 mt-1">
                                        <input type="date" class="form-control form-control-sm" id="ajaxStartDate" value="<?= htmlspecialchars($_GET['start_date'] ?? '') ?>">
                                        <input type="date" class="form-control form-control-sm" id="ajaxEndDate" value="<?= htmlspecialchars($_GET['end_date'] ?? '') ?>">
                                    </div>
                                </div>
                            </th>
                            <th>Kickoff</th>
                            <th>Home Team</th>
                            <th>Away Team</th>
                            <th>
                                Division
                                <div class="dropdown d-inline-block">
                                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" id="divisionFilterToggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                        <i class="bi bi-filter"></i>
                                    </button>
                                    <ul id="divisionFilterBox" class="dropdown-menu scrollable shadow rounded" aria-labelledby="divisionFilterToggle">
                                        <li id="divisionFilterOptions" class="px-3 py-2 d-flex flex-column gap-1">
                                            <!-- checkboxes will load here via AJAX -->
                                        </li>
                                        <li class="dropdown-divider"></li>
                                        <li class="px-3"><button type="button" id="clearDivisionFilter" class="btn btn-sm btn-light w-100">Clear</button></li>
                                        <li class="px-3"><button type="button" id="applyDivisionFilter" class="btn btn-sm btn-primary w-100">Apply</button></li>
                                    </ul>
                                </div>
                            </th>
                            <th>
                                District
                                <div class="dropdown d-inline-block">
                                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" id="districtFilterToggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                        <i class="bi bi-filter"></i>
                                    </button>
                                    <ul id="districtFilterBox" class="dropdown-menu scrollable shadow rounded" aria-labelledby="districtFilterToggle">
                                        <li id="districtFilterOptions" class="px-3 py-2 d-flex flex-column gap-1"></li>
                                        <li class="dropdown-divider"></li>
                                        <li class="px-3"><button type="button" id="clearDistrictFilter" class="btn btn-sm btn-light w-100">Clear</button></li>
                                        <li class="px-3"><button type="button" id="applyDistrictFilter" class="btn btn-sm btn-primary w-100">Apply</button></li>
                                    </ul>
                                </div>
                            </th>
                            <th>
                                Poule
                                <div class="dropdown d-inline-block">
                                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" id="pouleFilterToggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                        <i class="bi bi-filter"></i>
                                    </button>
                                    <ul id="pouleFilterBox" class="dropdown-menu scrollable shadow rounded" aria-labelledby="pouleFilterToggle">
                                        <li id="pouleFilterOptions" class="px-3 py-2 d-flex flex-column gap-1"></li>
                                        <li class="dropdown-divider"></li>
                                        <li class="px-3"><button type="button" id="clearPouleFilter" class="btn btn-sm btn-light w-100">Clear</button></li>
                                        <li class="px-3"><button type="button" id="applyPouleFilter" class="btn btn-sm btn-primary w-100">Apply</button></li>
                                    </ul>
                                </div>
                            </th>
                            <th>
                                Referee Assigner
                                <div class="dropdown d-inline-block">
                                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" id="refereeAssignerFilterToggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                        <i class="bi bi-filter"></i>
                                    </button>
                                    <ul id="refereeAssignerFilterBox" class="dropdown-menu scrollable shadow rounded" aria-labelledby="refereeAssignerFilterToggle">
                                        <li id="refereeAssignerFilterOptions" class="px-3 py-2 d-flex flex-column gap-1"></li>
                                        <li class="dropdown-divider"></li>
                                        <li class="px-3"><button type="button" id="clearRefereeAssignerFilter" class="btn btn-sm btn-light w-100">Clear</button></li>
                                        <li class="px-3"><button type="button" id="applyRefereeAssignerFilter" class="btn btn-sm btn-primary w-100">Apply</button></li>
                                    </ul>
                                </div>
                            </th>
                            <th>Referee</th>
                            <th>AR1</th>
                            <th>AR2</th>
                            <th>Commissioner</th>
                        </tr>
                        </thead>
                        <tbody id="matchesTableBody">
                        <?php foreach ($matches as $match): ?>
                            <tr>
                                <td><a href="match_detail.php?uuid=<?= htmlspecialchars($match['uuid']) ?>"><?= htmlspecialchars($match['match_date']) ?></a></td>
                                <td><?= htmlspecialchars(substr($match['kickoff_time'], 0, 5)) ?></td>
                                <td><?= htmlspecialchars($match['home_team']) ?></td>
                                <td><?= htmlspecialchars($match['away_team']) ?></td>
                                <td><?= htmlspecialchars($match['division']) ?></td>
                                <td><?= htmlspecialchars($match['district']) ?></td>
                                <td><?= htmlspecialchars($match['poule']) ?></td>
                                <td class="editable-cell"
                                    data-match-uuid="<?= htmlspecialchars($match['uuid']) ?>"
                                    data-field-type="referee_assigner"
                                    data-current-value="<?= htmlspecialchars($match['referee_assigner_uuid'] ?? '') ?>">
                                    <span class="cell-value"><?= htmlspecialchars($match['referee_assigner_username'] ?? 'N/A') ?></span>
                                    <i class="bi bi-pencil-square edit-icon"></i>
                                </td>
                                <td class="<?= $assignMode ? 'referee-select-cell' : '' ?>"><?php renderRefereeDropdown("referee_id", $match, $referees, $assignMode, $refereeSchedule_initial, $refereeAvailabilityCache_initial); ?></td>
                                <td class="<?= $assignMode ? 'referee-select-cell' : '' ?>"><?php renderRefereeDropdown("ar1_id", $match, $referees, $assignMode, $refereeSchedule_initial, $refereeAvailabilityCache_initial); ?></td>
                                <td class="<?= $assignMode ? 'referee-select-cell' : '' ?>"><?php renderRefereeDropdown("ar2_id", $match, $referees, $assignMode, $refereeSchedule_initial, $refereeAvailabilityCache_initial); ?></td>
                                <td class="<?= $assignMode ? 'referee-select-cell' : '' ?>"><?php renderRefereeDropdown("commissioner_id", $match, $referees, $assignMode, $refereeSchedule_initial, $refereeAvailabilityCache_initial); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Controls will be loaded here via AJAX -->
                <nav aria-label="Page navigation" id="paginationControls">
                    <ul class="pagination justify-content-center">
                        <?php if ($currentPage > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="#" data-page="<?= $currentPage - 1 ?>">Previous</a>
                            </li>
                        <?php endif; ?>

                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="page-item <?= ($i == $currentPage) ? 'active' : '' ?>">
                                <a class="page-link" href="#" data-page="<?= $i ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($currentPage < $totalPages): ?>
                            <li class="page-item">
                                <a class="page-link" href="#" data-page="<?= $currentPage + 1 ?>">Next</a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>

            </form>

            <script>
                const existingAssignments = <?= json_encode($matches); ?>;
            </script>

            <script src="/js/matches.js"></script>
            <script src="/js/match_conflicts.js"></script>

            <!-- Generic Edit Modal -->
            <div class="modal fade" id="editMatchFieldModal" tabindex="-1" aria-labelledby="editMatchFieldModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="editMatchFieldModalLabel">Edit Field</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body" id="editMatchFieldModalBody">
                            <!-- Input field will be injected here by JavaScript -->
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary-action" data-bs-dismiss="modal">Close</button>
                            <button type="button" class="btn btn-main-action" id="saveMatchFieldChange">Save changes</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php include 'includes/footer.php'; ?>