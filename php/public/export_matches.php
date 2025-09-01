<?php
require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';

// No direct output, clear any existing buffers
if (ob_get_level()) { ob_end_clean(); }

$pdo = Database::getConnection();

/* =======================
   Build filters / ACL
======================= */
$whereClauses = [];
$params       = [];

$userRole        = $_SESSION['user_role']    ?? null;
$userDivisionIds = $_SESSION['division_ids'] ?? [];
$userDistrictIds = $_SESSION['district_ids'] ?? [];

$allowedDivisionNames = [];
$allowedDistrictNames = [];
$canFetchData = true;

if ($userRole !== 'super_admin') {
    if (!empty($userDivisionIds) && !(count($userDivisionIds) === 1 && $userDivisionIds[0] === '')) {
        $ph = implode(',', array_fill(0, count($userDivisionIds), '?'));
        $stmtDiv = $pdo->prepare("SELECT name FROM divisions WHERE id IN ($ph)");
        $stmtDiv->execute($userDivisionIds);
        $allowedDivisionNames = $stmtDiv->fetchAll(PDO::FETCH_COLUMN);
    }

    if (!empty($userDistrictIds) && !(count($userDistrictIds) === 1 && $userDistrictIds[0] === '')) {
        $ph = implode(',', array_fill(0, count($userDistrictIds), '?'));
        $stmtDist = $pdo->prepare("SELECT name FROM districts WHERE id IN ($ph)");
        $stmtDist->execute($userDistrictIds);
        $allowedDistrictNames = $stmtDist->fetchAll(PDO::FETCH_COLUMN);
    }

    // If either is empty, user effectively has no scope
    if (empty($allowedDivisionNames) || empty($allowedDistrictNames)) {
        $canFetchData = false;
    } else {
        $ph = implode(',', array_fill(0, count($allowedDivisionNames), '?'));
        $whereClauses[] = "m.division IN ($ph)";
        array_push($params, ...$allowedDivisionNames);

        $ph = implode(',', array_fill(0, count($allowedDistrictNames), '?'));
        $whereClauses[] = "m.district IN ($ph)";
        array_push($params, ...$allowedDistrictNames);
    }
}

// Date filters
if (!empty($_GET['start_date'])) { $whereClauses[] = "m.match_date >= ?"; $params[] = $_GET['start_date']; }
if (!empty($_GET['end_date']))   { $whereClauses[] = "m.match_date <= ?"; $params[] = $_GET['end_date']; }

// Multi-select filters (division, district, poule)
foreach (['division','district','poule'] as $k) {
    if (!empty($_GET[$k])) {
        $vals = is_array($_GET[$k]) ? $_GET[$k] : [$_GET[$k]];
        if ($vals) {
            $ph = implode(',', array_fill(0, count($vals), '?'));
            $whereClauses[] = "m.$k IN ($ph)";
            array_push($params, ...$vals);
        }
    }
}

// Single-value filters
if (!empty($_GET['location_uuid']))        { $whereClauses[] = "m.location_uuid = ?";        $params[] = $_GET['location_uuid']; }
if (!empty($_GET['referee_assigner_uuid'])){ $whereClauses[] = "m.referee_assigner_uuid = ?"; $params[] = $_GET['referee_assigner_uuid']; }

$whereSQL = $whereClauses ? ('WHERE ' . implode(' AND ', $whereClauses)) : '';

/* =======================
   Query with name fallbacks
======================= */
/*
We build readable names and fall back to the raw UUID only when the name is missing.
This avoids UUIDs showing up in the export when the join doesn’t find a row.
*/
$sql = "
SELECT
    m.uuid AS match_id,
    m.match_date,
    m.kickoff_time,

    hc.club_name  AS home_club_name,
    ht.team_name  AS home_team_name,
    ac.club_name  AS away_club_name,
    at.team_name  AS away_team_name,

    m.division,
    m.district,
    m.poule,

    l.name         AS location_name,
    l.address_text AS location_address,

    ref_assigner.username AS referee_assigner_username,

    /* --- Names, no UUID fallbacks --- */
    CASE
      WHEN ref.uuid IS NULL THEN 'Unassigned'
      ELSE COALESCE(
             NULLIF(TRIM(CONCAT_WS(' ', ref.first_name,  ref.last_name)),  ''),
             NULLIF(ref.referee_id, ''),
             CAST(ref.ref_number AS CHAR),
             'Unassigned'
           )
    END AS referee_name,

    CASE
      WHEN ar1.uuid IS NULL THEN '—'
      ELSE COALESCE(
             NULLIF(TRIM(CONCAT_WS(' ', ar1.first_name, ar1.last_name)), ''),
             NULLIF(ar1.referee_id, ''),
             CAST(ar1.ref_number AS CHAR),
             '—'
           )
    END AS ar1_name,

    CASE
      WHEN ar2.uuid IS NULL THEN '—'
      ELSE COALESCE(
             NULLIF(TRIM(CONCAT_WS(' ', ar2.first_name, ar2.last_name)), ''),
             NULLIF(ar2.referee_id, ''),
             CAST(ar2.ref_number AS CHAR),
             '—'
           )
    END AS ar2_name,

    CASE
      WHEN com.uuid IS NULL THEN '—'
      ELSE COALESCE(
             NULLIF(TRIM(CONCAT_WS(' ', com.first_name, com.last_name)), ''),
             NULLIF(com.referee_id, ''),
             CAST(com.ref_number AS CHAR),
             '—'
           )
    END AS commissioner_name

FROM matches m
JOIN teams ht ON m.home_team_id = ht.uuid
JOIN clubs hc ON ht.club_id     = hc.uuid
JOIN teams at ON m.away_team_id = at.uuid
JOIN clubs ac ON at.club_id     = ac.uuid

LEFT JOIN locations l     ON m.location_uuid = l.uuid
LEFT JOIN users ref_assigner ON m.referee_assigner_uuid = ref_assigner.uuid

LEFT JOIN referees ref ON m.referee_id      = ref.uuid
LEFT JOIN referees ar1 ON m.ar1_id          = ar1.uuid
LEFT JOIN referees ar2 ON m.ar2_id          = ar2.uuid
LEFT JOIN referees com ON m.commissioner_id = com.uuid

{{ $whereSQL }}
ORDER BY m.match_date ASC, m.kickoff_time ASC

";

$matches = [];
if ($canFetchData) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Export Matches SQL Error: ' . $e->getMessage());
        // Fail safely with an empty CSV rather than rendering HTML
        $matches = [];
    }
}

/* =======================
   CSV response
======================= */
$filename = 'matches_export_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Optional: add UTF-8 BOM for Excel friendliness
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

$headers = [
    'Match ID', 'Date', 'Kickoff',
    'Home Club', 'Home Team',
    'Away Club', 'Away Team',
    'Division', 'District', 'Poule',
    'Location Name', 'Location Address',
    'Referee Assigner',
    'Referee', 'AR1', 'AR2', 'Commissioner',
];
if (!empty($matches)) error_log('Sample referee_name: ' . ($matches[0]['referee_name'] ?? 'MISSING'));

fputcsv($out, $headers, ',', '"', '\\');

foreach ($matches as $m) {
    // Format kickoff as HH:MM if present
    $kick = isset($m['kickoff_time']) && $m['kickoff_time'] !== null ? substr($m['kickoff_time'], 0, 5) : '';

    fputcsv($out, [
        $m['match_id'] ?? '',
        $m['match_date'] ?? '',
        $kick,
        $m['home_club_name'] ?? '',
        $m['home_team_name'] ?? '',
        $m['away_club_name'] ?? '',
        $m['away_team_name'] ?? '',
        $m['division'] ?? '',
        $m['district'] ?? '',
        $m['poule'] ?? '',
        $m['location_name'] ?? '',
        $m['location_address'] ?? '',
        $m['referee_assigner_username'] ?? '',
        $m['referee_name'] ?? '',
        $m['ar1_name'] ?? '',
        $m['ar2_name'] ?? '',
        $m['commissioner_name'] ?? '',
    ], ',', '"', '\\');
}

fclose($out);
exit;
