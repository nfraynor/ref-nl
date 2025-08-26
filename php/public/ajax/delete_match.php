<?php
require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';
header('Content-Type: application/json');

// --- DEV LOGGING (remove after debugging) ---
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/delete_match.error.log');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'POST only']);
    exit;
}

$uuid = isset($_POST['match_uuid']) ? trim((string)$_POST['match_uuid']) : '';
if ($uuid === '') {
    echo json_encode(['success'=>false,'message'=>'Missing match_uuid']);
    exit;
}

try {
    $pdo = Database::getConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // If session_auth.php can redirect/exit on invalid session, make sure we’re actually authenticated here.
    // If not, it may terminate earlier and your frontend just sees 500.

    // Small helper to check a column
    $hasCol = function(PDO $pdo, string $table, string $col): bool {
        // very defensive: allow only [A-Za-z0-9_]
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $col)) {
            throw new RuntimeException("Illegal identifier in hasCol: $table.$col");
        }
        // quote() is fine too, but since we validated, we can inline the literal safely
        $sql = "SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($col);
        $res = $pdo->query($sql);
        return (bool)($res && $res->fetch(PDO::FETCH_ASSOC));
    };

    // Determine key column
    $keyCol = null;
    if ($hasCol($pdo, 'matches', 'uuid'))      $keyCol = 'uuid';
    elseif ($hasCol($pdo, 'matches', 'id'))    $keyCol = 'id';
    else throw new RuntimeException("matches table has no uuid or id column.");

    // Soft delete?
    $soft = $hasCol($pdo, 'matches', 'deleted_at');

    if ($soft) {
        $sql = "UPDATE `matches` SET `deleted_at` = NOW() WHERE `$keyCol` = :u LIMIT 1";
    } else {
        $sql = "DELETE FROM `matches` WHERE `$keyCol` = :u LIMIT 1";
    }

    // For visibility while debugging:
    error_log("delete_match SQL: $sql  bind=:u => $uuid");

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':u' => $uuid]);

    if ($stmt->rowCount() < 1) {
        // Not found (or already deleted) — return 404-ish semantics but keep 200 with message for UI
        echo json_encode(['success'=>false,'message'=>'Match not found or already deleted']);
        exit;
    }

    echo json_encode(['success'=>true]);

} catch (PDOException $e) {
    // Foreign key constraint?
    if ($e->getCode() === '23000' && strpos($e->getMessage(), '1451') !== false) {
        http_response_code(409);
        echo json_encode([
            'success'=>false,
            'message'=>'Delete blocked by related records (foreign key). Remove/soft-delete dependents or enable ON DELETE CASCADE.',
            'detail'=>$e->getMessage(), // keep during debugging
        ]);
        exit;
    }
    error_log("delete_match PDO error: ".$e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Delete failed: '.$e->getMessage()]);
} catch (Throwable $e) {
    error_log("delete_match error: ".$e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Delete failed: '.$e->getMessage()]);
}
