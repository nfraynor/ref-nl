<?php
require_once __DIR__ . '/../../utils/db.php';

$pdo = Database::getConnection();
$stmt = $pdo->query("SELECT DISTINCT poule FROM matches ORDER BY poule ASC");
$poules = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($poules as $poule):
    $safe = htmlspecialchars($poule);
    ?>
    <label class="form-check">
        <input type="checkbox" class="form-check-input poule-filter-checkbox" value="<?= $safe ?>" <?= in_array($safe, ($_GET['poule'] ?? [])) ? 'checked' : '' ?>>
        <?= $safe ?>
    </label>
<?php endforeach; ?>
