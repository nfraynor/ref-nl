<?php
require_once __DIR__ . '/../../utils/db.php'; // Adjust path as needed

header('Content-Type: application/json');

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->query("SELECT uuid, club_name FROM clubs ORDER BY club_name ASC");
    $clubs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($clubs);
} catch (PDOException $e) {
    // Log error and return an error response
    error_log("Error fetching club options: " . $e->getMessage());
    http_response_code(500); // Internal Server Error
    echo json_encode(['error' => 'Failed to fetch club options.']);
}
?>
