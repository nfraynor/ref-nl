<?php
require_once __DIR__ . '/../../utils/db.php';

$pdo = Database::getConnection();
$selectedLocations = $_GET['location'] ?? [];

// Fetch unique locations used in matches.
// Filter out null or empty names.
$stmt = $pdo->query("
    SELECT DISTINCT
        l.uuid,
        l.name,
        l.address_text
    FROM locations l
    JOIN matches m ON l.uuid = m.location_uuid
    WHERE l.name IS NOT NULL AND l.name != ''
    ORDER BY l.name ASC
");
$locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($locations as $location) {
    $checked = in_array($location['uuid'], $selectedLocations) ? 'checked' : '';
    $displayName = htmlspecialchars($location['name']);
    if (!empty($location['address_text']) && $location['name'] !== $location['address_text']) {
        $displayName .= ' <small>(' . htmlspecialchars($location['address_text']) . ')</small>';
    }

    echo '<label class="list-group-item">';
    echo '<input class="form-check-input me-1 location-filter-checkbox" type="checkbox" value="' . htmlspecialchars($location['uuid']) . '" ' . $checked . '>';
    echo $displayName;
    echo '</label>';
}
?>
