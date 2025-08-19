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
?>
<div class="container">
    <div class="content-card">
        <!-- Hero/header (same structure/classes as Referees) -->
        <div class="referees-hero">
            <div class="referees-hero__bg"></div>
            <div class="referees-hero__content">
                <div class="referees-title-div">
                    <h1 class="referees-title">Clubs</h1>
                    <p class="referees-subtitle">Search, filter and sort — fast.</p>
                </div>

                <div class="referees-quick">
                    <div class="input-with-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M21 21l-4.3-4.3M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        <input id="globalFilter" class="form-control" placeholder="Search clubs…">
                    </div>

                    <!-- reuse the pill styles as a Status quick filter -->
                    <div class="grade-pills" role="group" aria-label="Quick status filter">
                        <button class="pill" data-status="">All</button>
                        <button class="pill" data-status="1">Active</button>
                        <button class="pill" data-status="0">Inactive</button>
                    </div>

                    <div class="actions">
                        <?php if ($isSuperAdmin): ?>
                            <button class="btn btn-primary" id="openAddClub">
                                <i class="bi bi-plus-lg me-1"></i>Add Club
                            </button>
                        <?php endif; ?>
                        <button id="clearFilters" class="btn btn-outline-secondary">Clear</button>
                        <button id="downloadCsv" class="btn btn-secondary">Export CSV</button>
                    </div>
                </div>
            </div>
        </div>

        <div id="table-stats" class="table-stats"></div>
        <div id="clubs-table"></div>
    </div>
</div>

<?php if ($isSuperAdmin): ?>
    <!-- Add Club Modal (unchanged) -->
    <div class="modal fade" id="addClubModal" tabindex="-1" aria-labelledby="addClubLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content" style="border-radius:16px;">
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

<script>
    // Safely embed the data
    const clubsData = <?=
        json_encode($clubs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
        ?>;

    const esc = s => (s ?? '').toString()
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');

    const statusChip = (isActive) => {
        const active = Number(isActive) === 1 || isActive === true || isActive === '1';
        const txt = active ? 'Active' : 'Inactive';
        const cls = active ? 'bg-success' : 'bg-secondary';
        return `<span class="badge ${cls}" style="font-weight:800;border-radius:999px;border:1px solid rgba(0,0,0,.06);padding:4px 10px;line-height:1;">${txt}</span>`;
    };

    document.addEventListener('DOMContentLoaded', () => {
        // Tabulator
        const table = new Tabulator("#clubs-table", {
            data: clubsData,
            layout: "fitColumns",
            height: "70vh",
            placeholder: "No clubs found",
            movableColumns: true,
            columnDefaults: { headerSortTristate: true, sorter: "string" },
            layout: "fitColumns",
            layoutColumnsOnNewData: false,
            columnMinWidth: 110,
            pagination: true,
            paginationMode: "local",
            paginationSize: 25,
            paginationSizeSelector: [25, 50, 100],

            rowClick: (e, row) => {
                const id = encodeURIComponent(row.getData().uuid);
                window.location.href = `clubs/club-details.php?id=${id}`;
            },

            columns: [
                {
                    title: "Club ID",
                    field: "club_id",
                    width: 120,
                    headerFilter: "input",
                    formatter: (cell) => esc(cell.getValue() ?? '—'),
                },
                {
                    title: "Club Name",
                    field: "club_name",
                    widthGrow: 2,
                    headerFilter: "input",
                    headerFilterPlaceholder: "Filter by name",
                    formatter: (cell) => {
                        const d = cell.getData();
                        const id = encodeURIComponent(d.uuid);
                        const name = esc(cell.getValue() ?? '');
                        return `<a href="clubs/club-details.php?id=${id}">${name}</a>`;
                    },
                },
                { title: "Field", field: "field_name", headerFilter: "input", formatter: c => esc(c.getValue() || '—') },
                { title: "Address", field: "address_text", headerFilter: "input", formatter: c => esc(c.getValue() || '') },
                {
                    title: "Status",
                    field: "active",
                    hozAlign: "center",
                    headerFilter: "select",
                    headerFilterParams: { values: { "": "All", "1": "Active", "0": "Inactive" } },
                    sorter: (a,b) => (Number(a)||0) - (Number(b)||0),
                    width: 120,
                    formatter: (c) => statusChip(c.getValue()),
                    accessorDownload: (value) => (Number(value) === 1 ? "Active" : "Inactive"),
                },
            ],
            initialSort: [{ column: "club_name", dir: "asc" }],
        });

        window.clubsTable = table; // optional for debugging

        // Global search
        const globalFilter = document.getElementById("globalFilter");
        const statsEl = document.getElementById("table-stats");

        const applyGlobal = () => {
            const q = globalFilter.value.trim().toLowerCase();
            if (!q) { table.clearFilter(true); return; }
            table.setFilter((data) =>
                Object.values(data).some(v => (v ?? "").toString().toLowerCase().includes(q))
            );
        };
        globalFilter.addEventListener("input", applyGlobal);

        // Quick Status pills (reuse pill styles)
        const pills = document.querySelectorAll('.grade-pills .pill');
        const setPillActive = (val) => pills.forEach(p => p.classList.toggle('is-active', p.dataset.status === val));
        const applyStatusFilter = (val) => {
            table.clearFilter(true);
            if (val !== "") table.addFilter("active", "=", val);
            // re-apply global text if present
            const q = globalFilter.value.trim().toLowerCase();
            if (q) table.addFilter((row) => Object.values(row).some(v => (v ?? "").toString().toLowerCase().includes(q)));
            setPillActive(val);
        };
        pills.forEach(p => p.addEventListener('click', () => applyStatusFilter(p.dataset.status || "")));
        setPillActive(""); // default = All

        // Clear & CSV
        document.getElementById("clearFilters").addEventListener("click", () => {
            globalFilter.value = "";
            table.clearFilter(true);
            setPillActive("");
            // refresh stats
            renderStats(table.getRows(true).length);
        });

        document.getElementById("downloadCsv").addEventListener("click", () => {
            table.download("csv", "clubs.csv", { bom: true });
        });

        // Stats
        const renderStats = (visible) => {
            const total = table.getDataCount();
            const showing = visible ?? table.getRows(true).length;
            statsEl.textContent = `Showing ${showing.toLocaleString()} of ${total.toLocaleString()} clubs`;
        };
        renderStats();
        table.on("dataFiltered", (filters, rows) => renderStats(rows.length));

        // Open modal (if you’re not already handling this in js/club.js)
        const openBtn = document.getElementById('openAddClub');
        if (openBtn && typeof bootstrap !== 'undefined') {
            openBtn.addEventListener('click', () => {
                const modalEl = document.getElementById('addClubModal');
                if (modalEl) new bootstrap.Modal(modalEl, { backdrop: 'static' }).show();
            });
        }
    });
</script>

<script src="clubs/js/club.js"></script>

<?php include 'includes/footer.php'; ?>
