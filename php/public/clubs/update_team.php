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

$teamUuid   = $_POST['team_uuid']   ?? '';
$teamName   = trim($_POST['team_name'] ?? '');
$districtId = $_POST['district_id'] ?? '';
$division   = trim($_POST['division'] ?? '');

if ($teamUuid === '' || $teamName === '' || $districtId === '' || $division === '') {
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

try {
    $pdo = Database::getConnection();

    // Load team & club
    $st = $pdo->prepare("SELECT club_id FROM teams WHERE uuid = ?");
    $st->execute([$teamUuid]);
    $clubId = $st->fetchColumn();
    if (!$clubId) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Team not found']); exit;
    }

    // Validate district exists
    $sd = $pdo->prepare("SELECT 1 FROM districts WHERE id = ?");
    $sd->execute([$districtId]);
    if (!$sd->fetchColumn()) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid district']); exit;
    }

    // Prevent duplicate name within the same club (excluding self)
    $du = $pdo->prepare("SELECT 1 FROM teams WHERE club_id = ? AND team_name = ? AND uuid <> ?");
    $du->execute([$clubId, $teamName, $teamUuid]);
    if ($du->fetchColumn()) {
        http_response_code(409);
        echo json_encode(['status' => 'error', 'message' => 'Another team with that name already exists for this club.']); exit;
    }

    $stmt = $pdo->prepare("
        UPDATE teams
           SET team_name = :name,
               division  = :division,
               district_id = :district
         WHERE uuid = :uuid
    ");
    $stmt->execute([
        ':name'     => $teamName,
        ':division' => $division,
        ':district' => $districtId,
        ':uuid'     => $teamUuid
    ]);

    echo json_encode([
        'status' => 'success',
        'team' => [
            'uuid'        => $teamUuid,
            'team_name'   => $teamName,
            'division'    => $division,
            'district_id' => $districtId
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}
