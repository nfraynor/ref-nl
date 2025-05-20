<?php
require_once __DIR__ . '/../../utils/db.php';
include __DIR__ . '/../components/referee_dropdown.php';

$pdo = Database::getConnection();
$assignMode = isset($_GET['assign_mode']);
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
if (!empty($_GET['division']) && is_array($_GET['division'])) {
    $placeholders = implode(',', array_fill(0, count($_GET['division']), '?'));
    $whereClauses[] = "m.division IN ($placeholders)";
    foreach ($_GET['division'] as $div) {
        $params[] = $div;
    }
}


$whereSQL = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$sql = "
    SELECT 
        m.*,
        hc.club_name AS home_club_name,
        ht.team_name AS home_team_name,
        ac.club_name AS away_club_name,
        at.team_name AS away_team_name
    FROM matches m
    JOIN teams ht ON m.home_team_id = ht.uuid
    JOIN clubs hc ON ht.club_id = hc.uuid
    JOIN teams at ON m.away_team_id = at.uuid
    JOIN clubs ac ON at.club_id = ac.uuid
    $whereSQL
    ORDER BY m.match_date ASC, m.kickoff_time ASC
    LIMIT 100
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$matches = $stmt->fetchAll();

$referees = $pdo->query("SELECT uuid, first_name, last_name, grade FROM referees ORDER BY first_name")->fetchAll();

foreach ($matches as $match): ?>
    <tr>
        <td><?= htmlspecialchars($match['match_date']) ?></td>
        <td><?= htmlspecialchars(substr($match['kickoff_time'], 0, 5)) ?></td>
        <td><?= htmlspecialchars($match['home_club_name'] . " - " . $match['home_team_name']) ?></td>
        <td><?= htmlspecialchars($match['away_club_name'] . " - " . $match['away_team_name']) ?></td>
        <td><?= htmlspecialchars($match['division']) ?></td>
        <td><?= htmlspecialchars($match['district']) ?></td>
        <td><?= htmlspecialchars($match['poule']) ?></td>
        <td><?php renderRefereeDropdown("referee_id", $match, $referees, $assignMode, $matches); ?></td>
        <td><?php renderRefereeDropdown("ar1_id", $match, $referees, $assignMode, $matches); ?></td>
        <td><?php renderRefereeDropdown("ar2_id", $match, $referees, $assignMode, $matches); ?></td>
        <td><?php renderRefereeDropdown("commissioner_id", $match, $referees, $assignMode, $matches); ?></td>
    </tr>
<?php endforeach; ?>
