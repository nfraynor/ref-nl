<?php
require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';
include 'includes/header.php';
include 'includes/nav.php';

$pdo = Database::getConnection();

// Fetch clubs
$clubs = $pdo->query("
    SELECT 
        c.uuid,
        c.club_id,
        c.club_name,
        l.name       AS field_name,
        l.address_text,
        l.latitude   AS lat,
        l.longitude  AS lon,
        c.primary_contact_name,
        c.primary_contact_email,
        c.primary_contact_phone,
        c.website_url,
        c.active
    FROM clubs c
    LEFT JOIN locations l ON c.location_uuid = l.uuid
    ORDER BY c.club_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

?>
<div class="container">
    <div class="content-card">
        <h1>Clubs</h1>

        <table class="table table-bordered table-striped"> <!-- Added table-striped for better readability -->
            <thead>
    <tr>
        <th>Club ID</th>
        <th>Club Name</th>
        <th>Field</th>
        <th>Address</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($clubs as $club): ?>
        <tr>
            <td>
                <a href="clubs/club-details.php?id=<?= urlencode($club['uuid']) ?>">
                    <?= htmlspecialchars($club['club_id']) ?>
                </a>
            </td>
            <td>
                <a href="clubs/club-details.php?id=<?= urlencode($club['uuid']) ?>">
                    <?= htmlspecialchars($club['club_name']) ?>
                </a>
            </td>
            <td><?= htmlspecialchars($club['field_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($club['address_text']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
    </div> <!-- close content-card -->
</div> <!-- close container -->
<?php include 'includes/footer.php'; ?>
