<?php
require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'POST only']); exit; }

$uuid = $_POST['uuid'] ?? '';
if (!$uuid) { echo json_encode(['success'=>false,'message'=>'Missing uuid']); exit; }

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("DELETE FROM matches WHERE uuid = :uuid");
    $stmt->execute([':uuid'=>$uuid]);
    echo json_encode(['success'=>true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Delete failed']);
}
