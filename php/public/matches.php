<?php
require_once __DIR__ . '/../utils/db.php';
include 'includes/header.php';
include 'includes/nav.php';

$pdo = Database::getConnection();

// Fetch matches with team and club names
$matches = $pdo->query("
    SELECT 
        m.uuid,
        hc.club_name AS home_club_name,
        ht.team_name AS home_team_name,
        ac.club_name AS away_club_name,
        at.team_name AS away_team_name,
        m.division,
        m.expected_grade,
        m.uuid AS match_uuid
    FROM matches m
    JOIN teams ht ON m.home_team_id = ht.uuid
    JOIN clubs hc ON ht.club_id = hc.uuid
    JOIN teams at ON m.away_team_id = at.uuid
    JOIN clubs ac ON at.club_id = ac.uuid
")->fetchAll();
?>

<h1>Matches</h1>

<table class="table table-bordered">
    <thead>
    <tr>
        <th>ID</th>
        <th>Home Team</th>
        <th>Away Team</th>
        <th>Division</th>
        <th>Expected Grade</th>
        <th>Actions</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($matches as $match): ?>
        <tr>
            <td><?= htmlspecialchars($match['uuid']) ?></td>
            <td><?= htmlspecialchars($match['home_club_name'] . ' - ' . $match['home_team_name']) ?></td>
            <td><?= htmlspecialchars($match['away_club_name'] . ' - ' . $match['away_team_name']) ?></td>
            <td><?= htmlspecialchars($match['division']) ?></td>
            <td><?= htmlspecialchars($match['expected_grade']) ?></td>
            <td><a href="assign.php?match_id=<?= $match['uuid'] ?>" class="btn btn-primary btn-sm">Assign</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php include 'includes/footer.php'; ?>
