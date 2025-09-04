<?php
// public/ajax/matches_list.php
declare(strict_types=1);

require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';

header('Content-Type: application/json');

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
