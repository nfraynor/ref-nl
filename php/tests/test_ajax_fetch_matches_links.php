<?php

require_once __DIR__ . '/test_utils.php';

echo "Running Test 3: Links in AJAX-loaded matches (ajax/fetch_matches.php)\n";

// Fetch the AJAX endpoint.
// Add parameters if necessary to ensure matches are returned, e.g., a wide date range.
// For now, assuming default call will return seeded matches.
// To simulate assign_mode for full coverage, one might add &assign_mode=1
echo "Test 3.1: Fetching ajax/fetch_matches.php\n";
$ajax_content = fetch_page_content("/ajax/fetch_matches.php");

if ($ajax_content === false || trim($ajax_content) === "") {
    // If fetch_page_content returns false, or if the content is empty (e.g. no matches found)
    test_assert(false, "Failed to fetch content from ajax/fetch_matches.php or it returned empty. Ensure DB is seeded and endpoint works.");
    // Check if any matches were expected
    $first_match_check = get_first_match_data(); // This will exit if no matches are in DB
    if ($first_match_check) {
         echo "INFO: Database has matches, so ajax/fetch_matches.php should have returned content.\n";
    }
    exit(1);
}

// The output is a series of <tr>...</tr>.
// Example: <tr><td><a href="match_detail.php?uuid=some-uuid-string">2024-09-14</a></td>...</tr>
// Need to be careful if the output is just one row or many. The pattern should find the first one.
$pattern = '/<td><a href="match_detail.php\?uuid=([a-f0-9\-]+)">.*?<\/a><\/td>/i';
preg_match($pattern, $ajax_content, $matches);

if (!empty($matches) && isset($matches[1])) {
    $found_uuid = $matches[1];
    test_assert(true, "Found a match link in ajax/fetch_matches.php output.");
    test_assert(!empty($found_uuid), "The extracted UUID from the AJAX link is not empty: '$found_uuid'.");

    // Optionally, compare with a UUID fetched from the DB.
    // $first_match_from_db = get_first_match_data(); // already called above implicitly
    // test_assert($found_uuid === $first_match_from_db['uuid'], "The AJAX link UUID matches first match UUID from DB if sort order is identical.");
    // Note: The order might differ from get_first_match_data if ajax/fetch_matches.php has different default ORDER BY.
    // The key is that *a* valid link is formed.

} else {
    test_assert(false, "Could not find a correctly formatted match_detail.php link in ajax/fetch_matches.php output. Pattern not matched.");
    // echo "DEBUG: AJAX Content was:\n" . substr($ajax_content, 0, 500) . "...\n"; // For debugging
}

echo "\nTest 3 Finished.\n";

?>
