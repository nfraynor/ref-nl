<?php

require_once __DIR__ . '/../utils/db.php';

// Function to generate a version 4 UUID (from subtask description)
function generateUuidV4() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, // set version to 4
        mt_rand(0, 0x3fff) | 0x8000, // set bits 6-7 to 10
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

echo "Starting location data migration script.\n";

try {
    $pdo = Database::getConnection();
    $pdo->beginTransaction();

    echo "1. Fetching distinct locations from 'matches' table...\n";
    // Ensure that we only fetch rows where the necessary data is present.
    // location_address is VARCHAR(255) NULLABLE
    // location_lat and location_lon are DECIMAL(10,7) NULLABLE
    // For a valid location, we need at least address and coordinates.
    $stmt_distinct_loc = $pdo->query("
        SELECT DISTINCT
            location_address,
            location_lat,
            location_lon
        FROM matches
        WHERE location_address IS NOT NULL
          AND location_lat IS NOT NULL
          AND location_lon IS NOT NULL
          AND TRIM(location_address) <> ''
    ");
    $distinct_locations = $stmt_distinct_loc->fetchAll(PDO::FETCH_ASSOC);

    if (empty($distinct_locations)) {
        echo "No distinct locations found to migrate. Exiting.\n";
        $pdo->rollBack(); // or commit if no action is fine
        exit;
    }

    echo "Found " . count($distinct_locations) . " distinct locations to migrate.\n";

    $location_map = []; // To map old location details to new location UUID

    echo "2. Inserting distinct locations into 'locations' table and creating map...\n";
    $insert_location_stmt = $pdo->prepare("
        INSERT INTO locations (uuid, name, address_text, latitude, longitude, notes)
        VALUES (:uuid, :name, :address_text, :latitude, :longitude, :notes)
    ");

    $count = 0;
    foreach ($distinct_locations as $loc) {
        $count++;
        $new_location_uuid = generateUuidV4();
        $location_key = trim($loc['location_address']) . '|' . $loc['location_lat'] . '|' . $loc['location_lon'];

        // For 'name', we can use the address or part of it. Or leave it NULL if appropriate.
        // Using address as name for now.
        $location_name = substr(trim($loc['location_address']), 0, 255);
        // Notes could indicate it's a migrated location
        $location_notes = "Migrated from matches table. Original address: " . trim($loc['location_address']);

        $insert_location_stmt->execute([
            ':uuid' => $new_location_uuid,
            ':name' => $location_name,
            ':address_text' => trim($loc['location_address']),
            ':latitude' => $loc['location_lat'],
            ':longitude' => $loc['location_lon'],
            ':notes' => $location_notes
        ]);

        $location_map[$location_key] = $new_location_uuid;
        if ($count % 50 == 0) {
            echo "Processed $count distinct locations...\n";
        }
    }
    echo "Finished inserting " . count($distinct_locations) . " locations.\n";

    echo "3. Updating 'matches' table with new location_uuid...\n";

    // Fetch all matches that need updating
    $stmt_all_matches = $pdo->query("
        SELECT uuid, location_address, location_lat, location_lon
        FROM matches
        WHERE location_address IS NOT NULL
          AND location_lat IS NOT NULL
          AND location_lon IS NOT NULL
          AND TRIM(location_address) <> ''
          AND location_uuid IS NULL
    ");
    $matches_to_update = $stmt_all_matches->fetchAll(PDO::FETCH_ASSOC);

    $update_match_stmt = $pdo->prepare("
        UPDATE matches
        SET location_uuid = :location_uuid
        WHERE uuid = :match_uuid
    ");

    $updated_count = 0;
    $skipped_count = 0;
    foreach ($matches_to_update as $match) {
        $match_location_key = trim($match['location_address']) . '|' . $match['location_lat'] . '|' . $match['location_lon'];

        if (isset($location_map[$match_location_key])) {
            $new_location_uuid_for_match = $location_map[$match_location_key];
            $update_match_stmt->execute([
                ':location_uuid' => $new_location_uuid_for_match,
                ':match_uuid' => $match['uuid']
            ]);
            $updated_count++;
            if ($updated_count % 100 == 0) {
                echo "Updated $updated_count matches...\n";
            }
        } else {
            // This case should ideally not happen if all distinct locations were processed correctly
            // and matches being iterated here were part of the initial distinct set.
            echo "WARN: Could not find location_uuid for match " . $match['uuid'] . " with key: " . $match_location_key . ". Skipping.\n";
            $skipped_count++;
        }
    }

    if ($skipped_count > 0) {
        echo "WARNING: Skipped $skipped_count matches as their location data did not map to a new location_uuid.\n";
    }
    echo "Finished updating $updated_count matches.\n";

    $pdo->commit();
    echo "Location data migration completed successfully!\n";

} catch (PDOException $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "ERROR: Database error during migration: " . $e->getMessage() . "\n";
    // Potentially log to a file as well
    exit(1);
} catch (Exception $e) {
    echo "ERROR: An unexpected error occurred: " . $e->getMessage() . "\n";
    // Potentially rollback if a transaction was started outside try-catch for PDO
    exit(1);
}

?>
