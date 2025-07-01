<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
error_log("[district_options.php] Script started. Session status: " . session_status());
require_once __DIR__ . '/../../utils/db.php';

$pdo = Database::getConnection();
error_log("[district_options.php] Session Data: User Role: " . ($_SESSION['user_role'] ?? 'N/A') .
            ", District IDs: " . print_r(($_SESSION['district_ids'] ?? []), true));
$districts = [];

$userRole = $_SESSION['user_role'] ?? null;
$userDistrictIds = $_SESSION['district_ids'] ?? [];
// Optional: Consider user's allowed divisions if districts should be filtered by them too.
// For now, just using direct district permissions as per current session structure.
// $userDivisionIds = $_SESSION['division_ids'] ?? [];

if ($userRole === 'super_admin') {
    $stmt = $pdo->query("SELECT DISTINCT district FROM matches WHERE district IS NOT NULL AND district != '' ORDER BY district ASC");
    $districts = $stmt->fetchAll(PDO::FETCH_COLUMN);
} else {
    if (!empty($userDistrictIds) && !(count($userDistrictIds) === 1 && $userDistrictIds[0] === '')) {
        // Fetch names of districts the user is directly permitted for
        $placeholders = implode(',', array_fill(0, count($userDistrictIds), '?'));
        $stmt = $pdo->prepare("SELECT name FROM districts WHERE id IN ($placeholders) ORDER BY name ASC");
        $stmt->execute($userDistrictIds);
        $districts = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Alternative/Further refinement: If districts should be constrained by user's allowed divisions.
        // This would require $userDivisionIds to be valid and a more complex query,
        // e.g., SELECT d.name FROM districts d JOIN user_permissions up ON d.id = up.district_id
        // WHERE up.user_id = :user_id AND d.division_id IN (user's_allowed_division_ids_placeholders)
        // For now, sticking to simpler direct district name fetching based on $userDistrictIds.
    }
    // If $districts is still empty, user has no specific district assignments or they are invalid.
}
error_log("[district_options.php] Districts to be displayed: " . print_r($districts, true));

if (empty($districts)) {
    echo '<small class="text-muted">No district options available based on your permissions or current data.</small>';
} else {
    foreach ($districts as $district):
        if (empty($district)) continue; // Skip empty district names
        $safe = htmlspecialchars($district);
        // Corrected bug: use $_GET['district'] for checking selected districts
        $isChecked = in_array($district, ($_GET['district'] ?? []));
    ?>
    <label class="form-check">
        <input type="checkbox" class="form-check-input district-filter-checkbox" value="<?= $safe ?>" <?= $isChecked ? 'checked' : '' ?>>
        <?= $safe ?>
    </label>
    <?php endforeach;
}
?>
