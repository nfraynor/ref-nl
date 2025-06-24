<?php
require_once __DIR__ . '/../../utils/db.php'; // Adjust path as needed

header('Content-Type: application/json');

try {
    $pdo = Database::getConnection();
    // Consider adding a WHERE clause if only users with a specific role can be assigners
    // e.g., "SELECT uuid, username FROM users WHERE role = 'assigner' ORDER BY username ASC"
    $stmt = $pdo->query("SELECT uuid, username FROM users ORDER BY username ASC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($users);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Failed to fetch users: ' . $e->getMessage()]);
}
?>
