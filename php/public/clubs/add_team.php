<?php
require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';
header('Content-Type: application/json');

if (($_SESSION['user_role'] ?? null) !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']); exit;
}

$clubUuid   = $_POST['club_uuid']   ?? '';
$teamName   = trim($_POST['team_name'] ?? '');
$districtId = $_POST['district_id'] ?? '';
$division   = trim($_POST['division'] ?? '');

if ($clubUuid === '' || $teamName === '' || $districtId === '' || $division === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'All fields are required.']); exit;
}
if (!ctype_digit($districtId) || (int)$districtId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid district.']); exit;
}
$districtId = (int)$districtId;

if (mb_strlen($teamName) > 100) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Team name too long (max 100).']); exit;
}
if (mb_strlen($division) > 100) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Division too long (max 100).']); exit;
}

function uuidv4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

try {
    $pdo = Database::getConnection();

    // Validate club
    $st = $pdo->prepare("SELECT 1 FROM clubs WHERE uuid = ?");
    $st->execute([$clubUuid]);
    if (!$st->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Club not found']); exit;
    }

    // Validate district
    $sd = $pdo->prepare("SELECT 1 FROM districts WHERE id = ?");
    $sd->execute([$districtId]);
    if (!$sd->fetchColumn()) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid district']); exit;
    }

    // Duplicate name check within club
    $du = $pdo->prepare("SELECT 1 FROM teams WHERE club_id = ? AND team_name = ?");
    $du->execute([$clubUuid, $teamName]);
    if ($du->fetchColumn()) {
        http_response_code(409);
        echo json_encode(['status' => 'error', 'message' => 'A team with that name already exists for this club.']); exit;
    }

    $uuid = uuidv4();
    $stmt = $pdo->prepare("
        INSERT INTO teams (uuid, team_name, club_id, division, district_id)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$uuid, $teamName, $clubUuid, $division, $districtId]);

    echo json_encode([
        'status' => 'success',
        'team' => [
            'uuid'        => $uuid,
            'team_name'   => $teamName,
            'division'    => $division,
            'district_id' => $districtId
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}
