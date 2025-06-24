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

if (!empty($_GET['start_date'])) {
    $whereClauses[] = "m.match_date >= ?";
    $params[] = $_GET['start_date'];
}

if (!empty($_GET['end_date'])) {
    $whereClauses[] = "m.match_date <= ?";
    $params[] = $_GET['end_date'];
}
foreach (['division', 'district', 'poule'] as $filter) {
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
    LIMIT 20
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$matches = $stmt->fetchAll();


// Conflict checker
function checkConflict($matches, $refId, $thisMatchId, $matchDate, $kickoffTime) {
    $conflictType = null;

    $currentDate = new DateTime($matchDate);
    $currentStart = strtotime("1970-01-01T" . $kickoffTime);
    $currentEnd = $currentStart + (90 * 60); // 90 min

    foreach ($matches as $match) {
        $sameMatch = $match['uuid'] === $thisMatchId;

        foreach (['referee_id', 'ar1_id', 'ar2_id', 'commissioner_id'] as $role) {
            if ($match[$role] == $refId) {

                // 🟥 Same match: multiple roles
                if ($sameMatch) {
                    $refCount = 0;
                    foreach (['referee_id', 'ar1_id', 'ar2_id', 'commissioner_id'] as $checkRole) {
                        if ($match[$checkRole] === $refId) $refCount++;
                    }
                    if ($refCount > 1) return 'red';
                    continue;
                }

                $otherDate = new DateTime($match['match_date']);
                $daysBetween = (int)$currentDate->diff($otherDate)->format('%r%a');

                // 🟥 Same day: check time overlap
                if ($daysBetween === 0) {
                    $otherStart = strtotime("1970-01-01T" . $match['kickoff_time']);
                    $otherEnd = $otherStart + (90 * 60);

                    if ($currentStart < $otherEnd && $otherStart < $currentEnd) {
                        return 'red';
                    } else {
                        $conflictType = 'orange';
                    }
                }

                // 🟡 Within ±2 days
                elseif (abs($daysBetween) <= 2) {
                    if (!$conflictType) $conflictType = 'yellow';
                }
            }
        }
    }

    return $conflictType;
}

function getRefName($referees, $uuid) {
    foreach ($referees as $ref) {
        if ($ref['uuid'] === $uuid) return $ref['first_name'] . ' ' . $ref['last_name'];
    }
    return "Unknown";
}
?>

<h1>Matches</h1>
<script src="/js/referee_dropdown.js"></script>
<?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success">Assignments saved successfully.</div>
<?php endif; ?>

<?php if ($assignMode): ?>
    <a href="matches.php?<?= buildQueryString(['assign_mode' => null]) ?>" class="btn btn-sm btn-secondary mb-3">Disable Assign Mode</a>
    <button type="button" id="suggestAssignments" class="btn btn-sm btn-info mb-3">Suggest Assignments</button>
    <button type="button" id="clearAssignments" class="btn btn-sm btn-danger mb-3">Clear Assignments</button>
<?php else: ?>
    <a href="matches.php?<?= buildQueryString(['assign_mode' => 1]) ?>" class="btn btn-sm btn-warning mb-3">Enable Assign Mode</a>
<?php endif; ?>

    <?php if ($assignMode): ?>
        <button type="submit" class="btn btn-success sticky-assign-button">Save Assignments</button>
    <?php endif; ?>
<div class="table-responsive-custom">

    <table class="table table-bordered">
        <thead>
        <tr>
            <th style="position: relative;">
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
            <th style="position: relative;">
                Division
                <button type="button" class="btn btn-sm btn-outline-secondary ms-1" id="divisionFilterToggle">
                    <i class="bi bi-filter"></i>
                </button>

                <div id="divisionFilterBox" style="display: none; position: absolute; background: #fff; padding: 10px; border: 1px solid #ccc; z-index: 1000;" class="shadow rounded">
                    <div id="divisionFilterOptions" class="d-flex flex-column gap-1" style="max-height: 200px; overflow-y: auto;">
                        <!-- checkboxes will load here via AJAX -->
                    </div>
                    <button type="button" id="clearDivisionFilter" class="btn btn-sm btn-light mt-2">Clear</button>
                </div>
            </th>

            <th style="position: relative;">
                District
                <button type="button" class="btn btn-sm btn-outline-secondary ms-1" id="districtFilterToggle">
                    <i class="bi bi-filter"></i>
                </button>
                <div id="districtFilterBox" class="filter-box">
                    <div id="districtFilterOptions" class="filter-options"></div>
                    <button type="button" id="clearDistrictFilter" class="btn btn-sm btn-light mt-2">Clear</button>
                </div>
            </th>

            <th style="position: relative;">
                Poule
                <button type="button" class="btn btn-sm btn-outline-secondary ms-1" id="pouleFilterToggle">
                    <i class="bi bi-filter"></i>
                </button>
                <div id="pouleFilterBox" class="filter-box">
                    <div id="pouleFilterOptions" class="filter-options"></div>
                    <button type="button" id="clearPouleFilter" class="btn btn-sm btn-light mt-2">Clear</button>
                </div>
            </th>
            <th>Location</th>
            <th>Referee Assigner</th>
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
                <td>
                    <?php
                    $locOutput = htmlspecialchars($match['location_name'] ?? 'N/A');
                    if (!empty($match['location_address']) && $match['location_name'] !== $match['location_address']) {
                        $locOutput .= '<br><small>' . htmlspecialchars($match['location_address']) . '</small>';
                    }
                    echo $locOutput;
                    ?>
                </td>
                <td><?= htmlspecialchars($match['referee_assigner_username'] ?? 'N/A') ?></td>
                <td><?php renderRefereeDropdown("referee_id", $match, $referees, $assignMode, $matches); ?></td>
                <td><?php renderRefereeDropdown("ar1_id", $match, $referees, $assignMode, $matches); ?></td>
                <td><?php renderRefereeDropdown("ar2_id", $match, $referees, $assignMode, $matches); ?></td>
                <td><?php renderRefereeDropdown("commissioner_id", $match, $referees, $assignMode, $matches); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
    <?php if ($assignMode): ?>
        <button type="submit" class="btn btn-success" style="position: fixed; bottom: 20px; right: 20px; z-index: 999;">Save Assignments</button>
    <?php endif; ?>
</form>

<script>
    const existingAssignments = <?= json_encode($matches); ?>;
</script>

<script src="/js/matches.js"></script>
<script src="/js/match_conflicts.js"></script>
<?php include 'includes/footer.php'; ?>
