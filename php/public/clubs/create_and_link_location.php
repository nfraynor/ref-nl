<?php
// File: php/public/clubs/create_and_link_location.php
declare(strict_types=1);

require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';

header('Content-Type: application/json; charset=utf-8');

// Only super admins can create/link locations (matches your UI)
if (($_SESSION['user_role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Not authorized.']);
    exit;
}

$clubUuid   = trim($_POST['club_uuid']    ?? '');
$fieldName  = trim($_POST['field_name']   ?? '');
$address    = trim($_POST['address_text'] ?? '');

if ($clubUuid === '' || $fieldName === '') {
    echo json_encode(['status' => 'error', 'message' => 'club_uuid and field_name are required.']);
    exit;
}

// Keep lengths sane (adjust if your schema differs)
$fieldName = mb_substr($fieldName, 0, 255);
$address   = mb_substr($address,   0, 255);

// Simple UUIDv4 generator (no external deps)
function uuidv4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

try {
    $pdo = Database::getConnection();
    $pdo->beginTransaction();

    // Ensure the club exists
    $chk = $pdo->prepare('SELECT 1 FROM clubs WHERE uuid = ? LIMIT 1');
    $chk->execute([$clubUuid]);
    if (!$chk->fetchColumn()) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Club not found.']);
        exit;
    }

    // Create the location
    $locUuid = uuidv4();
    $ins = $pdo->prepare('
        INSERT INTO locations (uuid, name, address_text)
        VALUES (?, ?, ?)
    ');
    $ins->execute([$locUuid, $fieldName, $address !== '' ? $address : null]);

    // Link the location to the club
    $upd = $pdo->prepare('UPDATE clubs SET location_uuid = ? WHERE uuid = ?');
    $upd->execute([$locUuid, $clubUuid]);

    $pdo->commit();

    echo json_encode([
        'status'   => 'success',
        'location' => [
            'uuid'         => $locUuid,
            'name'         => $fieldName,
            'address_text' => $address,
        ],
    ]);
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Optional: log $e->getMessage()
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to create/link location.']);
}
