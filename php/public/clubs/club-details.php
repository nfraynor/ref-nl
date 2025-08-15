<?php
// File: php/public/clubs/club-details.php
require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';

include '../includes/header.php';
include '../includes/nav.php';

$pdo = Database::getConnection();

// Helpers
function safe($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function url_with_scheme($url) {
    if (!$url) return null;
    return preg_match('~^https?://~i', $url) ? $url : ('https://' . $url);
}

$clubUuid = $_GET['id'] ?? null;
if (!$clubUuid) {
    echo '<div class="container"><div class="content-card"><p>Club ID is missing.</p></div></div>';
    include '../includes/footer.php'; exit;
}

// Fetch club w/ linked location
$stmt = $pdo->prepare("
    SELECT
        c.uuid, c.club_id, c.club_number, c.club_name,
        c.primary_contact_name, c.primary_contact_email, c.primary_contact_phone,
        c.website_url, c.notes, c.active,
        l.uuid AS location_uuid, l.name AS field_name, l.address_text,
        l.latitude AS lat, l.longitude AS lon
    FROM clubs c
    LEFT JOIN locations l ON c.location_uuid = l.uuid
    WHERE c.uuid = ?
");
$stmt->execute([$clubUuid]);
$club = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$club) {
    echo '<div class="container"><div class="content-card"><p>Club not found.</p></div></div>';
    include '../includes/footer.php'; exit;
}

// Teams for this club
$teamsStmt = $pdo->prepare("
    SELECT uuid, team_name, division
    FROM teams
    WHERE club_id = ?
    ORDER BY team_name ASC
");
$teamsStmt->execute([$clubUuid]);
$teams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);

// Derived
$maps_q = (!empty($club['lat']) && !empty($club['lon'])) ? ($club['lat'] . ',' . $club['lon']) : null;
$maps_url = $maps_q ? ('https://maps.google.com/?q=' . rawurlencode($maps_q)) : null;
$website = url_with_scheme($club['website_url'] ?? null);
?>
<style>
    .kv-label { min-width: 140px; color: #6c757d; }
    .kv-pair { display:flex; gap:0.75rem; padding:0.25rem 0; align-items:center; }
    .soft-card { border-radius: 0.75rem; }
    .mono { font-family: ui-monospace,SFMono-Regular,Menlo,Monaco,"Liberation Mono","Courier New",monospace; }
    .truncate { max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>

<div class="container">
    <div class="content-card soft-card p-3 p-md-4">
        <!-- Header -->
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mb-3">
            <div>
                <h1 class="mb-1"><?= safe($club['club_name']) ?></h1>
                <div class="text-muted">
                    <?= safe($club['club_id'] ?? '') ?>
                    <?php if (!empty($club['club_number'])): ?>
                        · <span class="mono">#<?= (int)$club['club_number'] ?></span>
                    <?php endif; ?>
                    · <?php if (!empty($club['active'])): ?>
                        <span class="badge bg-success"><i class="bi bi-check2-circle me-1"></i>Active</span>
                    <?php else: ?>
                        <span class="badge bg-secondary"><i class="bi bi-slash-circle me-1"></i>Inactive</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-secondary" href="../clubs.php"><i class="bi bi-arrow-left me-1"></i>Back to Clubs</a>
                <?php if ($website): ?>
                    <a class="btn btn-outline-primary" href="<?= safe($website) ?>" target="_blank" rel="noopener">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Website
                    </a>
                <?php endif; ?>
                <?php if ($maps_url): ?>
                    <a class="btn btn-primary" href="<?= safe($maps_url) ?>" target="_blank" rel="noopener">
                        <i class="bi bi-geo-alt me-1"></i>Open in Maps
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Main content grid -->
        <div class="row g-3">
            <!-- Left column -->
            <div class="col-12 col-lg-7">
                <!-- Location card -->
                <div class="card soft-card h-100">
                    <div class="card-header bg-white"><strong><i class="bi bi-pin-map me-2"></i>Field & Address</strong></div>
                    <div class="card-body">
                        <div class="kv-pair">
                            <div class="kv-label">Field</div>
                            <div class="flex-grow-1"><?= $club['field_name'] ? safe($club['field_name']) : '—' ?></div>
                        </div>
                        <div class="kv-pair">
                            <div class="kv-label">Address</div>
                            <div class="flex-grow-1"><?= $club['address_text'] ? safe($club['address_text']) : '—' ?></div>
                        </div>
                        <div class="kv-pair align-items-center">
                            <div class="kv-label">Coordinates</div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="mono"><?= ($club['lat'] !== null && $club['lon'] !== null) ? safe($club['lat'] . ', ' . $club['lon']) : '—' ?></span>
                                <?php if ($maps_url): ?>
                                    <button class="btn btn-sm btn-outline-secondary" id="copyCoordsBtn" type="button" title="Copy">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($maps_url): ?>
                            <div class="mt-2">
                                <small class="text-muted">Tip: use the buttons above to open the location or copy coordinates.</small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right column -->
            <div class="col-12 col-lg-5">
                <!-- Contact card -->
                <div class="card soft-card mb-3">
                    <div class="card-header bg-white"><strong><i class="bi bi-person-lines-fill me-2"></i>Primary Contact</strong></div>
                    <div class="card-body">
                        <div class="kv-pair">
                            <div class="kv-label">Name</div>
                            <div class="flex-grow-1"><?= $club['primary_contact_name'] ? safe($club['primary_contact_name']) : '—' ?></div>
                        </div>
                        <div class="kv-pair">
                            <div class="kv-label">Email</div>
                            <div class="flex-grow-1">
                                <?php if (!empty($club['primary_contact_email'])): ?>
                                    <a href="mailto:<?= safe($club['primary_contact_email']) ?>" class="truncate">
                                        <i class="bi bi-envelope me-1"></i><?= safe($club['primary_contact_email']) ?>
                                    </a>
                                <?php else: ?>—<?php endif; ?>
                            </div>
                        </div>
                        <div class="kv-pair">
                            <div class="kv-label">Phone</div>
                            <div class="flex-grow-1">
                                <?php if (!empty($club['primary_contact_phone'])): ?>
                                    <a href="tel:<?= safe($club['primary_contact_phone']) ?>">
                                        <i class="bi bi-telephone me-1"></i><?= safe($club['primary_contact_phone']) ?>
                                    </a>
                                <?php else: ?>—<?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Meta card -->
                <div class="card soft-card">
                    <div class="card-header bg-white"><strong><i class="bi bi-info-circle me-2"></i>Club Meta</strong></div>
                    <div class="card-body">
                        <div class="kv-pair">
                            <div class="kv-label">Club Code</div>
                            <div class="flex-grow-1 mono"><?= safe($club['club_id'] ?? '—') ?></div>
                        </div>
                        <div class="kv-pair">
                            <div class="kv-label">Internal #</div>
                            <div class="flex-grow-1 mono"><?= $club['club_number'] ? (int)$club['club_number'] : '—' ?></div>
                        </div>
                        <div class="kv-pair">
                            <div class="kv-label">Status</div>
                            <div class="flex-grow-1">
                                <?php if (!empty($club['active'])): ?>
                                    <span class="badge bg-success"><i class="bi bi-check2 me-1"></i>Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><i class="bi bi-slash-circle me-1"></i>Inactive</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="kv-pair">
                            <div class="kv-label">Website</div>
                            <div class="flex-grow-1">
                                <?php if ($website): ?>
                                    <a href="<?= safe($website) ?>" target="_blank" rel="noopener" class="truncate">
                                        <i class="bi bi-link-45deg me-1"></i><?= safe($club['website_url']) ?>
                                    </a>
                                <?php else: ?>—<?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Notes full width -->
            <div class="col-12">
                <div class="card soft-card">
                    <div class="card-header bg-white d-flex align-items-center justify-content-between">
                        <strong><i class="bi bi-journal-text me-2"></i>Notes</strong>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($club['notes'])): ?>
                            <pre class="mb-0" style="white-space: pre-wrap;"><?= safe($club['notes']) ?></pre>
                        <?php else: ?>
                            <span class="text-muted">No notes yet.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Teams list -->
            <div class="col-12">
                <div class="card soft-card">
                    <div class="card-header bg-white"><strong><i class="bi bi-people me-2"></i>Teams</strong></div>
                    <div class="card-body">
                        <?php if ($teams): ?>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead>
                                    <tr>
                                        <th>Team</th>
                                        <th>Division</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($teams as $t): ?>
                                        <tr>
                                            <td><?= safe($t['team_name']) ?></td>
                                            <td><?= safe($t['division'] ?? '—') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <span class="text-muted">No teams found for this club.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div> <!-- /row -->
    </div> <!-- /content-card -->
</div> <!-- /container -->

<script>
    document.addEventListener('DOMContentLoaded', () => {
        // Enable Bootstrap tooltips if available
        if (window.bootstrap) {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
        }
        // Copy coordinates
        const btn = document.getElementById('copyCoordsBtn');
        <?php if ($maps_q): ?>
        const coords = <?= json_encode($maps_q) ?>;
        <?php else: ?>
        const coords = null;
        <?php endif; ?>
        if (btn && coords) {
            btn.addEventListener('click', async () => {
                try {
                    await navigator.clipboard.writeText(coords);
                    btn.innerHTML = '<i class="bi bi-clipboard-check"></i>';
                    setTimeout(()=> btn.innerHTML = '<i class="bi bi-clipboard"></i>', 1500);
                } catch (e) {
                    console.error(e);
                }
            });
        }
    });
</script>

<?php include '../includes/footer.php'; ?>
