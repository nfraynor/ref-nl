<?php
require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';
header('Content-Type: application/json');

/* ====== DIAGNOSTIC LOGGING SETUP ====== */
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/delete_club.error.log'); // ensure writable

$REQ_ID = bin2hex(random_bytes(6)); // short request id for correlating
function dbg($msg, array $ctx = []) {
    global $REQ_ID;
    $line = '[' . date('c') . "][$REQ_ID] $msg";
    if (!empty($ctx)) {
        $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    error_log($line);
}
set_error_handler(function ($severity, $message, $file, $line) {
    dbg('PHP ERROR', ['severity'=>$severity, 'message'=>$message, 'file'=>$file, 'line'=>$line]);
    throw new ErrorException($message, 0, $severity, $file, $line);
});
set_exception_handler(function ($e) use ($REQ_ID) {
    dbg('UNCAUGHT', [
        'type'=>get_class($e),
        'msg'=>$e->getMessage(),
        'code'=>$e->getCode(),
        'file'=>$e->getFile(),
        'line'=>$e->getLine(),
        'trace'=>$e->getTraceAsString(),
    ]);
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'Delete failed','error_id'=>$REQ_ID,'detail'=>$e->getMessage()]);
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        dbg('SHUTDOWN FATAL', $e);
    }
});

/* ====== START HANDLER ====== */
dbg('BEGIN', [
    'method'   => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri'      => $_SERVER['REQUEST_URI'] ?? '',
    'remote'   => $_SERVER['REMOTE_ADDR'] ?? '',
]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    dbg('BAD METHOD', ['got'=>$_SERVER['REQUEST_METHOD'] ?? '']);
    http_response_code(405);
    echo json_encode(['status'=>'error','message'=>'POST only','error_id'=>$REQ_ID]);
    exit;
}

if (($_SESSION['user_role'] ?? null) !== 'super_admin') {
    dbg('FORBIDDEN', ['user_role'=>$_SESSION['user_role'] ?? null]);
    http_response_code(403);
    echo json_encode(['status'=>'error','message'=>'Forbidden','error_id'=>$REQ_ID]);
    exit;
}

$clubUuid = trim((string)($_POST['club_uuid'] ?? ''));
if ($clubUuid === '') {
    dbg('MISSING club_uuid', ['post_keys'=>array_keys($_POST ?? [])]);
    echo json_encode(['status'=>'error','message'=>'Missing club_uuid','error_id'=>$REQ_ID]);
    exit;
}

$pdo = Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true); // uncomment if needed

dbg('LOOKUP club');
$sql = "SELECT uuid, location_uuid FROM clubs WHERE uuid = ?";
dbg('SQL', ['sql'=>$sql, 'params'=>[$clubUuid]]);
$stmt = $pdo->prepare($sql);
$stmt->execute([$clubUuid]);
$clubRow = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$clubRow) {
    dbg('CLUB NOT FOUND', ['club_uuid'=>$clubUuid]);
    echo json_encode(['status'=>'error','message'=>'Club not found','error_id'=>$REQ_ID]);
    exit;
}

dbg('FETCH teams');
$sql = "SELECT uuid FROM teams WHERE club_id = ?"; // teams.club_id -> clubs.uuid
dbg('SQL', ['sql'=>$sql, 'params'=>[$clubUuid]]);
$teamsStmt = $pdo->prepare($sql);
$teamsStmt->execute([$clubUuid]);
$teamUuids = $teamsStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
dbg('TEAM COUNT', ['count'=>count($teamUuids)]);

$today = (new DateTime('today'))->format('Y-m-d');

/* ---------- Count future referee_travel_log rows tied to THIS club's FUTURE matches ---------- */
$futureRTLCount = 0;
try {
    $rtlCountSql = "
        SELECT COUNT(*)
        FROM referee_travel_log rtl
        JOIN matches m ON m.uuid = rtl.match_id
        JOIN teams   t ON t.uuid = m.home_team_id OR t.uuid = m.away_team_id
        WHERE t.club_id = ? AND m.match_date >= ?
    ";
    dbg('COUNT future referee_travel_log', ['sql'=>$rtlCountSql, 'params'=>[$clubUuid, $today]]);
    $rtlCountStmt = $pdo->prepare($rtlCountSql);
    $rtlCountStmt->execute([$clubUuid, $today]);
    $futureRTLCount = (int)$rtlCountStmt->fetchColumn();
} catch (Throwable $e) {
    dbg('referee_travel_log COUNT failed/absent', ['msg'=>$e->getMessage()]);
}

/* ---------- Build dynamic IN() safely for team UUIDs ---------- */
$makeIn = function(array $ids) {
    return count($ids) ? '(' . implode(',', array_fill(0, count($ids), '?')) . ')' : '(NULL)'; // (NULL) -> false
};
$dup = fn(array $a) => array_merge($a, $a); // for home OR away
$inTeams = $makeIn($teamUuids);

/* ---------- Count past/future matches for this club via team IN(...) ---------- */
$pastSql = "
    SELECT COUNT(*) FROM matches
    WHERE match_date < ?
      AND (home_team_id IN $inTeams OR away_team_id IN $inTeams)
";
$futureSql = "
    SELECT COUNT(*) FROM matches
    WHERE match_date >= ?
      AND (home_team_id IN $inTeams OR away_team_id IN $inTeams)
";
$pastParams   = array_merge([$today], $dup($teamUuids));
$futureParams = array_merge([$today], $dup($teamUuids));

dbg('COUNT past matches',   ['sql'=>$pastSql,   'params'=>$pastParams]);
$pastStmt = $pdo->prepare($pastSql);
$pastStmt->execute($pastParams);
$pastCount = (int)$pastStmt->fetchColumn();

dbg('COUNT future matches', ['sql'=>$futureSql, 'params'=>$futureParams]);
$futureStmt = $pdo->prepare($futureSql);
$futureStmt->execute($futureParams);
$futureCount = (int)$futureStmt->fetchColumn();

/* ---------- (Optional) Count future travel_logs (legacy/other table) ---------- */
$futureTravelCount = 0;
try {
    $travelSql = "
        SELECT COUNT(*) FROM travel_logs
        WHERE travel_date >= ?
          AND team_uuid IN $inTeams
    ";
    $travelParams = array_merge([$today], $teamUuids);
    dbg('COUNT future travel_logs', ['sql'=>$travelSql, 'params'=>$travelParams]);
    $travelStmt = $pdo->prepare($travelSql);
    $travelStmt->execute($travelParams);
    $futureTravelCount = (int)$travelStmt->fetchColumn();
} catch (Throwable $e) {
    dbg('TRAVEL_LOGS table missing/failed', ['msg'=>$e->getMessage()]);
}

dbg('COUNTS', [
    'past_matches'             => $pastCount,
    'future_matches'           => $futureCount,
    'future_travel_logs'       => $futureTravelCount,
    'future_ref_travel_logs'   => $futureRTLCount,
]);

$pdo->beginTransaction();

/* ---------- 1) Delete referee_travel_log for FUTURE matches first (FK -> matches) ---------- */
if ($futureRTLCount > 0) {
    $rtlDelSql = "
        DELETE rtl
        FROM referee_travel_log rtl
        JOIN matches m ON m.uuid = rtl.match_id
        JOIN teams   t ON t.uuid = m.home_team_id OR t.uuid = m.away_team_id
        WHERE t.club_id = ? AND m.match_date >= ?
    ";
    dbg('DELETE future referee_travel_log', ['sql'=>$rtlDelSql, 'params'=>[$clubUuid, $today]]);
    $pdo->prepare($rtlDelSql)->execute([$clubUuid, $today]);
}

/* ---------- 2) (Optional) Delete from travel_logs if present ---------- */
if ($futureTravelCount > 0) {
    try {
        $delTravelSql = "
            DELETE tl FROM travel_logs tl
            WHERE tl.travel_date >= ?
              AND tl.team_uuid IN $inTeams
        ";
        $delTravelParams = array_merge([$today], $teamUuids);
        dbg('DELETE future travel_logs', ['sql'=>$delTravelSql, 'params'=>$delTravelParams]);
        $pdo->prepare($delTravelSql)->execute($delTravelParams);
    } catch (Throwable $e) {
        dbg('DELETE travel_logs failed/absent (ignored)', ['msg'=>$e->getMessage()]);
    }
}

/* ---------- 3) Now delete the FUTURE matches ---------- */
if ($futureCount > 0) {
    $delFutureSql = "
        DELETE FROM matches
        WHERE match_date >= ?
          AND (home_team_id IN $inTeams OR away_team_id IN $inTeams)
    ";
    dbg('DELETE future matches', ['sql'=>$delFutureSql, 'params'=>$futureParams]);
    $pdo->prepare($delFutureSql)->execute($futureParams);
}

/* ---------- Archive or hard-delete ---------- */
if ($pastCount > 0) {
    dbg('ARCHIVE path');
    $sql = "UPDATE clubs SET active = 0 WHERE uuid = ?";
    dbg('SQL', ['sql'=>$sql, 'params'=>[$clubUuid]]);
    $pdo->prepare($sql)->execute([$clubUuid]);

    if ($teamUuids) {
        $sql = "UPDATE teams SET active = 0 WHERE uuid IN " . $makeIn($teamUuids);
        dbg('SQL', ['sql'=>$sql, 'params'=>$teamUuids]);
        $pdo->prepare($sql)->execute($teamUuids);
    }

    $pdo->commit();
    echo json_encode([
        'status'                         => 'success',
        'club_archived'                  => true,
        'club_deleted'                   => false,
        'future_matches_deleted'         => $futureCount,
        'future_travel_logs_deleted'     => $futureTravelCount + $futureRTLCount,
        'future_ref_travel_logs_deleted' => $futureRTLCount,
        'past_matches_retained'          => $pastCount,
        'error_id'                       => $REQ_ID,
        'message'                        => 'Club archived (historical matches exist). Future matches/logs removed.'
    ]);
    exit;
}

/* ---------- Hard delete (no historical matches) ---------- */
dbg('HARD DELETE path');
if ($teamUuids) {
    $sql = "DELETE FROM teams WHERE uuid IN " . $makeIn($teamUuids);
    dbg('SQL', ['sql'=>$sql, 'params'=>$teamUuids]);
    $pdo->prepare($sql)->execute($teamUuids);
}

$sql = "DELETE FROM clubs WHERE uuid = ?";
dbg('SQL', ['sql'=>$sql, 'params'=>[$clubUuid]]);
$pdo->prepare($sql)->execute([$clubUuid]);

/* Prune orphaned location */
$locationUuid = $clubRow['location_uuid'] ?? null;
if ($locationUuid) {
    $sql = "SELECT COUNT(*) FROM clubs WHERE location_uuid = ?";
    dbg('SQL', ['sql'=>$sql, 'params'=>[$locationUuid]]);
    $cntStmt = $pdo->prepare($sql);
    $cntStmt->execute([$locationUuid]);
    if ((int)$cntStmt->fetchColumn() === 0) {
        try {
            $sql = "DELETE FROM locations WHERE uuid = ?";
            dbg('SQL', ['sql'=>$sql, 'params'=>[$locationUuid]]);
            $pdo->prepare($sql)->execute([$locationUuid]);
        } catch (Throwable $e) {
            dbg('DELETE orphan location failed (ignored)', ['msg'=>$e->getMessage()]);
        }
    }
}

$pdo->commit();
dbg('SUCCESS');
echo json_encode([
    'status'                         => 'success',
    'club_deleted'                   => true,
    'club_archived'                  => false,
    'future_matches_deleted'         => $futureCount,
    'future_travel_logs_deleted'     => $futureTravelCount + $futureRTLCount,
    'future_ref_travel_logs_deleted' => $futureRTLCount,
    'error_id'                       => $REQ_ID,
    'message'                        => 'Club deleted (no historical matches).'
]);
