<?php
require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';
include 'includes/header.php';
include 'includes/nav.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
$pdo = Database::getConnection();
$assignMode = isset($_GET['assign_mode']) && $_GET['assign_mode'] == '1';

/* ===== Preloads ===== */

// Referees (value = uuid)
$referees = $pdo->query("
    SELECT r.uuid, r.first_name, r.last_name, r.grade
    FROM referees r
    ORDER BY r.first_name, r.last_name
")->fetchAll(PDO::FETCH_ASSOC);

// Assigners (users) (value = uuid)
$assigners = $pdo->query("
    SELECT u.uuid, u.username
    FROM users u
    ORDER BY u.username
")->fetchAll(PDO::FETCH_ASSOC);

// Teams with division, district, and club default location (uuid + label)
$teams = $pdo->query("
    SELECT
        t.uuid,
        t.team_name,
        t.division,
        d.name AS district_name,
        l.uuid AS default_location_uuid,
        CASE 
          WHEN COALESCE(l.name, '') <> '' AND COALESCE(l.address_text, '') <> ''
            THEN CONCAT(l.name, ', ', l.address_text)
          ELSE COALESCE(l.name, l.address_text, '')
        END AS default_location_label
    FROM teams t
    LEFT JOIN districts d ON d.id = t.district_id
    LEFT JOIN clubs     c ON c.uuid = t.club_id
    LEFT JOIN locations l ON l.uuid = c.location_uuid
    ORDER BY t.team_name
")->fetchAll(PDO::FETCH_ASSOC);

// All locations for datalist
$locations = $pdo->query("
  SELECT uuid, name, address_text,
         CASE
           WHEN COALESCE(name,'')<>'' AND COALESCE(address_text,'')<>'' THEN CONCAT(name,' – ',address_text)
           ELSE COALESCE(name, address_text, '')
         END AS label
  FROM locations
  ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<link href="https://unpkg.com/tabulator-tables@6.2.5/dist/css/tabulator.min.css" rel="stylesheet">
<script src="https://unpkg.com/tabulator-tables@6.2.5/dist/js/tabulator.min.js"></script>
<link rel="stylesheet" href="/css/matches.css">

<div class="container matches-container">
    <div class="content-card">
        <div class="referees-hero">
            <div class="referees-hero__bg"></div>
            <div class="referees-hero__content">
                <div class="toolbar">
                    <div class="toolbar-left">
                        <div class="title-wrap">
                            <h1 class="referees-title">Matches</h1>
                            <p class="referees-subtitle">Search, filter & assign officials quickly.</p>
                        </div>
                        <div class="filters-row">
                            <div class="input-with-icon search">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M21 21l-4.3-4.3M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                </svg>
                                <input id="globalFilter" class="form-control" placeholder="Search teams, division, district… ( / to focus )">
                            </div>
                            <div class="date-pair">
                                <div class="date-col">
                                    <small class="text-muted d-block">From</small>
                                    <input type="date" id="startDate" class="form-control">
                                </div>
                                <div class="date-col">
                                    <small class="text-muted d-block">To</small>
                                    <input type="date" id="endDate" class="form-control">
                                </div>
                            </div>
                            <div class="quick-ranges">
                                <button class="btn btn-ghost" data-range="today">Today</button>
                                <button class="btn btn-ghost" data-range="weekend">Weekend</button>
                                <button class="btn btn-ghost" data-range="next7">Next 7</button>
                            </div>
                        </div>
                    </div>

                    <div class="toolbar-right actions">
                        <?php if ($assignMode): ?>
                            <a href="matches.php" class="btn btn-neutral">Disable Assign</a>
                            <button type="button" id="suggestAssignments" class="btn btn-accent">Suggest</button>
                            <button type="button" id="clearAllAssignments" class="btn btn-outline">Clear</button>
                        <?php else: ?>
                            <a href="matches.php?assign_mode=1" class="btn btn-accent">Enable Assign</a>
                            <button type="button" id="newMatchBtn" class="btn btn-accent">New Match</button>
                        <?php endif; ?>
                        <button type="button" id="exportCsv" class="btn btn-outline">Export CSV</button>
                        <button type="button" id="importMatchesBtn" class="btn btn-outline">Import</button>
                        <a href="assign_assigner.php" class="btn btn-neutral">Assign Assigner</a>
                    </div>
                </div>
            </div>

            <div class="conflict-legend" aria-label="Conflict legend">
                <span class="dot sev-red"></span> Red (overlap / diff venue same day)
                <span class="dot sev-orange"></span> Orange (same venue same day, no overlap)
                <span class="dot sev-yellow"></span> Yellow (±2 days proximity)
                <span class="dot sev-unavail"></span> Unavailable
            </div>
        </div>

        <div id="table-stats" class="table-stats"></div>
        <div class="table-viewport">
            <div id="matches-table" data-density="cozy"></div>
        </div>
    </div>
</div>
<!-- Import Matches Modal -->
<div id="importMatchesModal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="importMatchesTitle">
    <div class="modal__dialog">
        <div class="modal__header">
            <h3 id="importMatchesTitle">Import Matches</h3>
            <button type="button" class="modal__close" aria-label="Close">×</button>
        </div>
        <div class="modal__body">
            <form id="importMatchesForm">
                <p class="text-muted">
                    Upload a .csv / .xlsx with headers: <strong>Date</strong>, <strong>Time KO</strong>, <strong>Home Team</strong>, <strong>Away Team</strong>.
                    Teams must already exist. Duplicates are <em>Date + Home Team</em>.
                </p>
                <input type="file" name="file" id="importFile" accept=".csv,.xlsx,.xls" required>
                <div class="add-row__actions" style="margin-top:12px">
                    <button type="button" class="btn btn-neutral" id="importCancelBtn">Cancel</button>
                    <button type="submit" class="btn btn-accent" id="importSubmitBtn">Import</button>
                </div>
            </form>
            <div id="importResult" class="card-like" style="display:none"></div>
        </div>
    </div>
</div>

<!-- Add Match Modal -->
<div id="newMatchModal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="newMatchTitle">
    <div class="modal__dialog">
        <div class="modal__header">
            <h3 id="newMatchTitle">New Match</h3>
            <button type="button" class="modal__close" aria-label="Close">×</button>
        </div>
        <div class="modal__body">
            <div class="add-row card-like" id="addMatchRow">
                <div class="add-row__grid">
                    <label>Date*   <input type="date" id="add_date"></label>
                    <label>KO      <input type="time" id="add_ko"></label>

                    <label>Home*
                        <input list="home_list" id="add_home" placeholder="Start typing…" autocomplete="off">
                        <datalist id="home_list"></datalist>
                        <input type="hidden" id="add_home_uuid">
                    </label>

                    <label>Away*
                        <input list="away_list" id="add_away" placeholder="Start typing…" autocomplete="off">
                        <datalist id="away_list"></datalist>
                        <input type="hidden" id="add_away_uuid">
                    </label>

                    <label>Division <input type="text" id="add_div"></label>
                    <label>District <input type="text" id="add_dist"></label>
                    <label>Poule    <input type="text" id="add_poule"></label>

                    <label>Location
                        <input list="location_list" id="add_loc" placeholder="Type to search…" autocomplete="off">
                        <datalist id="location_list"></datalist>
                        <input type="hidden" id="add_location_uuid">
                        <small class="text-muted">Not found?
                            <a href="#" id="loc_quick_add_link">create new</a>
                        </small>
                    </label>
                </div>

                <div class="add-row__actions">
                    <button class="btn btn-neutral" id="add_clear" type="button">Clear</button>
                    <button class="btn btn-accent"  id="add_create" type="button">Add match</button>
                </div>

                <div class="form-error" id="add_err" style="display:none;"></div>
            </div>
        </div>
    </div>
</div>

<script>
    /* ===== Preloaded option maps ===== */
    const teamOptions = (() => {
        const map = {};
        <?php foreach ($teams as $t): ?>
        map["<?= h($t['uuid']) ?>"] = "<?= h($t['team_name']) ?>";
        <?php endforeach; ?>
        return map;
    })();

    const teamMeta = {
        <?php foreach ($teams as $t): ?>
        "<?= h($t['uuid']) ?>": {
            name: "<?= h($t['team_name']) ?>",
            division: "<?= h($t['division'] ?? '') ?>",
            district: "<?= h($t['district_name'] ?? '') ?>",
            defaultLocationUuid: "<?= h($t['default_location_uuid'] ?? '') ?>",
            defaultLocationLabel: "<?= h($t['default_location_label'] ?? '') ?>",
        },
        <?php endforeach; ?>
    };
    function openModal(modal){
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }
    function closeModal(modal){
        modal.classList.add('hidden');
        document.body.style.overflow = '';
    }

    const locationOptions = (() => {
        const map = {};
        <?php foreach ($locations as $loc): ?>
        map["<?= h($loc['uuid']) ?>"] = "<?= h($loc['label']) ?>";
        <?php endforeach; ?>
        return map;
    })();

    const refereeOptions = (() => {
        const map = {};
        <?php foreach ($referees as $r):
        $grade = $r['grade'] ?? '';
        $gradeTag = $grade !== '' ? ' ('.h($grade).')' : '';
        $label = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) . $gradeTag;
        ?>
        map["<?= h($r['uuid']) ?>"] = "<?= $label ?>";
        <?php endforeach; ?>
        return map;
    })();

    const refereeMeta = {
        <?php foreach ($referees as $r): ?>
        "<?= h($r['uuid']) ?>": { grade: "<?= h($r['grade'] ?? '') ?>" },
        <?php endforeach; ?>
    };

    const assignerOptions = (() => {
        const map = {};
        <?php foreach ($assigners as $u): ?>
        map["<?= h($u['uuid']) ?>"] = "<?= h($u['username']) ?>";
        <?php endforeach; ?>
        return map;
    })();

    /* ===== General helpers ===== */
    const ASSIGN_FIELDS = ["referee_id","ar1_id","ar2_id","commissioner_id"];
    let table;
    const baselineById = new Map();
    function captureBaseline(tbl){
        baselineById.clear();
        tbl.getRows().forEach(r=>{
            const d=r.getData();
            baselineById.set(d.uuid, Object.fromEntries(ASSIGN_FIELDS.map(f=>[f, d[f] ?? null])));
        });
    }
    function htmlesc(s){ const d=document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }

    /* ===== Location datalist binding ===== */
    (function initLocationDatalist(){
        const list = document.getElementById('location_list');
        const frag = document.createDocumentFragment();
        Object.entries(locationOptions).forEach(([uuid, label])=>{
            const o = document.createElement('option');
            o.value = label; o.dataset.uuid = uuid;
            frag.appendChild(o);
        });
        list.appendChild(frag);
    })();

    function resolveFromDatalist(inputEl, listElId){
        const list = document.getElementById(listElId);
        const val = inputEl.value.trim().toLowerCase();
        if (!list) return '';
        // exact
        for (const opt of list.options) {
            if (opt.value.toLowerCase() === val) return opt.dataset.uuid || '';
        }
        // prefix (2+ chars)
        if (val.length >= 2) {
            for (const opt of list.options) {
                if (opt.value.toLowerCase().startsWith(val)) return opt.dataset.uuid || '';
            }
        }
        return '';
    }

    (function wireLocationBinding(){
        const locInput = document.getElementById('add_loc');
        const locUUID  = document.getElementById('add_location_uuid');
        function onChange(){
            const id = resolveFromDatalist(locInput, 'location_list'); // '' if none
            locUUID.value = id;
        }
        ['input','change','blur'].forEach(ev => locInput.addEventListener(ev, onChange));
    })();

    /* ===== Team datalists ===== */
    (function initTeamDatalists(){
        const homeDL = document.getElementById('home_list');
        const awayDL = document.getElementById('away_list');
        const fragH = document.createDocumentFragment();
        const fragA = document.createDocumentFragment();
        Object.entries(teamOptions).forEach(([uuid, name])=>{
            const o1 = document.createElement('option'); o1.value = name; o1.dataset.uuid = uuid; fragH.appendChild(o1);
            const o2 = document.createElement('option'); o2.value = name; o2.dataset.uuid = uuid; fragA.appendChild(o2);
        });
        homeDL.appendChild(fragH); awayDL.appendChild(fragA);
    })();

    function resolveTeamUUID(inputEl, listElId){
        const list = document.getElementById(listElId);
        const val = inputEl.value.trim().toLowerCase();
        for (const opt of list.options) {
            if (opt.value.toLowerCase() === val) return opt.dataset.uuid || '';
        }
        if (val.length >= 2){
            for (const opt of list.options) {
                if (opt.value.toLowerCase().startsWith(val)) return opt.dataset.uuid || '';
            }
        }
        return '';
    }

    /* ===== Autofill from Home team ===== */
    (function wireHomeAutofill(){
        const homeInput      = document.getElementById('add_home');
        const homeUUIDHidden = document.getElementById('add_home_uuid');
        const divEl          = document.getElementById('add_div');
        const distEl         = document.getElementById('add_dist');
        const locInput       = document.getElementById('add_loc');
        const locUUID        = document.getElementById('add_location_uuid');

        function applyMeta(uuid){
            const meta = teamMeta[uuid];
            if (!meta) return;

            // Copy division/district if empty
            if (!divEl.value)  divEl.value  = meta.division || '';
            if (!distEl.value) distEl.value = meta.district || '';

            // ---- Location autofill (prefer exact UUID if the club has one) ----
            if (!locUUID.value) {
                if (meta.defaultLocationUuid) {
                    // We have a real location id from the club -> fill both
                    locUUID.value = meta.defaultLocationUuid;
                    // Use label from options if known, otherwise the PHP-provided label
                    locInput.value = locationOptions[meta.defaultLocationUuid]
                        || meta.defaultLocationLabel
                        || '';
                } else if (meta.defaultLocationLabel) {
                    // No UUID, only a human label -> try resolving via datalist
                    const fakeInput = { value: meta.defaultLocationLabel };
                    const resolved = resolveFromDatalist(fakeInput, 'location_list'); // '' if not found
                    if (resolved) {
                        locUUID.value = resolved;
                        locInput.value = locationOptions[resolved] || meta.defaultLocationLabel;
                    } else {
                        // Keep the text so user can tweak; uuid stays empty
                        locInput.value = meta.defaultLocationLabel;
                    }
                }
            }
        }

        function onHomeChanged(){
            const uuid = resolveTeamUUID(homeInput, 'home_list');
            homeUUIDHidden.value = uuid;
            if (uuid) applyMeta(uuid);
        }

        homeInput.addEventListener('change', onHomeChanged);
        homeInput.addEventListener('blur',   onHomeChanged);
    })();

    /* Optional: capture Away UUID (future use) */
    (function wireAwayResolver(){
        const awayInput = document.getElementById('add_away');
        const awayUUIDHidden = document.getElementById('add_away_uuid');
        function onAwayChanged(){
            const uuid = resolveTeamUUID(awayInput, 'away_list');
            awayUUIDHidden.value = uuid;
        }
        awayInput.addEventListener('change', onAwayChanged);
        awayInput.addEventListener('blur', onAwayChanged);
    })();

    /* ===== Modal open/close ===== */
    (function(){
        const modal = document.getElementById('newMatchModal');
        const openBtn = document.getElementById('newMatchBtn');
        const closeBtn = modal?.querySelector('.modal__close');

        function open(){
            modal.classList.remove('hidden');
            (document.getElementById('add_date') || document.getElementById('add_home'))?.focus();
        }
        function close(){ modal.classList.add('hidden'); }

        openBtn?.addEventListener('click', open);
        closeBtn?.addEventListener('click', close);
        modal?.addEventListener('click', (e)=>{ if (e.target === modal) close(); });
        document.addEventListener('keydown', (e)=>{ if (e.key === 'Escape' && !modal.classList.contains('hidden')) close(); });
    })();

    /* ===== Create match (POST location_uuid) ===== */
    (function wireCreate(){
        const err = document.getElementById('add_err');
        const btn = document.getElementById('add_create');

        function showErr(msg){ err.textContent = msg; err.style.display = 'block'; }
        function clearErr(){ err.textContent = ''; err.style.display = 'none'; }

        async function create(){
            clearErr();
            const date = document.getElementById('add_date').value;
            const ko   = document.getElementById('add_ko').value;
            const homeTxt = document.getElementById('add_home').value.trim();
            const awayTxt = document.getElementById('add_away').value.trim();
            const homeUuid = document.getElementById('add_home_uuid').value.trim() || resolveTeamUUID(document.getElementById('add_home'),'home_list');
            const awayUuid = document.getElementById('add_away_uuid').value.trim() || resolveTeamUUID(document.getElementById('add_away'),'away_list');
            const div  = document.getElementById('add_div').value.trim();
            const dist = document.getElementById('add_dist').value.trim();
            const pou  = document.getElementById('add_poule').value.trim();
            const locUuid = document.getElementById('add_location_uuid').value.trim();

            if (!date || !homeTxt || !awayTxt){
                showErr('Please fill Date, Home and Away.'); return;
            }
            if (!homeUuid){
                showErr('Please select a valid Home team from the list.'); return;
            }
            if (!awayUuid){
                showErr('Please select a valid Away team from the list.'); return;
            }
            // Note: locUuid can be empty if unknown; server can allow NULL

            btn.disabled = true; const old = btn.textContent; btn.textContent = 'Adding…';
            try{
                const res = await fetch('/ajax/create_match.php', {
                    method:'POST',
                    headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        match_date: date,
                        kickoff_time: ko,

                        // send uuids you resolved (preferred)
                        home_team_uuid: homeUuid,
                        away_team_uuid: awayUuid,

                        // if your endpoint still wants display names too, send the text versions:
                        home_team: homeTxt,
                        away_team: awayTxt,

                        division: div,
                        district: dist,
                        poule: pou,

                        // location: send uuid + label (uuid may be '')
                        location_uuid: locUuid,
                        location_label: document.getElementById('add_loc').value.trim()
                    })
                });
                const j = await res.json();
                if (!j?.success) throw new Error(j?.message || 'Create failed');

                await table.addData([j.row], true);
                applyConflictClasses(table);
                captureBaseline(table);

                // keep Date/KO for quick entry; clear the rest
                ['add_home','add_home_uuid','add_away','add_away_uuid','add_div','add_dist','add_poule','add_loc','add_location_uuid']
                    .forEach(id => { const el=document.getElementById(id); if(el) el.value=''; });

                // Close modal
                document.getElementById('newMatchModal')?.classList.add('hidden');
            }catch(e){
                showErr(e.message || 'Create failed');
            }finally{
                btn.disabled=false; btn.textContent = old;
            }
        }

        btn.addEventListener('click', create);
        document.getElementById('add_clear').addEventListener('click', (e)=>{
            e.preventDefault();
            ['add_home','add_home_uuid','add_away','add_away_uuid','add_div','add_dist','add_poule','add_loc','add_location_uuid']
                .forEach(id => { const el = document.getElementById(id); if (!el) return; el.value = ''; });
            clearErr();
        });
    })();
    // ===== Import Matches (modal + upload) =====
    (function(){
        const modal = document.getElementById('importMatchesModal');
        const openBtn = document.getElementById('importMatchesBtn');
        const closeBtn = modal?.querySelector('.modal__close');
        const cancelBtn = document.getElementById('importCancelBtn');
        const form = document.getElementById('importMatchesForm');
        const fileInput = document.getElementById('importFile');
        const resultBox = document.getElementById('importResult');
        const submitBtn = document.getElementById('importSubmitBtn');

        function open(){
            resultBox.style.display = 'none';
            resultBox.innerHTML = '';
            fileInput.value = '';
            modal.classList.remove('hidden');
        }
        function close(){ modal.classList.add('hidden'); }

        openBtn?.addEventListener('click', open);
        closeBtn?.addEventListener('click', close);
        cancelBtn?.addEventListener('click', close);
        modal?.addEventListener('click', (e)=>{ if (e.target === modal) close(); });
        document.addEventListener('keydown', (e)=>{ if (e.key === 'Escape' && !modal.classList.contains('hidden')) close(); });

        form?.addEventListener('submit', async (e)=>{
            e.preventDefault();
            if (!fileInput.files?.length) return;

            submitBtn.disabled = true;
            const old = submitBtn.textContent;
            submitBtn.textContent = 'Importing…';

            try {
                const fd = new FormData();
                fd.append('file', fileInput.files[0]);
                const resp = await fetch('/ajax/import_matches.php', { method:'POST', body: fd });
                const data = await resp.json();

                // Show summary
                resultBox.style.display = 'block';
                if (!data?.success) {
                    resultBox.innerHTML = `<div class="form-error">${(data?.message || 'Import failed')}</div>`;
                    return;
                }
                const errs = Array.isArray(data.errors) ? data.errors : [];
                const dup = Number(data.skipped_duplicates || 0);
                const ins = Number(data.inserted || 0);
                resultBox.innerHTML = `
        <div>
          <p><strong>Imported:</strong> ${ins}</p>
          <p><strong>Duplicates skipped:</strong> ${dup}</p>
          ${errs.length ? `<details><summary>${errs.length} error(s)</summary><ul>${errs.map(e=>`<li>${e.replace(/[<>&]/g, s => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[s]))}</li>`).join('')}</ul></details>` : ''}
        </div>`;

                // Refresh the table (keeps current filters/pagination logic)
                try { table.setData(); } catch(e){ console.warn('Table refresh failed', e); }

            } catch (err) {
                resultBox.style.display = 'block';
                resultBox.innerHTML = `<div class="form-error">${(err?.message || 'Import failed')}</div>`;
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = old;
            }
        });
    })();


    /* ===== Conflicts + Tabulator (unchanged logic, plus optional Location column) ===== */

    const SEV = { NONE:0, YELLOW:1, ORANGE:2, RED:3, UNAVAILABLE:4 };
    const sevClass = (s) =>
        s===SEV.UNAVAILABLE ? "sev-unavail" :
            s===SEV.RED        ? "sev-red"     :
                s===SEV.ORANGE     ? "sev-orange"  :
                    s===SEV.YELLOW     ? "sev-yellow"  : "";

    const parseDT  = (d,t)=>{ const hhmm=(t||"00:00").slice(0,5); return new Date(`${d}T${hhmm}:00`); };
    const dayDiff  = (a,b)=> Math.round((new Date(b+"T00:00:00") - new Date(a+"T00:00:00"))/86400000);
    const normAddr = (s)=> (s||"").toLowerCase().replace(/[.,;:()\-]/g," ").replace(/\s+/g," ").trim();

    function computeConflicts(rows){
        const recs = [];
        rows.forEach(r=>{
            const d = r.getData();
            ["referee_id","ar1_id","ar2_id","commissioner_id"].forEach(role=>{
                const refId=d[role]; if(!refId) return;
                const start = parseDT(d.match_date, d.kickoff_time);
                recs.push({
                    key: `${d.uuid}:${role}`,
                    matchId: d.uuid, role, refId,
                    dateStr: d.match_date,
                    start, end: new Date(start.getTime()+90*60*1000),
                    loc: normAddr(d.location_label || d.location_address || "") // location_label expected from API
                });
            });
        });

        const sev = new Map();
        const bump=(k,s)=>{ const cur=sev.get(k) ?? SEV.NONE; if(s>cur) sev.set(k,s); };

        const byRef = {};
        recs.forEach(r => (byRef[r.refId] ||= []).push(r));
        Object.values(byRef).forEach(list=>{
            list.sort((a,b)=> a.start - b.start);
            const byMatch = {};
            list.forEach(r => (byMatch[r.matchId] ||= []).push(r));
            Object.values(byMatch).forEach(arr=>{ if (arr.length>1) arr.forEach(r=> bump(r.key, SEV.RED)); });

            for (let i=0;i<list.length;i++){
                for (let j=i+1;j<list.length;j++){
                    const A=list[i], B=list[j];
                    const dd = dayDiff(A.dateStr, B.dateStr);
                    if (dd === 0){
                        if (A.start < B.end && B.start < A.end){ bump(A.key,SEV.RED); bump(B.key,SEV.RED); continue; }
                        if (A.loc && B.loc && A.loc !== B.loc){ bump(A.key,SEV.RED); bump(B.key,SEV.RED); continue; }
                        bump(A.key,SEV.ORANGE); bump(B.key,SEV.ORANGE);
                    } else if (Math.abs(dd) <= 2){
                        bump(A.key,SEV.YELLOW); bump(B.key,SEV.YELLOW);
                    }
                }
            }
        });
        return sev;
    }

    function applyConflictClasses(tbl){
        const rows = tbl.getRows(true);
        const sevMap = computeConflicts(rows);
        rows.forEach(r=>{
            const d = r.getData();
            ["referee_id","ar1_id","ar2_id","commissioner_id"].forEach(role=>{
                const cell = r.getCell(role); if(!cell) return;
                const el = cell.getElement();
                el.classList.remove("sev-unavail","sev-red","sev-orange","sev-yellow");
                const cls = sevClass(sevMap.get(`${d.uuid}:${role}`) ?? SEV.NONE);
                if (cls) el.classList.add(cls);
            });
        });
    }

    /* ===== Tabulator init ===== */
    const ASSIGN_MODE = <?= $assignMode ? 'true' : 'false' ?>;

    function saveAssignment(matchUuid, field, value) {
        return fetch('/ajax/update_match_assignment.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: `match_uuid=${encodeURIComponent(matchUuid)}&field=${encodeURIComponent(field)}&value=${encodeURIComponent(value || '')}`
        }).then(r => r.json()).catch(() => ({success:false}));
    }
    function saveAssigner(matchUuid, assignerUuid) {
        return fetch('/ajax/update_match_field.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: `match_uuid=${encodeURIComponent(matchUuid)}&field_type=referee_assigner&new_value=${encodeURIComponent(assignerUuid || '')}`
        }).then(r => r.json()).catch(() => ({success:false}));
    }
    function formatRefCell(cell){
        const id = cell.getValue();
        const label = id ? (refereeOptions[id] || "—") : "—";
        const g = (refereeMeta[id]?.grade || "").toUpperCase();
        const klass = ["A","B","C","D"].includes(g) ? `chip-${g}` : "chip-E";
        const grade = g ? ` <span class="badge ${klass}">${g}</span>` : "";
        const clearBtn = ASSIGN_MODE ? `<button class="assign-clear-btn" type="button" title="Unassign" aria-label="Unassign">×</button>` : "";
        return `<span class="assign-text">${label}${grade}</span>${clearBtn}`;
    }
    function editorParamsFactory(){
        const values = { "": "— Unassigned —" };
        Object.entries(refereeOptions).forEach(([v,l])=> values[v]=l);
        return { values, autocomplete: true, listOnEmpty: true };
    }
    function makeRefereeCol(title, field, minWidth = 200){
        return {
            title, field, minWidth,
            formatter: formatRefCell,
            editor: ASSIGN_MODE ? "list" : false,
            editorParams: editorParamsFactory,
            accessorDownload: (value) => refereeOptions[value] || "",
            cellEdited: async (cell) => {
                const row = cell.getRow().getData();
                const newVal = cell.getValue() || "";
                try {
                    const res = await saveAssignment(row.uuid, field, newVal);
                    if (!res?.success) throw new Error(res?.message || "Save failed");
                    const base = baselineById.get(row.uuid) || {};
                    base[field] = newVal || null;
                    baselineById.set(row.uuid, base);
                } catch (err) {
                    cell.setValue(cell.getOldValue(), true);
                    alert("Saving assignment failed.");
                }
            },
        };
    }
    function makeAssignerCol(){
        const base = {
            title: "Assigner",
            field: "referee_assigner_uuid",
            headerFilter: "input",
            minWidth: 130,
            formatter: (cell) => assignerOptions[cell.getValue()] || "—",
            accessorDownload: (value) => assignerOptions[value] || "",
        };
        if (!ASSIGN_MODE) return base;
        return {
            ...base,
            editor: "list",
            editorParams: () => ({
                values: { "": "— Unassigned —", ...assignerOptions },
                autocomplete: true,
                listOnEmpty: true,
            }),
            cellEdited: async (cell) => {
                const row  = cell.getRow().getData();
                const uuid = cell.getValue() || "";
                try {
                    const res = await saveAssigner(row.uuid, uuid);
                    if (!res?.success) throw new Error(res?.message || "Save failed");
                    table.updateData([{ uuid: row.uuid, referee_assigner_username: assignerOptions[uuid] || null }]);
                } catch (err) {
                    cell.setValue(cell.getOldValue(), true);
                    alert("Saving assigner failed.");
                }
            },
        };
    }

    // Brace-depth JSON splitter (handles `}{` and no newlines)
    function extractJsonObjects(buffer) {
        const out = [];
        let depth = 0, inStr = false, esc = false, start = 0;
        for (let i = 0; i < buffer.length; i++) {
            const ch = buffer[i];
            if (inStr) {
                if (esc) { esc = false; continue; }
                if (ch === '\\') { esc = true; continue; }
                if (ch === '"') { inStr = false; }
                continue;
            } else {
                if (ch === '"') { inStr = true; continue; }
                if (ch === '{') { if (depth === 0) start = i; depth++; continue; }
                if (ch === '}') {
                    depth--;
                    if (depth === 0) out.push(buffer.slice(start, i + 1));
                    continue;
                }
            }
        }
        const remainder = depth > 0 ? buffer.slice(start) : '';
        return { objects: out, remainder };
    }

    document.addEventListener('DOMContentLoaded', () => {
        const statsEl = document.getElementById('table-stats');
        const startEl = document.getElementById('startDate');
        const endEl   = document.getElementById('endDate');
        const searchEl= document.getElementById('globalFilter');

        table = new Tabulator("#matches-table", {
            index: "uuid",
            height: "70vh",
            layout: "fitColumns",
            columnMinWidth: 120,
            placeholder: "No matches found",
            ajaxURL: "/ajax/matches_list.php",
            ajaxConfig: "GET",
            pagination: true,
            paginationMode: "local",
            paginationSize: 50,
            paginationSizeSelector: [50, 100, 200],
            paginationDataReceived: { last_page: "last_page", data: "data" },

            cellContextMenu: function(cell){
                const field = cell.getField();
                if (!["referee_id","ar1_id","ar2_id","commissioner_id"].includes(field)) return [];
                return [{
                    label: "Unassign",
                    action: function(e, c){
                        const row = c.getRow().getData();
                        const oldVal = c.getValue();
                        table.updateData([{ uuid: row.uuid, [field]: "" }]);
                        saveAssignment(row.uuid, field, "").then(res => {
                            if (!res?.success) table.updateData([{ uuid: row.uuid, [field]: oldVal }]);
                        }).catch(() => {
                            table.updateData([{ uuid: row.uuid, [field]: oldVal }]);
                        });
                    },
                }];
            },

            ajaxRequestFunc: (url, config, params) => {
                const q = new URLSearchParams();
                q.set("all", "1");

                if (Array.isArray(params.sort) && params.sort.length) {
                    q.set("sort_col", params.sort[0].field);
                    q.set("sort_dir", params.sort[0].dir);
                }
                const s = startEl?.value, e = endEl?.value, gl = searchEl?.value?.trim();
                if (s) q.set("start_date", s);
                if (e) q.set("end_date", e);
                if (gl) q.set("search", gl);
                q.set("_ts", Date.now().toString());
                const finalURL = `${url}?${q.toString()}`;
                return fetch(finalURL, { method: "GET", headers: { Accept: "application/json" } })
                    .then(async (r) => {
                        const raw = await r.text();
                        try { return JSON.parse(raw); } catch { return raw; }
                    });
            },

            ajaxResponse: (url, params, resp) => {
                if (typeof resp === "string") { try { resp = JSON.parse(resp); } catch { return []; } }
                const rows = Array.isArray(resp) ? resp : (Array.isArray(resp?.data) ? resp.data : []);
                // optional stats:
                const total = Array.isArray(resp) ? resp.length : (Number(resp?.total) || rows.length);
                const statsEl = document.getElementById('table-stats');
                if (statsEl) statsEl.textContent = `Showing ${rows.length} of ${total} matches`;
                return rows;
            },

            columns: [
                {
                    title: "", field: "actions", width: 56, hozAlign: "center", headerSort: false, frozen: true,
                    formatter: () => `
                        <button class="btn btn-ghost btn-icon delete-row-btn" title="Delete match" aria-label="Delete match">
                          🗑️
                        </button>`,
                    cellClick: (e, cell) => {
                        const rowData = cell.getRow().getData();
                        confirmAndDeleteMatch(rowData.uuid, cell.getRow());
                    }
                },
                {
                    title: "Date", field: "match_date", width: 120, sorter: "string", headerFilter: "input",
                    formatter: (cell) => {
                        const d = cell.getValue();
                        const id = cell.getRow().getData().uuid;
                        return `<a href="match_detail.php?uuid=${encodeURIComponent(id)}">${htmlesc(d || "")}</a>`;
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
                { title: "Location", field: "location_label", headerFilter: "input", minWidth: 200, visible: false}, // expects from API
                makeAssignerCol(),
                makeRefereeCol("Referee",      "referee_id"),
                makeRefereeCol("AR1",          "ar1_id"),
                makeRefereeCol("AR2",          "ar2_id"),
                makeRefereeCol("Commissioner", "commissioner_id"),
            ],
        });

        table.on("dataLoaded", () => { applyConflictClasses(table); captureBaseline(table); });
        table.on("dataFiltered", () => { if (statsEl) statsEl.textContent = `Showing ${table.getDataCount("active")} of ${table.getDataCount()} matches`; });
        table.on("dataProcessed", ()=> applyConflictClasses(table));
        table.on("renderComplete",()=> applyConflictClasses(table));
        table.on("pageLoaded",     ()=>{ applyConflictClasses(table); captureBaseline(table); });
        table.on("cellEdited",     ()=> applyConflictClasses(table));

        table.on("cellClick", (e, cell) => {
            const btn = e.target.closest(".assign-clear-btn");
            if (!btn) return;
            const field = cell.getField();
            if (!["referee_id","ar1_id","ar2_id","commissioner_id"].includes(field)) return;
            e.stopPropagation();
            const row = cell.getRow().getData();
            const oldVal = cell.getValue();
            table.updateData([{ uuid: row.uuid, [field]: "" }]);
            saveAssignment(row.uuid, field, "").then(res => {
                if (!res?.success) table.updateData([{ uuid: row.uuid, [field]: oldVal }]);
            }).catch(() => {
                table.updateData([{ uuid: row.uuid, [field]: oldVal }]);
            });
        });

        // Filters -> refresh
        const triggerRefresh = () => table.setData();
        document.getElementById('globalFilter')?.addEventListener('input', triggerRefresh);
        document.getElementById('startDate')?.addEventListener('change', triggerRefresh);
        document.getElementById('endDate')?.addEventListener('change', triggerRefresh);

        // Quick ranges
        function yyyy_mm_dd(dt){ return dt.toISOString().slice(0,10); }
        function setRange(start, end){
            const sEl = document.getElementById('startDate');
            const eEl = document.getElementById('endDate');
            sEl.value = yyyy_mm_dd(start); eEl.value = yyyy_mm_dd(end);
            sEl.dispatchEvent(new Event('change'));
        }
        function goToday(){ const d=new Date(); setRange(d, d); }
        function goWeekend(){
            const now=new Date(); const day=now.getDay();
            const sat=new Date(now); sat.setDate(now.getDate()+((6-day+7)%7));
            const sun=new Date(sat); sun.setDate(sat.getDate()+1);
            setRange(sat, sun);
        }
        function goNext7(){ const now=new Date(); const end=new Date(now); end.setDate(now.getDate()+7); setRange(now, end); }
        document.querySelectorAll('.quick-ranges .btn').forEach(b=>{
            b.addEventListener('click', ()=>({
                today: goToday, weekend: goWeekend, next7: goNext7
            })[b.dataset.range]?.());
        });

        // CSV
        document.getElementById('exportCsv')?.addEventListener('click', ()=>{
            if (typeof table?.download === "function") {
                const ts = new Date().toISOString().replace(/[:T]/g,'-').slice(0,16);
                table.download("csv", `matches-${ts}.csv`);
            } else {
                window.location.href = "export_matches.php";
            }
        });
    });

    async function deleteMatchOnServer(uuid){
        const res = await fetch('/ajax/delete_match.php', {
            method: 'POST',
            headers: { 'Content-Type':'application/x-www-form-urlencoded' },
            body: `match_uuid=${encodeURIComponent(uuid)}`
        });
        const j = await res.json().catch(() => ({}));
        if (!res.ok || !j?.success) {
            throw new Error(j?.message || `Delete failed (HTTP ${res.status})`);
        }
    }

    function confirmAndDeleteMatch(uuid, row){
        if (!confirm('Delete this match? This cannot be undone.')) return;
        row.getElement().style.opacity = 0.5;
        deleteMatchOnServer(uuid).then(() => {
            row.delete();
            try { applyConflictClasses(table); } catch(e){}
        }).catch(err => {
            alert(err.message || 'Delete failed');
            row.getElement().style.opacity = 1;
        });
    }

    // ---- Suggest button (streaming JSON parser; guard against double-binding) ----
    document.addEventListener('DOMContentLoaded', () => {
        const btn = document.getElementById('suggestAssignments');
        if (!btn) return;

        if (window.__suggestHandlerBound) return;
        window.__suggestHandlerBound = true;

        btn.addEventListener('click', async (event) => {
            const el = event.currentTarget;
            const original = el.textContent;
            el.disabled = true; el.textContent = 'Suggesting…';

            // Progress UI (optional)
            const barC = document.getElementById('suggestionProgressBarContainer');
            const bar  = document.getElementById('suggestionProgressBar');
            const txt  = document.getElementById('suggestionProgressText');
            const hasProgressUI = !!(barC && bar && txt);
            if (hasProgressUI) {
                barC.style.display = 'block';
                txt.style.display = 'block';
                bar.setAttribute('aria-valuenow', '0');
                txt.textContent = 'Starting...';
            }

            // Build query from current page filters
            const start  = document.getElementById('startDate')?.value || '';
            const end    = document.getElementById('endDate')?.value || '';
            const search = document.getElementById('globalFilter')?.value?.trim() || '';
            const qs = new URLSearchParams();
            if (start)  qs.set('start_date', start);
            if (end)    qs.set('end_date', end);
            if (search) qs.set('search', search);

            const url = '/suggest_weekend_referees.php' + (qs.toString() ? `?${qs}` : '');

            try {
                const res = await fetch(url);
                if (!res.ok) throw new Error(`HTTP ${res.status}`);

                // Always parse as stream (handles application/json and concatenated objects)
                let suggestions = {};
                const reader = res.body && res.body.getReader ? res.body.getReader() : null;

                if (reader) {
                    const decoder = new TextDecoder();
                    let buf = '';
                    for (;;) {
                        const { value, done } = await reader.read();
                        if (done) break;

                        buf += decoder.decode(value, { stream: true });

                        const { objects, remainder } = extractJsonObjects(buf);
                        buf = remainder;

                        for (const jsonStr of objects) {
                            try {
                                const obj = JSON.parse(jsonStr);
                                if (hasProgressUI && typeof obj.progress === 'number') {
                                    bar.style.setProperty('--progress-width', `${obj.progress}%`);
                                    bar.setAttribute('aria-valuenow', String(obj.progress));
                                    if (obj.message) txt.textContent = obj.message;
                                }
                                if (obj.suggestions) {
                                    // accumulate across chunks
                                    Object.assign(suggestions, obj.suggestions);
                                }
                            } catch (e) {
                                console.warn('Skipping malformed chunk:', e);
                            }
                        }
                    }

                    if (hasProgressUI) {
                        bar.style.setProperty('--progress-width', '100%');
                        bar.setAttribute('aria-valuenow', '100');
                        if (!txt.textContent) txt.textContent = 'Done';
                    }
                } else {
                    // Fallback: non-streaming
                    const text = await res.text();
                    const { objects } = extractJsonObjects(text);
                    if (objects.length) {
                        for (const s of objects) {
                            const obj = JSON.parse(s);
                            if (obj.suggestions) Object.assign(suggestions, obj.suggestions);
                        }
                    } else {
                        const obj = JSON.parse(text);
                        suggestions = obj.suggestions || {};
                    }
                }

                // Apply suggestions into Tabulator (safe-for-current-page version)
                const updates = [];
                for (const [uuid, roles] of Object.entries(suggestions || {})) {
                    const u = { uuid: String(uuid) };
                    if ('referee_id'      in roles) u.referee_id      = roles.referee_id      || '';
                    if ('ar1_id'          in roles) u.ar1_id          = roles.ar1_id          || '';
                    if ('ar2_id'          in roles) u.ar2_id          = roles.ar2_id          || '';
                    if ('commissioner_id' in roles) u.commissioner_id = roles.commissioner_id || '';
                    updates.push(u);
                }

// ---- KEY PART: filter to the rows Tabulator has loaded (current page) ----
                const currentIds = new Set((table.getData() || []).map(r => String(r.uuid)));
                const inPage     = updates.filter(u => currentIds.has(String(u.uuid)));
                const outOfPage  = updates.filter(u => !currentIds.has(String(u.uuid)));

                if (outOfPage.length) {
                    // purely informational; avoids the Tabulator "Find Error" spam
                    console.debug('[Suggest] skipped (not on this page):', outOfPage.length, outOfPage.slice(0,5).map(x=>x.uuid));
                }

                if (!inPage.length) {
                    alert('No suggested matches on this page. Try paging or widen the date filter.');
                } else {
                    try {
                        await table.updateData(inPage);        // single batch
                    } catch (e) {
                        console.warn('[Suggest] batch update rejected, falling back per-row:', e?.message || e);
                        // Per-row fallback so one bad row doesn’t tank the whole batch
                        for (const u of inPage) {
                            try { await table.updateData([u]); } catch (e2) { console.warn('Row failed', u.uuid, e2?.message || e2); }
                        }
                    }

                    applyConflictClasses(table);
                    // refresh baseline only for updated rows
                    inPage.forEach(u => {
                        const base = baselineById.get(u.uuid) || {};
                        ['referee_id','ar1_id','ar2_id','commissioner_id'].forEach(f => {
                            if (f in u) base[f] = u[f] || null;
                        });
                        baselineById.set(u.uuid, base);
                    });
                }

            } catch (err) {
                console.error('[Suggest] error:', err);
                alert('Suggest failed: ' + (err.message || 'Unknown error'));
                if (hasProgressUI) {
                    txt.textContent = 'An error occurred.';
                    bar.classList.add('bg-danger');
                }
            } finally {
                el.disabled = false; el.textContent = original;
                if (hasProgressUI) {
                    setTimeout(() => {
                        barC.style.display = 'none';
                        txt.style.display  = 'none';
                        bar.classList.remove('bg-danger');
                    }, 2000);
                }
            }
        });
    });
</script>

<?php include 'includes/footer.php'; ?>
