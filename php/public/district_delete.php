<?php
require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: divisions.php?err=1&msg=' . urlencode('Invalid district id'));
    exit;
}

try {
    $pdo = Database::getConnection();
    // Will cascade from division_districts (ON DELETE CASCADE)
    $stmt = $pdo->prepare("DELETE FROM districts WHERE id = :id");
    $stmt->execute([':id'=>$id]);

    $msg = ($stmt->rowCount() > 0) ? 'District deleted' : 'District not found';
    header('Location: divisions.php?msg=' . urlencode($msg));
} catch (PDOException $e) {
    header('Location: divisions.php?err=1&msg=' . urlencode('Delete failed: ' . $e->getMessage()));
}
