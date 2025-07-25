<?php
require_once __DIR__ . '/../../utils/db.php';

header('Content-Type: application/json');

$division_id = $_GET['division_id'] ?? null;

try {
    $pdo = Database::getConnection();
    if ($division_id) {
        $stmt = $pdo->prepare("SELECT id, name FROM districts WHERE division_id = ? ORDER BY name ASC");
        $stmt->execute([$division_id]);
    } else {
        $stmt = $pdo->query("SELECT id, name FROM districts ORDER BY name ASC");
    }
    $districts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($districts);
} catch (PDOException $e) {
    // It's a good practice to handle potential errors
    // For example, log the error and return a 500 status code
    error_log('Database error in district_options.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch districts']);
}
?>
