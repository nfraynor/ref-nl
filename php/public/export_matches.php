<?php
require_once __DIR__ . '/../utils/session_auth.php'; // Ensures user is authenticated
require_once __DIR__ . '/../utils/db.php';         // For database connection

// No direct output, clear any existing buffers
if (ob_get_level()) {
    ob_end_clean();
}

$pdo = Database::getConnection();

// --- START: SQL Query Construction (similar to matches.php) ---
$whereClauses = [];
$params = [];

// Role-based permission logic
$userRole = $_SESSION['user_role'] ?? null;
$userDivisionIds = $_SESSION['division_ids'] ?? [];
$userDistrictIds = $_SESSION['district_ids'] ?? [];

$allowedDivisionNames = [];
$allowedDistrictNames = [];
$canFetchData = true; // Flag to control if data fetching should proceed

if ($userRole !== 'super_admin') {
    if (!empty($userDivisionIds) && !(count($userDivisionIds) === 1 && $userDivisionIds[0] === '')) {
        $placeholders = implode(',', array_fill(0, count($userDivisionIds), '?'));
        $stmtDiv = $pdo->prepare("SELECT name FROM divisions WHERE id IN ($placeholders)");
        $stmtDiv->execute($userDivisionIds);
        $allowedDivisionNames = $stmtDiv->fetchAll(PDO::FETCH_COLUMN);
    }

    if (!empty($userDistrictIds) && !(count($userDistrictIds) === 1 && $userDistrictIds[0] === '')) {
        $placeholders = implode(',', array_fill(0, count($userDistrictIds), '?'));
        $stmtDist = $pdo->prepare("SELECT name FROM districts WHERE id IN ($placeholders)");
        $stmtDist->execute($userDistrictIds);
        $allowedDistrictNames = $stmtDist->fetchAll(PDO::FETCH_COLUMN);
    }

    if (empty($allowedDivisionNames) || empty($allowedDistrictNames)) {
        $canFetchData = false; // User has no permission for any division/district combination
    } else {
        $divisionPlaceholders = implode(',', array_fill(0, count($allowedDivisionNames), '?'));
        $whereClauses[] = "m.division IN ($divisionPlaceholders)";
        foreach ($allowedDivisionNames as $name) {
            $params[] = $name;
        }

        $districtPlaceholders = implode(',', array_fill(0, count($allowedDistrictNames), '?'));
        $whereClauses[] = "m.district IN ($districtPlaceholders)";
        foreach ($allowedDistrictNames as $name) {
            $params[] = $name;
        }
    }
}

// Apply filters from _GET parameters
if (!empty($_GET['start_date'])) {
    $whereClauses[] = "m.match_date >= ?";
    $params[] = $_GET['start_date'];
}
if (!empty($_GET['end_date'])) {
    $whereClauses[] = "m.match_date <= ?";
    $params[] = $_GET['end_date'];
}

// Handle array filters (division, district, poule)
foreach (['division', 'district', 'poule'] as $filter_key) {
    if (!empty($_GET[$filter_key])) {
        $filter_values = is_array($_GET[$filter_key]) ? $_GET[$filter_key] : [$_GET[$filter_key]];
        if (count($filter_values) > 0) {
            $placeholders = implode(',', array_fill(0, count($filter_values), '?'));
            $whereClauses[] = "m.$filter_key IN ($placeholders)";
            foreach ($filter_values as $value) {
                $params[] = $value;
            }
        }
    }
}

// Handle potential single string filters for location and referee_assigner from AJAX (though less likely for full export URL)
// For export, we assume these filters if present are single values or not set.
// The main matches.php uses AJAX for multi-select on these, which might not translate directly to GET params for export link
// For simplicity, we'll assume if they are in GET, they are single values.
if (!empty($_GET['location_uuid'])) {
    $whereClauses[] = "m.location_uuid = ?";
    $params[] = $_GET['location_uuid'];
}
if (!empty($_GET['referee_assigner_uuid'])) {
    $whereClauses[] = "m.referee_assigner_uuid = ?";
    $params[] = $_GET['referee_assigner_uuid'];
}


$whereSQL = count($whereClauses) > 0 ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$sql = "
    SELECT
        m.uuid AS match_id,
        m.match_date,
        m.kickoff_time,
        hc.club_name AS home_club_name,
        ht.team_name AS home_team_name,
        ac.club_name AS away_club_name,
        at.team_name AS away_team_name,
        m.division,
        m.district,
        m.poule,
        l.name AS location_name,
        l.address_text AS location_address,
        ref_assigner.username AS referee_assigner_username,
        ref.first_name AS referee_first_name, ref.last_name AS referee_last_name,
        ar1.first_name AS ar1_first_name, ar1.last_name AS ar1_last_name,
        ar2.first_name AS ar2_first_name, ar2.last_name AS ar2_last_name,
        com.first_name AS commissioner_first_name, com.last_name AS commissioner_last_name
    FROM matches m
    JOIN teams ht ON m.home_team_id = ht.uuid
    JOIN clubs hc ON ht.club_id = hc.uuid
    JOIN teams at ON m.away_team_id = at.uuid
    JOIN clubs ac ON at.club_id = ac.uuid
    LEFT JOIN locations l ON m.location_uuid = l.uuid
    LEFT JOIN users ref_assigner ON m.referee_assigner_uuid = ref_assigner.uuid
    LEFT JOIN referees ref ON m.referee_id = ref.uuid
    LEFT JOIN referees ar1 ON m.ar1_id = ar1.uuid
    LEFT JOIN referees ar2 ON m.ar2_id = ar2.uuid
    LEFT JOIN referees com ON m.commissioner_id = com.uuid
    $whereSQL
    ORDER BY m.match_date ASC, m.kickoff_time ASC
"; // Removed LIMIT clause for full export
// --- END: SQL Query Construction ---

$matches = [];
if ($canFetchData) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Log error or handle - for now, just die to prevent broken output
        error_log("Export Matches SQL Error: " . $e->getMessage());
        die("Error fetching data for export. Please check server logs.");
    }
} else {
    // If user cannot fetch data due to permissions, send an empty CSV or an error message.
    // For simplicity, we'll send an empty CSV.
    $matches = [];
}


// --- START: CSV Output ---
$filename = "matches_export_" . date('Ymd_His') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// Define CSV Headers
$headers = [
    'Match ID', 'Date', 'Kickoff',
    'Home Club', 'Home Team',
    'Away Club', 'Away Team',
    'Division', 'District', 'Poule',
    'Location Name', 'Location Address',
    'Referee Assigner',
    'Referee', 'AR1', 'AR2', 'Commissioner'
];
fputcsv($output, $headers, ',', '"');

// Write data rows
if (!empty($matches)) {
    foreach ($matches as $match) {
        $row = [
            $match['match_id'],
            $match['match_date'],
            substr($match['kickoff_time'], 0, 5), // Format kickoff time
            $match['home_club_name'],
            $match['home_team_name'],
            $match['away_club_name'],
            $match['away_team_name'],
            $match['division'],
            $match['district'],
            $match['poule'],
            $match['location_name'],
            $match['location_address'],
            $match['referee_assigner_username'],
            trim(($match['referee_first_name'] ?? '') . ' ' . ($match['referee_last_name'] ?? '')),
            trim(($match['ar1_first_name'] ?? '') . ' ' . ($match['ar1_last_name'] ?? '')),
            trim(($match['ar2_first_name'] ?? '') . ' ' . ($match['ar2_last_name'] ?? '')),
            trim(($match['commissioner_first_name'] ?? '') . ' ' . ($match['commissioner_last_name'] ?? ''))
        ];
        fputcsv($output, $row, ',', '"');
    }
} else if (!$canFetchData) {
    // Optional: Write a row indicating no permissions or no data due to permissions
    // fputcsv($output, ['No data available due to current user permissions or filters.']);
}


fclose($output);
exit;
// --- END: CSV Output ---
?>
