<?php
require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';
include 'includes/header.php';
include 'includes/nav.php';

$pdo = Database::getConnection();

// Fetch clubs
$clubs = $pdo->query("
    SELECT 
        club_id,
        club_name,
        precise_location_lat,
        precise_location_lon,
        address_text
    FROM clubs
    ORDER BY club_name
")->fetchAll();
?>

<h1>Clubs</h1>

<table class="table table-bordered">
    <thead>
    <tr>
        <th>Club ID</th>
        <th>Club Name</th>
        <th>Latitude</th>
        <th>Longitude</th>
        <th>Address</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($clubs as $club): ?>
        <tr>
            <td><?= htmlspecialchars($club['club_id']) ?></td>
            <td><?= htmlspecialchars($club['club_name']) ?></td>
            <td><?= htmlspecialchars($club['precise_location_lat']) ?></td>
            <td><?= htmlspecialchars($club['precise_location_lon']) ?></td>
            <td><?= htmlspecialchars($club['address_text']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php include 'includes/footer.php'; ?>
