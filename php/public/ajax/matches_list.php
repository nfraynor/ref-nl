<?php
// php/public/ajax/matches_list.php
declare(strict_types=1);

require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';
require_once __DIR__ . '/../../utils/grade_policy.php';

header('Content-Type: application/json');

// ===== Logging & error handling =====
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Correlate every request in logs & response
$REQ_ID = bin2hex(random_bytes(6));
header('X-Request-Id: ' . $REQ_ID);

// Turn ALL PHP errors into exceptions we can catch/JSONify
set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Catch fatals too (parse, compile, core)
register_shutdown_function(function() use ($REQ_ID) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log("[$REQ_ID] FATAL: {$e['message']} in {$e['file']}:{$e['line']}");
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'req_id'  => $REQ_ID,
            'message' => 'Fatal error. See logs with req_id.',
        ]);
    }
});

// ======== Helpers: grades, recency, fit scoring ========

/** Same venue iff BOTH uuids are non-empty and equal. */
function same_venue_uuid_only(?string $u1, ?string $u2): bool {
    $u1 = trim((string)$u1);
    $u2 = trim((string)$u2);
    return $u1 !== '' && $u2 !== '' && $u1 === $u2;
}

// ===== Debug helpers & schema helpers =====

/** Toggle via ?debug=1 */
if (!function_exists('debug_enabled')) {
    function debug_enabled(): bool {
        return isset($_GET['debug']) && $_GET['debug'] === '1';
    }
}

/**
 * Lightweight recorder used by conflict functions.
 * Works even when the referenced bucket (e.g. $D['role_check']) doesn't exist yet.
 */
if (!function_exists('dbg')) {
    function dbg(& $bag, string $k, $v): void {
        if (!debug_enabled()) return;       // No overhead unless debug=1
        if (!is_array($bag)) $bag = [];     // Auto-initialize nested buckets
        $bag[$k] = $v;
    }
}

/** INFORMATION_SCHEMA check with per-request cache */
if (!function_exists('column_exists')) {
    function column_exists(PDO $pdo, string $table, string $column): bool {
        static $CACHE = [];
        $key = "$table.$column";
        if (array_key_exists($key, $CACHE)) return $CACHE[$key];

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = ?
              AND COLUMN_NAME  = ?
        ");
        $stmt->execute([$table, $column]);
        return $CACHE[$key] = (bool)$stmt->fetchColumn();
    }
}


/** Fetch a referee’s grade once (cached per request). */
function get_ref_grade(PDO $pdo, ?string $uuid): ?string {
    if (!$uuid) return null;
    static $cache = [];
    if (array_key_exists($uuid, $cache)) return $cache[$uuid];
    $stmt = $pdo->prepare("SELECT grade FROM referees WHERE uuid = ?");
    $stmt->execute([$uuid]);
    $cache[$uuid] = $stmt->fetchColumn() ?: null;
    return $cache[$uuid];
}

/**
 * Did this referee have either team within the last N days before match_date?
 */
function ref_had_team_recently(PDO $pdo, string $refUuid, string $homeUuid, string $awayUuid, string $matchDate, int $days = 14): bool {
    $sql = "
        SELECT 1
        FROM matches m
        WHERE m.match_date >= DATE_SUB(?, INTERVAL ? DAY)
          AND m.match_date < ?
          AND (
              m.home_team_id IN (?, ?)
              OR m.away_team_id IN (?, ?)
          )
          AND (
              m.referee_id = ?
              OR m.ar1_id = ?
              OR m.ar2_id = ?
              OR m.commissioner_id = ?
          )
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $matchDate, $days, $matchDate,
        $homeUuid, $awayUuid, $homeUuid, $awayUuid,
        $refUuid, $refUuid, $refUuid, $refUuid,
    ]);
    return (bool)$stmt->fetchColumn();
}

// ===================== FIT / CONFLICT POLICY =====================
const MATCH_MINUTES = 90; // duration used for overlap math

$FIT_PENALTIES = [
    'hard_conflict'      => 100, // same-day overlap OR different venue same day
    'soft_conflict'      => 30,  // same day, same venue, no time overlap
    'proximity_conflict' => 10,  // assignment within ±2 days
    'below_grade'        => 40,  // ref below expected grade
    'recent_team'        => 20,  // had home/away in the last N days
    'unavailable'        => 100, // if you wire availability
    'own_club'           => 20,  // same club as one of the teams
];

/** Lightweight time helpers */
function match_start_dt(array $m): ?DateTimeImmutable {
    $d = trim((string)($m['match_date'] ?? ''));
    if ($d === '') return null;
    $t = trim((string)($m['kickoff_time'] ?? '00:00'));
    $hhmm = substr($t, 0, 5) ?: '00:00';
    return new DateTimeImmutable("$d $hhmm:00");
}
function match_end_dt(array $m): ?DateTimeImmutable {
    $s = match_start_dt($m);
    return $s ? $s->modify('+' . MATCH_MINUTES . ' minutes') : null;
}

/**
 * Fetch a referee's club UUID (if the referees table has club_id or club_uuid).
 * Returns '' if unknown/not set.
 */
/**
 * Return the referee's club UUID.
 * - referees.home_club_id may store either clubs.uuid (string) or clubs.id (int).
 * - We normalize to clubs.uuid. '' if unknown.
 */
function get_ref_club_uuid(PDO $pdo, ?string $refUuid): string {
    if (!$refUuid) return '';
    static $cache = [];
    if (array_key_exists($refUuid, $cache)) return $cache[$refUuid];

    // Ensure the column exists
    if (!column_exists($pdo, 'referees', 'home_club_id')) {
        return $cache[$refUuid] = '';
    }

    // 1) Read the raw value from referees
    $stmt = $pdo->prepare("SELECT home_club_id FROM referees WHERE uuid = ? LIMIT 1");
    $stmt->execute([$refUuid]);
    $raw = trim((string)($stmt->fetchColumn() ?: ''));
    if ($raw === '') {
        return $cache[$refUuid] = '';
    }

    // 2a) First try: treat it as a clubs.uuid
    $q1 = $pdo->prepare("SELECT uuid FROM clubs WHERE uuid = ? LIMIT 1");
    $q1->execute([$raw]);
    $uuid = (string)($q1->fetchColumn() ?: '');
    if ($uuid !== '') {
        return $cache[$refUuid] = $uuid;
    }

    // 2b) Second try: if it's numeric, resolve via clubs.id → uuid
    if (ctype_digit($raw)) {
        $q2 = $pdo->prepare("SELECT uuid FROM clubs WHERE id = ? LIMIT 1");
        $q2->execute([(int)$raw]);
        $uuid = (string)($q2->fetchColumn() ?: '');
        if ($uuid !== '') {
            return $cache[$refUuid] = $uuid;
        }
    }

    // Not resolvable
    return $cache[$refUuid] = '';
}




/** Same venue if either UUIDs match (when both set) OR normalized addresses match (when both set). */
function is_same_venue(array $v1, array $v2): bool {
    if ($v1['id'] !== '' && $v2['id'] !== '' && $v1['id'] === $v2['id']) {
        return true;
    }
    if ($v1['addr'] !== '' && $v2['addr'] !== '' && $v1['addr'] === $v2['addr']) {
        return true;
    }
    return false;
}


/**
 * HARD conflict:
 *  - same day AND 90-minute time overlap  -> always hard
 *  - same day AND different venue         -> hard **only if BOTH matches have a real location**
 *
 * "Real location" means a non-empty UUID OR a non-empty address not equal to "N/A" (case-insensitive).
 */
function has_hard_conflict(PDO $pdo, array $m, string $refUuid, ?array &$D = null): bool {
    $D = $D ?? [];

    // Same row double-role -> hard
    $roles = ['referee_id','ar1_id','ar2_id','commissioner_id'];
    $count = 0;
    foreach ($roles as $rf) {
        if (!empty($m[$rf]) && (string)$m[$rf] === (string)$refUuid) $count++;
    }
    dbg($D['role_check'], 'same_match_roles', $count);
    if ($count > 1) { dbg($D, 'result', 'HARD:same_match_multi_role'); return true; }

    $date  = trim((string)($m['match_date'] ?? ''));
    $start = match_start_dt($m);
    $end   = match_end_dt($m);
    dbg($D, 'times_this', ['date'=>$date, 'start'=>(string)($start?->format('H:i')), 'end'=>(string)($end?->format('H:i'))]);
    if ($date === '' || !$start || !$end) { dbg($D, 'result', 'NO:missing_time_or_date'); return false; }

    // Current match venue UUID (only)
    $locUuidThis = trim((string)($m['location_uuid'] ?? ''));
    $hasLocCol   = column_exists($pdo, 'matches', 'location_uuid');
    $hasLocThis  = $hasLocCol && $locUuidThis !== '';
    dbg($D, 'venue_this', ['uuid'=>$locUuidThis, 'has_uuid'=>$hasLocThis]);

    // Build schema-safe select
    $locCols = $hasLocCol ? "m2.location_uuid" : "NULL AS location_uuid";

    $sql = "
      SELECT m2.uuid, m2.kickoff_time, $locCols
      FROM matches m2
      WHERE m2.uuid <> ?
        AND m2.match_date = ?
        AND (m2.referee_id = ? OR m2.ar1_id = ? OR m2.ar2_id = ? OR m2.commissioner_id = ?)
      LIMIT 50
    ";
    $st = $pdo->prepare($sql);
    $st->execute([(string)($m['uuid'] ?? ''), $date, $refUuid, $refUuid, $refUuid, $refUuid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    dbg($D, 'same_day_count', count($rows));

    foreach ($rows as $r) {
        $otherUuid = $r['uuid'] ?? null;

        $other = [
            'uuid'         => $otherUuid,
            'match_date'   => $date,
            'kickoff_time' => $r['kickoff_time'] ?? '00:00:00',
            'location_uuid'=> $r['location_uuid'] ?? null,
        ];
        $os = match_start_dt($other);
        $oe = match_end_dt($other);
        $overlap = ($start < $oe) && ($os < $end);
        dbg($D['other_'.$other['uuid']], 'times', [
            'start'=>(string)($os?->format('H:i')),
            'end'  =>(string)($oe?->format('H:i')),
            'overlap'=>$overlap
        ]);

        if ($overlap) { dbg($D['other_'.$other['uuid']], 'decision', 'HARD:overlap'); return true; }

        // Venue-based hard ONLY when both UUIDs exist AND are different
        $locUuidOther = trim((string)($other['location_uuid'] ?? ''));
        $hasLocOther  = $hasLocCol && $locUuidOther !== '';
        dbg($D['other_'.$other['uuid']], 'venue', ['uuid'=>$locUuidOther, 'has_uuid'=>$hasLocOther]);

        if ($hasLocThis && $hasLocOther) {
            $sameVenue = same_venue_uuid_only($locUuidThis, $locUuidOther);
            dbg($D['other_'.$other['uuid']], 'venue_compare', ['sameVenue'=>$sameVenue]);
            if (!$sameVenue) {
                dbg($D['other_'.$other['uuid']], 'decision', 'HARD:different_known_venue_uuid');
                return true;
            }
        } else {
            // If either side lacks a UUID, ignore venue-based hard check.
            dbg($D['other_'.$other['uuid']], 'decision', 'SKIP:venue_uuid_missing');
        }
    }

    dbg($D, 'result', 'NO');
    return false;
}


function has_soft_conflict(PDO $pdo, array $m, string $refUuid, ?array &$D = null): bool {
    $D = $D ?? [];

    $date  = trim((string)($m['match_date'] ?? ''));
    $start = match_start_dt($m);
    $end   = match_end_dt($m);
    dbg($D, 'times_this', ['date'=>$date, 'start'=>(string)($start?->format('H:i')), 'end'=>(string)($end?->format('H:i'))]);
    if ($date === '' || !$start || !$end) { dbg($D, 'result', 'NO:missing_time_or_date'); return false; }

    $hasLocCol   = column_exists($pdo, 'matches', 'location_uuid');
    $locUuidThis = trim((string)($m['location_uuid'] ?? ''));
    $hasLocThis  = $hasLocCol && $locUuidThis !== '';
    dbg($D, 'venue_this', ['uuid'=>$locUuidThis, 'has_uuid'=>$hasLocThis]);

    if (!$hasLocThis) {
        // Without UUID we do NOT emit soft same-venue
        dbg($D, 'result', 'NO:venue_uuid_missing');
        return false;
    }

    $locCols = $hasLocCol ? "m2.location_uuid" : "NULL AS location_uuid";

    $sql = "
      SELECT m2.uuid, m2.kickoff_time, $locCols
      FROM matches m2
      WHERE m2.uuid <> ?
        AND m2.match_date = ?
        AND (m2.referee_id = ? OR m2.ar1_id = ? OR m2.ar2_id = ? OR m2.commissioner_id = ?)
      LIMIT 50
    ";
    $st = $pdo->prepare($sql);
    $st->execute([(string)($m['uuid'] ?? ''), $date, $refUuid, $refUuid, $refUuid, $refUuid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    dbg($D, 'same_day_count', count($rows));

    foreach ($rows as $r) {
        $other = [
            'uuid'         => $r['uuid'] ?? null,
            'match_date'   => $date,
            'kickoff_time' => $r['kickoff_time'] ?? '00:00:00',
            'location_uuid'=> $r['location_uuid'] ?? null,
        ];
        $os = match_start_dt($other);
        $oe = match_end_dt($other);
        $overlap = ($start < $oe) && ($os < $end);
        dbg($D['other_'.$other['uuid']], 'times', [
            'start'=>(string)($os?->format('H:i')),
            'end'  =>(string)($oe?->format('H:i')),
            'overlap'=>$overlap
        ]);
        if ($overlap) { dbg($D['other_'.$other['uuid']], 'decision', 'SKIP:overlap_is_hard'); continue; }

        $locUuidOther = trim((string)($other['location_uuid'] ?? ''));
        $hasLocOther  = $hasLocCol && $locUuidOther !== '';
        dbg($D['other_'.$other['uuid']], 'venue', ['uuid'=>$locUuidOther, 'has_uuid'=>$hasLocOther]);

        // Soft only if both UUIDs exist AND are the same
        if ($hasLocOther && same_venue_uuid_only($locUuidThis, $locUuidOther)) {
            dbg($D['other_'.$other['uuid']], 'decision', 'SOFT:same_venue_uuid_no_overlap');
            dbg($D, 'result', 'SOFT');
            return true;
        }
        dbg($D['other_'.$other['uuid']], 'decision', 'NO:not_same_venue_uuid_or_missing');
    }

    dbg($D, 'result', 'NO');
    return false;
}


/** Proximity conflict: any assignment within ±2 days, but on a DIFFERENT day (exclude same day) */
function has_proximity_conflict(PDO $pdo, array $m, string $refUuid, int $days = 2, ?array &$D = null): bool {
    $D = $D ?? [];
    $date = trim((string)($m['match_date'] ?? ''));
    dbg($D, 'window', ['center'=>$date, 'days'=> $days]);
    if ($date === '') { dbg($D, 'result', 'NO:missing_date'); return false; }

    $sql = "
      SELECT m2.uuid, m2.match_date
      FROM matches m2
      WHERE m2.uuid <> ?
        AND m2.match_date BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND DATE_ADD(?, INTERVAL ? DAY)
        AND m2.match_date <> ?
        AND (m2.referee_id = ? OR m2.ar1_id = ? OR m2.ar2_id = ? OR m2.commissioner_id = ?)
      LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute([(string)($m['uuid'] ?? ''), $date, $days, $date, $days, $date, $refUuid, $refUuid, $refUuid, $refUuid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    dbg($D, 'hit', $row ?: null);
    $res = (bool)$row;
    dbg($D, 'result', $res ? 'YES' : 'NO');
    return $res;
}



/** Compute 0..100 fit score + flags for a (ref, match). */
function compute_match_fit(PDO $pdo, array $matchRow, string $refUuid, ?string $refGrade, ?array &$DEBUG = null): array {
    global $FIT_PENALTIES;
    $DEBUG = $DEBUG ?? [];
    $score = 100; $flags = [];

    $Dhard = [];
    if (has_hard_conflict($pdo, $matchRow, $refUuid, $Dhard)) {
        $score -= $FIT_PENALTIES['hard_conflict'];
        $flags[] = 'hard_conflict';
    }
    if (debug_enabled()) $DEBUG['hard'] = $Dhard;

    $Dsoft = [];
    if (has_soft_conflict($pdo, $matchRow, $refUuid, $Dsoft)) {
        $score -= $FIT_PENALTIES['soft_conflict'];
        $flags[]='soft_conflict';
    }
    if (debug_enabled()) $DEBUG['soft'] = $Dsoft;

    $Dprox = [];
    if (has_proximity_conflict($pdo, $matchRow, $refUuid, 2, $Dprox)) {
        $score -= $FIT_PENALTIES['proximity_conflict'];
        $flags[]='proximity_conflict';
    }
    if (debug_enabled()) $DEBUG['prox'] = $Dprox;

    // grade policy
    $refG  = function_exists('grade_to_rank') ? grade_to_rank($refGrade) : 0;
    $needG = expected_grade_rank($matchRow);
    if ($refG > 0 && $needG > 0 && $refG < $needG) {
        $score -= $FIT_PENALTIES['below_grade'];
        $flags[] = 'below_grade';
    }
    if (debug_enabled()) {
        $DEBUG['grade'] = [
            'ref_raw' => $refGrade,
            'refG'    => $refG,
            'needG'   => $needG,
        ];
    }

    // --- Own club (compare UUIDs) ---
    $homeClubUuid = (string)($matchRow['home_club_uuid'] ?? '');
    $awayClubUuid = (string)($matchRow['away_club_uuid'] ?? '');
    if ($refUuid && ($homeClubUuid || $awayClubUuid)) {
        $refClubUuid = get_ref_club_uuid($pdo, $refUuid);
        if ($refClubUuid !== '' && ($refClubUuid === $homeClubUuid || $refClubUuid === $awayClubUuid)) {
            $score -= $FIT_PENALTIES['own_club'];
            $flags[] = 'own_club';
            if (debug_enabled()) {
                $DEBUG['own_club'] = [
                    'ref_club'  => $refClubUuid,
                    'home_club' => $homeClubUuid,
                    'away_club' => $awayClubUuid,
                    'applied'   => true,
                ];
            }
        } elseif (debug_enabled()) {
            $DEBUG['own_club'] = [
                'ref_club'  => $refClubUuid,
                'home_club' => $homeClubUuid,
                'away_club' => $awayClubUuid,
                'applied'   => false,
            ];
        }
    }

    return ['score' => max(0, min(100, $score)), 'flags' => $flags];
}



function add_fit_fields(PDO $pdo, array $matchRow, array &$rowOut, string $roleField): void {
    $refUuid = (string)($rowOut[$roleField] ?? '');
    if ($refUuid === '') return;

    $refGrade = get_ref_grade($pdo, $refUuid);
    $DEBUG = [];
    $fit = compute_match_fit($pdo, $matchRow, $refUuid, $refGrade, $DEBUG);

    $prefix = match ($roleField) {
        'referee_id' => 'referee',
        'ar1_id' => 'ar1',
        'ar2_id' => 'ar2',
        'commissioner_id' => 'commissioner',
        default => $roleField,
    };
    $rowOut[$prefix . '_fit_score'] = $fit['score'];
    $rowOut[$prefix . '_fit_flags'] = $fit['flags'];

    if (debug_enabled()) {
        $rowOut[$prefix . '_fit_debug'] = $DEBUG; // full reasoning tree
    }
}


/** Enrich a single row with all fit scores/flags. */
function enrich_row_with_fit(PDO $pdo, array $row): array {
    $matchRow = $row;
    add_fit_fields($pdo, $matchRow, $row, 'referee_id');
    add_fit_fields($pdo, $matchRow, $row, 'ar1_id');
    add_fit_fields($pdo, $matchRow, $row, 'ar2_id');
    add_fit_fields($pdo, $matchRow, $row, 'commissioner_id');
    return $row;
}

// ===================== Main endpoint =====================
try {
    $pdo = Database::getConnection();
    // (Optional) Ensure exceptions if your DB class didn't set it
    if ($pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    // ---- Permissions from session ----
    $userRole        = $_SESSION['user_role']    ?? null;
    $userDivisionIds = $_SESSION['division_ids'] ?? [];
    $userDistrictIds = $_SESSION['district_ids'] ?? [];

    $where  = [];
    $params = [];

    if ($userRole !== 'super_admin') {
        $allowedDivisionNames = [];
        $allowedDistrictNames = [];

        if (!empty($userDivisionIds) && !(count($userDivisionIds) === 1 && $userDivisionIds[0] === '')) {
            $ph = implode(',', array_fill(0, count($userDivisionIds), '?'));
            $stmt = $pdo->prepare("SELECT name FROM divisions WHERE id IN ($ph)");
            $stmt->execute($userDivisionIds);
            $allowedDivisionNames = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }
        if (!empty($userDistrictIds) && !(count($userDistrictIds) === 1 && $userDistrictIds[0] === '')) {
            $ph = implode(',', array_fill(0, count($userDistrictIds), '?'));
            $stmt = $pdo->prepare("SELECT name FROM districts WHERE id IN ($ph)");
            $stmt->execute($userDistrictIds);
            $allowedDistrictNames = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }

        if (empty($allowedDivisionNames) || empty($allowedDistrictNames)) {
            echo json_encode([]); exit;
        }

        $ph = implode(',', array_fill(0, count($allowedDivisionNames), '?'));
        $where[] = "m.division IN ($ph)"; $params = array_merge($params, $allowedDivisionNames);

        $ph = implode(',', array_fill(0, count($allowedDistrictNames), '?'));
        $where[] = "m.district IN ($ph)"; $params = array_merge($params, $allowedDistrictNames);
    }

    // ---- Filters ----
    $start_date = trim((string)($_GET['start_date'] ?? ''));
    $end_date   = trim((string)($_GET['end_date']   ?? ''));
    $search     = trim((string)($_GET['search']     ?? ''));

    if ($start_date !== '') { $where[] = "m.match_date >= ?"; $params[] = $start_date; }
    if ($end_date   !== '') { $where[] = "m.match_date <= ?"; $params[] = $end_date; }

    if ($search !== '') {
        $where[] = "(th.team_name LIKE ? OR ta.team_name LIKE ? OR m.division LIKE ? OR m.district LIKE ? OR m.poule LIKE ? OR m.location_address LIKE ?)";
        for ($i=0; $i<6; $i++) $params[] = "%{$search}%";
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // ---- Joins & SELECT ----
    $joins = "
        LEFT JOIN teams th ON m.home_team_id = th.uuid
        LEFT JOIN clubs ch ON th.club_id     = ch.uuid
        LEFT JOIN teams ta ON m.away_team_id = ta.uuid
        LEFT JOIN clubs ca ON ta.club_id     = ca.uuid
        LEFT JOIN users u  ON m.referee_assigner_uuid = u.uuid
    ";

    // team UUIDs + user-friendly location label (address fallback)
    $select = "
    m.uuid,
    m.match_date,
    m.kickoff_time,
    m.division,
    m.district,
    m.poule,
    m.expected_grade,

    m.referee_id, m.ar1_id, m.ar2_id, m.commissioner_id,

    th.team_name AS home_team,
    ta.team_name AS away_team,

    m.home_team_id AS home_team_uuid,
    m.away_team_id AS away_team_uuid,

    ch.club_name AS home_club,
    ca.club_name AS away_club,
    ch.uuid      AS home_club_uuid,  -- NEW
    ca.uuid      AS away_club_uuid,  -- NEW

    m.location_uuid,
    m.location_address AS location_label,
    m.location_address,

    u.username AS referee_assigner_username,
    m.referee_assigner_uuid
";


    // ---- Sorting ----
    $sortCol = (string)($_GET['sort_col'] ?? 'm.match_date');
    $sortDir = strtoupper((string)($_GET['sort_dir'] ?? 'ASC'));
    $allowedCols = ['m.match_date','m.kickoff_time','m.division','m.district','m.poule','th.team_name','ta.team_name'];
    if (!in_array($sortCol, $allowedCols, true)) $sortCol = 'm.match_date';
    $sortDir = ($sortDir === 'DESC') ? 'DESC' : 'ASC';
    $orderSql = "ORDER BY {$sortCol} {$sortDir}, m.kickoff_time ASC";

    // ---- ALL mode (plain array) ----
    $all = isset($_GET['all']) && $_GET['all'] !== '0';
    if ($all) {
        $sql = "SELECT {$select} FROM matches m {$joins} {$whereSql} {$orderSql}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Enrich with fit fields
        foreach ($rows as $i => $r) {
            $rows[$i] = enrich_row_with_fit($pdo, $r);
        }

        echo json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- Paginated mode ----
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $size   = min(500, max(10, (int)($_GET['size'] ?? 50)));
    $offset = ($page - 1) * $size;

    $countSql = "
        SELECT COUNT(*)
        FROM matches m
        LEFT JOIN teams th ON m.home_team_id = th.uuid
        LEFT JOIN teams ta ON m.away_team_id = ta.uuid
        {$whereSql}
    ";
    $cs = $pdo->prepare($countSql);
    $cs->execute($params);
    $total = (int)$cs->fetchColumn();

    $last_page = max(1, (int)ceil(($total ?: 0)/$size));
    if ($page > $last_page) { $page = 1; $offset = 0; }

    // MySQL doesn't like bound params for LIMIT/OFFSET when emulation is off; inline safe ints
    $size   = (int)$size;
    $offset = (int)$offset;

    $dataSql = "SELECT {$select} FROM matches m {$joins} {$whereSql} {$orderSql} LIMIT {$size} OFFSET {$offset}";
    $stmt = $pdo->prepare($dataSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $i => $r) {
        $rows[$i] = enrich_row_with_fit($pdo, $r);
    }

    echo json_encode([
        'data'         => $rows,
        'last_page'    => $last_page,
        'total'        => $total,
        'current_page' => $page,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    // Log full detail to STDERR (captured by your container/webserver)
    error_log("[$REQ_ID] ".get_class($e).": ".$e->getMessage()." at ".$e->getFile().":".$e->getLine()."\n".$e->getTraceAsString());

    http_response_code(500);
    echo json_encode([
        'success'      => false,
        'data'         => [],
        'last_page'    => 1,
        'total'        => 0,
        'current_page' => 1,
        'req_id'       => $REQ_ID,
        'message'      => 'Internal error. See logs with req_id.',
    ]);
}
