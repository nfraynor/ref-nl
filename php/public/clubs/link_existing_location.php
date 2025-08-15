<?php
require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';
header('Content-Type: application/json');

if (($_SESSION['user_role'] ?? null) !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['status'=>'error','message'=>'Forbidden']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status'=>'error','message'=>'Method not allowed']); exit;
}

$clubUuid     = $_POST['club_uuid'] ?? '';
$locationUuid = $_POST['location_uuid'] ?? '';

if ($clubUuid === '' || $locationUuid === '' || !preg_match('/^[0-9a-fA-F-]{36}$/', $locationUuid)) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Club and location are required.']); exit;
}

try {
    $pdo = Database::getConnection();
    $pdo->beginTransaction();

    // Validate club
    $c = $pdo->prepare("SELECT 1 FROM clubs WHERE uuid = ?");
    $c->execute([$clubUuid]);
    if (!$c->fetchColumn()) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['status'=>'error','message'=>'Club not found']); exit;
    }

    // Fetch location
    $l = $pdo->prepare("SELECT uuid, name, address_text, latitude, longitude FROM locations WHERE uuid = ?");
    $l->execute([$locationUuid]);
    $loc = $l->fetch(PDO::FETCH_ASSOC);
    if (!$loc) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['status'=>'error','message'=>'Location not found']); exit;
    }

    // Link
    $u = $pdo->prepare("UPDATE clubs SET location_uuid = ? WHERE uuid = ?");
    $u->execute([$locationUuid, $clubUuid]);

    $pdo->commit();
    echo json_encode(['status'=>'success','location'=>$loc]);
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'Server error']);
}
