<?php
require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';
include 'includes/header.php';
include 'includes/nav.php';

$pdo = Database::getConnection();

// Flash message helper
$flash_msg = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : '';
$flash_err = isset($_GET['err']) && $_GET['err'] === '1';

// Fetch all divisions with their districts (grouped in PHP)
$sql = "
    SELECT 
        dv.id   AS division_id,
        dv.name AS division_name,
        di.id   AS district_id,
        di.name AS district_name
    FROM divisions dv
    LEFT JOIN division_districts dd ON dd.division_id = dv.id
    LEFT JOIN districts di          ON di.id = dd.district_id
    ORDER BY dv.name ASC, di.name ASC
";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Group
$divisions = [];
foreach ($rows as $r) {
    $dvid = (int)$r['division_id'];
    if (!isset($divisions[$dvid])) {
        $divisions[$dvid] = [
            'id' => $dvid,
            'name' => $r['division_name'],
            'districts' => []
        ];
    }
    if (!is_null($r['district_id'])) {
        $divisions[$dvid]['districts'][] = [
            'id' => (int)$r['district_id'],
            'name' => $r['district_name'],
        ];
    }
}

// Fetch all districts (for link-selects)
$allDistricts = $pdo->query("SELECT id, name FROM districts ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="container">
    <div class="content-card">
        <h1>Divisions & Districts</h1>

        <?php if ($flash_msg): ?>
            <div class="alert <?= $flash_err ? 'alert-danger' : 'alert-success' ?>" role="alert">
                <?= $flash_msg ?>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Add Division -->
            <div class="col-md-4">
                <div class="card mb-3">
                    <div class="card-header">Add Division</div>
                    <div class="card-body">
                        <form method="post" action="division_add.php">
                            <div class="mb-3">
                                <label for="divisionName" class="form-label">Division name</label>
                                <input type="text" class="form-control" id="divisionName" name="division_name" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Add Division</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Add District -->
            <div class="col-md-4">
                <div class="card mb-3">
                    <div class="card-header">Add District</div>
                    <div class="card-body">
                        <form method="post" action="district_add.php">
                            <div class="mb-3">
                                <label for="districtName" class="form-label">District name</label>
                                <input type="text" class="form-control" id="districtName" name="district_name" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Add District</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- (Optional) Link multiple at once? Keeping simple: per-division forms below -->
            <div class="col-md-4">
                <div class="alert alert-info mb-3">
                    Tip: Use the selector under each division to link an existing district. Use the trash icon to unlink.
                </div>
            </div>
        </div>

        <hr>

        <?php if (empty($divisions)): ?>
            <p class="text-muted">No divisions yet — add one using the form above.</p>
        <?php endif; ?>

        <?php foreach ($divisions as $dv): ?>
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong><?= htmlspecialchars($dv['name']) ?></strong>
                    <div>
                        <a class="btn btn-sm btn-outline-danger"
                           href="division_delete.php?id=<?= $dv['id'] ?>"
                           onclick="return confirm('Delete this division? (Mappings will be removed; this cannot be undone)');">
                            Delete Division
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Linked districts list -->
                    <?php if (!empty($dv['districts'])): ?>
                        <ul class="list-group mb-3">
                            <?php foreach ($dv['districts'] as $di): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <?= htmlspecialchars($di['name']) ?>
                                    <div class="btn-group">
                                        <a class="btn btn-sm btn-outline-danger"
                                           href="division_unlink_district.php?division_id=<?= $dv['id'] ?>&district_id=<?= $di['id'] ?>"
                                           onclick="return confirm('Unlink this district from the division?');">
                                            Unlink
                                        </a>
                                        <a class="btn btn-sm btn-outline-secondary"
                                           href="district_delete.php?id=<?= $di['id'] ?>"
                                           onclick="return confirm('Delete this district entirely? This removes it from all divisions.');">
                                            Delete District
                                        </a>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-muted">No districts linked yet.</p>
                    <?php endif; ?>

                    <!-- Link a district -->
                    <form class="row g-2" method="post" action="division_link_district.php">
                        <input type="hidden" name="division_id" value="<?= $dv['id'] ?>">
                        <div class="col-md-8">
                            <select class="form-select" name="district_id" required>
                                <option value="">Select district to link…</option>
                                <?php foreach ($allDistricts as $opt): ?>
                                    <option value="<?= (int)$opt['id'] ?>">
                                        <?= htmlspecialchars($opt['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-success w-100">Link District</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- Quick link to the plain Districts page you already have -->
        <a class="btn btn-link" href="districts.php">Open "Districts" table view</a>

    </div>
</div>
<?php include 'includes/footer.php'; ?>
