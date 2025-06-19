<?php
require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/nav.php';

$pdo = Database::getConnection();
$matchUuid = $_GET['uuid'] ?? null;

if (!$matchUuid) {
    echo "<div class='container mt-4'><p class='alert alert-warning'>Match ID is missing.</p></div>";
    include __DIR__ . '/includes/footer.php';
    exit;
}

// Fetch match info
$stmt = $pdo->prepare("
    SELECT
        m.uuid AS match_uuid,
        m.match_date,
        m.kickoff_time,
        -- m.location_notes, -- Old notes from matches table, replaced by locations.notes
        m.division,
        ht.team_name AS home_team_name,
        at.team_name AS away_team_name,
        hcl.club_name AS home_club_name,
        acl.club_name AS away_club_name,
        l.name AS location_name,                 -- from locations table
        l.address_text AS location_address_text, -- from locations table
        l.latitude AS location_latitude,         -- from locations table
        l.longitude AS location_longitude,       -- from locations table
        l.notes AS location_specific_notes,      -- from locations table
        CONCAT(main_ref.first_name, ' ', main_ref.last_name) AS main_ref_name,
        main_ref.uuid AS main_ref_uuid,
        CONCAT(ar1.first_name, ' ', ar1.last_name) AS ar1_name,
        ar1.uuid AS ar1_uuid,
        CONCAT(ar2.first_name, ' ', ar2.last_name) AS ar2_name,
        ar2.uuid AS ar2_uuid
    FROM matches m
    JOIN teams ht ON m.home_team_id = ht.uuid
    JOIN teams at ON m.away_team_id = at.uuid
    LEFT JOIN clubs hcl ON ht.club_id = hcl.uuid
    LEFT JOIN clubs acl ON at.club_id = acl.uuid
    LEFT JOIN locations l ON m.location_uuid = l.uuid -- Corrected JOIN condition
    LEFT JOIN referees main_ref ON m.referee_id = main_ref.uuid
    LEFT JOIN referees ar1 ON m.ar1_id = ar1.uuid
    LEFT JOIN referees ar2 ON m.ar2_id = ar2.uuid
    WHERE m.uuid = ?
");
$stmt->execute([$matchUuid]);
$match = $stmt->fetch();

if (!$match) {
    echo "<div class='container mt-4'><p class='alert alert-danger'>Match not found.</p></div>";
    include __DIR__ . '/includes/footer.php';
    exit;
}
?>

<div class="container mt-4">
    <section class="mb-4">
        <div class="card">
            <div class="card-header">
                <h1>Match Details</h1>
            </div>
            <div class="card-body">
                <dl class="row">
                    <dt class="col-sm-3">Date</dt>
                    <dd class="col-sm-9"><?= htmlspecialchars(date('F j, Y', strtotime($match['match_date']))) ?></dd>

                    <dt class="col-sm-3">Kick-off Time</dt>
                    <dd class="col-sm-9"><?= htmlspecialchars(date('H:i', strtotime($match['kickoff_time']))) ?></dd>

                    <dt class="col-sm-3">Division</dt>
                    <dd class="col-sm-9"><?= htmlspecialchars($match['division']) ?></dd>

                    <dt class="col-sm-3">Home Team</dt>
                    <dd class="col-sm-9"><?= htmlspecialchars($match['home_club_name'] . ' - ' . $match['home_team_name']) ?></dd>

                    <dt class="col-sm-3">Away Team</dt>
                    <dd class="col-sm-9"><?= htmlspecialchars($match['away_club_name'] . ' - ' . $match['away_team_name']) ?></dd>

                    <dt class="col-sm-3">Location</dt>
                    <dd class="col-sm-9">
                        <?php if ($match['location_name'] || $match['location_address_text']): ?>
                            <strong><?= htmlspecialchars($match['location_name'] ?: 'N/A') ?></strong><br>
                            <small><?= htmlspecialchars($match['location_address_text'] ?: 'Address not available') ?></small><br>
                            <?php if ($match['location_latitude'] && $match['location_longitude']): ?>
                                <small>Lat: <?= htmlspecialchars($match['location_latitude']) ?>, Lon: <?= htmlspecialchars($match['location_longitude']) ?></small><br>
                                <a href="https://www.google.com/maps?q=<?= htmlspecialchars($match['location_latitude']) ?>,<?= htmlspecialchars($match['location_longitude']) ?>" target="_blank" class="btn btn-sm btn-info mt-1">View on Map</a>
                            <?php endif; ?>
                        <?php else: ?>
                            N/A
                        <?php endif; ?>
                    </dd>

                    <?php if (!empty($match['location_specific_notes'])): ?>
                    <dt class="col-sm-3">Location Notes</dt>
                    <dd class="col-sm-9"><?= nl2br(htmlspecialchars($match['location_specific_notes'])) ?></dd>
                    <?php endif; ?>

                    <dt class="col-sm-3">Referee</dt>
                    <dd class="col-sm-9">
                        <?php if ($match['main_ref_uuid']): ?>
                            <a href="referees/referee_detail.php?uuid=<?= htmlspecialchars($match['main_ref_uuid']) ?>">
                                <?= htmlspecialchars($match['main_ref_name']) ?>
                            </a>
                        <?php else: ?>
                            N/A
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-3">Assistant Referee 1</dt>
                    <dd class="col-sm-9">
                        <?php if ($match['ar1_uuid']): ?>
                            <a href="referees/referee_detail.php?uuid=<?= htmlspecialchars($match['ar1_uuid']) ?>">
                                <?= htmlspecialchars($match['ar1_name']) ?>
                            </a>
                        <?php else: ?>
                            N/A
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-3">Assistant Referee 2</dt>
                    <dd class="col-sm-9">
                        <?php if ($match['ar2_uuid']): ?>
                            <a href="referees/referee_detail.php?uuid=<?= htmlspecialchars($match['ar2_uuid']) ?>">
                                <?= htmlspecialchars($match['ar2_name']) ?>
                            </a>
                        <?php else: ?>
                            N/A
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>
        </div>
    </section>

    <!-- Future sections for match reports, etc., can be added here -->

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
