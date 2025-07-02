<?php
require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';
include '../includes/header.php';
include '../includes/nav.php';

$pdo = Database::getConnection();

// Fetch referees with home club name
$referees = $pdo->query("
    SELECT 
        r.referee_id,
        r.first_name,
        r.last_name,
        r.email,
        r.phone,
        c.club_name AS home_club_name,
        r.home_location_city,
        r.grade,
        r.ar_grade
    FROM referees r
    LEFT JOIN clubs c ON r.home_club_id = c.uuid
    ORDER BY r.last_name, r.first_name
")->fetchAll();
?>
<div class="container">
    <div class="content-card">
        <h1>Referees</h1>

        <table class="table table-bordered table-striped"> <!-- Added table-striped -->
            <thead>
    <tr>
        <th>Referee ID</th>
        <th>Name</th>
        <th>Email</th>
        <th>Phone</th>
        <th>Home Club</th>
        <th>City</th>
        <th>Grade</th>
        <th>AR Grade</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($referees as $ref): ?>
        <tr>
            <td>
                <a href="referee_detail.php?id=<?= urlencode($ref['referee_id']) ?>">
                    <?= htmlspecialchars($ref['referee_id']) ?>
                </a>
            </td>
            <td>
                <a href="referee_detail.php?id=<?= urlencode($ref['referee_id']) ?>">
                    <?= htmlspecialchars($ref['first_name'] . ' ' . $ref['last_name']) ?>
                </a>
            </td>
            <td><?= htmlspecialchars($ref['email']) ?></td>
            <td><?= htmlspecialchars($ref['phone']) ?></td>
            <td><?= htmlspecialchars($ref['home_club_name']) ?></td>
            <td><?= htmlspecialchars($ref['home_location_city']) ?></td>
            <td><?= htmlspecialchars($ref['grade']) ?></td>
            <td><?= htmlspecialchars($ref['ar_grade']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
    </div> <!-- close content-card -->
</div> <!-- close container -->
<?php include '../includes/footer.php'; ?>
