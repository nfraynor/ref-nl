<?php
// File: php/public/clubs/delete_team.php
require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';

header('Content-Type: application/json');

if (($_SESSION['user_role'] ?? null) !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']); exit;
}

$teamUuid = $_POST['team_uuid'] ?? '';
if ($teamUuid === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Team is required.']); exit;
}

try {
    $pdo = Database::getConnection();
    $pdo->beginTransaction();

    // Ensure team exists
    $chk = $pdo->prepare("SELECT 1 FROM teams WHERE uuid = ?");
    $chk->execute([$teamUuid]);
    if (!$chk->fetchColumn()) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Team not found']); exit;
    }

    // Separate FUTURE vs PAST matches using match_date + kickoff_time
    $futureStmt = $pdo->prepare("
        SELECT uuid FROM matches
         WHERE (home_team_id = ? OR away_team_id = ?)
           AND (match_date > CURDATE()
                OR (match_date = CURDATE() AND kickoff_time > CURTIME()))
    ");
    $futureStmt->execute([$teamUuid, $teamUuid]);
    $futureMatchIds = $futureStmt->fetchAll(PDO::FETCH_COLUMN);

    $pastStmt = $pdo->prepare("
        SELECT uuid FROM matches
         WHERE (home_team_id = ? OR away_team_id = ?)
           AND (match_date < CURDATE()
                OR (match_date = CURDATE() AND kickoff_time <= CURTIME()))
    ");
    $pastStmt->execute([$teamUuid, $teamUuid]);
    $pastMatchIds = $pastStmt->fetchAll(PDO::FETCH_COLUMN);

    $deletedTravel = 0;
    $deletedFutureMatches = 0;

    // Nuke travel logs + FUTURE matches only
    if (!empty($futureMatchIds)) {
        $chunkSize = 200;
        for ($i = 0; $i < count($futureMatchIds); $i += $chunkSize) {
            $chunk = array_slice($futureMatchIds, $i, $chunkSize);
            $in = implode(',', array_fill(0, count($chunk), '?'));

            $delTrav = $pdo->prepare("DELETE FROM referee_travel_log WHERE match_id IN ($in)");
            $delTrav->execute($chunk);
            $deletedTravel += $delTrav->rowCount();

            $delMatch = $pdo->prepare("DELETE FROM matches WHERE uuid IN ($in)");
            $delMatch->execute($chunk);
            $deletedFutureMatches += $delMatch->rowCount();
        }
    }

    $teamDeleted = false;
    $teamArchived = false;

    if (empty($pastMatchIds)) {
        // Safe to delete team entirely
        $delTeam = $pdo->prepare("DELETE FROM teams WHERE uuid = ?");
        $delTeam->execute([$teamUuid]);
        $teamDeleted = $delTeam->rowCount() > 0;
    } else {
        // Preserve historical integrity: keep team but mark inactive
        $arch = $pdo->prepare("UPDATE teams SET active = FALSE WHERE uuid = ?");
        $arch->execute([$teamUuid]);
        $teamArchived = true;
    }

    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'team_deleted' => $teamDeleted,
        'team_archived' => $teamArchived,
        'future_matches_deleted' => $deletedFutureMatches,
        'future_travel_logs_deleted' => $deletedTravel,
        'past_matches_retained' => count($pastMatchIds),
    ]);
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}
