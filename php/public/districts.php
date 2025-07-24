<?php
require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';
include 'includes/header.php';
include 'includes/nav.php';

$pdo = Database::getConnection();

// Fetch districts
$districts = $pdo->query("
    SELECT
        id,
        name
    FROM districts
    ORDER BY name
")->fetchAll();
?>
<div class="container">
    <div class="content-card">
        <h1>Districts</h1>

        <div class="add-district-form">
            <form action="add_district.php" method="post">
                <div class="form-group">
                    <label for="districtName">District Name</label>
                    <input type="text" class="form-control" id="districtName" name="districtName" required>
                </div>
                <button type="submit" class="btn btn-primary">Add District</button>
            </form>
        </div>
        <hr>
        <table class="table table-bordered table-striped">
            <thead>
    <tr>
        <th>District ID</th>
        <th>District Name</th>
        <th>Actions</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($districts as $district): ?>
        <tr>
            <td><?= htmlspecialchars($district['id']) ?></td>
            <td><?= htmlspecialchars($district['name']) ?></td>
            <td>
                <a href="edit_district.php?id=<?= $district['id'] ?>" class="btn btn-primary btn-sm">Edit</a>
                <a href="remove_district.php?id=<?= $district['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this district?');">Remove</a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
    </div> <!-- close content-card -->
</div> <!-- close container -->
<?php include 'includes/footer.php'; ?>
