<?php
require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';
include '../includes/header.php'; // <body> likely begins here and includes BS5 bundle
include '../includes/nav.php';

$pdo = Database::getConnection();

// Fetch referees with home club name
$referees = $pdo->query("
    SELECT 
        r.referee_id,
        r.first_name,
        r.last_name,
        r.email,
        r.phone,
        c.club_name AS home_club_name,
        r.home_location_city,
        r.grade,
        r.ar_grade
    FROM referees r
    LEFT JOIN clubs c ON r.home_club_id = c.uuid
    ORDER BY r.last_name, r.first_name
")->fetchAll();
?>
<div class="container">
    <div class="content-card">
        <div class="referees-hero">
            <div class="referees-hero__bg"></div>
            <div class="referees-hero__content">
                <div class="referees-title-div">
                    <h1 class="referees-title">Referees</h1>
                </div>

                <div class="referees-quick">
                    <div class="input-with-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M21 21l-4.3-4.3M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        <input id="globalFilter" class="form-control" placeholder="Search referees…">
                    </div>

                    <div class="grade-pills" role="group" aria-label="Quick grade filter">
                        <button class="pill" data-grade="">All</button>
                        <button class="pill" data-grade="A">A</button>
                        <button class="pill" data-grade="B">B</button>
                        <button class="pill" data-grade="C">C</button>
                        <button class="pill" data-grade="D">D</button>
                    </div>

                    <div class="actions">
                        <button id="addRefBtn" class="btn btn-primary">Add Referee</button>
                        <button id="clearFilters" class="btn btn-outline-secondary">Clear</button>
                        <button id="downloadCsv" class="btn btn-secondary">Export CSV</button>
                    </div>
                </div>
            </div>
        </div>

        <div id="table-stats" class="table-stats"></div>
        <div id="referees-table"></div>
    </div>
</div>

<?php
// preload clubs for the selector
$clubs = $pdo->query("SELECT uuid, club_name FROM clubs ORDER BY club_name")
    ->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- Add Referee Modal (kept simple; JS will relocate under <body>) -->
<div class="modal" id="addRefModal"
     tabindex="-1"
     role="dialog"
     aria-labelledby="addRefLabel"
     aria-modal="true"
     aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-md" role="document">
        <div class="modal-content" style="border-radius:16px;">
            <div class="modal-header">
                <h5 class="modal-title" id="addRefLabel">Add Referee</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form id="addRefForm" novalidate>
                <div class="modal-body">
                    <div id="addRefAlert" class="alert alert-danger d-none" role="alert"></div>

                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label">First Name<span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="first_name" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Last Name<span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="last_name" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Email<span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="email" required>
                            <div class="form-text">Email must be unique.</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Referee Grade<span class="text-danger">*</span></label>
                            <select class="form-select" id="grade" required>
                                <option value="">Select grade…</option>
                                <option>A</option><option>B</option><option>C</option><option>D</option>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Home Club</label>
                            <select class="form-select" id="home_club_id">
                                <option value="">Select a club…</option>
                                <?php foreach($clubs as $club): ?>
                                    <option value="<?= htmlspecialchars($club['uuid']) ?>">
                                        <?= htmlspecialchars($club['club_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" id="addRefSubmit" class="btn btn-primary">
                        <span class="submit-text">Create</span>
                        <span class="spinner-border spinner-border-sm ms-2 d-none" role="status" aria-hidden="true"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$referees = $pdo->query("
    SELECT 
        r.referee_id,
        r.first_name,
        r.last_name,
        r.email,
        r.phone,
        c.club_name AS home_club_name,
        r.home_location_city,
        r.grade,
        r.ar_grade
    FROM referees r
    LEFT JOIN clubs c ON r.home_club_id = c.uuid
    ORDER BY r.last_name, r.first_name
")->fetchAll(PDO::FETCH_ASSOC);
?>
<script>
    /* --------------------- Utilities --------------------- */
    const refereeData = <?= json_encode($referees, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
    const esc = s => (s ?? '').toString()
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    const initials = (first, last) => {
        const a = (first||'').trim()[0] || '';
        const b = (last||'').trim()[0] || '';
        return (a + b || '?').toUpperCase();
    };
    const gradeClass = g => (['A','B','C','D'].includes((g||'').toUpperCase()) ? `chip-${(g||'').toUpperCase()}` : 'chip-default');
    const gradeBadge = g => `<span class="badge ${gradeClass(g)}">${esc(g || '')}</span>`;

    /* --------------------- Table --------------------- */
    document.addEventListener('DOMContentLoaded', () => {
        const table = new Tabulator("#referees-table", {
            data: refereeData,
            height: "70vh",
            placeholder: "No referees found",
            movableColumns: true,
            columnDefaults: { headerSortTristate: true, sorter: "string", tooltip: false },
            layoutColumnsOnNewData: false,
            columnMinWidth: 110,

            reactiveData: false,
            pagination: true,
            paginationMode: "local",
            paginationSize: 25,
            paginationSizeSelector: [25, 50, 100],
            responsiveLayout: "collapse",
            responsiveLayoutCollapseFormatter: function(data){
                const lines = [];
                if (data.email) lines.push(`📧 ${esc(data.email)}`);
                if (data.phone) lines.push(`☎️ ${esc(data.phone)}`);
                if (data.home_club_name) lines.push(`🏟️ ${esc(data.home_club_name)}`);
                if (data.home_location_city) lines.push(`📍 ${esc(data.home_location_city)}`);
                return lines.map(l => `<div style="margin:.2rem 0">${l}</div>`).join('');
            },

            rowClick: (e, row) => {
                const id = encodeURIComponent(row.getData().referee_id);
                window.location.href = `referee_detail.php?id=${id}`;
            },
            rowFormatter: (row) => {
                const g = (row.getData().grade || '').toString().toUpperCase();
                row.getElement().classList.remove("grade-A","grade-B","grade-C","grade-D");
                if (["A","B","C","D"].includes(g)) row.getElement().classList.add(`grade-${g}`);
            },

            columns: [
                { title: "First Name", field: "first_name", visible: false },
                {
                    title: "Referee",
                    field: "last_name",
                    widthGrow: 2.4,
                    headerFilter: "input",
                    headerFilterPlaceholder: "Filter by name",
                    accessorDownload: (value, data) => [data.first_name, data.last_name].filter(Boolean).join(" "),
                    formatter: (cell) => {
                        const d = cell.getData();
                        const full = [d.first_name, d.last_name].filter(Boolean).join(" ");
                        const sub = [d.home_club_name, d.home_location_city].filter(Boolean).join(" • ");
                        const id = encodeURIComponent(d.referee_id);
                        return `
            <div class="name-cell">
              <div class="avatar">${esc(initials(d.first_name, d.last_name))}</div>
              <div>
                <div class="cell-title"><a href="referee_detail.php?id=${id}">${esc(full)}</a></div>
                ${sub ? `<div class="cell-sub">${esc(sub)}</div>` : ``}
              </div>
            </div>`;
                    },
                },
                {
                    title: "Referee ID",
                    field: "referee_id",
                    width: 130,
                    headerFilter: "input",
                    formatter: cell => {
                        const v = cell.getValue();
                        const id = encodeURIComponent(v);
                        return `<a href="referee_detail.php?id=${id}">${esc(v)}</a>`;
                    },
                },
                {
                    title: "Email",
                    field: "email",
                    headerFilter: "input",
                    formatter: c => {
                        const v = c.getValue();
                        return v ? `<a href="mailto:${esc(v)}">${esc(v)}</a>` : "";
                    },
                },
                { title: "Phone", field: "phone", headerFilter: "input" },
                { title: "Home Club", field: "home_club_name", headerFilter: "input" },
                { title: "City", field: "home_location_city", headerFilter: "input" },
                {
                    title: "Grade",
                    field: "grade",
                    headerFilter: "list",
                    headerFilterParams: { values: ["A","B","C","D"], clearable: true },
                    headerFilterPlaceholder: "All",
                    hozAlign: "center",
                    width: 120,
                    formatter: c => gradeBadge(c.getValue()),
                },
                {
                    title: "AR Grade",
                    field: "ar_grade",
                    headerFilter: "list",
                    headerFilterParams: { values: ["A","B","C","D"], clearable: true },
                    headerFilterPlaceholder: "All",
                    hozAlign: "center",
                    width: 120,
                    formatter: c => gradeBadge(c.getValue()),
                },
            ],
            initialSort: [
                { column: "last_name", dir: "asc" },
                { column: "first_name", dir: "asc" },
            ],
        });

        window.refTable = table;

        // Stats
        const renderStats = (filteredCount) => {
            const statsEl = document.getElementById('table-stats');
            if (!statsEl) return;
            const currentRows = table.getData();
            const total = filteredCount ?? currentRows.length;
            const counts = currentRows.reduce((m, r) => {
                const g = (r.grade || '').toUpperCase();
                m[g] = (m[g] || 0) + 1;
                return m;
            }, {});
            const chip = (label, n) =>
                `<span style="display:inline-block;margin-right:.4rem;padding:.2rem .5rem;border:1px solid var(--border);border-radius:999px;font-weight:700;font-size:12px;background:var(--card);">${label}: ${n ?? 0}</span>`;
            statsEl.innerHTML = [
                chip('Total', total),
                chip('A', counts.A),
                chip('B', counts.B),
                chip('C', counts.C),
                chip('D', counts.D),
            ].join(' ');
        };
        renderStats();
        table.on("dataFiltered", (filters, rows) => renderStats(rows.length));

        // Global search
        document.getElementById("globalFilter").addEventListener("input", function () {
            const q = this.value.toLowerCase();
            if (!q) { table.clearFilter(true); return; }
            table.setFilter((data) =>
                Object.values(data).some(v => (v ?? "").toString().toLowerCase().includes(q))
            );
        });

        // Grade pills
        const pills = document.querySelectorAll('.grade-pills .pill');
        const setPillActive = (grade) => {
            pills.forEach(p => p.classList.toggle('is-active', p.dataset.grade === grade));
        };
        const applyGradeFilter = (grade) => {
            table.clearFilter(true);
            if (grade) table.addFilter("grade", "=", grade);
            const q = document.getElementById("globalFilter").value.trim().toLowerCase();
            if (q) {
                table.addFilter((data) =>
                    Object.values(data).some(v => (v ?? "").toString().toLowerCase().includes(q))
                );
            }
            setPillActive(grade || "");
        };
        pills.forEach(p => p.addEventListener('click', () => applyGradeFilter(p.dataset.grade || "")));
        setPillActive("");

        // Clear + CSV
        document.getElementById("clearFilters").addEventListener("click", () => {
            document.getElementById("globalFilter").value = "";
            table.clearFilter(true);
            setPillActive("");
        });
        document.getElementById("downloadCsv").addEventListener("click", () => {
            table.download("csv", "referees.csv", { bom: true });
        });
    });

    /* --------------------- Modal (Bootstrap 5 only) --------------------- */
    document.addEventListener('DOMContentLoaded', () => {
        const modalEl = document.getElementById('addRefModal');
        const trigger = document.getElementById('addRefBtn');

        if (!modalEl) return;

        // Move under <body> (best practice to avoid hidden ancestors)
        if (modalEl.parentElement !== document.body) {
            document.body.appendChild(modalEl);
        }

        // Force-HIDE on load in case a previous crash left it open
        const hardHide = () => {
            modalEl.classList.remove('show');
            modalEl.style.display = 'none';
            modalEl.setAttribute('aria-hidden', 'true');
            document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('padding-right');
        };
        hardHide();

        // Remove data-api attrs to avoid double inits
        trigger?.removeAttribute('data-bs-toggle');
        trigger?.removeAttribute('data-bs-target');

        // Create/get instance
        const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl, {
            backdrop: 'static',
            keyboard: true,
            focus: true
        });

        // Show from button
        trigger?.addEventListener('click', (e) => {
            e.preventDefault();
            // scrub any hidden/inert on ancestors
            let cur = modalEl;
            while (cur && cur !== document.documentElement) {
                cur.removeAttribute?.('aria-hidden');
                cur.removeAttribute?.('inert');
                cur = cur.parentElement;
            }
            bsModal.show();
        });

        // Focus management and cleanup
        modalEl.addEventListener('shown.bs.modal', () => {
            document.getElementById('first_name')?.focus();
        });
        modalEl.addEventListener('hidden.bs.modal', () => {
            trigger?.focus();
            hardHide(); // ensure no stale state
        });

        // Submit handling (hide on success)
        const form = document.getElementById('addRefForm');
        const alertBox = document.getElementById('addRefAlert');
        const submitBtn = document.getElementById('addRefSubmit');
        const spinner = submitBtn?.querySelector('.spinner-border');
        const submitText = submitBtn?.querySelector('.submit-text');
        const setBusy = (busy) => {
            if (!submitBtn) return;
            submitBtn.disabled = busy;
            spinner?.classList.toggle('d-none', !busy);
            if (submitText) submitText.textContent = busy ? 'Creating…' : 'Create';
        };

        form?.addEventListener('submit', async (e) => {
            e.preventDefault();
            alertBox?.classList.add('d-none');

            const first_name   = document.getElementById('first_name').value.trim();
            const last_name    = document.getElementById('last_name').value.trim();
            const email        = document.getElementById('email').value.trim();
            const grade        = document.getElementById('grade').value.trim().toUpperCase();
            const home_club_id = document.getElementById('home_club_id').value || null;

            if (!first_name || !last_name || !email || !grade) {
                if (alertBox) { alertBox.textContent = 'Please fill in all required fields.'; alertBox.classList.remove('d-none'); }
                return;
            }
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                if (alertBox) { alertBox.textContent = 'Please enter a valid email address.'; alertBox.classList.remove('d-none'); }
                return;
            }
            if (!['A','B','C','D'].includes(grade)) {
                if (alertBox) { alertBox.textContent = 'Grade must be A, B, C, or D.'; alertBox.classList.remove('d-none'); }
                return;
            }

            setBusy(true);
            try {
                const res = await fetch('referees_create.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ first_name, last_name, email, grade, home_club_id })
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    if (alertBox) { alertBox.textContent = data.message || 'Failed to create referee.'; alertBox.classList.remove('d-none'); }
                    return;
                }

                // Add to table and close modal
                if (data && data.referee && window.refTable) {
                    window.refTable.addData([data.referee], true);
                }
                bsModal.hide(); // will also call hardHide() via 'hidden' handler

                // Quick success flash
                const stats = document.getElementById('table-stats');
                if (stats) {
                    const old = stats.innerHTML;
                    stats.innerHTML = `<span class="badge bg-success" style="border-radius:999px;">Referee created</span> ${old}`;
                    setTimeout(() => (stats.innerHTML = old), 2500);
                }
            } catch (err) {
                if (alertBox) { alertBox.textContent = 'Network error. Please try again.'; alertBox.classList.remove('d-none'); }
            } finally {
                setBusy(false);
            }
        });
    });
</script>

<?php include '../includes/footer.php'; // </body> likely ends here ?>
