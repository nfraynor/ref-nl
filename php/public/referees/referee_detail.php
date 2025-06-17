<?php
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
    SELECT r.*, c.club_name 
    FROM referees r 
    LEFT JOIN clubs c ON r.home_club_id = c.uuid 
    WHERE r.referee_id = ?
");
$stmt->execute([$refereeId]);
$referee = $stmt->fetch();

if (!$referee) {
    echo "<p>Referee not found.</p>";
    exit;
}

// Fetch unavailability
$unavailability = $pdo->prepare("
    SELECT * FROM referee_unavailability 
    WHERE referee_id = ? 
    ORDER BY start_date DESC
");
$unavailability->execute([$referee['uuid']]);
$unavailabilityList = $unavailability->fetchAll();

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
            referee_travel_log rtl ON m.uuid = rtl.match_id AND rtl.referee_id = :current_referee_uuid
        WHERE
            (m.referee_id = :current_referee_uuid OR
             m.ar1_id = :current_referee_uuid OR
             m.ar2_id = :current_referee_uuid) AND
            m.match_date >= DATE('now')
        ORDER BY
            m.match_date ASC, m.kickoff_time ASC;
    ";
    try {
        error_log("ASSIGNED_MATCHES: SQL: " . $sqlAssignedMatches);
        $stmtAssignedMatches = $pdo->prepare($sqlAssignedMatches);
        $stmtAssignedMatches->execute([':current_referee_uuid' => $currentRefereeUuid]);
        $assignedMatches = $stmtAssignedMatches->fetchAll(PDO::FETCH_ASSOC);
        error_log("ASSIGNED_MATCHES: Data: " . print_r($assignedMatches, true));
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
                referee_travel_log rtl ON m.uuid = rtl.match_id AND rtl.referee_id = :current_referee_uuid
            WHERE
                (m.referee_id = :current_referee_uuid OR
                 m.ar1_id = :current_referee_uuid OR
                 m.ar2_id = :current_referee_uuid) AND
                m.match_date < DATE('now')
            ORDER BY
                m.match_date DESC, m.kickoff_time DESC;
        ";
        error_log("PREVIOUS_MATCHES: SQL: " . $sqlPreviousMatches);
        $stmtPreviousMatches = $pdo->prepare($sqlPreviousMatches);
        $stmtPreviousMatches->execute([':current_referee_uuid' => $currentRefereeUuid]);
        $previousMatches = $stmtPreviousMatches->fetchAll(PDO::FETCH_ASSOC);
        error_log("PREVIOUS_MATCHES: Data: " . print_r($previousMatches, true));
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
                <h1>Referee Details: <?= htmlspecialchars($referee['first_name'] . ' ' . $referee['last_name']) ?></h1>
            </div>
            <div class="card-body">
                <dl class="row">
                    <dt class="col-sm-3">Email</dt>
                    <dd class="col-sm-9"><?= htmlspecialchars($referee['email']) ?></dd>

                    <dt class="col-sm-3">Phone</dt>
                    <dd class="col-sm-9"><?= htmlspecialchars($referee['phone']) ?></dd>

                    <dt class="col-sm-3">Club</dt>
                    <dd class="col-sm-9"><?= htmlspecialchars($referee['club_name']) ?></dd>

                    <dt class="col-sm-3">City</dt>
                    <dd class="col-sm-9"><?= htmlspecialchars($referee['home_location_city']) ?></dd>

                    <dt class="col-sm-3">Grade</dt>
                    <dd class="col-sm-9"><?= htmlspecialchars($referee['grade']) ?></dd>
                </dl>
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
                <h3>Assigned Matches</h3>
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
                                                if ($match['main_ref_name'] && strpos($match['main_ref_name'], $referee['first_name']) !== false && strpos($match['main_ref_name'], $referee['last_name']) !== false) {
                                                    echo 'Referee';
                                                } elseif ($match['ar1_ref_name'] && strpos($match['ar1_ref_name'], $referee['first_name']) !== false && strpos($match['ar1_ref_name'], $referee['last_name']) !== false) {
                                                    echo 'AR1';
                                                } elseif ($match['ar2_ref_name'] && strpos($match['ar2_ref_name'], $referee['first_name']) !== false && strpos($match['ar2_ref_name'], $referee['last_name']) !== false) {
                                                    echo 'AR2';
                                                } else {
                                                    echo 'N/A';
                                                }
                                            ?>
                                        </td>
                                        <td><?= htmlspecialchars($match['travel_distance_for_current_referee'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($match['main_ref_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($match['ar1_ref_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($match['ar2_ref_name'] ?? 'N/A') ?></td>
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
                <h3>Previous Matches</h3>
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
                                            }
                                            echo htmlspecialchars($role);
                                            ?>
                                        </td>
                                        <td><?= htmlspecialchars($match['travel_distance_for_current_referee'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($match['main_ref_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($match['ar1_ref_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($match['ar2_ref_name'] ?? 'N/A') ?></td>
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
    flatpickr("#unavailability_end_date", {
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

            // Log FormData content for debugging
            // for (var pair of formData.entries()) {
            //     console.log(pair[0]+ ', ' + pair[1]);
            // }

            fetch('add_unavailability.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    // Clear form fields
                    addUnavailabilityForm.reset();
                    // Clear Flatpickr fields
                    if(startDatePicker) startDatePicker.clear();
                    if(endDatePicker) endDatePicker.clear();


                    // Add new row to the table
                    const newRow = unavailabilityListBody.insertRow(0); // Insert at the top like current list
                    const cell1 = newRow.insertCell(0);
                    const cell2 = newRow.insertCell(1);
                    const cell3 = newRow.insertCell(2);

                    cell1.textContent = data.data.start_date;
                    cell2.textContent = data.data.end_date;
                    // For reason, handle potential null and nl2br equivalent
                    let reasonText = data.data.reason || "";
                    cell3.innerHTML = reasonText.replace(/\r\n|\r|\n/g, '<br>');


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
});
</script>

<?php include '../includes/footer.php'; ?>
