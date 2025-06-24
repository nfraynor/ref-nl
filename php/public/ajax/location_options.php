<?php
require_once __DIR__ . '/../../utils/db.php'; // Adjust path as needed

header('Content-Type: application/json');

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->query("SELECT uuid, name, address_text FROM locations ORDER BY name ASC");
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($locations);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Failed to fetch locations: ' . $e->getMessage()]);
}
?>
