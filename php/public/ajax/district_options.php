<?php
require_once __DIR__ . '/../../utils/db.php';

$pdo = Database::getConnection();
$selectedDistricts = $_GET['district'] ?? [];

// Fetch all districts
$stmt = $pdo->query("SELECT id, name FROM districts ORDER BY name ASC");
$districts = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($districts as $district) {
    $checked = in_array($district['id'], $selectedDistricts) ? 'checked' : '';
    $displayName = htmlspecialchars($district['name']);

    echo '<label class="list-group-item">';
    echo '<input class="form-check-input me-1 district-filter-checkbox" type="checkbox" value="' . htmlspecialchars($district['id']) . '" ' . $checked . '>';
    echo $displayName;
    echo '</label>';
}
?>
