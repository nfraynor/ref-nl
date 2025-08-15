<?php
require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';

header('Content-Type: application/json');

// Gate to super admins (adjust if you want broader access)
if (($_SESSION['user_role'] ?? null) !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']); exit;
}

$clubName     = trim($_POST['club_name'] ?? '');
$locationUuid = trim($_POST['location_uuid'] ?? ''); // optional

if ($clubName === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Club name is required.']); exit;
}
if (mb_strlen($clubName) > 255) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Club name too long (max 255).']); exit;
}
if ($locationUuid !== '' && !preg_match('/^[0-9a-fA-F-]{36}$/', $locationUuid)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid location.']); exit;
}

function uuidv4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

try {
    $pdo = Database::getConnection();
    $pdo->beginTransaction();

    // Validate optional location
    if ($locationUuid !== '') {
        $stLoc = $pdo->prepare("SELECT 1 FROM locations WHERE uuid = ?");
        $stLoc->execute([$locationUuid]);
        if (!$stLoc->fetchColumn()) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Selected location not found.']); exit;
        }
    }

    // Generate club_number and club_id (CB-#) atomically
    $nextNumStmt = $pdo->query("SELECT COALESCE(MAX(club_number), 0) + 1 AS next_num FROM clubs FOR UPDATE");
    $nextNum = (int)$nextNumStmt->fetchColumn();
    if ($nextNum < 1) { $nextNum = 1; }

    $clubNumber = $nextNum;
    $clubId = 'CB-' . $clubNumber;

    $uuid = uuidv4();

    $ins = $pdo->prepare("
        INSERT INTO clubs (
            uuid, club_id, club_number, club_name, location_uuid, active
        ) VALUES (?, ?, ?, ?, ?, TRUE)
    ");
    $ins->execute([$uuid, $clubId, $clubNumber, $clubName, ($locationUuid !== '' ? $locationUuid : null)]);

    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'club' => [
            'uuid'        => $uuid,
            'club_id'     => $clubId,
            'club_number' => $clubNumber,
            'club_name'   => $clubName,
            'location_uuid' => ($locationUuid !== '' ? $locationUuid : null),
        ]
    ]);
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}
