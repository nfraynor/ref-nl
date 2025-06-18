<?php
require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';

$pdo = Database::getConnection();

if (!isset($_POST['assignments']) || !is_array($_POST['assignments'])) {
    die("No assignments received.");
}

$assignments = $_POST['assignments'];

foreach ($assignments as $matchId => $roles) {

    // Validate matchId
    if (empty($matchId)) {
        continue;
    }

    // Build assignment update dynamically
    $fields = [];
    $values = [];

    foreach ($roles as $role => $refereeId) {

        if (!in_array($role, ['referee_id', 'ar1_id', 'ar2_id', 'commissioner_id'])) {
            continue;
        }

        if (empty($refereeId)) {
            $fields[] = "$role = NULL";
        } else {
            $fields[] = "$role = ?";
            $values[] = $refereeId;
        }
    }

    if (!empty($fields)) {
        $sql = "UPDATE matches SET " . implode(", ", $fields) . " WHERE uuid = ?";
        $values[] = $matchId;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
    }
}

header("Location: matches.php?saved=1");
exit;
?>
