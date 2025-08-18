<?php
require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';
include 'includes/header.php';
include 'includes/nav.php';

$pdo = Database::getConnection();
$assignMode = isset($_GET['assign_mode']) && $_GET['assign_mode'] == '1';

// Preload referees for the select editors (value = uuid)
$referees = $pdo->query("
    SELECT r.uuid, r.first_name, r.last_name, r.grade
    FROM referees r
    ORDER BY r.first_name, r.last_name
")->fetchAll(PDO::FETCH_ASSOC);

// Preload assigners (users) for the assigner editor (value = uuid)
$assigners = $pdo->query("
    SELECT u.uuid, u.username
    FROM users u
    ORDER BY u.username
")->fetchAll(PDO::FETCH_ASSOC);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<link href="https://unpkg.com/tabulator-tables@6.2.5/dist/css/tabulator.min.css" rel="stylesheet">
<script src="https://unpkg.com/tabulator-tables@6.2.5/dist/js/tabulator.min.js"></script>

<style>
    /* Reuse your theme vibe; keep width stable */
    .table-viewport { margin:0 auto; width:100%; max-width:1200px; }
    #matches-table { width:100%; min-width:1024px; }
    #matches-table .tabulator-header { position: sticky; top: 0; z-index: 2; }

    /* Pretty chips */
    .fw-700{ font-weight:700; }
    .badge{ display:inline-block; padding:.25rem .5rem; border-radius:999px; border:1px solid rgba(0,0,0,.06); font-size:12.5px; line-height:1; }

    .bg-success-subtle{ background: rgba(22,163,74,.10); color:#15803d; border-color: rgba(22,163,74,.25); }
    .bg-secondary-subtle{ background: rgba(100,116,139,.12); color:#475569; border-color: rgba(100,116,139,.28); }

    /* Expected grade colors */
    .chip-A{ background: rgba(37,99,235,.10); color:#1d4ed8; border-color: rgba(37,99,235,.25); }
    .chip-B{ background: rgba(22,163,74,.10); color:#15803d; border-color: rgba(22,163,74,.25); }
    .chip-C{ background: rgba(202,138,4,.12); color:#a16207; border-color: rgba(202,138,4,.30); }
    .chip-D{ background: rgba(220,38,38,.12); color:#b91c1c; border-color: rgba(220,38,38,.30); }
    .chip-E{ background: rgba(100,116,139,.12); color:#475569; border-color: rgba(100,116,139,.28); }

    /* Match the hero from Referees/Clubs (classes already exist in your CSS file) */
    .actions .btn{ border-radius: 12px; height: 44px; }
</style>

<div class="container matches-container">
    <div class="content-card">
        <!-- Glassy hero (same as Referees/Clubs) -->
        <div class="referees-hero">
            <div class="referees-hero__bg"></div>
            <div class="referees-hero__content">
                <div class="referees-title-div">
                    <h1 class="referees-title">Matches</h1>
                    <p class="referees-subtitle">Search, filter, sort & assign — fast.</p>
                </div>

                <div class="referees-quick">
                    <div class="input-with-icon" style="min-width:min(380px,100%);">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M21 21l-4.3-4.3M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        <input id="globalFilter" class="form-control" placeholder="Search matches…">
                    </div>

                    <div class="d-flex align-items-center gap-2">
                        <div>
                            <small class="text-muted d-block">From</small>
                            <input type="date" id="startDate" class="form-control" style="width: 175px;">
                        </div>
                        <div>
                            <small class="text-muted d-block">To</small>
                            <input type="date" id="endDate" class="form-control" style="width: 175px;">
                        </div>
                    </div>

                    <div class="actions">
                        <?php if ($assignMode): ?>
                            <a href="matches.php" class="btn btn-sm btn-outline-secondary">Disable Assign Mode</a>
                        <?php else: ?>
                            <a href="matches.php?assign_mode=1" class="btn btn-sm btn-warning">Enable Assign Mode</a>
                        <?php endif; ?>
                        <a href="export_matches.php" class="btn btn-sm btn-info">Export CSV</a>
                        <a href="assign_assigner.php" class="btn btn-sm btn-primary">Assign Assigner</a>
                        <?php if ($assignMode): ?>
                            <button type="button" id="suggestAssignments" class="btn btn-sm btn-main-action">Suggest Assignments</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div id="table-stats" class="table-stats"></div>
        <div class="table-viewport">
            <div id="matches-table" data-density="cozy"></div>
        </div>
    </div>
</div>

<script>
    // ---- Conflict helpers ----
    const SEV = { NONE:0, YELLOW:1, ORANGE:2, RED:3, UNAVAILABLE:4 };
    const sevClass = (s) =>
        s===SEV.UNAVAILABLE ? "sev-unavail" :
            s===SEV.RED        ? "sev-red"     :
                s===SEV.ORANGE     ? "sev-orange"  :
                    s===SEV.YELLOW     ? "sev-yellow"  : "";

    const parseDT  = (d,t)=>{ const hhmm=(t||"00:00").slice(0,5); return new Date(`${d}T${hhmm}:00`); };
    const dayDiff  = (a,b)=> Math.round((new Date(b+"T00:00:00") - new Date(a+"T00:00:00"))/86400000);
    const normAddr = (s)=> (s||"").toLowerCase().replace(/[.,;:()\-]/g," ").replace(/\s+/g," ").trim();

    // Build a severity map for visible rows: key = `${uuid}:${role}` -> SEV.*
    function computeConflicts(rows){
        const recs = [];
        rows.forEach(r=>{
            const d = r.getData();
            const roles = ["referee_id","ar1_id","ar2_id","commissioner_id"];
            roles.forEach(role=>{
                const refId = d[role];
                if (!refId) return;
                const start = parseDT(d.match_date, d.kickoff_time);
                recs.push({
                    key: `${d.uuid}:${role}`,
                    matchId: d.uuid, role, refId,
                    dateStr: d.match_date,
                    start, end: new Date(start.getTime()+90*60*1000),
                    loc: normAddr(d.location_address || ""),
                    // Optional server hints (if you ever add them to your JSON):
                    baseAvail: d[`_${role}_availability`] || null,   // 'available' | 'unavailable'
                    baseConf : d[`_${role}_conflict`]     || "none", // 'red'|'orange'|'yellow'|'none'
                });
            });
        });

        const sev = new Map();
        const bump = (k,s)=>{ const cur = sev.get(k) ?? SEV.NONE; if (s>cur) sev.set(k,s); };

        // Seed from server-provided hints (if present)
        for (const r of recs){
            if (r.baseAvail === "unavailable") bump(r.key, SEV.UNAVAILABLE);
            if (r.baseConf === "red")    bump(r.key, SEV.RED);
            if (r.baseConf === "orange") bump(r.key, SEV.ORANGE);
            if (r.baseConf === "yellow") bump(r.key, SEV.YELLOW);
        }

        // Group by referee to find live conflicts
        const byRef = {};
        recs.forEach(r => (byRef[r.refId] ||= []).push(r));

        Object.values(byRef).forEach(list=>{
            list.sort((a,b)=> a.start - b.start);

            // Same match, multiple roles -> RED for all in that match
            const byMatch = {};
            list.forEach(r => (byMatch[r.matchId] ||= []).push(r));
            Object.values(byMatch).forEach(arr=>{
                if (arr.length > 1) arr.forEach(r => bump(r.key, SEV.RED));
            });

            // Pairwise checks across a ref's assignments
            for (let i=0;i<list.length;i++){
                for (let j=i+1;j<list.length;j++){
                    const A=list[i], B=list[j];
                    const dd = dayDiff(A.dateStr, B.dateStr);

                    if (dd === 0){
                        // time overlap -> RED
                        if (A.start < B.end && B.start < A.end){ bump(A.key,SEV.RED); bump(B.key,SEV.RED); continue; }
                        // same day, different venues -> RED
                        if (A.loc && B.loc && A.loc !== B.loc){ bump(A.key,SEV.RED); bump(B.key,SEV.RED); continue; }
                        // same day, same venue, no overlap -> ORANGE
                        bump(A.key,SEV.ORANGE); bump(B.key,SEV.ORANGE);
                    } else if (Math.abs(dd) <= 2){
                        bump(A.key,SEV.YELLOW); bump(B.key,SEV.YELLOW);
                    }
                }
            }
        });

        return sev;
    }

    // Apply classes to referee cells based on computeConflicts
    function applyConflictClasses(table){
        const rows = table.getRows(true); // visible only (perf)
        const sevMap = computeConflicts(rows);

        rows.forEach(r=>{
            const d = r.getData();
            ["referee_id","ar1_id","ar2_id","commissioner_id"].forEach(role=>{
                const cell = r.getCell(role);
                if (!cell) return;
                const el = cell.getElement();
                el.classList.remove("sev-unavail","sev-red","sev-orange","sev-yellow");
                const cls = sevClass(sevMap.get(`${d.uuid}:${role}`) ?? SEV.NONE);
                if (cls) el.classList.add(cls);
            });
        });
    }
    /* ==== Preloaded option maps for editors ==== */
    const refereeOptions = (() => {
        const map = {};
        <?php foreach ($referees as $r):
        $label = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        $label .= $r['grade'] ? ' (' . $r['grade'] . ')' : '';
        ?>
        map["<?= h($r['uuid']) ?>"] = "<?= h($label) ?>";
        <?php endforeach; ?>
        return map;
    })();

    const assignerOptions = (() => {
        const map = {};
        <?php foreach ($assigners as $u): ?>
        map["<?= h($u['uuid']) ?>"] = "<?= h($u['username']) ?>";
        <?php endforeach; ?>
        return map;
    })();

    const ASSIGN_MODE = <?= $assignMode ? 'true' : 'false' ?>;

    /* ==== Helpers ==== */
    const fmtTime = (t) => (t ? t.slice(0,5) : "");
    const chipAssigned = (ok, textIfKnown) =>
        ok
            ? `<span class="badge bg-success-subtle fw-700">${textIfKnown || 'Assigned'}</span>`
            : `<span class="badge bg-secondary-subtle fw-700">—</span>`;

    function gradeBadge(g) {
        if (!g) return '<span class="badge chip-E fw-700">—</span>';
        const G = String(g).toUpperCase();
        const cls = ['A','B','C','D','E'].includes(G) ? `chip-${G}` : 'chip-E';
        return `<span class="badge ${cls} fw-700">${G}</span>`;
    }

    /* ==== Networking helpers for saves ==== */
    function saveAssignment(matchUuid, field, value) {
        // field in: referee_id | ar1_id | ar2_id | commissioner_id
        return fetch('/ajax/update_match_assignment.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: `match_uuid=${encodeURIComponent(matchUuid)}&field=${encodeURIComponent(field)}&value=${encodeURIComponent(value || '')}`
        }).then(r => r.json()).catch(() => ({success:false}));
    }

    function saveAssigner(matchUuid, assignerUuid) {
        // delegates to your generic endpoint
        return fetch('/ajax/update_match_field.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: `match_uuid=${encodeURIComponent(matchUuid)}&field_type=referee_assigner&new_value=${encodeURIComponent(assignerUuid || '')}`
        }).then(r => r.json()).catch(() => ({success:false}));
    }
    function formatRefName(id){
        if (!id) return "—";
        const label = refereeOptions[id];
        return label ? label : "—";
    }

    function makeRefereeCol(title, field, minWidth = 180){
        const base = {
            title, field, minWidth,
            formatter: (cell) => formatRefName(cell.getValue()),
        };
        if (!ASSIGN_MODE) return base;

        return {
            ...base,
            editor: "list",
            editorParams: {
                // Tabulator accepts an object map {value: label}
                values: refereeOptions,
                clearable: true,          // allow clearing assignment
                autocomplete: true,       // quick type-to-search
                listOnEmpty: true,
                freetext: false,
            },
            cellEdited: async (cell) => {
                const row = cell.getRow().getData();
                const newVal = cell.getValue() || "";
                try {
                    const res = await saveAssignment(row.uuid, field, newVal);
                    if (!res?.success) throw new Error(res?.message || "Save failed");
                } catch (err) {
                    console.error("[Matches] saveAssignment failed:", err);
                    // revert if server save fails
                    cell.setValue(cell.getOldValue(), true);
                    alert("Saving assignment failed.");
                }
            },
        };
    }

    // Assigner column editable only in ASSIGN_MODE
    function makeAssignerCol(){
        const base = {
            title: "Assigner",
            field: "referee_assigner_username",
            headerFilter: "input",
            minWidth: 130,
            formatter: (cell) => cell.getValue() || "—",
        };
        if (!ASSIGN_MODE) return base;

        return {
            ...base,
            editor: "list",
            editorParams: {
                values: assignerOptions,  // {uuid: username}
                clearable: true,
                autocomplete: true,
                listOnEmpty: true,
                freetext: false,
            },
            // store uuid in DB, but we show username in the cell
            mutatorEdit: (value, data, type, params, component) => {
                // mutatorEdit runs before cellEdited; translate username->uuid here if needed
                // We actually want the selected *uuid*. Tabulator list editor returns the key (uuid)
                // because `values` is a map. So just pass through.
                return value;
            },
            cellEdited: async (cell) => {
                const row = cell.getRow().getData();
                const uuid = cell.getValue() || "";
                try {
                    const res = await saveAssigner(row.uuid, uuid);
                    if (!res?.success) throw new Error(res?.message || "Save failed");
                    // Optional: if your server responds with the username string, you could refresh the row here.
                } catch (err) {
                    console.error("[Matches] saveAssigner failed:", err);
                    cell.setValue(cell.getOldValue(), true);
                    alert("Saving assigner failed.");
                }
            },
        };
    }


    document.addEventListener('DOMContentLoaded', () => {
        const statsEl = document.getElementById('table-stats');
        const startEl = document.getElementById('startDate');
        const endEl   = document.getElementById('endDate');
        const searchEl= document.getElementById('globalFilter');

        const table = new Tabulator("#matches-table", {
            index: "uuid",
            height: "70vh",
            layout: "fitColumns",
            columnMinWidth: 120,
            placeholder: "No matches found",

            ajaxURL: "/ajax/matches_list.php",               // ✅ absolute to avoid /admin/ path issues
            ajaxConfig: "GET",
            pagination: true,
            paginationMode: "remote",
            paginationSize: 50,
            paginationSizeSelector: [50, 100, 200],
            paginationDataReceived: {
                last_page: "last_page",
                data: "data",
            },

            ajaxURLGenerator: (url, _cfg, params) => {
                const q = new URLSearchParams();
                q.set("page", params.page || 1);
                q.set("size", params.size || 50);
                if (params.sort?.length) {
                    q.set("sort_col", params.sort[0].field);
                    q.set("sort_dir", params.sort[0].dir);
                }
                const s = document.getElementById("startDate")?.value;
                const e = document.getElementById("endDate")?.value;
                const gl = document.getElementById("globalFilter")?.value?.trim();
                if (s) q.set("start_date", s);
                if (e) q.set("end_date", e);
                if (gl) q.set("search", gl);
                const finalURL = `${url}?${q.toString()}`;
                console.log("[Matches] ajaxURLGenerator ->", finalURL);
                return finalURL;
            },

            // IMPORTANT: build the querystring from Tabulator's params
            ajaxRequestFunc: (url, config, params) => {
                console.log("[Matches] ajaxRequestFunc", config?.method || "GET", url);
                // Tabulator gives you current page/size/sort here:
                // params = { page, size, sort: [{field, dir}], filter: [...] }
                const q = new URLSearchParams();

                // page & size (1-based page)
                q.set("page", String(params.page || 1));
                q.set("size", String(params.size || 50));

                // sort (use the first sort for server)
                if (Array.isArray(params.sort) && params.sort.length) {
                    q.set("sort_col", params.sort[0].field);
                    q.set("sort_dir", params.sort[0].dir);
                }

                // extras from the UI (date range + global search)
                const s = document.getElementById("startDate")?.value;
                const e = document.getElementById("endDate")?.value;
                const gl = document.getElementById("globalFilter")?.value?.trim();
                if (s) q.set("start_date", s);
                if (e) q.set("end_date", e);
                if (gl) q.set("search", gl);

                // cache-buster for some CDNs/proxies
                q.set("_ts", Date.now().toString());

                const finalURL = `${url}?${q.toString()}`;
                console.log("[Matches] fetch >", finalURL, config || {method: "GET"});

                return fetch(finalURL, { method: "GET", headers: { Accept: "application/json" } })
                    .then(async (r) => {
                        const raw = await r.text();
                        console.log("[Matches] fetch <", r.status, finalURL, raw);
                        try { return JSON.parse(raw); } catch { return raw; }
                    });
            },

            /* Unwrap + validate + log shape before handing rows to Tabulator */
            ajaxResponse: (url, params, resp) => {
                try {
                    const { rows, total, last_page } = unwrapServerPayload(resp); // from the debug harness
                    const statsEl = document.getElementById("table-stats");
                    if (statsEl) statsEl.textContent = `Showing ${rows.length} of ${total} matches`;

                    console.log("[Matches] ajaxResponse OK -> rows:", rows.length, "total:", total, "last_page:", last_page);

                    // Hand Tabulator the full object for remote pager
                    return {
                        data: rows,
                        last_page: last_page || 1,
                        total: total || rows.length,
                    };
                } catch (e) {
                    console.error("[Matches] ajaxResponse BAD:", e.message);
                    const statsEl = document.getElementById("table-stats");
                    if (statsEl) statsEl.textContent = "⚠ Data shape error: " + e.message;
                    // Still return an object so pager doesn’t explode
                    return { data: [], last_page: 1, total: 0 };
                }
            },

            ajaxError: (xhr, textStatus, errorThrown) => {
                derr("ajaxError:", textStatus, errorThrown, xhr?.status, xhr?.responseText?.slice?.(0,400));
            },

            columns: [
                {
                    title: "Date", field: "match_date", width: 120, sorter: "string", headerFilter: "input",
                    formatter: (cell) => {
                        const d = cell.getValue();
                        const id = cell.getRow().getData().uuid;
                        return `<a href="match_detail.php?uuid=${encodeURIComponent(id)}">${d || ""}</a>`;
                    },
                },
                { title: "KO", field: "kickoff_time", width: 80, hozAlign: "center",
                    formatter: (cell) => (cell.getValue() ? cell.getValue().slice(0,5) : "")
                },
                { title: "Home", field: "home_team", headerFilter: "input", minWidth: 160 },
                { title: "Away", field: "away_team", headerFilter: "input", minWidth: 160 },
                { title: "Division", field: "division", headerFilter: "input", width: 140 },
                { title: "District", field: "district", headerFilter: "input", width: 130 },
                { title: "Poule", field: "poule", headerFilter: "input", width: 110 },

                // ⬇️ Assigner becomes editable in assign mode
                makeAssignerCol(),

                // ⬇️ These three become dropdowns in assign mode, otherwise show names
                makeRefereeCol("Referee", "referee_id", 180),
                makeRefereeCol("AR1",     "ar1_id",      180),
                makeRefereeCol("AR2",     "ar2_id",      180),

                // Optional: Commissioner same behavior — include if you want this editable too
                makeRefereeCol("Commissioner", "commissioner_id", 190),
            ],
        });

        table.on("dataLoaded", data => console.log("[Matches] Loaded rows:", Array.isArray(data)?data.length:data));
        table.on("dataLoadError", err => console.error("[Matches] dataLoadError:", err));
        table.on("dataLoaded",       ()=> applyConflictClasses(table));
        table.on("dataProcessed",    ()=> applyConflictClasses(table));
        table.on("renderComplete",   ()=> applyConflictClasses(table));
        table.on("pageLoaded",       ()=> applyConflictClasses(table));
        table.on("cellEdited",       ()=> applyConflictClasses(table));

        // Filters -> refresh remote
        const triggerRefresh = () => table.setData(); // re-hits ajaxURLGenerator
        searchEl.addEventListener('input', triggerRefresh);
        startEl.addEventListener('change', triggerRefresh);
        endEl.addEventListener('change', triggerRefresh);

        // Optional: Suggest Assignments (streaming). Applies updates live to table.
        const suggestBtn = document.getElementById('suggestAssignments');
        suggestBtn?.addEventListener('click', async () => {
            suggestBtn.disabled = true; const orig = suggestBtn.textContent; suggestBtn.textContent = 'Suggesting…';
            try {
                const q = new URLSearchParams();
                if (startEl.value) q.set('start_date', startEl.value);
                if (endEl.value)   q.set('end_date', endEl.value);
                const resp = await fetch(`suggest_assignments.php${q.toString()?`?${q}`:''}`);
                if (!resp.ok) throw new Error('Suggest failed');
                const reader = resp.body.getReader(); const decoder=new TextDecoder(); let buf='';
                const applyBatch = (suggestions) => {
                    const updates = [];
                    Object.keys(suggestions||{}).forEach(matchId=>{
                        const roles = suggestions[matchId]||{};
                        ['referee_id','ar1_id','ar2_id','commissioner_id'].forEach(role=>{
                            if (roles[role]) updates.push({ uuid: matchId, [role]: roles[role] });
                        });
                    });
                    if (updates.length){
                        table.updateData(updates).then(()=> applyConflictClasses(table));
                    }
                };
                while(true){
                    const {value, done} = await reader.read(); if (done) break;
                    buf += decoder.decode(value,{stream:true});
                    const lines = buf.split('\n'); buf = lines.pop();
                    lines.forEach(line=>{
                        if (!line.trim()) return;
                        try{ const j=JSON.parse(line); if (j.suggestions) applyBatch(j.suggestions); }catch(e){}
                    });
                }
            } catch (e) {
                console.error(e);
                alert('Suggestion error. Check console.');
            } finally {
                suggestBtn.disabled=false; suggestBtn.textContent=orig;
            }
        });
    });


    /* ===== DEBUG HARNESS (paste above Tabulator init) ===== */
    const DEBUG_MATCHES = true;
    const dlog = (...a)=>{ if(DEBUG_MATCHES) console.log("[Matches]", ...a); };
    const derr = (...a)=>console.error("[Matches]", ...a);

    // Global error hooks
    window.addEventListener("error", (e)=> derr("GlobalError:", e.message, e.error || "" ));
    window.addEventListener("unhandledrejection", (e)=> derr("UnhandledRejection:", e.reason));

    // (Optional) instrument fetch to see *all* requests
    (function(){
        const _fetch = window.fetch;
        window.fetch = async (...args)=>{
            try{
                const [req, init] = args;
                const url = typeof req === "string" ? req : req?.url;
                dlog("fetch >", url, init || {});
                const res = await _fetch(...args);
                const clone = res.clone();
                let text = "";
                try { text = await clone.text(); } catch(_) {}
                dlog("fetch <", res.status, url, text?.slice(0, 1200));
                return res;
            }catch(e){
                derr("fetch FAILED", e);
                throw e;
            }
        };
    })();

    /* Convenience: validate and unwrap your envelope */
    function unwrapServerPayload(resp){
        // If server already parsed to JSON, fine. If it's a string, try to parse.
        if (typeof resp === "string") {
            try { resp = JSON.parse(resp); }
            catch(e){ throw new Error("JSON parse failed: " + (resp?.slice?.(0,200) || "")); }
        }

        // Accept either raw array or {data:[...], ...}
        if (Array.isArray(resp)) return { rows: resp, total: resp.length, last_page: 1 };

        const data = resp?.data;
        if (Array.isArray(data)) {
            return {
                rows: data,
                total: Number(resp?.total ?? data.length) || data.length,
                last_page: Number(resp?.last_page ?? 1) || 1,
            };
        }

        // Nothing matched: throw detailed error
        const shape = resp && typeof resp === "object" ? Object.keys(resp) : typeof resp;
        const hint = "Expected an array or an object with a `data` array.\n"
            + "Got: " + (shape || "undefined");
        const sample = typeof resp === "object" ? JSON.stringify(resp).slice(0,800) : String(resp).slice(0,800);
        throw new Error("Bad payload shape. " + hint + "\nSample: " + sample);
    }
</script>

<?php include 'includes/footer.php'; ?>
