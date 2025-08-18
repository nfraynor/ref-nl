<?php
// public/ajax/update_match_assignment.php
declare(strict_types=1);

require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success'=>false, 'message'=>'Method not allowed']); exit;
    }

    $matchUuid = trim($_POST['match_uuid'] ?? '');
    $field     = trim($_POST['field'] ?? '');
    $value     = trim($_POST['value'] ?? '');

    if ($matchUuid === '' || $field === '') {
        http_response_code(400);
        echo json_encode(['success'=>false, 'message'=>'Missing parameters']); exit;
    }

    // Permit list
    $allowed = ['referee_id','ar1_id','ar2_id','commissioner_id','referee_assigner_uuid'];
    if (!in_array($field, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['success'=>false, 'message'=>'Invalid field']); exit;
    }

    $pdo = Database::getConnection();

    // If clearing, set NULL
    $sql = "UPDATE matches SET {$field} = :val WHERE uuid = :uuid";
    $stmt = $pdo->prepare($sql);
    $paramVal = ($value === '') ? null : $value;
    $stmt->bindValue(':val', $paramVal, $paramVal===null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':uuid', $matchUuid, PDO::PARAM_STR);
    $stmt->execute();

    echo json_encode(['success'=>true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false, 'message'=>'Server error']);
}
