<?php

require_once __DIR__ . '/test_utils.php';

echo "Running Test 2: Links on matches.php\n";

// Fetch the main matches page
echo "Test 2.1: Fetching matches.php\n";
$page_content = fetch_page_content("/matches.php");

if ($page_content === false) {
    test_assert(false, "Failed to fetch matches.php page.");
    exit(1);
}

// Attempt to find the first match link and verify its structure.
// The link is on the match date, which is the first <td> in the row.
// Example: <td><a href="match_detail.php?uuid=some-uuid-string">2024-09-14</a></td>
$pattern = '/<td><a href="match_detail.php\?uuid=([a-f0-9\-]+)">.*?<\/a><\/td>/i';
preg_match($pattern, $page_content, $matches);

if (!empty($matches) && isset($matches[1])) {
    $found_uuid = $matches[1];
    test_assert(true, "Found a match link on matches.php.");
    test_assert(!empty($found_uuid), "The extracted UUID from the link is not empty: '$found_uuid'.");

    // Optionally, compare with a UUID fetched from the DB if precise matching is needed
    // For now, just checking format and presence is good.
    // $first_match_from_db = get_first_match_data();
    // test_assert($found_uuid === $first_match_from_db['uuid'], "The link UUID matches the first match UUID from DB.");

} else {
    test_assert(false, "Could not find a correctly formatted match_detail.php link on matches.php. Pattern not matched.");
}

echo "\nTest 2 Finished.\n";

?>
