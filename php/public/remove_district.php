<?php
require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';

$districtId = $_GET['id'] ?? null;
if (!$districtId) {
    header('Location: districts.php');
    exit;
}

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("DELETE FROM districts WHERE id = ?");
    $stmt->execute([$districtId]);
    header('Location: districts.php');
    exit;
} catch (PDOException $e) {
    die("Error deleting district: " . $e->getMessage());
}
?>
