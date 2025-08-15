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
    SELECT uuid, team_name, division
    FROM teams
    WHERE club_id = ?
    ORDER BY team_name ASC
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
                    <a class="btn btn-primary" id="openMapsBtn" href="<?= safe($maps_url) ?>" target="_blank" rel="noopener">
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
                        <?php if ($locationUuid): ?>
                            <div class="d-flex align-items-center gap-1">
                                <button class="btn btn-sm btn-outline-secondary btn-icon" id="editAddressBtn" type="button" title="Edit Address">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                            </div>
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

                        <!-- Coords (collapsed details) -->
                        <details class="mt-3">
                            <summary class="text-muted">Coordinates (optional)</summary>
                            <div class="mt-2">
                                <div class="kv-pair">
                                    <div class="kv-label">Latitude</div>
                                    <div class="flex-grow-1 mono"><?= isset($club['lat']) ? safe($club['lat']) : '—' ?></div>
                                </div>
                                <div class="kv-pair">
                                    <div class="kv-label">Longitude</div>
                                    <div class="flex-grow-1 mono"><?= isset($club['lon']) ? safe($club['lon']) : '—' ?></div>
                                </div>
                            </div>
                        </details>
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

            <!-- Teams -->
            <div class="col-12">
                <div class="card soft-card">
                    <div class="card-header bg-white"><strong><i class="bi bi-people me-2"></i>Teams</strong></div>
                    <div class="card-body">
                        <?php if (!empty($teams)): ?>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead><tr><th>Team</th><th>Division</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($teams as $t): ?>
                                        <tr><td><?= safe($t['team_name']) ?></td><td><?= safe($t['division'] ?? '—') ?></td></tr>
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
        const msg = document.getElementById('clubMsg');
        const clubUuid = <?= json_encode($clubUuid) ?>;
        const locationUuid = <?= json_encode($locationUuid) ?>;

        const showMsg = (html, cls='success') => {
            msg.innerHTML = `<div class="alert alert-${cls}">${html}</div>`;
            setTimeout(() => { msg.innerHTML = ''; }, cls === 'success' ? 2500 : 5000);
        };

        // ===== Address editing =====
        const addressRowDisplay = document.getElementById('addressRowDisplay');
        const addressRowEdit    = document.getElementById('addressRowEdit');
        const addressText       = document.getElementById('addressText');
        const addressInput      = document.getElementById('addressInput');
        const editAddrBtn       = document.getElementById('editAddressBtn');
        const saveAddrBtn       = document.getElementById('saveAddressBtn');
        const cancelAddrBtn     = document.getElementById('cancelAddressBtn');
        const copyAddrBtn       = document.getElementById('copyAddressBtn');
        const openMapsBtn       = document.getElementById('openMapsBtn');

        if (copyAddrBtn && addressText?.textContent.trim()) {
            copyAddrBtn.addEventListener('click', async () => {
                try {
                    await navigator.clipboard.writeText(addressText.textContent.trim());
                    copyAddrBtn.innerHTML = '<i class="bi bi-clipboard-check"></i>';
                    setTimeout(()=> copyAddrBtn.innerHTML = '<i class="bi bi-clipboard"></i>', 1500);
                } catch (e) { console.error(e); }
            });
        }

        const toggleAddrEdit = (editing) => {
            if (!addressRowDisplay || !addressRowEdit) return;
            addressRowDisplay.style.display = editing ? 'none' : '';
            addressRowEdit.style.display    = editing ? '' : 'none';
            if (editing) addressInput?.focus();
        };

        editAddrBtn?.addEventListener('click', () => toggleAddrEdit(true));
        cancelAddrBtn?.addEventListener('click', () => {
            if (addressInput) addressInput.value = addressText?.textContent.trim() || '';
            toggleAddrEdit(false);
        });

        const postLocationField = async (field, value) => {
            const body = new URLSearchParams({
                location_uuid: locationUuid,
                field_name: field,
                field_value: value
            });
            const res = await fetch('update_location_field.php', {
                method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body
            });
            const data = await res.json();
            if (data.status !== 'success') throw new Error(data.message || 'Update failed');
        };

        saveAddrBtn?.addEventListener('click', async () => {
            if (!locationUuid) return;
            const val = (addressInput?.value || '').trim();
            try {
                await postLocationField('address_text', val);
                if (addressText) addressText.textContent = val || '—';
                if (openMapsBtn && val) {
                    openMapsBtn.href = 'https://maps.google.com/?q=' + encodeURIComponent(val);
                    openMapsBtn.style.display = '';
                }
                toggleAddrEdit(false);
                showMsg('Address saved.', 'success');
            } catch (e) { console.error(e); showMsg(e.message || 'Update failed.', 'danger'); }
        });

        // ===== Primary Contact editing =====
        const editContactBtn   = document.getElementById('editContactBtn');
        const contactDisplay   = document.getElementById('contactDisplay');
        const contactEdit      = document.getElementById('contactEdit');
        const contactNameText  = document.getElementById('contactNameText');
        const contactEmailText = document.getElementById('contactEmailText');
        const contactPhoneText = document.getElementById('contactPhoneText');
        const contactNameInput  = document.getElementById('contactNameInput');
        const contactEmailInput = document.getElementById('contactEmailInput');
        const contactPhoneInput = document.getElementById('contactPhoneInput');
        const saveContactBtn   = document.getElementById('saveContactBtn');
        const cancelContactBtn = document.getElementById('cancelContactBtn');

        const toggleContactEdit = (editing) => {
            contactDisplay.style.display = editing ? 'none' : '';
            contactEdit.style.display    = editing ? '' : 'none';
            if (editing) contactNameInput?.focus();
        };

        editContactBtn?.addEventListener('click', () => toggleContactEdit(true));
        cancelContactBtn?.addEventListener('click', () => {
            contactNameInput.value  = (contactNameText?.textContent.trim() || '').replace(/^—$/, '');
            const emailA = contactEmailText?.querySelector('a');
            contactEmailInput.value = emailA ? (emailA.textContent.trim()) : '';
            const phoneA = contactPhoneText?.querySelector('a');
            contactPhoneInput.value = phoneA ? (phoneA.textContent.trim()) : '';
            toggleContactEdit(false);
        });

        const postClubField = async (field, value) => {
            const body = new URLSearchParams({
                club_uuid: clubUuid,
                field_name: field,
                field_value: value
            });
            const res = await fetch('update_club_field.php', {
                method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body
            });
            const data = await res.json();
            if (data.status !== 'success') throw new Error(data.message || 'Update failed');
        };

        saveContactBtn?.addEventListener('click', async () => {
            const name  = (contactNameInput?.value || '').trim();
            const email = (contactEmailInput?.value || '').trim();
            const phone = (contactPhoneInput?.value || '').trim();

            try {
                await postClubField('primary_contact_name',  name);
                await postClubField('primary_contact_email', email);
                await postClubField('primary_contact_phone', phone);

                contactNameText.textContent = name || '—';
                contactEmailText.innerHTML = email
                    ? `<a href="mailto:${email}" class="truncate"><i class="bi bi-envelope me-1"></i>${email}</a>`
                    : '—';
                contactPhoneText.innerHTML = phone
                    ? `<a href="tel:${phone}"><i class="bi bi-telephone me-1"></i>${phone}</a>`
                    : '—';

                toggleContactEdit(false);
                showMsg('Primary contact saved.', 'success');
            } catch (e) { console.error(e); showMsg(e.message || 'Failed to save contact.', 'danger'); }
        });

        // ===== Notes editing (NEW) =====
        const editNotesBtn   = document.getElementById('editNotesBtn');
        const notesDisplay   = document.getElementById('notesDisplay');
        const notesEdit      = document.getElementById('notesEdit');
        const notesTextarea  = document.getElementById('notesTextarea');
        const saveNotesBtn   = document.getElementById('saveNotesBtn');
        const cancelNotesBtn = document.getElementById('cancelNotesBtn');

        const toggleNotesEdit = (editing) => {
            notesDisplay.style.display = editing ? 'none' : '';
            notesEdit.style.display    = editing ? '' : 'none';
            if (editing) notesTextarea?.focus();
        };

        editNotesBtn?.addEventListener('click', () => toggleNotesEdit(true));
        cancelNotesBtn?.addEventListener('click', () => {
            // Reset textarea to currently displayed text
            const pre = document.getElementById('notesPre');
            const empty = document.getElementById('notesEmpty');
            notesTextarea.value = pre ? pre.textContent : (empty ? '' : '');
            toggleNotesEdit(false);
        });

        saveNotesBtn?.addEventListener('click', async () => {
            const val = (notesTextarea?.value || '').trim();
            try {
                await postClubField('notes', val);

                // Refresh display area safely
                notesDisplay.innerHTML = '';
                if (val) {
                    const pre = document.createElement('pre');
                    pre.id = 'notesPre';
                    pre.className = 'mb-0';
                    pre.style.whiteSpace = 'pre-wrap';
                    pre.textContent = val; // safe text
                    notesDisplay.appendChild(pre);
                } else {
                    const span = document.createElement('span');
                    span.id = 'notesEmpty';
                    span.className = 'text-muted';
                    span.textContent = 'No notes yet.';
                    notesDisplay.appendChild(span);
                }

                toggleNotesEdit(false);
                showMsg('Notes saved.', 'success');
            } catch (e) { console.error(e); showMsg(e.message || 'Failed to save notes.', 'danger'); }
        });
    });
</script>

<?php include '../includes/footer.php'; ?>
