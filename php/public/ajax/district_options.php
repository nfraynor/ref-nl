<?php
require_once __DIR__ . '/../../utils/db.php';

$pdo = Database::getConnection();
$stmt = $pdo->query("SELECT DISTINCT district FROM matches ORDER BY district ASC");
$districts = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($districts as $district):
    $safe = htmlspecialchars($district);
    ?>
    <label class="form-check">
        <input type="checkbox" class="form-check-input district-filter-checkbox" value="<?= $safe ?>" <?= in_array($safe, ($_GET['division'] ?? [])) ? 'checked' : '' ?>>
        <?= $safe ?>
    </label>
<?php endforeach; ?>
