<?php
require_once __DIR__ . '/../../utils/db.php';

$pdo = Database::getConnection();
$selectedAssigners = $_GET['referee_assigner'] ?? [];

// Fetch unique referee assigners. Join with users table to get username.
// Filter out null or empty usernames.
$stmt = $pdo->query("
    SELECT DISTINCT
        u.uuid,
        u.username
    FROM users u
    JOIN matches m ON u.uuid = m.referee_assigner_uuid
    WHERE u.username IS NOT NULL AND u.username != ''
    ORDER BY u.username ASC
");
$assigners = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($assigners as $assigner) {
    $checked = in_array($assigner['uuid'], $selectedAssigners) ? 'checked' : '';
    echo '<label class="list-group-item">';
    echo '<input class="form-check-input me-1 referee-assigner-filter-checkbox" type="checkbox" value="' . htmlspecialchars($assigner['uuid']) . '" ' . $checked . '>';
    echo htmlspecialchars($assigner['username']);
    echo '</label>';
}
?>
