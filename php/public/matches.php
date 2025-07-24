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
// --- END: Role-based permission logic ---

// Pagination settings
$matchesPerPage = 20;
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
    FROM (
        SELECT uuid
        FROM matches m
        $whereSQL
        ORDER BY m.match_date ASC, m.kickoff_time ASC
        LIMIT :limit OFFSET :offset
    ) AS paginated_matches
    JOIN matches m ON paginated_matches.uuid = m.uuid
    JOIN teams ht ON m.home_team_id = ht.uuid
    JOIN clubs hc ON ht.club_id = hc.uuid
    JOIN teams at ON m.away_team_id = at.uuid
    JOIN clubs ac ON at.club_id = ac.uuid
    LEFT JOIN locations l ON m.location_uuid = l.uuid
    LEFT JOIN users assigner_user ON m.referee_assigner_uuid = assigner_user.uuid
    ORDER BY m.match_date ASC, m.kickoff_time ASC
";

$countSql = "SELECT COUNT(*) FROM matches m $whereSQL";

if ($loadInitialMatches) {
    // Fetch total matches for pagination
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalMatches = (int)$countStmt->fetchColumn();
    $totalPages = ceil($totalMatches / $matchesPerPage);

    // Fetch matches for the current page
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $matchesPerPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        // PDOStatement::bindValue uses 1-based indexing for placeholders
        $stmt->bindValue($key + 1, $value);
    }
    $stmt->execute();
    $matches = $stmt->fetchAll();
} else {
    $matches = [];
    $totalMatches = 0;
    $totalPages = 0;
}

// --- START: Pre-computation for Conflict & Availability for initial load ---
$refereeSchedule_initial = [];
if ($loadInitialMatches) { // Only compute if matches were loaded
    foreach ($matches as $match_item_initial) { // Use unique var name
        $match_uuid_initial = $match_item_initial['uuid'];
        $match_date_for_schedule_initial = $match_item_initial['match_date'];
        $kickoff_time_for_schedule_initial = $match_item_initial['kickoff_time'];

        foreach (['referee_id', 'ar1_id', 'ar2_id', 'commissioner_id'] as $role_key_initial) {
            if (!empty($match_item_initial[$role_key_initial])) {
                $ref_id_for_schedule_initial = $match_item_initial[$role_key_initial];
                if (!isset($refereeSchedule_initial[$ref_id_for_schedule_initial])) {
                    $refereeSchedule_initial[$ref_id_for_schedule_initial] = [];
                }
                $refereeSchedule_initial[$ref_id_for_schedule_initial][] = [
                    'match_id' => $match_uuid_initial,
                    'match_date_str' => $match_date_for_schedule_initial,
                    'kickoff_time_str' => $kickoff_time_for_schedule_initial,
                    'role' => $role_key_initial,
                    'location_uuid' => $match_item_initial['location_uuid']
                ];
            }
        }
    }
}

$refereeAvailabilityCache_initial = [];
if ($loadInitialMatches && !empty($referees)) { // Only compute if matches were loaded and referees exist
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
                <a href="matches.php?<?= buildQueryString(['assign_mode' => null]) ?>" class="false-a btn btn-sm btn-secondary-action mb-3">Disable Assign Mode</a>
                <button type="button" id="suggestAssignments" class="btn btn-sm btn-main-action mb-3">Suggest Assignments</button>
                <button type="button" id="clearAssignments" class="btn btn-sm btn-destructive-action mb-3">Clear Assignments</button>
            <?php else: ?>
                <a href="matches.php?<?= buildQueryString(['assign_mode' => 1]) ?>" class="btn btn-sm btn-warning-action mb-3">Enable Assign Mode</a>
            <?php endif; ?>
            <a href="export_matches.php?<?= buildQueryString([]) ?>" class="btn btn-sm btn-info-action mb-3 ms-2">Export to Excel (CSV)</a>

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
                        <form method="POST" action="bulk_assign.php">
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
                                Location
                                <div class="dropdown d-inline-block">
                                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" id="locationFilterToggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                        <i class="bi bi-filter"></i>
                                    </button>
                                    <ul id="locationFilterBox" class="dropdown-menu scrollable shadow rounded" aria-labelledby="locationFilterToggle">
                                        <li id="locationFilterOptions" class="px-3 py-2 d-flex flex-column gap-1"></li>
                                        <li class="dropdown-divider"></li>
                                        <li class="px-3"><button type="button" id="clearLocationFilter" class="btn btn-sm btn-light w-100">Clear</button></li>
                                        <li class="px-3"><button type="button" id="applyLocationFilter" class="btn btn-sm btn-primary w-100">Apply</button></li>
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
                            <td><?= htmlspecialchars($match['home_club_name'] . " - " . $match['home_team_name']) ?></td>
                            <td><?= htmlspecialchars($match['away_club_name'] . " - " . $match['away_team_name']) ?></td>
                            <td><?= htmlspecialchars($match['division']) ?></td>
                            <td><?= htmlspecialchars($match['district']) ?></td>
                            <td><?= htmlspecialchars($match['poule']) ?></td>
                            <td class="editable-cell location-cell"
                                data-match-uuid="<?= htmlspecialchars($match['uuid']) ?>"
                                data-field-type="location"
                                data-current-value="<?= htmlspecialchars($match['location_uuid'] ?? '') ?>">
                            <span class="cell-value">
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
            <nav aria-label="Page navigation">
                <ul class="pagination justify-content-center">
                    <?php if ($currentPage > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?= buildQueryString(['page' => $currentPage - 1]) ?>">Previous</a>
                        </li>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?= ($i == $currentPage) ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= buildQueryString(['page' => $i]) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($currentPage < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?= buildQueryString(['page' => $currentPage + 1]) ?>">Next</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>

            <?php if ($assignMode): ?>
                <button type="submit" class="btn btn-main-action" style="position: fixed; bottom: 20px; right: 20px; z-index: 999;">Save Assignments</button>
            <?php endif; ?>
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