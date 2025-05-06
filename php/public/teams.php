<?php
require_once __DIR__ . '/../utils/db.php';
include 'includes/header.php';
include 'includes/nav.php';

$pdo = Database::getConnection();

// Fetch teams with club names
$teams = $pdo->query("
    SELECT 
        t.uuid,
        t.team_name,
        c.club_name,
        t.division
    FROM teams t
    JOIN clubs c ON t.club_id = c.uuid
    ORDER BY c.club_name, t.team_name
")->fetchAll();
?>

<h1>Teams</h1>

<table class="table table-bordered">
    <thead>
    <tr>
        <th>Team ID</th>
        <th>Team Name</th>
        <th>Club</th>
        <th>Division</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($teams as $team): ?>
        <tr>
            <td><?= htmlspecialchars($team['uuid']) ?></td>
            <td><?= htmlspecialchars($team['team_name']) ?></td>
            <td><?= htmlspecialchars($team['club_name']) ?></td>
            <td><?= htmlspecialchars($team['division']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php include 'includes/footer.php'; ?>
