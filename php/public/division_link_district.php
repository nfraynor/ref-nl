<?php
require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: divisions.php?err=1&msg=' . urlencode('Invalid method'));
    exit;
}

$division_id = (int)($_POST['division_id'] ?? 0);
$district_id = (int)($_POST['district_id'] ?? 0);

if ($division_id <= 0 || $district_id <= 0) {
    header('Location: divisions.php?err=1&msg=' . urlencode('division_id and district_id required'));
    exit;
}

try {
    $pdo = Database::getConnection();

    // Optional existence checks (helpful error messages)
    $okDiv = $pdo->prepare("SELECT 1 FROM divisions WHERE id = ?");
    $okDiv->execute([$division_id]);
    $okDis = $pdo->prepare("SELECT 1 FROM districts WHERE id = ?");
    $okDis->execute([$district_id]);
    if (!$okDiv->fetch()) throw new RuntimeException('Division not found');
    if (!$okDis->fetch()) throw new RuntimeException('District not found');

    $stmt = $pdo->prepare("
        INSERT INTO division_districts (division_id, district_id)
        VALUES (:dv, :di)
    ");
    $stmt->execute([':dv'=>$division_id, ':di'=>$district_id]);

    header('Location: divisions.php?msg=' . urlencode('District linked to division'));
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        // Duplicate PK or FK issue
        $msg = 'Already linked or invalid foreign key';
        header('Location: divisions.php?err=1&msg=' . urlencode($msg));
    } else {
        header('Location: divisions.php?err=1&msg=' . urlencode('Link failed: ' . $e->getMessage()));
    }
} catch (RuntimeException $e) {
    header('Location: divisions.php?err=1&msg=' . urlencode($e->getMessage()));
}
