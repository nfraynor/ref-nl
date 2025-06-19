<?php

require_once __DIR__ . '/../utils/db.php';

define('BASE_URL', getenv('TEST_BASE_URL') ?: 'http://localhost:8000'); // Allow overriding via env var

function get_first_match_data() {
    static $match_data = null; // Cache the result

    if ($match_data === null) {
        $pdo = Database::getConnection();
        // Fetch the first match along with team names
        $stmt = $pdo->query("
            SELECT
                m.uuid,
                m.match_date,
                ht.team_name AS home_team_name,
                ac.club_name AS away_club_name,
                at.team_name AS away_team_name,
                l.name AS location_name,
                l.address_text AS location_address_text,
                l.latitude AS location_latitude,
                l.longitude AS location_longitude,
                l.notes AS location_specific_notes
            FROM matches m
            JOIN teams ht ON m.home_team_id = ht.uuid
            JOIN teams at ON m.away_team_id = at.uuid
            JOIN clubs ac ON at.club_id = ac.uuid
            LEFT JOIN locations l ON m.location_uuid = l.uuid -- Join with locations table
            ORDER BY m.match_date ASC, m.kickoff_time ASC
            LIMIT 1
        ");
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$data) {
            echo "ERROR: No match data found in the database. Please seed the database (php provision/seed.php).\n";
            exit(1);
        }
        $match_data = $data;
    }
    return $match_data;
}

function fetch_page_content($url) {
    $full_url = BASE_URL . $url;
    // Use a stream context to capture HTTP status and headers if needed later
    // For now, basic file_get_contents and error suppression
    $content = @file_get_contents($full_url);
    if ($content === false) {
        // Could check $http_response_header for status codes if needed
        // For now, a simple failure is enough
        return false;
    }
    return $content;
}

// Helper for simple assertions
function test_assert($condition, $message) {
    if (!$condition) {
        echo "FAIL: " . $message . "\n";
        // Optionally, increment a global error counter or throw an exception
        return false;
    }
    echo "PASS: " . $message . "\n";
    return true;
}

?>
