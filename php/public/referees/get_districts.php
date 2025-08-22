<?php
declare(strict_types=1);

require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';

header('Content-Type: application/json; charset=utf-8');

try {

    $pdo = Database::getConnection();

    // Adjust table/columns if your schema differs
    $stmt = $pdo->query("SELECT id, name FROM districts ORDER BY name ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Basic shape validation
    $data = array_map(function ($r) {
        return [
            'id'   => isset($r['id']) ? (int)$r['id'] : null,
            'name' => (string)($r['name'] ?? ''),
        ];
    }, $rows);

    echo json_encode(['status' => 'success', 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('get_districts.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to fetch districts.']);
    exit;
}
