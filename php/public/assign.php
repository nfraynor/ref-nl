<?php
require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';
include 'includes/header.php';
include 'includes/nav.php';

$pdo = Database::getConnection();

$match_id = $_GET['match_id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $referee_id = $_POST['referee_id'];
    $role = $_POST['role'];

    $stmt = $pdo->prepare("INSERT INTO assignments (uuid, match_id, referee_id, role, proposed, assigned_on) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->execute([uniqid(), $match_id, $referee_id, $role, 0]);

    echo "<div class='alert alert-success'>Referee assigned successfully!</div>";
}

$referees = $pdo->query("SELECT * FROM referees")->fetchAll();
?>

<h1>Assign Referee</h1>

<form method="POST">
    <div class="mb-3">
        <label for="referee_id" class="form-label">Referee</label>
        <select class="form-select" name="referee_id" required>
            <?php foreach ($referees as $ref): ?>
                <option value="<?= $ref['uuid'] ?>"><?= htmlspecialchars($ref['first_name'] . ' ' . $ref['last_name'] . " ({$ref['grade']})") ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="mb-3">
        <label for="role" class="form-label">Role</label>
        <select class="form-select" name="role" required>
            <option value="REFEREE">Referee</option>
            <option value="AR1">Assistant Referee 1 (AR1)</option>
            <option value="AR2">Assistant Referee 2 (AR2)</option>
            <option value="MATCH_COMMISSIONER">Match Commissioner</option>
        </select>
    </div>

    <button type="submit" class="btn btn-success">Assign</button>
</form>

<?php include 'includes/footer.php'; ?>
