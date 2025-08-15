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

// Fetch club with linked location
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
    SELECT t.uuid, t.team_name, t.division, t.district_id, t.active, d.name AS district_name
      FROM teams t
      LEFT JOIN districts d ON t.district_id = d.id
     WHERE t.club_id = ?
     ORDER BY t.team_name ASC
");
$teamsStmt->execute([$clubUuid]);
$teams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);

// Derived links: prefer address for Maps if present
$maps_q = !empty($club['address_text'])
    ? $club['address_text']
    : ((isset($club['lat'], $club['lon']) && $club['lat'] !== null && $club['lon'] !== null) ? ($club['lat'] . ',' . $club['lon']) : null);
$maps_url = $maps_q ? ('https://maps.google.com/?q=' . rawurlencode($maps_q)) : null;
$website  = url_with_scheme($club['website_url'] ?? null);

// Vars for JS
$locationUuid = $club['location_uuid'] ?? null;

$isSuperAdmin = ($_SESSION['user_role'] ?? null) === 'super_admin';

// Preload dropdown data
$districts = $pdo->query("SELECT id, name FROM districts ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Build divisions from both matches and teams to be comprehensive
$divisions = $pdo->query("
    SELECT division FROM (
        SELECT DISTINCT division FROM matches WHERE division IS NOT NULL AND division <> ''
        UNION
        SELECT DISTINCT division FROM teams   WHERE division IS NOT NULL AND division <> ''
    ) AS d
    ORDER BY division ASC
")->fetchAll(PDO::FETCH_COLUMN);

?>
<style>
    .kv-label { min-width: 140px; color: #6c757d; }
    .kv-pair { display:flex; gap:0.75rem; padding:0.25rem 0; align-items:center; }
    .soft-card { border-radius: 0.75rem; }
    .mono { font-family: ui-monospace,SFMono-Regular,Menlo,Monaco,"Liberation Mono","Courier New",monospace; }
    .truncate { max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .btn-icon { padding: .125rem .375rem; }
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
                    <a class="btn btn-primary" id="openMapsBtn"
                       href="<?= safe($maps_url ?? '#') ?>"
                       target="_blank" rel="noopener"
                       style="<?= $maps_url ? '' : 'display:none;' ?>">
                        <i class="bi bi-geo-alt me-1"></i>Open in Maps
                    </a>

                <?php endif; ?>
            </div>
        </div>

        <div id="clubMsg" class="mb-3"></div>

        <!-- Main content grid -->
        <div class="row g-3">
            <!-- Left column -->
            <div class="col-12 col-lg-7">
                <!-- Location card -->
                <div class="card soft-card h-100">
                    <div class="card-header bg-white d-flex align-items-center justify-content-between">
                        <strong><i class="bi bi-pin-map me-2"></i>Field & Address</strong>
                        <?php if (($_SESSION['user_role'] ?? null) === 'super_admin'): ?>
                            <button class="btn btn-sm btn-primary" id="manageLocationBtn" type="button">
                                <i class="bi bi-geo me-1"></i>Manage Location
                            </button>
                        <?php endif; ?>
                    </div>

                    <div class="card-body">
                        <div class="kv-pair">
                            <div class="kv-label">Field</div>
                            <div class="flex-grow-1"><?= $club['field_name'] ? safe($club['field_name']) : '—' ?></div>
                        </div>

                        <!-- Address display -->
                        <div class="kv-pair" id="addressRowDisplay">
                            <div class="kv-label">Address</div>
                            <div class="flex-grow-1 d-flex align-items-center gap-2">
                                <span id="addressText"><?= $club['address_text'] ? safe($club['address_text']) : '—' ?></span>
                                <?php if (!empty($club['address_text'])): ?>
                                    <button class="btn btn-sm btn-outline-secondary btn-icon" id="copyAddressBtn" type="button" title="Copy Address">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Address editor -->
                        <div class="kv-pair" id="addressRowEdit" style="display:none;">
                            <div class="kv-label">Address</div>
                            <div class="flex-grow-1">
                                <div class="input-group">
                                    <input type="text" class="form-control" id="addressInput" placeholder="e.g., Sportpark Wouterland, Example City"
                                           value="<?= safe($club['address_text'] ?? '') ?>" maxlength="255">
                                    <button class="btn btn-primary" id="saveAddressBtn" type="button">
                                        <i class="bi bi-check-lg me-1"></i>Save
                                    </button>
                                    <button class="btn btn-outline-secondary" id="cancelAddressBtn" type="button">
                                        <i class="bi bi-x-lg me-1"></i>Cancel
                                    </button>
                                </div>
                                <small class="text-muted">A human-friendly address will be used for the Maps link.</small>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Right column -->
            <div class="col-12 col-lg-5">
                <!-- Contact card -->
                <div class="card soft-card mb-3">
                    <div class="card-header bg-white d-flex align-items-center justify-content-between">
                        <strong><i class="bi bi-person-lines-fill me-2"></i>Primary Contact</strong>
                        <div class="d-flex align-items-center gap-1">
                            <button class="btn btn-sm btn-outline-secondary btn-icon" id="editContactBtn" type="button" title="Edit Contact">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Display rows -->
                        <div id="contactDisplay">
                            <div class="kv-pair">
                                <div class="kv-label">Name</div>
                                <div class="flex-grow-1" id="contactNameText"><?= $club['primary_contact_name'] ? safe($club['primary_contact_name']) : '—' ?></div>
                            </div>
                            <div class="kv-pair">
                                <div class="kv-label">Email</div>
                                <div class="flex-grow-1" id="contactEmailText">
                                    <?php if (!empty($club['primary_contact_email'])): ?>
                                        <a href="mailto:<?= safe($club['primary_contact_email']) ?>" class="truncate">
                                            <i class="bi bi-envelope me-1"></i><?= safe($club['primary_contact_email']) ?>
                                        </a>
                                    <?php else: ?>—<?php endif; ?>
                                </div>
                            </div>
                            <div class="kv-pair">
                                <div class="kv-label">Phone</div>
                                <div class="flex-grow-1" id="contactPhoneText">
                                    <?php if (!empty($club['primary_contact_phone'])): ?>
                                        <a href="tel:<?= safe($club['primary_contact_phone']) ?>">
                                            <i class="bi bi-telephone me-1"></i><?= safe($club['primary_contact_phone']) ?>
                                        </a>
                                    <?php else: ?>—<?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Edit form -->
                        <div id="contactEdit" style="display:none;">
                            <div class="mb-2">
                                <label for="contactNameInput" class="form-label mb-1">Name</label>
                                <input type="text" class="form-control" id="contactNameInput"
                                       value="<?= safe($club['primary_contact_name'] ?? '') ?>" maxlength="255" placeholder="Full name">
                            </div>
                            <div class="mb-2">
                                <label for="contactEmailInput" class="form-label mb-1">Email</label>
                                <input type="email" class="form-control" id="contactEmailInput"
                                       value="<?= safe($club['primary_contact_email'] ?? '') ?>" maxlength="255" placeholder="name@example.com">
                            </div>
                            <div class="mb-3">
                                <label for="contactPhoneInput" class="form-label mb-1">Phone</label>
                                <input type="text" class="form-control" id="contactPhoneInput"
                                       value="<?= safe($club['primary_contact_phone'] ?? '') ?>" maxlength="50" placeholder="+31 6 1234 5678">
                                <small class="text-muted">Use digits, spaces, +, -, ( ).</small>
                            </div>
                            <div class="d-flex gap-2">
                                <button class="btn btn-primary" id="saveContactBtn" type="button">
                                    <i class="bi bi-check-lg me-1"></i>Save
                                </button>
                                <button class="btn btn-outline-secondary" id="cancelContactBtn" type="button">
                                    <i class="bi bi-x-lg me-1"></i>Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Teams -->
            <div class="col-12">
                <div class="card soft-card">
                    <div class="card-header bg-white d-flex align-items-center justify-content-between">
                        <strong><i class="bi bi-people me-2"></i>Teams</strong>
                        <?php if ($isSuperAdmin): ?>
                            <button class="btn btn-sm btn-primary" id="addTeamBtn">
                                <i class="bi bi-plus-lg me-1"></i>Add New Team
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                <tr>
                                    <th>Team</th>
                                    <th>District</th>
                                    <th>Division</th>
                                    <?php if ($isSuperAdmin): ?><th style="width:160px;">Actions</th><?php endif; ?>
                                </tr>
                                </thead>
                                <tbody id="teamsTableBody">
                                <?php if (!empty($teams)): ?>
                                    <?php foreach ($teams as $t): ?>
                                        <tr
                                                data-team-uuid="<?= safe($t['uuid']) ?>"
                                                data-team-name="<?= safe($t['team_name']) ?>"
                                                data-division="<?= safe($t['division'] ?? '') ?>"
                                                data-district-id="<?= (int)($t['district_id'] ?? 0) ?>"
                                                data-active="<?= !empty($t['active']) ? '1' : '0' ?>"
                                        >
                                            <td>
                                                <?= safe($t['team_name']) ?>
                                                <?php if (empty($t['active'])): ?>
                                                    <span class="badge bg-secondary ms-2">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= safe($t['district_name'] ?? '—') ?></td>
                                            <td><?= safe($t['division'] ?? '—') ?></td>
                                            <?php if ($isSuperAdmin): ?>
                                                <td class="text-nowrap">
                                                    <button class="btn btn-sm btn-outline-secondary me-2 edit-team">
                                                        <i class="bi bi-pencil-square me-1"></i>Edit
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-danger delete-team">
                                                        <i class="bi bi-trash me-1"></i>Delete
                                                    </button>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr id="noTeamsRow">
                                        <td colspan="<?= $isSuperAdmin ? 4 : 3 ?>">
                                            <span class="text-muted">No teams found for this club.</span>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>


            <!-- Notes -->
            <div class="col-12">
                <div class="card soft-card">
                    <div class="card-header bg-white d-flex align-items-center justify-content-between">
                        <strong><i class="bi bi-journal-text me-2"></i>Notes</strong>
                        <div class="d-flex align-items-center gap-1">
                            <button class="btn btn-sm btn-outline-secondary btn-icon" id="editNotesBtn" type="button" title="Edit Notes">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Display -->
                        <div id="notesDisplay">
                            <?php if (!empty($club['notes'])): ?>
                                <pre id="notesPre" class="mb-0" style="white-space: pre-wrap;"><?= safe($club['notes']) ?></pre>
                            <?php else: ?>
                                <span id="notesEmpty" class="text-muted">No notes yet.</span>
                            <?php endif; ?>
                        </div>

                        <!-- Editor -->
                        <div id="notesEdit" style="display:none;">
                            <label for="notesTextarea" class="form-label mb-2">Notes</label>
                            <textarea id="notesTextarea" class="form-control" rows="6" maxlength="65535"
                                      placeholder="Add notes for this club..."><?= safe($club['notes'] ?? '') ?></textarea>
                            <div class="mt-2 d-flex gap-2">
                                <button class="btn btn-primary" id="saveNotesBtn" type="button">
                                    <i class="bi bi-check-lg me-1"></i>Save
                                </button>
                                <button class="btn btn-outline-secondary" id="cancelNotesBtn" type="button">
                                    <i class="bi bi-x-lg me-1"></i>Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div> <!-- /row -->
    </div> <!-- /content-card -->

    <?php if (($_SESSION['user_role'] ?? null) === 'super_admin'): ?>
        <div class="modal fade" id="manageLocationModal" tabindex="-1" aria-labelledby="manageLocationLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="manageLocationLabel">Manage Location</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <ul class="nav nav-tabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="tab-edit-current" data-bs-toggle="tab" data-bs-target="#pane-edit-current" type="button" role="tab">
                                    Edit Current
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="tab-use-existing" data-bs-toggle="tab" data-bs-target="#pane-use-existing" type="button" role="tab">
                                    Use Existing
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="tab-create-new" data-bs-toggle="tab" data-bs-target="#pane-create-new" type="button" role="tab">
                                    Create New
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content pt-3">
                            <!-- Edit Current -->
                            <div class="tab-pane fade show active" id="pane-edit-current" role="tabpanel">
                                <div id="editCurrentMsg" class="mb-2"></div>
                                <?php if ($locationUuid): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Field Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="ml_fieldNameInput" maxlength="255" value="<?= safe($club['field_name'] ?? '') ?>" placeholder="e.g., Sportpark Wouterland">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Address (optional)</label>
                                        <input type="text" class="form-control" id="ml_addressInput" maxlength="255" value="<?= safe($club['address_text'] ?? '') ?>" placeholder="e.g., Parklaan 1, Rotterdam">
                                    </div>
                                    <button class="btn btn-primary" id="ml_saveEditBtn"><i class="bi bi-check-lg me-1"></i>Save Changes</button>
                                <?php else: ?>
                                    <div class="alert alert-info mb-0">This club doesn’t have a location yet. Use the “Use Existing” or “Create New” tabs.</div>
                                <?php endif; ?>
                            </div>

                            <!-- Use Existing -->
                            <div class="tab-pane fade" id="pane-use-existing" role="tabpanel">
                                <div id="useExistingMsg" class="mb-2"></div>
                                <div class="input-group mb-2">
                                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                                    <input type="text" class="form-control" id="ml_searchInput" placeholder="Search by field name or address">
                                </div>
                                <div class="table-responsive" style="max-height: 320px; overflow:auto;">
                                    <table class="table table-sm align-middle mb-2">
                                        <thead><tr><th>Field</th><th>Address</th><th>Used By</th><th></th></tr></thead>
                                        <tbody id="ml_resultsBody"></tbody>
                                    </table>
                                </div>
                                <button class="btn btn-primary" id="ml_useSelectedBtn" disabled>Use Selected Location</button>
                            </div>

                            <!-- Create New -->
                            <div class="tab-pane fade" id="pane-create-new" role="tabpanel">
                                <div id="createNewMsg" class="mb-2"></div>
                                <div class="mb-3">
                                    <label class="form-label">Field Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="ml_newFieldName" maxlength="255" placeholder="e.g., Sportpark Wouterland">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Address (optional)</label>
                                    <input type="text" class="form-control" id="ml_newAddress" maxlength="255" placeholder="e.g., Parklaan 1, Rotterdam">
                                </div>
                                <button class="btn btn-primary" id="ml_createLinkBtn"><i class="bi bi-plus-lg me-1"></i>Create and Link</button>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <!-- Pass IDs to JS -->
    <div id="clubMeta"
         data-club-uuid="<?= safe($clubUuid) ?>"
         data-location-uuid="<?= safe($locationUuid ?? '') ?>">
    </div>

</div> <!-- /container -->

<script src="js/club.js"></script>

<?php include '../includes/footer.php'; ?>
