<?php
// File: php/public/clubs/update_club_field.php
require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$clubUuid   = $_POST['club_uuid']  ?? '';
$fieldName  = $_POST['field_name'] ?? '';
$fieldValue = $_POST['field_value'] ?? '';

if (!$clubUuid || !$fieldName) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required parameters']);
    exit;
}

$allowedFields = [
    'primary_contact_name',
    'primary_contact_email',
    'primary_contact_phone',
    'notes' // NEW
];

if (!in_array($fieldName, $allowedFields, true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid field']);
    exit;
}

$value = is_string($fieldValue) ? trim($fieldValue) : $fieldValue;

// Validation
if ($fieldName === 'primary_contact_name') {
    if ($value !== '' && mb_strlen($value) > 255) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Name too long (max 255).']);
        exit;
    }
    $value = ($value === '') ? null : $value;

} elseif ($fieldName === 'primary_contact_email') {
    if ($value === '') {
        $value = null;
    } else {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid email address.']);
            exit;
        }
        if (mb_strlen($value) > 255) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Email too long (max 255).']);
            exit;
        }
    }

} elseif ($fieldName === 'primary_contact_phone') {
    if ($value === '') {
        $value = null;
    } else {
        if (!preg_match('/^[0-9()+\-\s]{1,50}$/', $value)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid phone format.']);
            exit;
        }
    }

} elseif ($fieldName === 'notes') {
    // TEXT in MySQL: up to 65,535 bytes
    if ($value === '') {
        $value = null;
    } else {
        if (mb_strlen($value) > 65535) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Notes too long (max 65535 characters).']);
            exit;
        }
    }
}

try {
    $pdo = Database::getConnection();

    $check = $pdo->prepare("SELECT uuid FROM clubs WHERE uuid = ?");
    $check->execute([$clubUuid]);
    if (!$check->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Club not found']);
        exit;
    }

    $sql = "UPDATE clubs SET {$fieldName} = :val WHERE uuid = :uuid";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':val' => $value, ':uuid' => $clubUuid]);

    echo json_encode(['status' => 'success']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}
