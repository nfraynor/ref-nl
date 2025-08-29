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

/** Utility: does a column exist? */
function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

/** Detect which location reference column (if any) exists */
$locationColumn = null;
if (columnExists($pdo, 'matches', 'location_uuid')) {
    $locationColumn = 'location_uuid';
} elseif (columnExists($pdo, 'matches', 'location_id')) {
    $locationColumn = 'location_id';
}

/** Fetch options for selects */
$teamsStmt = $pdo->query("
    SELECT t.uuid, t.team_name, c.club_name
    FROM teams t
    LEFT JOIN clubs c ON t.club_id = c.uuid
    ORDER BY c.club_name IS NULL, c.club_name ASC, t.team_name ASC
");
$allTeams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);

$locationsStmt = $pdo->query("SELECT uuid, name, address_text FROM locations ORDER BY name ASC");
$allLocations = $locationsStmt->fetchAll(PDO::FETCH_ASSOC);

$usersStmt = $pdo->query("SELECT uuid, username FROM users ORDER BY username ASC");
$allUsers   = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

/** Handle POST for core match edits (date, time, location, home/away) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_match_core'])) {
    $errors = [];

    // Incoming fields
    $newDate  = isset($_POST['match_date']) ? trim($_POST['match_date']) : '';
    $newTime  = isset($_POST['kickoff_time']) ? trim($_POST['kickoff_time']) : '';
    $newHome  = isset($_POST['home_team_uuid']) ? trim($_POST['home_team_uuid']) : '';
    $newAway  = isset($_POST['away_team_uuid']) ? trim($_POST['away_team_uuid']) : '';
    $newLoc   = isset($_POST['location_uuid']) ? trim($_POST['location_uuid']) : '';

    // Basic validation
    if ($newDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate)) {
        $errors[] = "Please provide a valid date (YYYY-MM-DD).";
    }
    if ($newTime === '' || !preg_match('/^\d{2}:\d{2}$/', $newTime)) {
        $errors[] = "Please provide a valid time (HH:MM).";
    }

    // Check teams exist (and not equal)
    if ($newHome === '' || $newAway === '') {
        $errors[] = "Please choose both a Home Team and an Away Team.";
    } elseif ($newHome === $newAway) {
        $errors[] = "Home Team and Away Team must be different.";
    } else {
        $chkTeam = $pdo->prepare("SELECT COUNT(*) FROM teams WHERE uuid = ?");
        $chkTeam->execute([$newHome]);
        if (!$chkTeam->fetchColumn()) $errors[] = "Selected Home Team is invalid.";

        $chkTeam->execute([$newAway]);
        if (!$chkTeam->fetchColumn()) $errors[] = "Selected Away Team is invalid.";
    }

    // If a location column exists, validate location uuid (can be empty for NULL)
    if ($locationColumn) {
        if ($newLoc !== '') {
            $chkLoc = $pdo->prepare("SELECT COUNT(*) FROM locations WHERE uuid = ?");
            $chkLoc->execute([$newLoc]);
            if (!$chkLoc->fetchColumn()) $errors[] = "Selected Location is invalid.";
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Build SET clause dynamically to support optional location column
            $sets = [
                "match_date = ?",
                "kickoff_time = ?",
                "home_team_id = ?",
                "away_team_id = ?",
            ];
            $params = [$newDate, $newTime, $newHome, $newAway];

            if ($locationColumn) {
                if ($newLoc === '') {
                    $sets[] = "{$locationColumn} = NULL";
                } else {
                    $sets[] = "{$locationColumn} = ?";
                    $params[] = $newLoc;
                }
            }

            $params[] = $matchUuid;

            $sql = "UPDATE matches SET " . implode(", ", $sets) . " WHERE uuid = ?";
            $upd = $pdo->prepare($sql);
            $upd->execute($params);

            $pdo->commit();

            $_SESSION['success_message'] = "Match details updated successfully.";
            header("Location: match_detail.php?uuid=" . urlencode($matchUuid));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error_message'] = "Update failed: " . htmlspecialchars($e->getMessage());
            header("Location: match_detail.php?uuid=" . urlencode($matchUuid));
            exit;
        }
    } else {
        $_SESSION['error_message'] = implode("<br>", array_map('htmlspecialchars', $errors));
        header("Location: match_detail.php?uuid=" . urlencode($matchUuid));
        exit;
    }
}

/** Backwards-compatible POST for referee assigner (unchanged) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_referee_assigner'])) {
    $newAssignerUuid = $_POST['referee_assigner_uuid'] ?? '';
    if ($newAssignerUuid === '') {
        $updateStmt = $pdo->prepare("UPDATE matches SET referee_assigner_uuid = NULL WHERE uuid = ?");
        $updateStmt->execute([$matchUuid]);
    } else {
        $userCheckStmt = $pdo->prepare("SELECT uuid FROM users WHERE uuid = ?");
        $userCheckStmt->execute([$newAssignerUuid]);
        if ($userCheckStmt->fetch()) {
            $updateStmt = $pdo->prepare("UPDATE matches SET referee_assigner_uuid = ? WHERE uuid = ?");
            $updateStmt->execute([$newAssignerUuid, $matchUuid]);
        } else {
            $_SESSION['error_message'] = "Invalid referee assigner selected.";
        }
    }
    header("Location: match_detail.php?uuid=" . urlencode($matchUuid));
    exit;
}

/** Fetch the match (with optional location join) */
$joinLocations = $locationColumn ? "LEFT JOIN locations l ON m.{$locationColumn} = l.uuid" : "";

$locationFields = $locationColumn
    ? "
        l.name AS location_name,
        l.address_text AS location_address_text,
        l.latitude AS location_latitude,
        l.longitude AS location_longitude,
        l.notes AS location_specific_notes,
        m.{$locationColumn} AS match_location_uuid
      "
    : "
        NULL AS location_name,
        NULL AS location_address_text,
        NULL AS location_latitude,
        NULL AS location_longitude,
        NULL AS location_specific_notes,
        NULL AS match_location_uuid
      ";

$sql = "
    SELECT
        m.uuid AS match_uuid,
        m.match_date,
        m.kickoff_time,
        m.division,
        m.home_team_id,
        m.away_team_id,
        ht.team_name AS home_team_name,
        at.team_name AS away_team_name,
        hcl.club_name AS home_club_name,
        acl.club_name AS away_club_name,
        {$locationFields},
        CONCAT(main_ref.first_name, ' ', main_ref.last_name) AS main_ref_name,
        main_ref.uuid AS main_ref_uuid,
        CONCAT(ar1.first_name, ' ', ar1.last_name) AS ar1_name,
        ar1.uuid AS ar1_uuid,
        CONCAT(ar2.first_name, ' ', ar2.last_name) AS ar2_name,
        ar2.uuid AS ar2_uuid,
        assigner_user.username AS referee_assigner_username,
        m.referee_assigner_uuid
    FROM matches m
    JOIN teams ht ON m.home_team_id = ht.uuid
    JOIN teams at ON m.away_team_id = at.uuid
    LEFT JOIN clubs hcl ON ht.club_id = hcl.uuid
    LEFT JOIN clubs acl ON at.club_id = acl.uuid
    {$joinLocations}
    LEFT JOIN referees main_ref ON m.referee_id = main_ref.uuid
    LEFT JOIN referees ar1 ON m.ar1_id = ar1.uuid
    LEFT JOIN referees ar2 ON m.ar2_id = ar2.uuid
    LEFT JOIN users assigner_user ON m.referee_assigner_uuid = assigner_user.uuid
    WHERE m.uuid = ?
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$matchUuid]);
$match = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$match) {
    echo "<div class='container mt-4'><p class='alert alert-danger'>Match not found.</p></div>";
    include __DIR__ . '/includes/footer.php';
    exit;
}

/** Flash messages */
if (isset($_SESSION['error_message'])) {
    echo "<div class='container mt-4'><p class='alert alert-danger'>".$_SESSION['error_message']."</p></div>";
    unset($_SESSION['error_message']);
}
if (isset($_SESSION['success_message'])) {
    echo "<div class='container mt-4'><p class='alert alert-success'>".$_SESSION['success_message']."</p></div>";
    unset($_SESSION['success_message']);
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<div class="container mt-4">
    <section class="mb-4">
        <div class="card">
            <div class="card-header">
                <h1>Match Details</h1>
            </div>
            <div class="card-body">
                <!-- Edit form for Date, Time, Location, Home/Away -->
                <form method="POST" action="match_detail.php?uuid=<?= h($matchUuid) ?>" class="mb-4">
                    <input type="hidden" name="save_match_core" value="1">

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Date</label>
                            <input type="date" name="match_date" class="form-control"
                                   value="<?= h($match['match_date']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Kick-off Time</label>
                            <input type="time" name="kickoff_time" class="form-control"
                                   value="<?= h(substr($match['kickoff_time'], 0, 5)) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Location</label>
                            <?php if ($locationColumn): ?>
                                <select name="location_uuid" class="form-select">
                                    <option value="">-- None / Not set --</option>
                                    <?php foreach ($allLocations as $loc): ?>
                                        <option value="<?= h($loc['uuid']) ?>"
                                            <?= ($match['match_location_uuid'] ?? '') === $loc['uuid'] ? 'selected' : '' ?>>
                                            <?= h($loc['name'] . ($loc['address_text'] ? ' - ' . $loc['address_text'] : '')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <div class="alert alert-warning mb-0">
                                    No location reference column on <code>matches</code>. (Editing disabled)
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Home Team</label>
                            <select name="home_team_uuid" class="form-select" required>
                                <option value="">-- Select Home Team --</option>
                                <?php foreach ($allTeams as $t): ?>
                                    <option value="<?= h($t['uuid']) ?>"
                                        <?= $match['home_team_id'] === $t['uuid'] ? 'selected' : '' ?>>
                                        <?= h(($t['club_name'] ? "{$t['club_name']} - " : "") . $t['team_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Away Team</label>
                            <select name="away_team_uuid" class="form-select" required>
                                <option value="">-- Select Away Team --</option>
                                <?php foreach ($allTeams as $t): ?>
                                    <option value="<?= h($t['uuid']) ?>"
                                        <?= $match['away_team_id'] === $t['uuid'] ? 'selected' : '' ?>>
                                        <?= h(($t['club_name'] ? "{$t['club_name']} - " : "") . $t['team_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mt-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <a href="matches.php" class="btn btn-outline-secondary">Back to Matches</a>
                    </div>
                </form>

                <!-- Read-only details -->
                <dl class="row">
                    <dt class="col-sm-3">Division</dt>
                    <dd class="col-sm-9"><?= h($match['division']) ?></dd>

                    <?php if (!empty($match['location_specific_notes'])): ?>
                        <dt class="col-sm-3">Location Notes</dt>
                        <dd class="col-sm-9"><?= nl2br(h($match['location_specific_notes'])) ?></dd>
                    <?php endif; ?>

                    <dt class="col-sm-3">Current Location</dt>
                    <dd class="col-sm-9">
                        <?php if ($match['location_name'] || $match['location_address_text']): ?>
                            <strong><?= h($match['location_name'] ?: 'N/A') ?></strong><br>
                            <small><?= h($match['location_address_text'] ?: 'Address not available') ?></small><br>
                            <?php if ($match['location_latitude'] && $match['location_longitude']): ?>
                                <small>Lat: <?= h($match['location_latitude']) ?>, Lon: <?= h($match['location_longitude']) ?></small><br>
                                <a href="https://www.google.com/maps?q=<?= h($match['location_latitude']) ?>,<?= h($match['location_longitude']) ?>"
                                   target="_blank" class="btn btn-sm btn-info mt-1">View on Map</a>
                            <?php endif; ?>
                        <?php else: ?>
                            N/A
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-3">Referee</dt>
                    <dd class="col-sm-9">
                        <?php if ($match['main_ref_uuid']): ?>
                            <a href="referees/referee_detail.php?uuid=<?= h($match['main_ref_uuid']) ?>">
                                <?= h($match['main_ref_name']) ?>
                            </a>
                        <?php else: ?>
                            N/A
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-3">Assistant Referee 1</dt>
                    <dd class="col-sm-9">
                        <?php if ($match['ar1_uuid']): ?>
                            <a href="referees/referee_detail.php?uuid=<?= h($match['ar1_uuid']) ?>">
                                <?= h($match['ar1_name']) ?>
                            </a>
                        <?php else: ?>
                            N/A
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-3">Assistant Referee 2</dt>
                    <dd class="col-sm-9">
                        <?php if ($match['ar2_uuid']): ?>
                            <a href="referees/referee_detail.php?uuid=<?= h($match['ar2_uuid']) ?>">
                                <?= h($match['ar2_name']) ?>
                            </a>
                        <?php else: ?>
                            N/A
                        <?php endif; ?>
                    </dd>
                </dl>

                <!-- Referee Assigner editor (unchanged) -->
                <div class="mt-4">
                    <h5>Referee Assigner</h5>
                    <form method="POST" action="match_detail.php?uuid=<?= h($matchUuid) ?>" class="mb-3">
                        <div class="input-group">
                            <select name="referee_assigner_uuid" class="form-select">
                                <option value="">-- Select Referee Assigner --</option>
                                <?php foreach ($allUsers as $user): ?>
                                    <option value="<?= h($user['uuid']) ?>"
                                        <?= ($match['referee_assigner_uuid'] ?? '') === $user['uuid'] ? 'selected' : '' ?>>
                                        <?= h($user['username']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="update_referee_assigner" class="btn btn-primary">Update Assigner</button>
                        </div>
                    </form>
                    Current: <?= h($match['referee_assigner_username'] ?? 'N/A') ?>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
