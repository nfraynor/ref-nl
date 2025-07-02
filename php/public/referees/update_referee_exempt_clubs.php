<?php
require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$refereeUuid = $_POST['referee_uuid'] ?? null;
$clubUuids = $_POST['club_uuids'] ?? []; // Expect an array, default to empty

if (empty($refereeUuid)) {
    http_response_code(400); // Bad Request
    echo json_encode(['status' => 'error', 'message' => 'Referee UUID is required.']);
    exit;
}

// Validate that refereeUuid is a valid UUID format (optional but good practice)
if (!preg_match('/^[a-f\d]{8}-(?:[a-f\d]{4}-){3}[a-f\d]{12}$/i', $refereeUuid)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid Referee UUID format.']);
    exit;
}

// Validate each club UUID (optional but good practice)
if (!is_array($clubUuids)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Club UUIDs must be an array.']);
    exit;
}

foreach ($clubUuids as $clubUuid) {
    if (!preg_match('/^[a-f\d]{8}-(?:[a-f\d]{4}-){3}[a-f\d]{12}$/i', $clubUuid)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid Club UUID format provided: ' . htmlspecialchars($clubUuid)]);
        exit;
    }
}

$pdo = Database::getConnection();

try {
    $pdo->beginTransaction();

    // Delete existing exemptions for this referee
    $deleteStmt = $pdo->prepare("DELETE FROM referee_exempt_clubs WHERE referee_uuid = ?");
    $deleteStmt->execute([$refereeUuid]);

    // Insert new exemptions
    if (!empty($clubUuids)) {
        $insertSql = "INSERT INTO referee_exempt_clubs (referee_uuid, club_uuid) VALUES (?, ?)";
        $insertStmt = $pdo->prepare($insertSql);
        foreach ($clubUuids as $clubUuid) {
            $insertStmt->execute([$refereeUuid, $clubUuid]);
        }
    }

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => 'Exempt clubs updated successfully.']);

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("PDOException updating exempt clubs: " . $e->getMessage());
    http_response_code(500); // Internal Server Error
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred while updating exempt clubs.']);
}
?>
