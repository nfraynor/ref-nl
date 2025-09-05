<?php
// public/ajax/matches_list.php
declare(strict_types=1);

require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';
require_once __DIR__ . '/../../utils/grade_policy.php';

header('Content-Type: application/json');

/* -------------------- Helpers: grades, recency, fit scoring -------------------- */

// Map letters -> numeric strength for comparisons
if (!function_exists('normalize_grade_letter')) {
    function normalize_grade_letter(?string $g): int {
        static $map = ['A'=>4,'B'=>3,'C'=>2,'D'=>1];
        $g = strtoupper(trim((string)$g));
        return $map[$g] ?? 0;
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
 * NOTE: uses matches.home_team_id / away_team_id (your schema); adjust if needed.
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
/** =====================  FIT / CONFLICT POLICY  ===================== **/

// Easy to tune in one place:
const MATCH_MINUTES = 90; // how long a game is, for overlap math

$FIT_PENALTIES = [
    'hard_conflict'      => 100, // same-day overlap OR different venue same day
    'soft_conflict'      => 30,  // same day, same venue, no time overlap
    'proximity_conflict' => 10,  // assignment within ±2 days
    'below_grade'        => 40,  // ref below expected grade
    'recent_team'        => 20,  // had home/away in the last N days
    'unavailable'        => 100, // if you wire availability
];

/**
 * Lightweight time helpers
 */
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
 * Does the candidate ref have a HARD conflict with this match?
 * HARD = (same day AND time overlap) OR (same day AND different venue)
 *
 * Uses a simple window overlap on kickoff/end. If your schema stores location_uuid,
 * this uses it (best). Otherwise falls back to location_address.
 */
function has_hard_conflict(PDO $pdo, array $m, string $refUuid): bool {

    $roles = ['referee_id','ar1_id','ar2_id','commissioner_id'];
    $count = 0;
    foreach ($roles as $rf) {
        if (!empty($m[$rf]) && (string)$m[$rf] === (string)$refUuid) $count++;
    }
    if ($count > 1) return true;

    $date = trim((string)($m['match_date'] ?? ''));
    if ($date === '') return false;

    $start = match_start_dt($m);
    $end   = match_end_dt($m);
    if (!$start || !$end) return false;

    $locUuid = trim((string)($m['location_uuid'] ?? ''));
    $locAddr = trim((string)($m['location_address'] ?? ''));

    // We check any other assignment for the same ref on the same day.
    // (cover all 4 roles)
    $sql = "
      SELECT
        m2.kickoff_time,
        m2.location_uuid,
        m2.location_address
      FROM matches m2
      WHERE m2.uuid <> ?
        AND m2.match_date = ?
        AND (
          m2.referee_id = ? OR m2.ar1_id = ? OR m2.ar2_id = ? OR m2.commissioner_id = ?
        )
      LIMIT 50
    ";
    $st = $pdo->prepare($sql);
    $st->execute([
        (string)($m['uuid'] ?? ''),
        $date,
        $refUuid, $refUuid, $refUuid, $refUuid
    ]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $other = [
            'match_date'     => $date,
            'kickoff_time'   => $r['kickoff_time'] ?? '00:00:00',
            'location_uuid'  => $r['location_uuid'] ?? null,
            'location_address' => $r['location_address'] ?? '',
        ];
        $os = match_start_dt($other);
        $oe = match_end_dt($other);
        if (!$os || !$oe) continue;

        $overlap = ($start < $oe) && ($os < $end);
        if ($overlap) return true;

        // different venue same day
        $otherUuid = trim((string)($other['location_uuid'] ?? ''));
        $otherAddr = trim((string)($other['location_address'] ?? ''));
        $sameVenue =
            ($locUuid && $otherUuid && $locUuid === $otherUuid) ||
            (!$locUuid && !$otherUuid && $otherAddr !== '' && $locAddr !== '' && mb_strtolower($otherAddr) === mb_strtolower($locAddr));
        if (!$sameVenue) return true; // same day, different venue
    }
    return false;
}

/**
 * Soft conflict: same day, same venue, no time overlap (busy day)
 */
function has_soft_conflict(PDO $pdo, array $m, string $refUuid): bool {
    $date = trim((string)($m['match_date'] ?? ''));
    if ($date === '') return false;

    $start = match_start_dt($m);
    $end   = match_end_dt($m);
    if (!$start || !$end) return false;

    $locUuid = trim((string)($m['location_uuid'] ?? ''));
    $locAddr = trim((string)($m['location_address'] ?? ''));

    $sql = "
      SELECT m2.kickoff_time, m2.location_uuid, m2.location_address
      FROM matches m2
      WHERE m2.uuid <> ?
        AND m2.match_date = ?
        AND (
          m2.referee_id = ? OR m2.ar1_id = ? OR m2.ar2_id = ? OR m2.commissioner_id = ?
        )
      LIMIT 50
    ";
    $st = $pdo->prepare($sql);
    $st->execute([
        (string)($m['uuid'] ?? ''),
        $date,
        $refUuid, $refUuid, $refUuid, $refUuid
    ]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $other = [
            'match_date'     => $date,
            'kickoff_time'   => $r['kickoff_time'] ?? '00:00:00',
            'location_uuid'  => $r['location_uuid'] ?? null,
            'location_address' => $r['location_address'] ?? '',
        ];
        $os = match_start_dt($other);
        $oe = match_end_dt($other);
        if (!$os || !$oe) continue;

        $overlap = ($start < $oe) && ($os < $end);
        if ($overlap) continue; // overlap is hard, skip here

        $otherUuid = trim((string)($other['location_uuid'] ?? ''));
        $otherAddr = trim((string)($other['location_address'] ?? ''));
        $sameVenue =
            ($locUuid && $otherUuid && $locUuid === $otherUuid) ||
            (!$locUuid && !$otherUuid && $otherAddr !== '' && $locAddr !== '' && mb_strtolower($otherAddr) === mb_strtolower($locAddr));

        if ($sameVenue) return true;
    }
    return false;
}

/**
 * Proximity conflict: any assignment within ±2 days of this match
 */
function has_proximity_conflict(PDO $pdo, array $m, string $refUuid, int $days = 2): bool {
    $date = trim((string)($m['match_date'] ?? ''));
    if ($date === '') return false;

    $sql = "
      SELECT 1
      FROM matches m2
      WHERE m2.uuid <> ?
        AND m2.match_date BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND DATE_ADD(?, INTERVAL ? DAY)
        AND (
          m2.referee_id = ? OR m2.ar1_id = ? OR m2.ar2_id = ? OR m2.commissioner_id = ?
        )
      LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute([
        (string)($m['uuid'] ?? ''),
        $date, $days, $date, $days,
        $refUuid, $refUuid, $refUuid, $refUuid
    ]);
    return (bool)$st->fetchColumn();
}

/** Compute 0..100 fit score + flags for a (ref, match). */
function compute_match_fit(PDO $pdo, array $matchRow, string $refUuid, ?string $refGrade): array {
    // Make $FIT_PENALTIES visible here
    /** @var array $FIT_PENALTIES */
    global $FIT_PENALTIES;

    $score = 100;
    $flags = [];

    // 1) Hard conflict (–100)
    if (has_hard_conflict($pdo, $matchRow, $refUuid)) {
        $score -= $FIT_PENALTIES['hard_conflict'];
        $flags[] = 'hard_conflict';
        // Early clamp — nothing beats a hard conflict
        $score = max(0, $score);
        return ['score' => $score, 'flags' => $flags];
    }

    // 2) Soft / Proximity conflicts (–30 / –10)
    if (has_soft_conflict($pdo, $matchRow, $refUuid)) {
        $score -= $FIT_PENALTIES['soft_conflict'];
        $flags[] = 'soft_conflict';
    }
    if (has_proximity_conflict($pdo, $matchRow, $refUuid, 2)) {
        $score -= $FIT_PENALTIES['proximity_conflict'];
        $flags[] = 'proximity_conflict';
    }

    // 3) Below expected grade? (–40)
    $refG = normalize_grade_letter($refGrade);

    // Get expected grade letter from policy (prefer policy, then DB field, else D)
    $needLetter =
        (function_exists('expected_grade_for_match_letter') ? expected_grade_for_match_letter($matchRow) : null)
        ?? ($matchRow['expected_grade'] ?? 'D');

    $needG = normalize_grade_letter($needLetter);

    if ($refG > 0 && $needG > 0 && $refG < $needG) {
        $score -= $FIT_PENALTIES['below_grade'];
        $flags[] = 'below_grade';
    }

    // 4) Recent team? (–20)
    $homeUuid  = $matchRow['home_team_uuid'] ?? '';
    $awayUuid  = $matchRow['away_team_uuid'] ?? '';
    $matchDate = $matchRow['match_date'] ?? '';
    if ($refUuid && $homeUuid && $awayUuid && $matchDate) {
        if (ref_had_team_recently($pdo, $refUuid, $homeUuid, $awayUuid, $matchDate, 14)) {
            $score -= $FIT_PENALTIES['recent_team'];
            $flags[] = 'recent_team';
        }
    }

    // 5) Availability (optional placeholder) (–100)
    // if (is_ref_unavailable($pdo, $refUuid, $matchRow)) {
    //     $score -= $FIT_PENALTIES['unavailable'];
    //     $flags[] = 'unavailable';
    // }

    // Clamp
    $score = max(0, min(100, $score));
    return ['score' => $score, 'flags' => $flags];
}


/** Append per-role fit fields to the output row (only if role is assigned). */
function add_fit_fields(PDO $pdo, array $matchRow, array &$rowOut, string $roleField): void {
    $refUuid = (string)($rowOut[$roleField] ?? '');
    if ($refUuid === '') return;

    $refGrade = get_ref_grade($pdo, $refUuid);
    $fit = compute_match_fit($pdo, $matchRow, $refUuid, $refGrade);

    $prefix = match ($roleField) {
        'referee_id'      => 'referee',
        'ar1_id'          => 'ar1',
        'ar2_id'          => 'ar2',
        'commissioner_id' => 'commissioner',
        default           => $roleField,
    };
    $rowOut[$prefix . '_fit_score'] = $fit['score'];
    $rowOut[$prefix . '_fit_flags'] = $fit['flags'];
}

/** Enrich a single row with all fit scores/flags. */
function enrich_row_with_fit(PDO $pdo, array $row): array {
    // The scorer needs: division/expected_grade, match_date, home_team_uuid, away_team_uuid, and assigned ids.
    $matchRow = $row;

    add_fit_fields($pdo, $matchRow, $row, 'referee_id');
    add_fit_fields($pdo, $matchRow, $row, 'ar1_id');
    add_fit_fields($pdo, $matchRow, $row, 'ar2_id');
    add_fit_fields($pdo, $matchRow, $row, 'commissioner_id');

    return $row;
}

/* -------------------- Main endpoint -------------------- */

try {
    $pdo = Database::getConnection();

    // Permissions
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

    // Filters
    $start_date = trim($_GET['start_date'] ?? '');
    $end_date   = trim($_GET['end_date']   ?? '');
    $search     = trim($_GET['search']     ?? '');

    if ($start_date !== '') { $where[] = "m.match_date >= ?"; $params[] = $start_date; }
    if ($end_date   !== '') { $where[] = "m.match_date <= ?"; $params[] = $end_date; }

    if ($search !== '') {
        $where[] = "(th.team_name LIKE ? OR ta.team_name LIKE ? OR m.division LIKE ? OR m.district LIKE ? OR m.poule LIKE ? OR m.location_address LIKE ?)";
        for ($i=0;$i<6;$i++) $params[] = "%{$search}%";
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Joins & SELECT
    $joins = "
        LEFT JOIN teams th ON m.home_team_id = th.uuid
        LEFT JOIN clubs ch ON th.club_id     = ch.uuid
        LEFT JOIN teams ta ON m.away_team_id = ta.uuid
        LEFT JOIN clubs ca ON ta.club_id     = ca.uuid
        LEFT JOIN users u  ON m.referee_assigner_uuid = u.uuid
    ";

    // Important: expose team UUIDs + alias location_label for UI
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
        
        m.location_uuid,    
        m.location_address AS location_label,
        m.location_address,

        u.username AS referee_assigner_username,
        m.referee_assigner_uuid
    ";

    // Sorting
    $sortCol = $_GET['sort_col'] ?? 'm.match_date';
    $sortDir = strtoupper($_GET['sort_dir'] ?? 'ASC');
    $allowedCols = ['m.match_date','m.kickoff_time','m.division','m.district','m.poule','th.team_name','ta.team_name'];
    if (!in_array($sortCol, $allowedCols, true)) $sortCol = 'm.match_date';
    $sortDir = ($sortDir === 'DESC') ? 'DESC' : 'ASC';
    $orderSql = "ORDER BY {$sortCol} {$sortDir}, m.kickoff_time ASC";

    // “ALL” mode (plain array)
    $all = isset($_GET['all']) && $_GET['all'] !== '0';
    if ($all) {
        $sql = "SELECT {$select} FROM matches m {$joins} {$whereSql} {$orderSql}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Enrich
        foreach ($rows as $i => $r) {
            $rows[$i] = enrich_row_with_fit($pdo, $r);
        }

        echo json_encode($rows);
        exit;
    }

    // Paginated mode
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

    $dataSql = "SELECT {$select} FROM matches m {$joins} {$whereSql} {$orderSql} LIMIT ? OFFSET ?";
    $stmt = $pdo->prepare($dataSql);
    $stmt->execute(array_merge($params, [$size, $offset]));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Enrich
    foreach ($rows as $i => $r) {
        $rows[$i] = enrich_row_with_fit($pdo, $r);
    }

    echo json_encode([
        'data'         => $rows,
        'last_page'    => $last_page,
        'total'        => $total,
        'current_page' => $page,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'data' => [],
        'last_page' => 1,
        'total' => 0,
        'current_page' => 1,
        'error' => 'Server error',
    ]);
}
