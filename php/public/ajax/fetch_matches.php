<?php
// Ensure session is started at the very beginning.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
error_log("[fetch_matches.php] Script started. Session status: " . session_status());

require_once __DIR__ . '/../../utils/db.php';
include __DIR__ . '/../components/referee_dropdown.php';

$pdo = Database::getConnection();
$assignMode = isset($_GET['assign_mode']);
$whereClauses = [];
$params = [];

// User role-based filtering
$userRole = $_SESSION['user_role'] ?? null;
$userId = $_SESSION['user_id'] ?? null; // Ensure user_id is fetched from session

error_log("[fetch_matches.php] Session Data: User Role: " . $userRole . ", User ID: " . $userId);

$proceedWithQuery = true;
$permissionConditions = []; // Stores "(m.division = ? AND m.district = ?)" strings

if ($userRole !== 'super_admin' && $userId) {
    // Fetch allowed division and district name pairs
    $sqlPermissions = "
        SELECT d.name AS division_name, dist.name AS district_name
        FROM user_permissions up
        JOIN divisions d ON up.division_id = d.id
        JOIN districts dist ON up.district_id = dist.id
        WHERE up.user_id = ?
    ";
    $stmtPermissions = $pdo->prepare($sqlPermissions);
    $stmtPermissions->execute([$userId]);
    $allowedPairs = $stmtPermissions->fetchAll(PDO::FETCH_ASSOC);

    error_log("[fetch_matches.php] Allowed Division/District Pairs: " . print_r($allowedPairs, true));

    if (empty($allowedPairs)) {
        $matches = []; // Prepare an empty result set
        $proceedWithQuery = false; // Signal to skip the main match query
        error_log("[fetch_matches.php] Permission Check: User has no specific division/district pairs. Proceed with query: false");
    } else {
        foreach ($allowedPairs as $pair) {
            $permissionConditions[] = "(m.division = ? AND m.district = ?)";
            $params[] = $pair['division_name'];
            $params[] = $pair['district_name'];
        }
        // Combine all permission conditions with OR
        $whereClauses[] = "(" . implode(' OR ', $permissionConditions) . ")";
        error_log("[fetch_matches.php] Permission Check: Constructed permission WHERE clause: " . end($whereClauses));
    }
} elseif ($userRole !== 'super_admin' && !$userId) {
    // Non-super_admin without a user_id, should not see anything.
    $matches = [];
    $proceedWithQuery = false;
    error_log("[fetch_matches.php] Permission Check: Non-super_admin without user_id. Proceed with query: false");
}
// For super_admin, $proceedWithQuery remains true and no specific permission clauses are added, so they see all.

if ($proceedWithQuery) {
    // Only add other filters and execute query if we are proceeding
    // Date filters
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
    error_log("[fetch_matches.php] SQL Query Data: WHERE Clauses: " . print_r($whereClauses, true) .
                ", Params: " . print_r($params, true));

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
    error_log("[fetch_matches.php] SQL Query Result: Number of matches fetched: " . count($matches));
} else {
    error_log("[fetch_matches.php] SQL Query Skipped. Proceed with query was false.");
} // End of if ($proceedWithQuery)

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
