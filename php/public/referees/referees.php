<?php
require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';
include '../includes/header.php';
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
                <div>
                    <h1 class="referees-title">Referees</h1>
                    <p class="referees-subtitle">Search, filter and sort — fast.</p>
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
    const refereeData = <?=
        json_encode($referees, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
        ?>;

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

    document.addEventListener('DOMContentLoaded', () => {
        const statsEl = document.getElementById('table-stats');

        const table = new Tabulator("#referees-table", {
            data: refereeData,
            layout: "fitColumns",
            height: "70vh",
            placeholder: "No referees found",
            movableColumns: true,
            columnDefaults: { headerSortTristate: true, sorter: "string", tooltip: false },

            // polished UX
            reactiveData: false,
            pagination: true,
            paginationMode: "local",
            paginationSize: 25,
            paginationSizeSelector: [25, 50, 100],
            responsiveLayout: "collapse",
            responsiveLayoutCollapseFormatter: function(data){
                // small, clean collapse view
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
                    headerFilter: "select",
                    headerFilterParams: { values: ["A","B","C","D"] },
                    headerFilterPlaceholder: "All",
                    hozAlign: "center",
                    width: 120,
                    formatter: c => gradeBadge(c.getValue()),
                },
                {
                    title: "AR Grade",
                    field: "ar_grade",
                    headerFilter: "select",
                    headerFilterParams: { values: ["A","B","C","D"] },
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

        // Global search
        document.getElementById("globalFilter").addEventListener("input", function () {
            const q = this.value.toLowerCase();
            if (!q) { table.clearFilter(true); return; }
            table.setFilter((data) =>
                Object.values(data).some(v => (v ?? "").toString().toLowerCase().includes(q))
            );
        });

        // Quick grade pills
        const pills = document.querySelectorAll('.grade-pills .pill');
        const setPillActive = (grade) => {
            pills.forEach(p => p.classList.toggle('is-active', p.dataset.grade === grade));
        };
        const applyGradeFilter = (grade) => {
            table.clearFilter(true);
            if (grade) table.addFilter("grade", "=", grade);
            // re-apply global text if any
            const q = document.getElementById("globalFilter").value.trim().toLowerCase();
            if (q) table.addFilter((row) => Object.values(row).some(v => (v ?? "").toString().toLowerCase().includes(q)));
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

        // Stats
        const countBy = (arr, key) => arr.reduce((m, x) => (m[x[key] || '']=(m[x[key]||'']||0)+1, m), {});
        const all = table.getData();
        const counts = countBy(all, "grade");
        const total = all.length;
        const chip = (label, n) => `<span style="display:inline-block;margin-right:.4rem;padding:.2rem .5rem;border:1px solid var(--border);border-radius:999px;font-weight:700;font-size:12px;background:var(--card);">${label}: ${n ?? 0}</span>`;
        renderStats();
        table.on("dataFiltered", (filters, rows) => renderStats(rows.length));
    });
</script>

<?php include '../includes/footer.php'; ?>
