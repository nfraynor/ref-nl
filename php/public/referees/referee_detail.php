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
?>

<h1>Referee Details: <?= htmlspecialchars($referee['first_name'] . ' ' . $referee['last_name']) ?></h1>

<ul>
    <li>Email: <?= htmlspecialchars($referee['email']) ?></li>
    <li>Phone: <?= htmlspecialchars($referee['phone']) ?></li>
    <li>Club: <?= htmlspecialchars($referee['club_name']) ?></li>
    <li>City: <?= htmlspecialchars($referee['home_location_city']) ?></li>
    <li>Grade: <?= htmlspecialchars($referee['grade']) ?></li>
</ul>

<h2>Unavailability</h2>

<table class="table table-bordered">
    <thead>
    <tr><th>From</th><th>To</th><th>Reason</th></tr>
    </thead>
    <tbody>
    <?php foreach ($unavailabilityList as $ua): ?>
        <tr>
            <td><?= htmlspecialchars($ua['start_date']) ?></td>
            <td><?= htmlspecialchars($ua['end_date']) ?></td>
            <td><?= nl2br(htmlspecialchars($ua['reason'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<h2>Weekly Availability</h2>
<form method="post" action="update_weekly_availability.php">
    <input type="hidden" name="referee_id" value="<?= htmlspecialchars($referee['uuid']) ?>">

    <table class="table table-bordered">
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
                <td><input type="checkbox" name="availability[<?= $i ?>][morning]" <?= $row['morning_available'] ? 'checked' : '' ?>></td>
                <td><input type="checkbox" name="availability[<?= $i ?>][afternoon]" <?= $row['afternoon_available'] ? 'checked' : '' ?>></td>
                <td><input type="checkbox" name="availability[<?= $i ?>][evening]" <?= $row['evening_available'] ? 'checked' : '' ?>></td>
            </tr>
        <?php endfor; ?>
        </tbody>
    </table>

    <button type="submit" class="btn btn-primary">Save Weekly Availability</button>
</form>

<h2>Add Unavailability</h2>
<form method="post" action="add_unavailability.php">
    <input type="hidden" name="referee_id" value="<?= htmlspecialchars($referee['uuid']) ?>">
    <label>Start Date: <input type="date" name="start_date" required></label><br>
    <label>End Date: <input type="date" name="end_date" required></label><br>
    <label>Reason:<br>
        <textarea name="reason" rows="4" cols="40"></textarea>
    </label><br>
    <button type="submit">Add</button>
</form>

<?php include 'includes/footer.php'; ?>
