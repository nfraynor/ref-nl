<?php
require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';
include 'includes/header.php';
include 'includes/nav.php';

$districtId = $_GET['id'] ?? null;
if (!$districtId) {
    header('Location: districts.php');
    exit;
}

$pdo = Database::getConnection();
$stmt = $pdo->prepare("SELECT * FROM districts WHERE id = ?");
$stmt->execute([$districtId]);
$district = $stmt->fetch();

if (!$district) {
    header('Location: districts.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['districtName'])) {
    $newName = trim($_POST['districtName']);
    if (!empty($newName)) {
        try {
            $updateStmt = $pdo->prepare("UPDATE districts SET name = ? WHERE id = ?");
            $updateStmt->execute([$newName, $districtId]);
            header('Location: districts.php');
            exit;
        } catch (PDOException $e) {
            die("Error updating district: " . $e->getMessage());
        }
    }
}
?>
<div class="container">
    <div class="content-card">
        <h1>Edit District</h1>
        <form method="post">
            <div class="form-group">
                <label for="districtName">District Name</label>
                <input type="text" class="form-control" id="districtName" name="districtName" value="<?= htmlspecialchars($district['name']) ?>" required>
            </div>
            <button type="submit" class="btn btn-primary">Update</button>
            <a href="districts.php" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
