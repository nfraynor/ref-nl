<?php

require_once __DIR__ . '/test_utils.php';

echo "Running Test 1: Match Detail Page Content\n";

// Get data for a valid match
$match_to_test = get_first_match_data();
if (!$match_to_test || !isset($match_to_test['uuid'])) {
    echo "FAIL: Could not retrieve a valid match UUID for testing. Ensure database is seeded.\n";
    exit(1);
}

$valid_match_uuid = $match_to_test['uuid'];
$expected_home_team = htmlspecialchars($match_to_test['home_team_name']);
// The away team display is "Club Name - Team Name"
$expected_away_team_fragment = htmlspecialchars($match_to_test['away_team_name']);
$expected_away_club_fragment = htmlspecialchars($match_to_test['away_club_name']);
$expected_date = htmlspecialchars(date('F j, Y', strtotime($match_to_test['match_date'])));

// Expected location details from the joined locations table
// Note: These can be NULL if the location_uuid is not set or location not found.
$expected_location_name = isset($match_to_test['location_name']) ? htmlspecialchars($match_to_test['location_name']) : null;
$expected_location_address = isset($match_to_test['location_address_text']) ? htmlspecialchars($match_to_test['location_address_text']) : null;
$expected_location_latitude = isset($match_to_test['location_latitude']) ? htmlspecialchars($match_to_test['location_latitude']) : null;
$expected_location_longitude = isset($match_to_test['location_longitude']) ? htmlspecialchars($match_to_test['location_longitude']) : null;
$expected_location_notes = isset($match_to_test['location_specific_notes']) ? htmlspecialchars($match_to_test['location_specific_notes']) : null;


// Test 1.1: Valid Match UUID
echo "Test 1.1: Fetching match_detail.php with valid UUID: $valid_match_uuid\n";
$page_content = fetch_page_content("/match_detail.php?uuid=" . $valid_match_uuid);

if ($page_content === false) {
    test_assert(false, "Failed to fetch page for valid UUID.");
} else {
    test_assert(strpos($page_content, $expected_home_team) !== false, "Page contains expected home team name: '$expected_home_team'.");
    test_assert(strpos($page_content, $expected_away_team_fragment) !== false, "Page contains expected away team name fragment: '$expected_away_team_fragment'.");
    test_assert(strpos($page_content, $expected_away_club_fragment) !== false, "Page contains expected away club name fragment: '$expected_away_club_fragment'.");
    test_assert(strpos($page_content, $expected_date) !== false, "Page contains expected match date: '$expected_date'.");
    test_assert(strpos($page_content, "<h1>Match Details</h1>") !== false, "Page contains correct title 'Match Details'.");

    // Assertions for location details
    if ($expected_location_name) {
        test_assert(strpos($page_content, "<strong>" . $expected_location_name . "</strong>") !== false, "Page contains expected location name: '$expected_location_name'.");
    } else {
        // If name is null, it might show N/A or address might be primary
        test_assert(strpos($page_content, "<strong>N/A</strong>") !== false || $expected_location_address, "Page contains N/A for location name or has an address when name is null.");
    }
    if ($expected_location_address) {
        test_assert(strpos($page_content, "<small>" . $expected_location_address . "</small>") !== false, "Page contains expected location address: '$expected_location_address'.");
    } else {
        test_assert(strpos($page_content, "<small>Address not available</small>") !== false, "Page shows 'Address not available' when address is null.");
    }

    if ($expected_location_latitude && $expected_location_longitude) {
        $lat_str = "Lat: " . $expected_location_latitude;
        $lon_str = "Lon: " . $expected_location_longitude;
        test_assert(strpos($page_content, $lat_str) !== false, "Page contains expected location latitude: '$lat_str'.");
        test_assert(strpos($page_content, $lon_str) !== false, "Page contains expected location longitude: '$lon_str'.");
        // Check for Google Maps link
        $maps_link_pattern = 'https://www.google.com/maps?q=' . preg_quote($expected_location_latitude, '/') . ',' . preg_quote($expected_location_longitude, '/');
        test_assert(strpos($page_content, $maps_link_pattern) !== false, "Page contains Google Maps link with correct coordinates.");
    }

    if ($expected_location_notes) {
        // nl2br is used in the template, so we search for the raw note text. The actual HTML might have <br> tags.
        // A simple strpos should be sufficient if the note isn't too complex.
        test_assert(strpos($page_content, $expected_location_notes) !== false, "Page contains expected location notes: (first part) '".substr($expected_location_notes,0,50)."'.");
    }
}

// Test 1.2: Invalid/Non-existent Match UUID
$invalid_match_uuid = "invalid-uuid-does-not-exist";
echo "\nTest 1.2: Fetching match_detail.php with invalid UUID: $invalid_match_uuid\n";
$page_content_invalid = fetch_page_content("/match_detail.php?uuid=" . $invalid_match_uuid);

if ($page_content_invalid === false) {
    test_assert(false, "Failed to fetch page for invalid UUID (this might be OK if it's a 404, but script should show error).");
    // Depending on server setup, file_get_contents might return false on 404.
    // The match_detail.php script is designed to output an error message within a 200 OK page.
    // So, a `false` here means the server itself might have errored or the URL is fundamentally wrong.
} else {
    // match_detail.php should output "Match not found." within the HTML structure.
    test_assert(strpos($page_content_invalid, "<p class='alert alert-danger'>Match not found.</p>") !== false, "Page for invalid UUID shows 'Match not found.' error message.");
}

// Test 1.3: Missing Match UUID
echo "\nTest 1.3: Fetching match_detail.php with no UUID\n";
$page_content_no_uuid = fetch_page_content("/match_detail.php");

if ($page_content_no_uuid === false) {
    test_assert(false, "Failed to fetch page with no UUID.");
} else {
    test_assert(strpos($page_content_no_uuid, "<p class='alert alert-warning'>Match ID is missing.</p>") !== false, "Page with no UUID shows 'Match ID is missing.' error message.");
}

echo "\nTest 1 Finished.\n";

?>
