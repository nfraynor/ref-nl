<?php
require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';

$pdo = Database::getConnection();

$refereeId = $_POST['referee_id'] ?? '';
$availability = $_POST['availability'] ?? [];

if (!$refereeId) {
    die("Missing referee ID");
}

// Remove current entries
$pdo->prepare("DELETE FROM referee_weekly_availability WHERE referee_id = ?")->execute([$refereeId]);

// Insert new entries
$stmt = $pdo->prepare("
    INSERT INTO referee_weekly_availability 
    (uuid, referee_id, weekday, morning_available, afternoon_available, evening_available)
    VALUES (UUID(), :referee_id, :weekday, :morning, :afternoon, :evening)
");

foreach ($availability as $weekday => $slots) {
    $stmt->execute([
        'referee_id' => $refereeId,
        'weekday' => $weekday,
        'morning' => isset($slots['morning']) ? 1 : 0,
        'afternoon' => isset($slots['afternoon']) ? 1 : 0,
        'evening' => isset($slots['evening']) ? 1 : 0,
    ]);
}

header("Location: referee_detail.php?id=" . urlencode($_POST['referee_id']));
exit;
