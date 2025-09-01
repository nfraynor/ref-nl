<?php
require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';

$division_id = (int)($_GET['division_id'] ?? 0);
$district_id = (int)($_GET['district_id'] ?? 0);

if ($division_id <= 0 || $district_id <= 0) {
    header('Location: divisions.php?err=1&msg=' . urlencode('division_id and district_id required'));
    exit;
}

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("DELETE FROM division_districts WHERE division_id = :dv AND district_id = :di");
    $stmt->execute([':dv'=>$division_id, ':di'=>$district_id]);

    $msg = ($stmt->rowCount() > 0) ? 'Unlinked' : 'No link found';
    header('Location: divisions.php?msg=' . urlencode($msg));
} catch (PDOException $e) {
    header('Location: divisions.php?err=1&msg=' . urlencode('Unlink failed: ' . $e->getMessage()));
}
