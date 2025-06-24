<?php
require_once __DIR__ . '/../../utils/session_auth.php'; // Ensure user is logged in
require_once __DIR__ . '/../../utils/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$matchUuid = $_POST['match_uuid'] ?? null;
$fieldType = $_POST['field_type'] ?? null;
$newValue = $_POST['new_value'] ?? null; // This will be the UUID for location/assigner, or empty string

if (!$matchUuid || !$fieldType) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters (match_uuid or field_type).']);
    exit;
}

$pdo = Database::getConnection();
$response = ['success' => false, 'message' => 'An unknown error occurred.'];

try {
    $columnToUpdate = null;
    $newValueDisplay = 'N/A'; // Default display value

    if ($fieldType === 'location') {
        $columnToUpdate = 'location_uuid';
        if (!empty($newValue)) {
            $stmt = $pdo->prepare("SELECT name, address_text FROM locations WHERE uuid = ?");
            $stmt->execute([$newValue]);
            $loc = $stmt->fetch();
            if ($loc) {
                $newValueDisplay = htmlspecialchars($loc['name'] ?? 'N/A');
                if (!empty($loc['address_text']) && $loc['name'] !== $loc['address_text'] && $loc['name']) {
                    $newValueDisplay .= '<br><small>' . htmlspecialchars($loc['address_text']) . '</small>';
                } elseif (empty($loc['name']) && !empty($loc['address_text'])) {
                    $newValueDisplay = '<small>' . htmlspecialchars($loc['address_text']) . '</small>';
                }
            } else if (!empty($newValue)) { // newValue is not empty but location not found
                echo json_encode(['success' => false, 'message' => 'Invalid location selected.']);
                exit;
            }
        }
    } elseif ($fieldType === 'referee_assigner') {
        $columnToUpdate = 'referee_assigner_uuid';
        if (!empty($newValue)) {
            $stmt = $pdo->prepare("SELECT username FROM users WHERE uuid = ?");
            $stmt->execute([$newValue]);
            $user = $stmt->fetch();
            if ($user) {
                $newValueDisplay = htmlspecialchars($user['username']);
            } else if (!empty($newValue)) { // newValue is not empty but user not found
                echo json_encode(['success' => false, 'message' => 'Invalid user selected as assigner.']);
                exit;
            }
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid field type specified.']);
        exit;
    }

    // If $newValue is an empty string, it means we want to set the DB field to NULL
    $valueToSet = empty($newValue) ? null : $newValue;

    $updateSql = "UPDATE matches SET $columnToUpdate = :newValue WHERE uuid = :matchUuid";
    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->bindParam(':newValue', $valueToSet, $valueToSet === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $updateStmt->bindParam(':matchUuid', $matchUuid, PDO::PARAM_STR);

    if ($updateStmt->execute()) {
        $response = ['success' => true, 'message' => 'Match updated successfully.', 'newValueDisplay' => $newValueDisplay];
    } else {
        $response['message'] = 'Failed to update match.';
    }

} catch (PDOException $e) {
    // Log error: error_log($e->getMessage());
    $response['message'] = 'Database error: ' . $e->getMessage();
} catch (Exception $e) {
    // Log error: error_log($e->getMessage());
    $response['message'] = 'General error: ' . $e->getMessage();
}

echo json_encode($response);
?>
