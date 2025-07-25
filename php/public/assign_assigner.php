<?php
require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';
include 'includes/header.php';
include 'includes/nav.php';

$pdo = Database::getConnection();

// Fetch all users to populate the assigner dropdown
$userStmt = $pdo->query("SELECT uuid, username FROM users ORDER BY username ASC");
$users = $userStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all divisions and districts for the filters
$divisionStmt = $pdo->query("SELECT name FROM divisions ORDER BY name ASC");
$divisions = $divisionStmt->fetchAll(PDO::FETCH_COLUMN);
$districtStmt = $pdo->query("SELECT name FROM districts ORDER BY name ASC");
$districts = $districtStmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch matches (similar to matches.php, but simplified)
$whereClauses = [];
$params = [];

// Existing filters from _GET parameters
if (!empty($_GET['division'])) {
    $whereClauses[] = "m.division = ?";
    $params[] = $_GET['division'];
}
if (!empty($_GET['district'])) {
    $whereClauses[] = "m.district = ?";
    $params[] = $_GET['district'];
}

$whereSQL = count($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$sql = "
    SELECT
        m.uuid,
        m.match_date,
        m.kickoff_time,
        ht.team_name AS home_team_name,
        at.team_name AS away_team_name,
        m.division,
        m.district,
        assigner_user.username AS referee_assigner_username
    FROM matches m
    JOIN teams ht ON m.home_team_id = ht.uuid
    JOIN clubs hc ON ht.club_id = hc.uuid
    JOIN teams at ON m.away_team_id = at.uuid
    JOIN clubs ac ON at.club_id = ac.uuid
    LEFT JOIN users assigner_user ON m.referee_assigner_uuid = assigner_user.uuid
    $whereSQL
    ORDER BY m.match_date ASC, m.kickoff_time ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$matches = $stmt->fetchAll();
?>

<div class="container-fluid">
    <div class="content-card">
        <h1>Assign Assigner to Matches</h1>

        <form method="GET" action="assign_assigner.php" class="mb-4">
            <div class="row">
                <div class="col-md-4">
                    <label for="division" class="form-label">Division:</label>
                    <select name="division" id="division" class="form-select">
                        <option value="">All Divisions</option>
                        <?php foreach ($divisions as $division): ?>
                            <option value="<?= htmlspecialchars($division) ?>" <?= ($_GET['division'] ?? '') == $division ? 'selected' : '' ?>><?= htmlspecialchars($division) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="district" class="form-label">District:</label>
                    <select name="district" id="district" class="form-select">
                        <option value="">All Districts</option>
                        <?php foreach ($districts as $district): ?>
                            <option value="<?= htmlspecialchars($district) ?>" <?= ($_GET['district'] ?? '') == $district ? 'selected' : '' ?>><?= htmlspecialchars($district) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary">Filter</button>
                </div>
            </div>
        </form>

        <form method="POST" action="bulk_assign_assigner.php">
            <div class="row mb-3">
                <div class="col-md-4">
                    <label for="assigner_id" class="form-label">Select Assigner:</label>
                    <select name="assigner_id" id="assigner_id" class="form-select" required>
                        <option value="">Select a user</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= htmlspecialchars($user['uuid']) ?>"><?= htmlspecialchars($user['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="table-responsive-custom">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="selectAllMatches"></th>
                            <th>Date</th>
                            <th>Kickoff</th>
                            <th>Home Team</th>
                            <th>Away Team</th>
                            <th>Division</th>
                            <th>District</th>
                            <th>Current Assigner</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($matches as $match): ?>
                            <tr>
                                <td><input type="checkbox" name="match_ids[]" value="<?= htmlspecialchars($match['uuid']) ?>" class="match-checkbox"></td>
                                <td><a href="match_detail.php?uuid=<?= htmlspecialchars($match['uuid']) ?>"><?= htmlspecialchars($match['match_date']) ?></a></td>
                                <td><?= htmlspecialchars(substr($match['kickoff_time'], 0, 5)) ?></td>
                                <td><?= htmlspecialchars($match['home_team_name']) ?></td>
                                <td><?= htmlspecialchars($match['away_team_name']) ?></td>
                                <td><?= htmlspecialchars($match['division']) ?></td>
                                <td><?= htmlspecialchars($match['district']) ?></td>
                                <td><?= htmlspecialchars($match['referee_assigner_username'] ?? 'N/A') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <button type="submit" class="btn btn-primary mt-3">Assign Selected Matches</button>
        </form>
    </div>
</div>

<script>
    document.getElementById('selectAllMatches').addEventListener('change', function(e) {
        const checkboxes = document.querySelectorAll('.match-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = e.target.checked;
        });
    });
</script>

<?php include 'includes/footer.php'; ?>
