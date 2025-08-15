<?php
require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';
header('Content-Type: application/json');

if (($_SESSION['user_role'] ?? null) !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['status'=>'error','message'=>'Forbidden']); exit;
}

$q = trim($_GET['q'] ?? '');
$limit = 25;

try {
    $pdo = Database::getConnection();
    if ($q === '') {
        $stmt = $pdo->prepare("
            SELECT l.uuid, l.name, l.address_text,
                   (SELECT COUNT(*) FROM clubs c WHERE c.location_uuid = l.uuid) AS clubs_count
            FROM locations l
            ORDER BY l.name ASC
            LIMIT $limit
        ");
        $stmt->execute();
    } else {
        $stmt = $pdo->prepare("
            SELECT l.uuid, l.name, l.address_text,
                   (SELECT COUNT(*) FROM clubs c WHERE c.location_uuid = l.uuid) AS clubs_count
            FROM locations l
            WHERE l.name LIKE ? OR l.address_text LIKE ?
            ORDER BY l.name ASC
            LIMIT $limit
        ");
        $like = "%$q%";
        $stmt->execute([$like, $like]);
    }
    echo json_encode(['status'=>'success','results'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'Server error']);
}
