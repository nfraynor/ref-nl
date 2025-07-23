<?php
require_once __DIR__ . '/../../utils/db.php';

header('Content-Type: application/json');

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->query("SELECT id, name FROM districts ORDER BY name ASC");
    $districts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($districts);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
