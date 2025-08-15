<?php
// File: php/public/clubs/update_location_field.php
require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']); exit;
}

$locationUuid = $_POST['location_uuid'] ?? '';
$fieldName    = $_POST['field_name'] ?? '';
$fieldValue   = $_POST['field_value'] ?? '';

if (!$locationUuid || !$fieldName) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required parameters']); exit;
}

$allowed = ['address_text']; // keep tight; extend to ['name','address_text'] if you want to edit field name too
if (!in_array($fieldName, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid field']); exit;
}

$fieldValue = is_string($fieldValue) ? trim($fieldValue) : $fieldValue;
if ($fieldName === 'address_text') {
    if (mb_strlen($fieldValue) > 255) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Address too long (max 255 chars).']); exit;
    }
    // empty address -> set NULL
    $value = ($fieldValue === '') ? null : $fieldValue;
}

try {
    $pdo = Database::getConnection();

    // ensure location exists
    $check = $pdo->prepare("SELECT uuid FROM locations WHERE uuid = ?");
    $check->execute([$locationUuid]);
    if (!$check->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Location not found']); exit;
    }

    $sql = "UPDATE locations SET {$fieldName} = :val WHERE uuid = :uuid";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':val' => $value, ':uuid' => $locationUuid]);

    echo json_encode(['status' => 'success']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']); // avoid leaking details
}
