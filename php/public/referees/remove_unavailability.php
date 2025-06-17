<?php
require_once __DIR__ . '/../../utils/db.php'; // Adjust path as necessary

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$unavailabilityUuid = $_POST['unavailability_uuid'] ?? null;

if (empty($unavailabilityUuid)) {
    http_response_code(400); // Bad Request
    echo json_encode(['status' => 'error', 'message' => 'Unavailability UUID is required.']);
    exit;
}

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("DELETE FROM referee_unavailability WHERE uuid = ?");

    if ($stmt->execute([$unavailabilityUuid])) {
        if ($stmt->rowCount() > 0) {
            echo json_encode(['status' => 'success', 'message' => 'Unavailability period removed successfully.']);
        } else {
            // No rows affected, meaning UUID might not exist or was already deleted
            http_response_code(404); // Not Found
            echo json_encode(['status' => 'error', 'message' => 'Unavailability period not found or already removed.']);
        }
    } else {
        // Execution failed
        http_response_code(500); // Internal Server Error
        error_log("Database execution failed for DELETE FROM referee_unavailability WHERE uuid = " . $unavailabilityUuid);
        echo json_encode(['status' => 'error', 'message' => 'Failed to remove unavailability period due to a database error.']);
    }
} catch (PDOException $e) {
    http_response_code(500); // Internal Server Error
    error_log("PDOException in remove_unavailability.php: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database connection error: ' . $e->getMessage()]);
}

exit;
?>
