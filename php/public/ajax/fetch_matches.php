<?php
// Ensure session is started at the very beginning.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../utils/db.php';
include __DIR__ . '/../components/referee_dropdown.php';

$pdo = Database::getConnection();
$assignMode = isset($_GET['assign_mode']);
$whereClauses = [];
$params = [];

// User role-based filtering
$userRole = $_SESSION['user_role'] ?? null;
$userDivisionIds = $_SESSION['division_ids'] ?? [];
$userDistrictIds = $_SESSION['district_ids'] ?? [];

$allowedDivisionNames = [];
$allowedDistrictNames = [];
$proceedWithQuery = true;

if ($userRole !== 'super_admin') {
    // Fetch allowed division names
    if (!empty($userDivisionIds) && !(count($userDivisionIds) === 1 && $userDivisionIds[0] === '')) {
        $placeholders = implode(',', array_fill(0, count($userDivisionIds), '?'));
        $stmt = $pdo->prepare("SELECT name FROM divisions WHERE id IN ($placeholders)");
        $stmt->execute($userDivisionIds);
        $allowedDivisionNames = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Fetch allowed district names
    if (!empty($userDistrictIds) && !(count($userDistrictIds) === 1 && $userDistrictIds[0] === '')) {
        $placeholders = implode(',', array_fill(0, count($userDistrictIds), '?'));
        $stmt = $pdo->prepare("SELECT name FROM districts WHERE id IN ($placeholders)");
        $stmt->execute($userDistrictIds);
        $allowedDistrictNames = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // If user lacks permissions for BOTH divisions AND districts, they see no matches.
    if (empty($allowedDivisionNames) || empty($allowedDistrictNames)) {
        $matches = []; // Prepare an empty result set
        $proceedWithQuery = false; // Signal to skip the main match query
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

if ($proceedWithQuery) {
    // Only add other filters and execute query if we are proceeding
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

// Add new filters for location and referee_assigner
if (!empty($_GET['location']) && is_array($_GET['location'])) {
    $placeholders = implode(',', array_fill(0, count($_GET['location']), '?'));
    $whereClauses[] = "m.location_uuid IN ($placeholders)";
    foreach ($_GET['location'] as $value) {
        $params[] = $value;
    }
}

if (!empty($_GET['referee_assigner']) && is_array($_GET['referee_assigner'])) {
    $placeholders = implode(',', array_fill(0, count($_GET['referee_assigner']), '?'));
    $whereClauses[] = "m.referee_assigner_uuid IN ($placeholders)";
    foreach ($_GET['referee_assigner'] as $value) {
        $params[] = $value;
    }
}

$whereSQL = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

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
    LIMIT 100
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$matches = $stmt->fetchAll();

$referees = $pdo->query("SELECT uuid, first_name, last_name, grade FROM referees ORDER BY first_name")->fetchAll();

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
foreach ($matches as $match): ?>
    <tr>
        <td><a href="match_detail.php?uuid=<?= htmlspecialchars($match['uuid']) ?>"><?= htmlspecialchars($match['match_date']) ?></a></td>
        <td><?= htmlspecialchars(substr($match['kickoff_time'], 0, 5)) ?></td>
        <td><?= htmlspecialchars($match['home_club_name'] . " - " . $match['home_team_name']) ?></td>
        <td><?= htmlspecialchars($match['away_club_name'] . " - " . $match['away_team_name']) ?></td>
        <td><?= htmlspecialchars($match['division']) ?></td>
        <td><?= htmlspecialchars($match['district']) ?></td>
        <td><?= htmlspecialchars($match['poule']) ?></td>
        <td class="editable-cell"
            data-match-uuid="<?= htmlspecialchars($match['uuid']) ?>"
            data-field-type="location"
            data-current-value="<?= htmlspecialchars($match['location_uuid'] ?? '') ?>">
            <span class="cell-value">
                <?php
                $locOutput = htmlspecialchars($match['location_name'] ?? 'N/A');
                if (!empty($match['location_address']) && $match['location_name'] !== $match['location_address'] && $match['location_name']) {
                    $locOutput .= '<br><small>' . htmlspecialchars($match['location_address']) . '</small>';
                } elseif (empty($match['location_name']) && !empty($match['location_address'])) {
                     $locOutput = '<small>' . htmlspecialchars($match['location_address']) . '</small>';
                }
                echo $locOutput;
                ?>
            </span>
            <i class="bi bi-pencil-square edit-icon" style="display: none;"></i>
        </td>
        <td class="editable-cell"
            data-match-uuid="<?= htmlspecialchars($match['uuid']) ?>"
            data-field-type="referee_assigner"
            data-current-value="<?= htmlspecialchars($match['referee_assigner_uuid'] ?? '') ?>">
            <span class="cell-value"><?= htmlspecialchars($match['referee_assigner_username'] ?? 'N/A') ?></span>
            <i class="bi bi-pencil-square edit-icon" style="display: none;"></i>
        </td>
        <td><?php renderRefereeDropdown("referee_id", $match, $referees, $assignMode, $matches); ?></td>
        <td><?php renderRefereeDropdown("ar1_id", $match, $referees, $assignMode, $matches); ?></td>
        <td><?php renderRefereeDropdown("ar2_id", $match, $referees, $assignMode, $matches); ?></td>
        <td><?php renderRefereeDropdown("commissioner_id", $match, $referees, $assignMode, $matches); ?></td>
    </tr>
<?php endforeach; ?>
