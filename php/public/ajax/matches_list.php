<?php
// public/ajax/matches_list.php
declare(strict_types=1);

require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';
require_once __DIR__ . '/../../utils/grade_policy.php';


header('Content-Type: application/json');
function normalize_grade(?string $g): int {
    static $map = ['A'=>4,'B'=>3,'C'=>2,'D'=>1];
    $g = strtoupper(trim((string)$g));
    return $map[$g] ?? 0; // unknown -> 0
}

/**
 * Example: infer expected min grade from a match row.
 * You can refine this mapping per division/district/poule later or store on the match.
 */
function expected_grade_for_match(array $m): int {
    $div = strtoupper(trim((string)($m['division'] ?? '')));
    // Very rough defaults; tweak freely:
    if (str_contains($div, 'ERE') || str_contains($div, 'PREM') || str_contains($div, 'TOP')) return normalize_grade('A');
    if (str_contains($div, '1E')) return normalize_grade('B');
    if (str_contains($div, '2E')) return normalize_grade('C');
    if (str_contains($div, '3E') || str_contains($div, 'DEV')) return normalize_grade('D');
    return normalize_grade('C'); // safe default
}

function get_ref_grade(PDO $pdo, ?string $uuid): ?string {
    if (!$uuid) return null;
    static $cache = [];
    if (array_key_exists($uuid, $cache)) return $cache[$uuid];
    $stmt = $pdo->prepare("SELECT grade FROM referees WHERE uuid = ?");
    $stmt->execute([$uuid]);
    $cache[$uuid] = $stmt->fetchColumn() ?: null;
    return $cache[$uuid];
}

function add_fit_fields(PDO $pdo, array $matchRow, array &$rowOut, string $roleField) {
    $refUuid = $rowOut[$roleField] ?? '';
    if (!$refUuid) return; // only add for assigned roles (keeps payload small)
    $refGrade = get_ref_grade($pdo, $refUuid);
    $fit = compute_match_fit($pdo, $matchRow, $refUuid, $refGrade);
    // e.g. referee_fit_score, referee_fit_flags
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
/**
 * Did this referee have either team within the last N days before match_date?
 */
function ref_had_team_recently(PDO $pdo, string $refUuid, string $homeUuid, string $awayUuid, string $matchDate, int $days = 14): bool {
    // Adjust table/column names to your schema if needed.
    $sql = "
        SELECT 1
        FROM matches m
        WHERE m.match_date >= DATE_SUB(?, INTERVAL ? DAY)
          AND m.match_date < ?
          AND (
              m.home_team_uuid IN (?, ?)
              OR m.away_team_uuid IN (?, ?)
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
    $stmt->execute([$matchDate, $days, $matchDate,
        $homeUuid, $awayUuid, $homeUuid, $awayUuid,
        $refUuid, $refUuid, $refUuid, $refUuid
    ]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Compute 0..100 fit score + flags for a (ref, match).
 * Currently: penalties only (simple + transparent). We’ll expand later.
 */
function compute_match_fit(PDO $pdo, array $matchRow, string $refUuid, ?string $refGrade): array {
    $score = 100;
    $flags = [];

    // --- Below expected grade?
    $refG  = normalize_grade($refGrade);
    $needG = expected_grade_for_match($matchRow);
    if ($refG > 0 && $needG > 0 && $refG < $needG) {
        $score -= 40;
        $flags[] = 'below_grade';
    }

    // --- Recent same team(s) in last 14 days?
    $homeUuid = $matchRow['home_team_uuid'] ?? '';
    $awayUuid = $matchRow['away_team_uuid'] ?? '';
    $matchDate= $matchRow['match_date'] ?? '';
    if ($refUuid && $homeUuid && $awayUuid && $matchDate) {
        if (ref_had_team_recently($pdo, $refUuid, $homeUuid, $awayUuid, $matchDate, 14)) {
            $score -= 20;
            $flags[] = 'recent_team';
        }
    }

    // --- Clamp
    $score = max(0, min(100, $score));
    return ['score'=>$score, 'flags'=>$flags];
}



try {
    $pdo = Database::getConnection();

    // ---- Permissions ----
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
            echo json_encode([]); exit; // local mode expects array; empty array is fine for remote too
        }

        $ph = implode(',', array_fill(0, count($allowedDivisionNames), '?'));
        $where[] = "m.division IN ($ph)"; $params = array_merge($params, $allowedDivisionNames);

        $ph = implode(',', array_fill(0, count($allowedDistrictNames), '?'));
        $where[] = "m.district IN ($ph)"; $params = array_merge($params, $allowedDistrictNames);
    }

    // ---- Filters from GET ----
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

    $joins = "
        LEFT JOIN teams th ON m.home_team_id = th.uuid
        LEFT JOIN clubs ch ON th.club_id     = ch.uuid
        LEFT JOIN teams ta ON m.away_team_id = ta.uuid
        LEFT JOIN clubs ca ON ta.club_id     = ca.uuid
        LEFT JOIN users u  ON m.referee_assigner_uuid = u.uuid
    ";

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
        ch.club_name AS home_club,
        ca.club_name AS away_club,
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

    $all = isset($_GET['all']) && $_GET['all'] !== '0';

    if ($all) {
        // Return ALL rows as a plain array (no pagination wrapper).
        $sql = "SELECT {$select} FROM matches m {$joins} {$whereSql} {$orderSql}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // Remote pagination (kept for other consumers or fallback)
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

    echo json_encode([
        'data'         => $rows,
        'last_page'    => $last_page,
        'total'        => $total,
        'current_page' => $page,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['data'=>[], 'last_page'=>1, 'total'=>0, 'current_page'=>1, 'error'=>'Server error']);
}
