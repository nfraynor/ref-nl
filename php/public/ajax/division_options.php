<?php
require_once __DIR__ . '/../../utils/db.php';

$pdo = Database::getConnection();
$stmt = $pdo->query("SELECT DISTINCT division FROM matches ORDER BY division ASC");
$divisions = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($divisions as $division):
    $safe = htmlspecialchars($division);
    ?>
    <label class="form-check">
        <input type="checkbox" class="form-check-input division-filter-checkbox" value="<?= $safe ?>" <?= in_array($safe, ($_GET['division'] ?? [])) ? 'checked' : '' ?>>
        <?= $safe ?>
    </label>
<?php endforeach; ?>
