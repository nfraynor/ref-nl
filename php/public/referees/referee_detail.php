<?php
require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';
include '../includes/header.php';
include '../includes/nav.php';

$pdo = Database::getConnection();
$refereeId = $_GET['id'] ?? null;

if (!$refereeId) {
    echo "<p>Referee ID is missing.</p>";
    exit;
}

// Fetch referee info
$stmt = $pdo->prepare("
    SELECT r.*, c.club_name, d.name as district_name
    FROM referees r 
    LEFT JOIN clubs c ON r.home_club_id = c.uuid 
    LEFT JOIN districts d ON r.district_id = d.id
    WHERE r.referee_id = ?
");
$stmt->execute([$refereeId]);
$referee = $stmt->fetch();

if (!$referee) {
    echo "<p>Referee not found.</p>";
    exit;
}

// Fetch all clubs for the exempt clubs dropdown
$allClubsStmt = $pdo->query("SELECT uuid, club_name FROM clubs ORDER BY club_name ASC");
$allClubs = $allClubsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch current exempt clubs for the referee
$exemptClubsStmt = $pdo->prepare("
    SELECT c.uuid, c.club_name 
    FROM referee_exempt_clubs rec
    JOIN clubs c ON rec.club_uuid = c.uuid
    WHERE rec.referee_uuid = ?
    ORDER BY c.club_name ASC
");
$exemptClubsStmt->execute([$referee['uuid']]);
$exemptedClubs = $exemptClubsStmt->fetchAll(PDO::FETCH_ASSOC);
$exemptedClubUuids = array_column($exemptedClubs, 'uuid');


// Fetch unavailability
$unavailability = $pdo->prepare("
    SELECT * FROM referee_unavailability 
    WHERE referee_id = ? 
    ORDER BY start_date DESC
");
$unavailability->execute([$referee['uuid']]);
$unavailabilityList = $unavailability->fetchAll();

// Get current date from DB for logging
$currentDateStmt = $pdo->query("SELECT CURDATE() AS `current_date`");
$currentDate = $currentDateStmt->fetch(PDO::FETCH_ASSOC)['current_date'];
error_log("Current date in DB: " . $currentDate);

// Fetch assigned matches
$assignedMatches = []; // Initialize as empty array
if ($referee && isset($referee['uuid'])) {
    $currentRefereeUuid = $referee['uuid'];
    // Note: $currentRefereeUuid is defined here and used by both queries if this block executes.
    error_log("ASSIGNED_MATCHES: Current referee UUID: " . $currentRefereeUuid);
    $sqlAssignedMatches = "
        SELECT
            m.uuid AS match_uuid,
            m.match_date,
            m.kickoff_time,
            ht.team_name AS home_team_name,
            at.team_name AS away_team_name,
            (SELECT c.club_name FROM clubs c WHERE c.uuid = ht.club_id) AS home_club_name,
            (SELECT c.club_name FROM clubs c WHERE c.uuid = at.club_id) AS away_club_name,
            CONCAT(main_ref.first_name, ' ', main_ref.last_name) AS main_ref_name,
            CONCAT(ar1_ref.first_name, ' ', ar1_ref.last_name) AS ar1_ref_name,
            CONCAT(ar2_ref.first_name, ' ', ar2_ref.last_name) AS ar2_ref_name,
            CONCAT(comm_ref.first_name, ' ', comm_ref.last_name) AS comm_ref_name,
            rtl.distance_km AS travel_distance_for_current_referee
        FROM
            matches m
        JOIN
            teams ht ON m.home_team_id = ht.uuid
        JOIN
            teams at ON m.away_team_id = at.uuid
        LEFT JOIN
            referees main_ref ON m.referee_id = main_ref.uuid
        LEFT JOIN
            referees ar1_ref ON m.ar1_id = ar1_ref.uuid
        LEFT JOIN
            referees ar2_ref ON m.ar2_id = ar2_ref.uuid
        LEFT JOIN
            referees comm_ref ON m.commissioner_id = comm_ref.uuid
        LEFT JOIN
            referee_travel_log rtl ON m.uuid = rtl.match_id AND rtl.referee_id = ?
        WHERE
            (m.referee_id = ? OR
             m.ar1_id = ? OR
             m.ar2_id = ? OR
             m.commissioner_id = ?) AND
            m.match_date >= CURDATE()
        ORDER BY
            m.match_date ASC, m.kickoff_time ASC;
    ";
    try {
        error_log("ASSIGNED_MATCHES: SQL: " . $sqlAssignedMatches);
        $stmtAssignedMatches = $pdo->prepare($sqlAssignedMatches);
        $stmtAssignedMatches->execute([$currentRefereeUuid, $currentRefereeUuid, $currentRefereeUuid, $currentRefereeUuid, $currentRefereeUuid]);
        $assignedMatches = $stmtAssignedMatches->fetchAll(PDO::FETCH_ASSOC);
        error_log("ASSIGNED_MATCHES: Number of matches: " . count($assignedMatches));
        error_log("ASSIGNED_MATCHES: Data: " . print_r($assignedMatches, true));
        if (!empty($assignedMatches)) {
            error_log("ASSIGNED_MATCHES: Sample match date: " . $assignedMatches[0]['match_date']);
        }
    } catch (PDOException $e) {
        // Handle or log the error appropriately
        error_log("Error fetching assigned matches: " . $e->getMessage());
        // Optionally, set a flag or message to display to the user
        // For now, $assignedMatches will remain an empty array if an error occurs.
    }
}

// Fetch Previous Matches
$previousMatches = []; // Initialize
if ($referee && isset($referee['uuid'])) { // Ensure $currentRefereeUuid is available
    // $currentRefereeUuid is already defined from fetching assigned matches
    try {
        error_log("PREVIOUS_MATCHES: Current referee UUID: " . $currentRefereeUuid);
        $sqlPreviousMatches = "
            SELECT
                m.uuid AS match_uuid,
                m.match_date,
                m.kickoff_time,
                ht.team_name AS home_team_name,
                at.team_name AS away_team_name,
                (SELECT c.club_name FROM clubs c WHERE c.uuid = ht.club_id) AS home_club_name,
                (SELECT c.club_name FROM clubs c WHERE c.uuid = at.club_id) AS away_club_name,
                CONCAT(main_ref.first_name, ' ', main_ref.last_name) AS main_ref_name,
                CONCAT(ar1_ref.first_name, ' ', ar1_ref.last_name) AS ar1_ref_name,
                CONCAT(ar2_ref.first_name, ' ', ar2_ref.last_name) AS ar2_ref_name,
                CONCAT(comm_ref.first_name, ' ', comm_ref.last_name) AS comm_ref_name,
                rtl.distance_km AS travel_distance_for_current_referee
            FROM
                matches m
            JOIN
                teams ht ON m.home_team_id = ht.uuid
            JOIN
                teams at ON m.away_team_id = at.uuid
            LEFT JOIN
                referees main_ref ON m.referee_id = main_ref.uuid
            LEFT JOIN
                referees ar1_ref ON m.ar1_id = ar1_ref.uuid
            LEFT JOIN
                referees ar2_ref ON m.ar2_id = ar2_ref.uuid
            LEFT JOIN
                referees comm_ref ON m.commissioner_id = comm_ref.uuid
            LEFT JOIN
                referee_travel_log rtl ON m.uuid = rtl.match_id AND rtl.referee_id = ?
            WHERE
                (m.referee_id = ? OR
                 m.ar1_id = ? OR
                 m.ar2_id = ? OR
                 m.commissioner_id = ?) AND
                m.match_date < CURDATE()
            ORDER BY
                m.match_date DESC, m.kickoff_time DESC;
        ";
        error_log("PREVIOUS_MATCHES: SQL: " . $sqlPreviousMatches);
        $stmtPreviousMatches = $pdo->prepare($sqlPreviousMatches);
        $stmtPreviousMatches->execute([$currentRefereeUuid, $currentRefereeUuid, $currentRefereeUuid, $currentRefereeUuid, $currentRefereeUuid]);
        $previousMatches = $stmtPreviousMatches->fetchAll(PDO::FETCH_ASSOC);
        error_log("PREVIOUS_MATCHES: Number of matches: " . count($previousMatches));
        error_log("PREVIOUS_MATCHES: Data: " . print_r($previousMatches, true));
        if (!empty($previousMatches)) {
            error_log("PREVIOUS_MATCHES: Sample match date: " . $previousMatches[0]['match_date']);
        }
    } catch (PDOException $e) {
        error_log("Error fetching previous matches: " . $e->getMessage());
        // $previousMatches will remain an empty array
    }
}
?>

    <div class="container mt-4">
        <section class="mb-4">
            <div class="card">
                <div class="card-header">
                    <h1>Referee Details:
                        <span data-field="first_name" data-original-value="<?= htmlspecialchars($referee['first_name']) ?>"><?= htmlspecialchars($referee['first_name']) ?></span>
                        <span data-field="last_name" data-original-value="<?= htmlspecialchars($referee['last_name']) ?>"><?= htmlspecialchars($referee['last_name']) ?></span>
                    </h1>
                </div>
                <div class="card-body">
                    <div id="referee-details-messages" class="mb-3"></div> <!-- For success/error messages -->
                    <dl class="row">
                        <dt class="col-sm-3">Referee ID</dt>
                        <dd class="col-sm-9"><?= htmlspecialchars($referee['referee_id']) ?></dd>

                        <dt class="col-sm-3">First Name</dt>
                        <dd class="col-sm-9 editable-field">
                            <span class="display-value" data-field="first_name"><?= htmlspecialchars($referee['first_name'] ?? '') ?></span>
                            <i class="bi bi-pencil-square edit-icon" data-field="first_name" style="cursor:pointer; margin-left: 5px;"></i>
                        </dd>

                        <dt class="col-sm-3">Last Name</dt>
                        <dd class="col-sm-9 editable-field">
                            <span class="display-value" data-field="last_name"><?= htmlspecialchars($referee['last_name'] ?? '') ?></span>
                            <i class="bi bi-pencil-square edit-icon" data-field="last_name" style="cursor:pointer; margin-left: 5px;"></i>
                        </dd>

                        <dt class="col-sm-3">Email</dt>
                        <dd class="col-sm-9 editable-field">
                            <span class="display-value" data-field="email"><?= htmlspecialchars($referee['email'] ?? '') ?></span>
                            <i class="bi bi-pencil-square edit-icon" data-field="email" style="cursor:pointer; margin-left: 5px;"></i>
                        </dd>

                        <dt class="col-sm-3">Phone</dt>
                        <dd class="col-sm-9 editable-field">
                            <span class="display-value" data-field="phone"><?= htmlspecialchars($referee['phone'] ?? '') ?></span>
                            <i class="bi bi-pencil-square edit-icon" data-field="phone" style="cursor:pointer; margin-left: 5px;"></i>
                        </dd>

                        <dt class="col-sm-3">Club</dt>
                        <dd class="col-sm-9 editable-field" data-current-club-id="<?= htmlspecialchars($referee['home_club_id'] ?? '') ?>">
                            <span class="display-value" data-field="home_club_id"><?= htmlspecialchars($referee['club_name'] ?? '') ?></span>
                            <i class="bi bi-pencil-square edit-icon" data-field="home_club_id" style="cursor:pointer; margin-left: 5px;"></i>
                        </dd>

                        <dt class="col-sm-3">City</dt>
                        <dd class="col-sm-9 editable-field">
                            <span class="display-value" data-field="home_location_city"><?= htmlspecialchars($referee['home_location_city'] ?? '') ?></span>
                            <i class="bi bi-pencil-square edit-icon" data-field="home_location_city" style="cursor:pointer; margin-left: 5px;"></i>
                        </dd>

                        <dt class="col-sm-3">District</dt>
                        <dd class="col-sm-9 editable-field" data-current-district-id="<?= htmlspecialchars($referee['district_id'] ?? '') ?>">
                            <span class="display-value" data-field="district_id"><?= htmlspecialchars($referee['district_name'] ?? 'N/A') ?></span>
                            <i class="bi bi-pencil-square edit-icon" data-field="district_id" style="cursor:pointer; margin-left: 5px;"></i>
                        </dd>

                        <dt class="col-sm-3">Grade</dt>
                        <dd class="col-sm-9 editable-field">
                            <span class="display-value" data-field="grade"><?= htmlspecialchars($referee['grade'] ?? '') ?></span>
                            <i class="bi bi-pencil-square edit-icon" data-field="grade" style="cursor:pointer; margin-left: 5px;"></i>
                        </dd>

                        <dt class="col-sm-3">AR Grade</dt>
                        <dd class="col-sm-9 editable-field">
                            <span class="display-value" data-field="ar_grade"><?= htmlspecialchars($referee['ar_grade'] ?? '') ?></span>
                            <i class="bi bi-pencil-square edit-icon" data-field="ar_grade" style="cursor:pointer; margin-left: 5px;"></i>
                        </dd>
                        <!-- Max Matches Per Weekend -->
                        <dt class="col-sm-3">Max Matches Per Weekend</dt>
                        <dd class="col-sm-9">
                            <select id="max_matches_per_weekend" class="form-select form-select-sm">
                                <?php
                                $currentMaxMatches = (int)($referee['max_matches_per_weekend'] ?? 1);
                                ?>
                                <option value="1" <?= $currentMaxMatches === 1 ? 'selected' : '' ?>>1</option>
                                <option value="3" <?= $currentMaxMatches === 3 ? 'selected' : '' ?>>Multiple (Max 3)</option>
                            </select>
                        </dd>

                        <!-- Max Days Per Weekend -->
                        <dt class="col-sm-3">Max Days Per Weekend</dt>
                        <dd class="col-sm-9">
                            <select id="max_days_per_weekend" class="form-select form-select-sm">
                                <?php
                                $currentMaxDays = (int)($referee['max_days_per_weekend'] ?? 1); // 1=one day, 2=both
                                ?>
                                <option value="1" <?= $currentMaxDays === 1 ? 'selected' : '' ?>>1</option>
                                <option value="2" <?= $currentMaxDays === 2 ? 'selected' : '' ?>>Both</option>
                            </select>
                        </dd>

                        <dt class="col-sm-3">Max Travel Distance (km)</dt>
                        <dd class="col-sm-9 editable-field">
                            <span class="display-value" data-field="max_travel_distance"><?= htmlspecialchars($referee['max_travel_distance'] ?? '') ?></span>
                            <i class="bi bi-pencil-square edit-icon" data-field="max_travel_distance" style="cursor:pointer; margin-left: 5px;"></i>
                        </dd>
                        <dt class="col-sm-3">Home Latitude</dt>
                        <dd class="col-sm-9 editable-field">
                            <span class="display-value" data-field="home_lat"><?= htmlspecialchars($referee['home_lat'] ?? 'N/A') ?></span>
                            <i class="bi bi-pencil-square edit-icon" data-field="home_lat" style="cursor:pointer; margin-left: 5px;"></i>
                        </dd>

                        <dt class="col-sm-3">Home Longitude</dt>
                        <dd class="col-sm-9 editable-field">
                            <span class="display-value" data-field="home_lon"><?= htmlspecialchars($referee['home_lon'] ?? 'N/A') ?></span>
                            <i class="bi bi-pencil-square edit-icon" data-field="home_lon" style="cursor:pointer; margin-left: 5px;"></i>
                        </dd>

                        <dt class="col-sm-3">Exempt Clubs</dt>
                        <dd class="col-sm-9 editable-field" id="exempt-clubs-dd">
                        <span class="display-value" data-field="exempt_clubs">
                            <?php
                            if (!empty($exemptedClubs)) {
                                echo htmlspecialchars(implode(', ', array_column($exemptedClubs, 'club_name')));
                            } else {
                                echo 'None';
                            }
                            ?>
                        </span>
                            <i class="bi bi-pencil-square edit-icon" data-field="exempt_clubs" style="cursor:pointer; margin-left: 5px;"></i>
                        </dd>
                    </dl>
                </div>
            </div>
        </section>
        <script>
            // Store referee UUID for JS
            const refereeUUID = "<?= htmlspecialchars($referee['uuid']) ?>";
            const allClubsForJS = <?= json_encode($allClubs) ?>;
            let exemptedClubUuidsForJS = <?= json_encode($exemptedClubUuids) ?>;
            (function () {
                const postField = (fieldName, value) => {
                    return fetch('update_referee_field.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: `referee_uuid=${encodeURIComponent(refereeUUID)}&field_name=${encodeURIComponent(fieldName)}&field_value=${encodeURIComponent(value)}`
                    }).then(res => res.json());
                };

                const hookSelect = (id, fieldName) => {
                    const el = document.getElementById(id);
                    if (!el) return;
                    el.addEventListener('change', () => {
                        const val = el.value;
                        postField(fieldName, val)
                            .then(data => {
                                const messagesDiv = document.getElementById('referee-details-messages');
                                if (data.status === 'success') {
                                    if (messagesDiv) {
                                        messagesDiv.innerHTML = '<div class="alert alert-success">Saved.</div>';
                                        setTimeout(() => { if (messagesDiv.querySelector('.alert')) messagesDiv.querySelector('.alert').remove(); }, 3000);
                                    }
                                } else {
                                    if (messagesDiv) {
                                        messagesDiv.innerHTML = `<div class="alert alert-danger">${data.message || 'Update failed.'}</div>`;
                                        setTimeout(() => { if (messagesDiv.querySelector('.alert')) messagesDiv.querySelector('.alert').remove(); }, 5000);
                                    }
                                }
                            })
                            .catch(err => {
                                console.error(err);
                                const messagesDiv = document.getElementById('referee-details-messages');
                                if (messagesDiv) {
                                    messagesDiv.innerHTML = '<div class="alert alert-danger">An unexpected error occurred.</div>';
                                    setTimeout(() => { if (messagesDiv.querySelector('.alert')) messagesDiv.querySelector('.alert').remove(); }, 5000);
                                }
                            });
                    });
                };

                hookSelect('max_matches_per_weekend', 'max_matches_per_weekend');
                hookSelect('max_days_per_weekend', 'max_days_per_weekend');
            })();
        </script>
        <section class="mb-4">
            <div class="card">
                <div class="card-header">
                    <h2>Weekly Availability</h2>
                </div>
                <div class="card-body">
                    <form method="post" action="update_weekly_availability.php">
                        <input type="hidden" name="referee_id" value="<?= htmlspecialchars($referee['uuid']) ?>">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                <tr>
                                    <th>Day</th>
                                    <th>Morning</th>
                                    <th>Afternoon</th>
                                    <th>Evening</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php
                                $days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

                                // Fetch current weekly availability
                                $stmt = $pdo->prepare("SELECT * FROM referee_weekly_availability WHERE referee_id = ?");
                                $stmt->execute([$referee['uuid']]);
                                $weeklyData = [];
                                foreach ($stmt->fetchAll() as $row) {
                                    $weeklyData[$row['weekday']] = $row;
                                }

                                for ($i = 0; $i < 7; $i++):
                                    $row = $weeklyData[$i] ?? ['morning_available' => false, 'afternoon_available' => false, 'evening_available' => false];
                                    ?>
                                    <tr>
                                        <td><?= $days[$i] ?></td>
                                        <td><input type="checkbox" class="form-check-input" name="availability[<?= $i ?>][morning]" <?= $row['morning_available'] ? 'checked' : '' ?>></td>
                                        <td><input type="checkbox" class="form-check-input" name="availability[<?= $i ?>][afternoon]" <?= $row['afternoon_available'] ? 'checked' : '' ?>></td>
                                        <td><input type="checkbox" class="form-check-input" name="availability[<?= $i ?>][evening]" <?= $row['evening_available'] ? 'checked' : '' ?>></td>
                                    </tr>
                                <?php endfor; ?>
                                </tbody>
                            </table>
                        </div>
                        <button type="submit" class="btn btn-primary mt-3">Save Weekly Availability</button>
                    </form>
                </div>
            </div>
        </section>
        <section class="mb-4">
            <div class="card">
                <div class="card-header">
                    <h2>Unavailability</h2>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped">
                            <thead>
                            <tr><th>From</th><th>To</th><th>Reason</th><th>Actions</th></tr>
                            </thead>
                            <tbody id="unavailabilityListBody">
                            <?php foreach ($unavailabilityList as $ua): ?>
                                <tr>
                                    <td><?= htmlspecialchars($ua['start_date']) ?></td>
                                    <td><?= htmlspecialchars($ua['end_date']) ?></td>
                                    <td><?= nl2br(htmlspecialchars($ua['reason'])) ?></td>
                                    <td>
                                        <button class="btn btn-danger btn-sm remove-unavailability-btn" data-unavailability-uuid="<?= htmlspecialchars($ua['uuid']) ?>">Remove</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
        <section class="mb-4">
            <div class="card">
                <div class="card-header">
                    <h2>Add Unavailability</h2>
                </div>
                <div class="card-body">
                    <div id="formFeedback" class="mt-3"></div>
                    <form id="addUnavailabilityForm" method="post" action="add_unavailability.php">
                        <input type="hidden" name="referee_id" value="<?= htmlspecialchars($referee['uuid']) ?>">
                        <div class="row">
                            <div class="col-md-6 form-group mb-3">
                                <label for="unavailability_start_date">Start Date:</label>
                                <input type="text" class="form-control" id="unavailability_start_date" name="start_date" required>
                            </div>
                            <div class="col-md-6 form-group mb-3">
                                <label for="unavailability_end_date">End Date:</label>
                                <input type="text" class="form-control" id="unavailability_end_date" name="end_date" required>
                            </div>
                        </div>
                        <div class="form-group mb-3">
                            <label for="reason">Reason:</label>
                            <textarea class="form-control" id="reason" name="reason" rows="4"></textarea>
                        </div>
                        <button type="submit" class="btn btn-success">Add Unavailability</button>
                    </form>
                </div>
            </div>
        </section>

        <section class="mb-4">
            <div class="card">
                <div class="card-header">
                    <h3>Assigned Matches <span class="badge bg-success"><?= !empty($assignedMatches) ? 'Populated (' . count($assignedMatches) . ' matches)' : 'Not Populated' ?></span></h3>
                </div>
                <div class="card-body">
                    <?php if (empty($assignedMatches)): ?>
                        <p>This referee has no upcoming assigned matches.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered">
                                <thead>
                                <tr>
                                    <th>Match Date</th>
                                    <th>Kick-off</th>
                                    <th>Home Team</th>
                                    <th>Away Team</th>
                                    <th>Your Role</th>
                                    <th>Your Travel (km)</th>
                                    <th>Main Referee</th>
                                    <th>AR1</th>
                                    <th>AR2</th>
                                    <th>Commissioner</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($assignedMatches as $match): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($match['match_date'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars(substr($match['kickoff_time'], 0, 5) ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars(($match['home_club_name'] ? $match['home_club_name'] . ' - ' : '') . ($match['home_team_name'] ?? 'N/A')) ?></td>
                                        <td><?= htmlspecialchars(($match['away_club_name'] ? $match['away_club_name'] . ' - ' : '') . ($match['away_team_name'] ?? 'N/A')) ?></td>
                                        <td>
                                            <?php
                                            $currentRefereeFullName = $referee['first_name'] . ' ' . $referee['last_name'];
                                            $role = 'N/A';
                                            if (isset($match['main_ref_name']) && $match['main_ref_name'] == $currentRefereeFullName) {
                                                $role = 'Referee';
                                            } elseif (isset($match['ar1_ref_name']) && $match['ar1_ref_name'] == $currentRefereeFullName) {
                                                $role = 'AR1';
                                            } elseif (isset($match['ar2_ref_name']) && $match['ar2_ref_name'] == $currentRefereeFullName) {
                                                $role = 'AR2';
                                            } elseif (isset($match['comm_ref_name']) && $match['comm_ref_name'] == $currentRefereeFullName) {
                                                $role = 'Commissioner';
                                            }
                                            echo htmlspecialchars($role);
                                            ?>
                                        </td>
                                        <td><?= htmlspecialchars($match['travel_distance_for_current_referee'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($match['main_ref_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($match['ar1_ref_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($match['ar2_ref_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($match['comm_ref_name'] ?? 'N/A') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="mb-4">
            <div class="card">
                <div class="card-header">
                    <h3>Previous Matches <span class="badge bg-success"><?= !empty($previousMatches) ? 'Populated (' . count($previousMatches) . ' matches)' : 'Not Populated' ?></span></h3>
                </div>
                <div class="card-body">
                    <?php if (empty($previousMatches)): ?>
                        <p>No previous matches found for this referee.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered">
                                <thead>
                                <tr>
                                    <th>Match Date</th>
                                    <th>Kick-off</th>
                                    <th>Home Team</th>
                                    <th>Away Team</th>
                                    <th>Your Role</th>
                                    <th>Your Travel (km)</th>
                                    <th>Main Referee</th>
                                    <th>AR1</th>
                                    <th>AR2</th>
                                    <th>Commissioner</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($previousMatches as $match): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($match['match_date'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars(isset($match['kickoff_time']) ? substr($match['kickoff_time'], 0, 5) : 'N/A') ?></td>
                                        <td><?= htmlspecialchars(($match['home_club_name'] ? $match['home_club_name'] . ' - ' : '') . ($match['home_team_name'] ?? 'N/A')) ?></td>
                                        <td><?= htmlspecialchars(($match['away_club_name'] ? $match['away_club_name'] . ' - ' : '') . ($match['away_team_name'] ?? 'N/A')) ?></td>
                                        <td>
                                            <?php
                                            $currentRefereeFullName = $referee['first_name'] . ' ' . $referee['last_name'];
                                            $role = 'N/A';
                                            if (isset($match['main_ref_name']) && $match['main_ref_name'] == $currentRefereeFullName) {
                                                $role = 'Referee';
                                            } elseif (isset($match['ar1_ref_name']) && $match['ar1_ref_name'] == $currentRefereeFullName) {
                                                $role = 'AR1';
                                            } elseif (isset($match['ar2_ref_name']) && $match['ar2_ref_name'] == $currentRefereeFullName) {
                                                $role = 'AR2';
                                            } elseif (isset($match['comm_ref_name']) && $match['comm_ref_name'] == $currentRefereeFullName) {
                                                $role = 'Commissioner';
                                            }
                                            echo htmlspecialchars($role);
                                            ?>
                                        </td>
                                        <td><?= htmlspecialchars($match['travel_distance_for_current_referee'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($match['main_ref_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($match['ar1_ref_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($match['ar2_ref_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($match['comm_ref_name'] ?? 'N/A') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            flatpickr("#unavailability_start_date", {
                dateFormat: "Y-m-d",
                altInput: true,
                altFormat: "F j, Y",
            });
            flatpickr("#unavailability_start_date", { // Ensure this ID is unique if you reuse flatpickr elsewhere
                dateFormat: "Y-m-d",
                altInput: true,
                altFormat: "F j, Y",
            });
            flatpickr("#unavailability_end_date", { // Ensure this ID is unique
                dateFormat: "Y-m-d",
                altInput: true,
                altFormat: "F j, Y",
            });

            const addUnavailabilityForm = document.getElementById('addUnavailabilityForm');
            const unavailabilityListBody = document.getElementById('unavailabilityListBody');
            const formFeedback = document.getElementById('formFeedback');
            // Get Flatpickr instances to clear them later
            const startDatePicker = document.querySelector("#unavailability_start_date")._flatpickr;
            const endDatePicker = document.querySelector("#unavailability_end_date")._flatpickr;


            if (addUnavailabilityForm) {
                addUnavailabilityForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    formFeedback.innerHTML = ''; // Clear previous feedback

                    const formData = new FormData(addUnavailabilityForm);

                    fetch('add_unavailability.php', {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'  // Keep this if not already added from previous fixes
                        },
                        body: formData
                    })
                        .then(response => {
                            if (!response.ok) {
                                throw new Error(`HTTP error! status: ${response.status}`);
                            }
                            return response.json();
                        })
                        .then(data => {
                            if (data.status === 'success') {
                                // Clear form fields
                                addUnavailabilityForm.reset();
                                // Clear Flatpickr fields
                                if (startDatePicker) startDatePicker.clear();
                                if (endDatePicker) endDatePicker.clear();

                                // Add new row to the table
                                const newRow = unavailabilityListBody.insertRow(0); // Insert at the top like current list
                                const cell1 = newRow.insertCell(0);
                                const cell2 = newRow.insertCell(1);
                                const cell3 = newRow.insertCell(2);
                                const cell4 = newRow.insertCell(3);  // Add this for Actions

                                cell1.textContent = data.data.start_date;
                                cell2.textContent = data.data.end_date;
                                // For reason, handle potential null and nl2br equivalent
                                let reasonText = data.data.reason || "";
                                cell3.innerHTML = reasonText.replace(/\r\n|\r|\n/g, '<br>');

                                // Add the Remove button using the returned UUID
                                cell4.innerHTML = `<button class="btn btn-danger btn-sm remove-unavailability-btn" data-unavailability-uuid="${data.data.uuid}">Remove</button>`;

                                formFeedback.innerHTML = '<div class="alert alert-success">Unavailability added successfully.</div>';
                            } else {
                                formFeedback.innerHTML = `<div class="alert alert-danger">Error: ${data.message || 'Could not add unavailability.'}</div>`;
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            formFeedback.innerHTML = '<div class="alert alert-danger">An unexpected error occurred. Please try again.</div>';
                        });
                });
            }

            // Event listener for removing unavailability
            if (unavailabilityListBody) {
                unavailabilityListBody.addEventListener('click', function(event) {
                    if (event.target.classList.contains('remove-unavailability-btn')) {
                        event.preventDefault(); // Prevent any default button action
                        formFeedback.innerHTML = ''; // Clear previous feedback

                        const button = event.target;
                        const unavailabilityUuid = button.dataset.unavailabilityUuid;

                        if (confirm("Are you sure you want to remove this unavailability period?")) {
                            const formData = new FormData();
                            formData.append('unavailability_uuid', unavailabilityUuid);

                            fetch('remove_unavailability.php', {
                                method: 'POST',
                                body: formData
                                // Headers are not strictly necessary for FormData with fetch,
                                // but good practice for other types or if server requires it.
                                // headers: { 'Content-Type': 'application/x-www-form-urlencoded' } // Example if not using FormData
                            })
                                .then(response => {
                                    // Try to parse JSON regardless of response.ok, as server might send error details in JSON
                                    return response.json().then(data => ({ ok: response.ok, status: response.status, data }));
                                })
                                .then(result => {
                                    if (result.ok && result.data.status === 'success') {
                                        button.closest('tr').remove();
                                        formFeedback.innerHTML = `<div class="alert alert-success">${result.data.message || 'Unavailability removed successfully.'}</div>`;
                                    } else {
                                        // Handle HTTP errors (like 404, 500) or application errors ({status: 'error'})
                                        let message = result.data.message || `Error ${result.status}: Could not remove unavailability.`;
                                        if (result.status === 405) message = "Error: Invalid request method."; // From remove_unavailability.php
                                        if (result.status === 400) message = "Error: Missing Unavailability UUID."; // From remove_unavailability.php
                                        formFeedback.innerHTML = `<div class="alert alert-danger">${message}</div>`;
                                    }
                                })
                                .catch(error => {
                                    console.error('Error:', error);
                                    // Network error or JSON parsing error from response.json()
                                    formFeedback.innerHTML = '<div class="alert alert-danger">An unexpected error occurred while trying to remove unavailability. Please check console.</div>';
                                });
                        }
                    }
                });
            }

            // --- Logic for Exempt Clubs Edit ---
            const exemptClubsDd = document.getElementById('exempt-clubs-dd');
            if (exemptClubsDd) {
                const editIconExemptClubs = exemptClubsDd.querySelector('.edit-icon[data-field="exempt_clubs"]');
                const displaySpanExemptClubs = exemptClubsDd.querySelector('.display-value[data-field="exempt_clubs"]');

                if (editIconExemptClubs && displaySpanExemptClubs) {
                    editIconExemptClubs.addEventListener('click', function(event) { // Added event parameter
                        event.stopPropagation(); // Stop event from bubbling to other listeners

                        // Hide display span and edit icon
                        displaySpanExemptClubs.style.display = 'none';
                        this.style.display = 'none';

                        // Explicitly remove any generic edit controls that might have been added by inline_edit_referee.js
                        const parentDdElement = this.closest('.editable-field'); // 'this' is the clicked edit icon
                        if (parentDdElement) {
                            const existingGenericControls = parentDdElement.querySelector('.edit-controls');
                            if (existingGenericControls) {
                                existingGenericControls.remove();
                            }
                        }

                        // Remove any existing custom controls for exempt clubs (good practice before rebuilding)
                        const existingControls = exemptClubsDd.querySelector('.edit-controls-exempt-clubs');
                        if (existingControls) {
                            existingControls.remove();
                        }

                        const controlsWrapper = document.createElement('div');
                        controlsWrapper.classList.add('edit-controls-exempt-clubs', 'd-flex', 'flex-column', 'align-items-start');

                        const checkboxContainer = document.createElement('div');
                        checkboxContainer.classList.add('exempt-clubs-checkbox-container', 'mb-2');
                        checkboxContainer.style.maxHeight = '200px';
                        checkboxContainer.style.overflowY = 'auto';
                        checkboxContainer.style.border = '1px solid #ced4da'; // Standard Bootstrap border color
                        checkboxContainer.style.padding = '10px';
                        checkboxContainer.style.borderRadius = '.25rem'; // Standard Bootstrap border radius


                        allClubsForJS.forEach(club => {
                            const formCheckDiv = document.createElement('div');
                            formCheckDiv.classList.add('form-check');

                            const checkbox = document.createElement('input');
                            checkbox.type = 'checkbox';
                            checkbox.classList.add('form-check-input');
                            checkbox.value = club.uuid;
                            checkbox.id = `exempt-club-${club.uuid}`;
                            if (exemptedClubUuidsForJS.includes(club.uuid)) {
                                checkbox.checked = true;
                            }

                            const label = document.createElement('label');
                            label.classList.add('form-check-label');
                            label.htmlFor = checkbox.id;
                            label.textContent = club.club_name;

                            formCheckDiv.appendChild(checkbox);
                            formCheckDiv.appendChild(label);
                            checkboxContainer.appendChild(formCheckDiv);
                        });

                        const buttonGroup = document.createElement('div');
                        buttonGroup.classList.add('mt-2');

                        const saveButton = document.createElement('button');
                        saveButton.classList.add('btn', 'btn-success', 'btn-sm', 'mr-2');
                        saveButton.textContent = 'Save';

                        const cancelButton = document.createElement('button');
                        cancelButton.classList.add('btn', 'btn-secondary', 'btn-sm');
                        cancelButton.textContent = 'Cancel';

                        buttonGroup.appendChild(saveButton);
                        buttonGroup.appendChild(cancelButton);

                        controlsWrapper.appendChild(checkboxContainer);
                        controlsWrapper.appendChild(buttonGroup);
                        exemptClubsDd.appendChild(controlsWrapper);

                        saveButton.addEventListener('click', function() {
                            const selectedClubUuids = [];
                            const selectedClubNames = [];
                            checkboxContainer.querySelectorAll('input[type="checkbox"]:checked').forEach(cb => {
                                selectedClubUuids.push(cb.value);
                                // Find the label text for the name
                                const label = checkboxContainer.querySelector(`label[for="${cb.id}"]`);
                                if (label) {
                                    selectedClubNames.push(label.textContent);
                                }
                            });

                            const formData = new FormData();
                            formData.append('referee_uuid', refereeUUID);
                            selectedClubUuids.forEach(uuid => {
                                formData.append('club_uuids[]', uuid);
                            });

                            fetch('update_referee_exempt_clubs.php', {
                                method: 'POST',
                                body: formData
                            })
                                .then(response => response.json())
                                .then(data => {
                                    const messagesDiv = document.getElementById('referee-details-messages');
                                    if (data.status === 'success') {
                                        exemptedClubUuidsForJS = selectedClubUuids; // Update global JS variable for subsequent edits
                                        // const selectedClubNames array is already populated from the saveButton click listener
                                        displaySpanExemptClubs.textContent = selectedClubNames.length > 0 ? selectedClubNames.join(', ') : 'None';
                                        if (messagesDiv) {
                                            messagesDiv.innerHTML = `<div class="alert alert-success alert-dismissible fade show" role="alert">
                                    ${data.message || 'Exempt clubs updated successfully.'}
                                    <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">×</span></button>
                                </div>`;
                                        }
                                        toggleExemptClubsToViewMode();
                                    } else {
                                        if (messagesDiv) {
                                            messagesDiv.innerHTML = `<div class="alert alert-danger">${data.message || 'Error updating exempt clubs.'}</div>`;
                                        }
                                    }
                                    // Auto-dismiss message
                                    setTimeout(() => {
                                        if (messagesDiv && messagesDiv.querySelector('.alert')) {
                                            messagesDiv.querySelector('.alert').remove();
                                        }
                                    }, 5000);
                                })
                                .catch(error => {
                                    console.error('Error:', error);
                                    const messagesDiv = document.getElementById('referee-details-messages');
                                    if (messagesDiv) {
                                        messagesDiv.innerHTML = '<div class="alert alert-danger">An unexpected error occurred.</div>';
                                        setTimeout(() => {
                                            if (messagesDiv.querySelector('.alert')) messagesDiv.querySelector('.alert').remove();
                                        }, 5000);
                                    }
                                });
                        });

                        cancelButton.addEventListener('click', function() {
                            toggleExemptClubsToViewMode();
                        });
                    });
                }
            }

            function toggleExemptClubsToViewMode() {
                const controls = exemptClubsDd.querySelector('.edit-controls-exempt-clubs');
                if (controls) {
                    controls.remove();
                }
                const displaySpan = exemptClubsDd.querySelector('.display-value[data-field="exempt_clubs"]');
                const editIcon = exemptClubsDd.querySelector('.edit-icon[data-field="exempt_clubs"]');
                if (displaySpan) displaySpan.style.display = '';
                if (editIcon) editIcon.style.display = '';
            }
            // --- End of Logic for Exempt Clubs Edit ---
        });
    </script>

<?php include '../includes/footer.php'; ?>