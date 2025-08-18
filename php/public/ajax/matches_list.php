<?php
// public/ajax/matches_list.php
declare(strict_types=1);

require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';

header('Content-Type: application/json');

try {
    $pdo = Database::getConnection();

    // Permissions
    $userRole = $_SESSION['user_role'] ?? null;
    $userDivisionIds = $_SESSION['division_ids'] ?? [];
    $userDistrictIds = $_SESSION['district_ids'] ?? [];

    $where = [];
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
            echo json_encode(['data'=>[], 'last_page'=>1, 'total'=>0]); exit;
        }

        $ph = implode(',', array_fill(0, count($allowedDivisionNames), '?'));
        $where[] = "m.division IN ($ph)"; $params = array_merge($params, $allowedDivisionNames);
        $ph = implode(',', array_fill(0, count($allowedDistrictNames), '?'));
        $where[] = "m.district IN ($ph)"; $params = array_merge($params, $allowedDistrictNames);
    }

    // Filters from GET
    $page = max(1, (int)($_GET['page'] ?? 1));
    $size = min(500, max(10, (int)($_GET['size'] ?? 50)));
    $offset = ($page - 1) * $size;

    $start_date = trim($_GET['start_date'] ?? '');
    $end_date   = trim($_GET['end_date'] ?? '');
    $search     = trim($_GET['search'] ?? '');

    if ($start_date !== '') { $where[] = "m.match_date >= ?"; $params[] = $start_date; }
    if ($end_date !== '')   { $where[] = "m.match_date <= ?"; $params[] = $end_date; }

    // Simple global search across a few columns
    if ($search !== '') {
        $where[] = "(th.team_name LIKE ? OR ta.team_name LIKE ? OR m.division LIKE ? OR m.district LIKE ? OR m.poule LIKE ? OR m.location_address LIKE ?)";
        for ($i=0;$i<6;$i++) $params[] = "%{$search}%";
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Sorting
    $sortCol = $_GET['sort_col'] ?? 'm.match_date';
    $sortDir = strtoupper($_GET['sort_dir'] ?? 'ASC');
    $allowedCols = ['m.match_date','m.kickoff_time','m.division','m.district','m.poule','th.team_name','ta.team_name'];
    if (!in_array($sortCol, $allowedCols, true)) $sortCol = 'm.match_date';
    $sortDir = ($sortDir === 'DESC') ? 'DESC' : 'ASC';

    // Count
    $countSql = "SELECT COUNT(*) FROM matches m {$whereSql}";
    $cs = $pdo->prepare($countSql); $cs->execute($params);
    $total = (int)$cs->fetchColumn();

    // Data
    $sql = "
    SELECT
      m.uuid,
      m.match_date,
      m.kickoff_time,
      m.division,
      m.district,
      m.poule,
      m.expected_grade,

      m.referee_id,
      m.ar1_id,
      m.ar2_id,
      m.commissioner_id,

      th.team_name AS home_team,
      ta.team_name AS away_team,

      ch.club_name AS home_club,
      ca.club_name AS away_club,

      m.location_address,

      u.username AS referee_assigner_username,
      m.referee_assigner_uuid
    FROM matches m
    LEFT JOIN teams th ON m.home_team_id = th.uuid
    LEFT JOIN clubs ch ON th.club_id     = ch.uuid
    LEFT JOIN teams ta ON m.away_team_id = ta.uuid
    LEFT JOIN clubs ca ON ta.club_id     = ca.uuid
    LEFT JOIN users u  ON m.referee_assigner_uuid = u.uuid
    {$whereSql}
    ORDER BY {$sortCol} {$sortDir}, m.kickoff_time ASC
    LIMIT ? OFFSET ?
    ";
    $stmt = $pdo->prepare($sql);
    $pageParams = array_merge($params, [$size, $offset]);
    $stmt->execute($pageParams);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'data' => $rows,
        'last_page' => (int)ceil($total / $size),
        'total' => $total,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['data'=>[], 'last_page'=>1, 'total'=>0, 'error'=>'Server error']);
}
