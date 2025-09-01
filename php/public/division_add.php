<?php
require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: divisions.php?err=1&msg=' . urlencode('Invalid method'));
    exit;
}

$name = trim((string)($_POST['division_name'] ?? ''));
if ($name === '') {
    header('Location: divisions.php?err=1&msg=' . urlencode('Division name required'));
    exit;
}

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("INSERT INTO divisions (name) VALUES (:name)");
    $stmt->execute([':name' => $name]);
    header('Location: divisions.php?msg=' . urlencode('Division added'));
} catch (PDOException $e) {
    $dup = ($e->getCode() === '23000');
    $msg = $dup ? 'Division already exists' : ('Insert failed: ' . $e->getMessage());
    header('Location: divisions.php?err=1&msg=' . urlencode($msg));
}
