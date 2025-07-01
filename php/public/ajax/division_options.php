<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
error_log("[division_options.php] Script started. Session status: " . session_status());
require_once __DIR__ . '/../../utils/db.php';

$pdo = Database::getConnection();
error_log("[division_options.php] Session Data: User Role: " . ($_SESSION['user_role'] ?? 'N/A') .
            ", Division IDs: " . print_r(($_SESSION['division_ids'] ?? []), true));
$divisions = [];

$userRole = $_SESSION['user_role'] ?? null;
$userDivisionIds = $_SESSION['division_ids'] ?? [];

if ($userRole === 'super_admin') {
    $stmt = $pdo->query("SELECT DISTINCT division FROM matches WHERE division IS NOT NULL AND division != '' ORDER BY division ASC");
    $divisions = $stmt->fetchAll(PDO::FETCH_COLUMN);
} else {
    if (!empty($userDivisionIds) && !(count($userDivisionIds) === 1 && $userDivisionIds[0] === '')) {
        $placeholders = implode(',', array_fill(0, count($userDivisionIds), '?'));
        $stmt = $pdo->prepare("SELECT name FROM divisions WHERE id IN ($placeholders) ORDER BY name ASC");
        $stmt->execute($userDivisionIds);
        $divisions = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    // If $divisions is still empty here, the user has no specific division assignments or they are invalid.
    // No divisions will be shown, which is correct.
}

// Deduplicate division names before displaying
if (!empty($divisions)) {
    $divisions = array_unique($divisions);
}
error_log("[division_options.php] Divisions to be displayed (deduplicated): " . print_r($divisions, true));

if (empty($divisions)) {
    echo '<small class="text-muted">No division options available based on your permissions or current data.</small>';
} else {
    foreach ($divisions as $division):
        if (empty($division)) continue; // Skip empty division names if any
        $safe = htmlspecialchars($division);
        $isChecked = in_array($division, ($_GET['division'] ?? []));
    ?>
    <label class="form-check">
        <input type="checkbox" class="form-check-input division-filter-checkbox" value="<?= $safe ?>" <?= $isChecked ? 'checked' : '' ?>>
        <?= $safe ?>
    </label>
    <?php endforeach;
}
?>
