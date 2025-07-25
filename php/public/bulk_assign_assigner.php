<?php
require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';

$pdo = Database::getConnection();

if (!isset($_POST['match_ids']) || !is_array($_POST['match_ids'])) {
    die("No matches received.");
}

if (!isset($_POST['assigner_id']) || empty($_POST['assigner_id'])) {
    die("No assigner received.");
}

$match_ids = $_POST['match_ids'];
$assigner_id = $_POST['assigner_id'];

foreach ($match_ids as $match_id) {
    // Validate matchId
    if (empty($match_id)) {
        continue;
    }

    $sql = "UPDATE matches SET referee_assigner_uuid = ? WHERE uuid = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$assigner_id, $match_id]);
}

header("Location: matches.php?saved=1");
exit;
?>
