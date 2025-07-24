<?php
// Ensure session is started at the very beginning.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../utils/db.php';

header('Content-Type: application/json');

$pdo = Database::getConnection();
$assignMode = isset($_GET['assign_mode']);
$whereClauses = [];
$params = [];

// User role-based filtering
$userRole = $_SESSION['user_role'] ?? null;
$userId = $_SESSION['user_id'] ?? null;

$proceedWithQuery = true;
$permissionConditions = [];

if ($userRole !== 'super_admin' && $userId) {
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

    if (empty($allowedPairs)) {
        $proceedWithQuery = false;
    } else {
        foreach ($allowedPairs as $pair) {
            $permissionConditions[] = "(m.division = ? AND m.district = ?)";
            $params[] = $pair['division_name'];
            $params[] = $pair['district_name'];
        }
        $whereClauses[] = "(" . implode(' OR ', $permissionConditions) . ")";
    }
} elseif ($userRole !== 'super_admin' && !$userId) {
    $proceedWithQuery = false;
}

if ($proceedWithQuery) {
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
} else {
    $matches = [];
}

$referees = $pdo->query("SELECT uuid, first_name, last_name, grade FROM referees ORDER BY first_name")->fetchAll();

echo json_encode([
    'matches' => $matches,
    'referees' => $referees,
    'assignMode' => $assignMode
]);
?>
