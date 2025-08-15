<?php
require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';
include 'includes/header.php';
include 'includes/nav.php';

$pdo = Database::getConnection();

$isSuperAdmin = ($_SESSION['user_role'] ?? null) === 'super_admin';

// Fetch clubs
$clubs = $pdo->query("
    SELECT 
        c.uuid,
        c.club_id,
        c.club_name,
        l.name       AS field_name,
        l.address_text,
        c.active
    FROM clubs c
    LEFT JOIN locations l ON c.location_uuid = l.uuid
    ORDER BY c.club_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

function safe($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<div class="container">
    <div class="content-card">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <h1 class="mb-0">Clubs</h1>
            <?php if ($isSuperAdmin): ?>
                <button class="btn btn-primary" id="openAddClub">
                    <i class="bi bi-plus-lg me-1"></i>Add Club
                </button>
            <?php endif; ?>
        </div>

        <table class="table table-bordered table-striped">
            <thead>
            <tr>
                <th>Club ID</th>
                <th>Club Name</th>
                <th>Field</th>
                <th>Address</th>
            </tr>
            </thead>
            <tbody id="clubsTbody">
            <?php foreach ($clubs as $club): ?>
                <tr>
                    <td><?= safe($club['club_id']) ?></td>
                    <td>
                        <a href="clubs/club-details.php?id=<?= safe($club['uuid']) ?>">
                            <?= safe($club['club_name']) ?>
                        </a>
                    </td>
                    <td><?= safe($club['field_name'] ?? '—') ?></td>
                    <td><?= safe($club['address_text'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($isSuperAdmin): ?>
    <!-- Add Club Modal (Name required, no location field) -->
    <div class="modal fade" id="addClubModal" tabindex="-1" aria-labelledby="addClubLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addClubLabel">Add New Club</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="addClubMsg" class="mb-2"></div>

                    <div class="mb-3">
                        <label for="clubNameInput" class="form-label">
                            Club Name <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="clubNameInput" maxlength="255" placeholder="e.g., Rotterdam RFC" required>
                        <div class="invalid-feedback">Club name is required.</div>
                    </div>

                    <small class="text-muted">You can add field/address and contacts on the club page after creating.</small>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" id="saveClubBtn">Create Club</button>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<script src="js/club.js"></script>

<?php include 'includes/footer.php'; ?>
