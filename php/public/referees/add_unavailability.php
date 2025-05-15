<?php
require_once __DIR__ . '/../../utils/db.php';

$pdo = Database::getConnection();

$refereeUuid = $_POST['referee_id'] ?? '';
$startDate = $_POST['start_date'] ?? '';
$endDate = $_POST['end_date'] ?? '';
$reason = $_POST['reason'] ?? '';

if ($refereeUuid && $startDate && $endDate) {
    $stmt = $pdo->prepare("
        INSERT INTO referee_unavailability (uuid, referee_id, start_date, end_date, reason)
        VALUES (UUID(), ?, ?, ?, ?)
    ");
    $stmt->execute([$refereeUuid, $startDate, $endDate, $reason]);
}

header("Location: referee_detail.php?id=" . urlencode($_GET['referee_id']));
exit;
