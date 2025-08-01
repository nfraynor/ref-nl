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
        grade,
        ar_grade
    FROM referees 
    ORDER BY first_name
")->fetchAll();

// Fetch referee preferences for weekend availability
$refereePreferences = [];
$refPrefsStmt = $pdo->prepare("
    SELECT uuid, max_matches_per_weekend, max_days_per_weekend 
    FROM referees 
    WHERE max_matches_per_weekend IS NOT NULL 
    OR max_days_per_weekend IS NOT NULL
");
$refPrefsStmt->execute();
while ($row = $refPrefsStmt->fetch(PDO::FETCH_ASSOC)) {
    $refereePreferences[$row['uuid']] = [
        'max_matches_per_weekend' => $row['max_matches_per_weekend'],
        'max_days_per_weekend' => $row['max_days_per_weekend']
    ];
}

global $refereePreferences;

// Fetch all future assignments for conflict checking
$allAssignments = [];
$stmtAll = $pdo->prepare("
    SELECT m.uuid, m.match_date, m.kickoff_time, m.referee_id, m.ar1_id, m.ar2_id, m.commissioner_id
    FROM matches m
    WHERE m.match_date >= CURDATE()
    ORDER BY m.match_date ASC, m.kickoff_time ASC
");
$stmtAll->execute();
while ($m = $stmtAll->fetch(PDO::FETCH_ASSOC)) {
    if ($m['referee_id']) {
        $allAssignments[] = [
            'matchId' => $m['uuid'],
            'role' => 'referee',
            'refereeId' => $m['referee_id'],
            'matchDate' => $m['match_date'],
            'kickoffTime' => $m['kickoff_time']
        ];
    }
    if ($m['ar1_id']) {
        $allAssignments[] = [
            'matchId' => $m['uuid'],
            'role' => 'ar1',
            'refereeId' => $m['ar1_id'],
            'matchDate' => $m['match_date'],
            'kickoffTime' => $m['kickoff_time']
        ];
    }
    if ($m['ar2_id']) {
        $allAssignments[] = [
            'matchId' => $m['uuid'],
            'role' => 'ar2',
            'refereeId' => $m['ar2_id'],
            'matchDate' => $m['match_date'],
            'kickoffTime' => $m['kickoff_time']
        ];
    }
    if ($m['commissioner_id']) {
        $allAssignments[] = [
            'matchId' => $m['uuid'],
            'role' => 'commissioner',
            'refereeId' => $m['commissioner_id'],
            'matchDate' => $m['match_date'],
            'kickoffTime' => $m['kickoff_time']
        ];
    }
}

// Fetch matches
$whereClauses = [];
$params = [];

// Role-based permission logic for initial query
$userRole = $_SESSION['user_role'] ?? null;
$userDivisionIds = $_SESSION['division_ids'] ?? [];
$userDistrictIds = $_SESSION['district_ids'] ?? [];

$allowedDivisionNames = [];
$allowedDistrictNames = [];
$loadInitialMatches = true;

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
        $matches = [];
    } else {
        $divisionPlaceholders = implode(',', array_fill(0, count($allowedDivisionNames), '?'));
        $whereClauses[] = "m.division IN ($divisionPlaceholders)";
        foreach ($allowedDivisionNames as $name) {
            $params[] = $name;
        }

        $districtPlaceholders = implode(',', array_fill(0, count($allowedDistrictNames), '?'));
        $whereClauses[] = "m.district IN ($districtPlaceholders)";
        foreach ($allowedDistrictNames as $name) {
            $params[] = $name;
        }
    }
}

// Pagination settings
$matchesPerPage = 50;
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) {
    $currentPage = 1;
}
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
foreach (['division', 'district', 'poule', 'location', 'referee_assigner'] as $filter) {
    if (!empty($_GET[$filter]) && is_array($_GET[$filter])) {
        $placeholders = implode(',', array_fill(0, count($_GET[$filter]), '?'));
        $whereClauses[] = "m.$filter IN ($placeholders)";
        foreach ($_GET[$filter] as $value) {
            $params[] = $value;
        }
    }
}

$whereSQL = count($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$sql = "
    SELECT 
        m.*,
        hc.club_name AS home_club_name,
        ht.team_name AS home_team_name,
        ac.club_name AS away_club_name,
        at.team_name AS away_team_name,
        l.name AS location_name,
        l.address_text AS location_address,
        assigner_user.username AS referee_assigner_username
    FROM matches m
    JOIN teams ht ON m.home_team_id = ht.uuid
    JOIN clubs hc ON ht.club_id = hc.uuid
    JOIN teams at ON m.away_team_id = at.uuid
    JOIN clubs ac ON at.club_id = ac.uuid
    LEFT JOIN locations l ON m.location_uuid = l.uuid
    LEFT JOIN users assigner_user ON m.referee_assigner_uuid = assigner_user.uuid
    $whereSQL
    ORDER BY m.match_date ASC, m.kickoff_time ASC
    LIMIT ? OFFSET ?
";

// Append positional placeholders for LIMIT and OFFSET
$params[] = $matchesPerPage;
$params[] = $offset;

$countSql = "SELECT COUNT(*) FROM matches m $whereSQL";

if ($loadInitialMatches) {
    // Fetch total matches for pagination
    $countStmt = $pdo->prepare($countSql);
    $paramIndex = 1;
    foreach ($params as $value) {
        if ($paramIndex <= count($params) - 2) { // Exclude LIMIT and OFFSET params for count query
            $countStmt->bindValue($paramIndex, $value);
            $paramIndex++;
        }
    }
    $countStmt->execute();
    $totalMatches = (int)$countStmt->fetchColumn();
    $totalPages = ceil($totalMatches / $matchesPerPage);

    // Fetch matches for the current page
    $stmt = $pdo->prepare($sql);
    $paramIndex = 1;
    foreach ($params as $value) {
        $stmt->bindValue($paramIndex, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $paramIndex++;
    }
    error_log("Executing query: $sql with params: " . json_encode($params));
    $stmt->execute();
    $matches = $stmt->fetchAll();
} else {
    $matches = [];
    $totalMatches = 0;
    $totalPages = 0;
}

// --- START: Pre-computation for Conflict & Availability for initial load ---
$refereeSchedule_initial = [];
if ($loadInitialMatches) {
    foreach ($matches as $match_item_initial) {
        $match_uuid_initial = $match_item_initial['uuid'];
        $match_date_for_schedule_initial = $match_item_initial['match_date'];
        $match_kickoff_time_initial = $match_item_initial['kickoff_time'];

        foreach (['referee_id', 'ar1_id', 'ar2_id', 'commissioner_id'] as $role_key_initial) {
            if (!empty($match_item_initial[$role_key_initial])) {
                $ref_id_for_schedule_initial = $match_item_initial[$role_key_initial];
                if (!isset($refereeSchedule_initial[$ref_id_for_schedule_initial])) {
                    $refereeSchedule_initial[$ref_id_for_schedule_initial] = [];
                }
                $refereeSchedule_initial[$ref_id_for_schedule_initial][] = [
                    'match_id' => $match_uuid_initial,
                    'match_date_str' => $match_date_for_schedule_initial,
                    'kickoff_time_str' => $match_kickoff_time_initial,
                    'role' => $role_key_initial,
                    'location_uuid' => $match_item_initial['location_uuid']
                ];
            }
        }
    }
}

$refereeAvailabilityCache_initial = [];
if ($loadInitialMatches && !empty($referees)) {
    $allRefereeIds_initial = array_map(function($ref) { return $ref['uuid']; }, $referees);

    if (!empty($allRefereeIds_initial)) {
        $placeholders_initial = implode(',', array_fill(0, count($allRefereeIds_initial), '?'));
        $unavailabilityStmt_initial = $pdo->prepare("SELECT referee_id, start_date, end_date FROM referee_unavailability WHERE referee_id IN ($placeholders_initial)");
        $unavailabilityStmt_initial->execute($allRefereeIds_initial);
        while ($row_initial = $unavailabilityStmt_initial->fetch(PDO::FETCH_ASSOC)) {
            if (!isset($refereeAvailabilityCache_initial[$row_initial['referee_id']])) {
                $refereeAvailabilityCache_initial[$row_initial['referee_id']] = ['unavailability' => [], 'weekly' => []];
            }
            $refereeAvailabilityCache_initial[$row_initial['referee_id']]['unavailability'][] = $row_initial;
        }

        $weeklyStmt_initial = $pdo->prepare("SELECT referee_id, weekday, morning_available, afternoon_available, evening_available FROM referee_weekly_availability WHERE referee_id IN ($placeholders_initial)");
        $weeklyStmt_initial->execute($allRefereeIds_initial);
        while ($row_initial = $weeklyStmt_initial->fetch(PDO::FETCH_ASSOC)) {
            if (!isset($refereeAvailabilityCache_initial[$row_initial['referee_id']])) {
                $refereeAvailabilityCache_initial[$row_initial['referee_id']] = ['unavailability' => [], 'weekly' => []];
            }
            $refereeAvailabilityCache_initial[$row_initial['referee_id']]['weekly'][$row_initial['weekday']] = $row_initial;
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
                <a href="matches.php?<?= buildQueryString(['assign_mode' => null]) ?>" class="false-a btn-sm btn-secondary-action mb-3">Disable Assign Mode</a>
                <button type="button" id="suggestAssignments" class="btn-sm btn-main-action mb-3">Suggest Assignments</button>
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
                <button type="button" id="clearAssignments" class="btn-sm btn-destructive-action mb-3">Clear Assignments</button>
            <?php else: ?>
                <a href="matches.php?<?= buildQueryString(['assign_mode' => 1]) ?>" class="btn-yellow btn-sm btn-main-action mb-4">Enable Assign Mode</a>
                <a href="export_matches.php?<?= buildQueryString([]) ?>" class="btn-sm btn-main-action mb-4 ms-2">Export to Excel (CSV)</a>
                <a href="assign_assigner.php" class="btn-sm btn-main-action mb-4 ms-2">Assign Assigner</a>
            <?php endif; ?>
            <form method="POST" action="bulk_assign.php">
                <?php if ($assignMode): ?>
                    <button type="submit" class="btn-main-action sticky-assign-button">Save Assignments</button>
                <?php endif; ?>
                <div class="table-responsive-custom mt-4">
                    <table class="table table-bordered">
                        <thead>
                        <tr>
                            <th>
                                Date
                                <div class="flex flex-col gap-2 mt-2">
                                    <input type="date" class="form-control form-control-sm" id="ajaxStartDate" value="<?= htmlspecialchars($_GET['start_date'] ?? '') ?>">
                                    <input type="date" class="form-control form-control-sm" id="ajaxEndDate" value="<?= htmlspecialchars($_GET['end_date'] ?? '') ?>">
                                    <button type="button" id="clearDateFilter" class="btn btn-sm btn-outline-secondary">Clear Dates</button>
                                </div>
                            </th>
                            <th>Kickoff</th>
                            <th>Home Team</th>
                            <th>Away Team</th>
                            <th>
                                Division
                                <div class="relative inline-block">
                                    <button type="button" class="btn-sm btn-outline-secondary flex items-center gap-1" id="divisionFilterToggle" data-bs-toggle="dropdown" data-bs-auto-close="outside">
                                        <i class="bi bi-filter"></i>
                                    </button>
                                    <div class="dropdown-menu w-64 max-h-96 overflow-y-auto shadow-lg rounded-lg p-4" aria-labelledby="divisionFilterToggle">
                                        <input type="text" class="form-control form-control-sm mb-2" id="divisionFilterSearch" placeholder="Search...">
                                        <div id="divisionFilterOptions" class="flex flex-col gap-2"></div>
                                        <hr class="my-2">
                                        <div class="flex gap-2">
                                            <button type="button" id="clearDivisionFilter" class="btn btn-sm btn-outline-secondary flex-1">Clear</button>
                                            <button type="button" id="applyDivisionFilter" class="btn btn-sm btn-primary flex-1">Apply</button>
                                        </div>
                                    </div>
                                </div>
                            </th>
                            <th>
                                District
                                <div class="relative inline-block">
                                    <button type="button" class="btn-sm btn-outline-secondary flex items-center gap-1" id="districtFilterToggle" data-bs-toggle="dropdown" data-bs-auto-close="outside">
                                        <i class="bi bi-filter"></i>
                                    </button>
                                    <div class="dropdown-menu w-64 max-h-96 overflow-y-auto shadow-lg rounded-lg p-4" aria-labelledby="districtFilterToggle">
                                        <input type="text" class="form-control form-control-sm mb-2" id="districtFilterSearch" placeholder="Search...">
                                        <div id="districtFilterOptions" class="flex flex-col gap-2"></div>
                                        <hr class="my-2">
                                        <div class="flex gap-2">
                                            <button type="button" id="clearDistrictFilter" class="btn btn-sm btn-outline-secondary flex-1">Clear</button>
                                            <button type="button" id="applyDistrictFilter" class="btn btn-sm btn-primary flex-1">Apply</button>
                                        </div>
                                    </div>
                                </div>
                            </th>
                            <th>
                                Poule
                                <div class="relative inline-block">
                                    <button type="button" class="btn-sm btn-outline-secondary flex items-center gap-1" id="pouleFilterToggle" data-bs-toggle="dropdown" data-bs-auto-close="outside">
                                        <i class="bi bi-filter"></i>
                                    </button>
                                    <div class="dropdown-menu w-64 max-h-96 overflow-y-auto shadow-lg rounded-lg p-4" aria-labelledby="pouleFilterToggle">
                                        <input type="text" class="form-control form-control-sm mb-2" id="pouleFilterSearch" placeholder="Search...">
                                        <div id="pouleFilterOptions" class="flex flex-col gap-2"></div>
                                        <hr class="my-2">
                                        <div class="flex gap-2">
                                            <button type="button" id="clearPouleFilter" class="btn btn-sm btn-outline-secondary flex-1">Clear</button>
                                            <button type="button" id="applyPouleFilter" class="btn btn-sm btn-primary flex-1">Apply</button>
                                        </div>
                                    </div>
                                </div>
                            </th>
                            <th class="hide-this">
                                Location
                                <div class="relative inline-block">
                                    <button type="button" class="btn-sm btn-outline-secondary flex items-center gap-1" id="locationFilterToggle" data-bs-toggle="dropdown" data-bs-auto-close="outside">
                                        <i class="bi bi-filter"></i>
                                    </button>
                                    <div class="dropdown-menu w-64 max-h-96 overflow-y-auto shadow-lg rounded-lg p-4" aria-labelledby="locationFilterToggle">
                                        <input type="text" class="form-control form-control-sm mb-2" id="locationFilterSearch" placeholder="Search...">
                                        <div id="locationFilterOptions" class="flex flex-col gap-2"></div>
                                        <hr class="my-2">
                                        <div class="flex gap-2">
                                            <button type="button" id="clearLocationFilter" class="btn btn-sm btn-outline-secondary flex-1">Clear</button>
                                            <button type="button" id="applyLocationFilter" class="btn btn-sm btn-primary flex-1">Apply</button>
                                        </div>
                                    </div>
                                </div>
                            </th>
                            <th>
                                Referee Assigner
                                <div class="relative inline-block">
                                    <button type="button" class="btn-sm btn-outline-secondary flex items-center gap-1" id="refereeAssignerFilterToggle" data-bs-toggle="dropdown" data-bs-auto-close="outside">
                                        <i class="bi bi-filter"></i>
                                    </button>
                                    <div class="dropdown-menu w-64 max-h-96 overflow-y-auto shadow-lg rounded-lg p-4" aria-labelledby="refereeAssignerFilterToggle">
                                        <input type="text" class="form-control form-control-sm mb-2" id="refereeAssignerFilterSearch" placeholder="Search...">
                                        <div id="refereeAssignerFilterOptions" class="flex flex-col gap-2"></div>
                                        <hr class="my-2">
                                        <div class="flex gap-2">
                                            <button type="button" id="clearRefereeAssignerFilter" class="btn btn-sm btn-outline-secondary flex-1">Clear</button>
                                            <button type="button" id="applyRefereeAssignerFilter" class="btn btn-sm btn-primary flex-1">Apply</button>
                                        </div>
                                    </div>
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
                                <td><?= htmlspecialchars($match['home_team_name']) ?></td>
                                <td><?= htmlspecialchars($match['away_team_name']) ?></td>
                                <td><?= htmlspecialchars($match['division']) ?></td>
                                <td><?= htmlspecialchars($match['district']) ?></td>
                                <td><?= htmlspecialchars($match['poule']) ?></td>
                                <td class="editable-cell location-cell hide-this"
                                    data-match-uuid="<?= htmlspecialchars($match['uuid']) ?>"
                                    data-field-type="location"
                                    data-current-value="<?= htmlspecialchars($match['location_uuid'] ?? '') ?>">
                                    <span class="cell-value hide-this">
                                        <?php
                                        $locationName = htmlspecialchars($match['location_name'] ?? 'N/A');
                                        $locationAddress = htmlspecialchars($match['location_address'] ?? '');
                                        $tooltip = '';
                                        if (!empty($locationAddress) && $locationName !== $locationAddress) {
                                            $tooltip = 'title="' . $locationAddress . '"';
                                        }
                                        echo '<span ' . $tooltip . '>' . $locationName . '</span>';
                                        ?>
                                    </span>
                                    <i class="bi bi-pencil-square edit-icon"></i>
                                </td>
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

                <!-- Pagination Controls -->
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
                const existingAssignments = <?= json_encode($allAssignments); ?>;
                const refereePreferences = <?= json_encode($refereePreferences); ?>;
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