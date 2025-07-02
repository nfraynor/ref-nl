<?php
require_once __DIR__ . '/../../utils/session_auth.php'; // Session and authentication
require_once __DIR__ . '/../../utils/db.php'; // Database connection

header('Content-Type: application/json');

// The require_once for session_auth.php (above) handles authentication.
// If the user is not logged in, session_auth.php will redirect to login.php and exit.
// So, if the script reaches this point, the user is considered authenticated.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$refereeUuid = $_POST['referee_uuid'] ?? null;
$fieldName = $_POST['field_name'] ?? null;
$fieldValue = $_POST['field_value'] ?? null;

// --- Basic Validation ---
if (empty($refereeUuid) || empty($fieldName) || !isset($fieldValue)) {
    http_response_code(400); // Bad Request
    echo json_encode(['status' => 'error', 'message' => 'Missing required parameters.']);
    exit;
}

// --- Field Whitelisting & Specific Validation ---
$allowedFields = [
    'first_name',
    'last_name',
    'email',
    'phone',
    'home_club_id',
    'home_location_city',
    'grade',
    'ar_grade',
    'max_travel_distance' // Added new field
];

if (!in_array($fieldName, $allowedFields)) {
    http_response_code(400); // Bad Request
    echo json_encode(['status' => 'error', 'message' => 'Invalid field specified for update.']);
    exit;
}

// Specific validations
if ($fieldName === 'email') {
    if (!filter_var($fieldValue, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid email format.']);
        exit;
    }
} elseif ($fieldName === 'max_travel_distance') {
    if (!is_numeric($fieldValue) && $fieldValue !== '') { // Allow empty string to clear the value
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Max travel distance must be a number.']);
        exit;
    }
    if ($fieldValue !== '' && (int)$fieldValue < 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Max travel distance cannot be negative.']);
        exit;
    }
    // Convert to integer or null if empty
    $fieldValue = ($fieldValue === '') ? null : (int)$fieldValue;
}

// Specific validation for grade fields
if (($fieldName === 'grade' || $fieldName === 'ar_grade')) {
    $allowedGrades = ["A", "B", "C", "D", "E"];
    if (trim($fieldValue) === '') { // Check if empty first
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => ucfirst(str_replace('_', ' ', $fieldName)) . ' cannot be empty.']);
        exit;
    }
    if (!in_array($fieldValue, $allowedGrades)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid ' . str_replace('_', ' ', $fieldName) . ' selected. Please choose from A-E.']);
        exit;
    }
} elseif (in_array($fieldName, ['first_name', 'last_name', 'home_location_city']) && trim($fieldValue ?? '') === '') {
    // Moved other required field checks here, ensure $fieldValue is treated as string for trim
     http_response_code(400);
     echo json_encode(['status' => 'error', 'message' => ucfirst(str_replace('_', ' ', $fieldName)) . ' cannot be empty.']);
     exit;
}


$pdo = Database::getConnection();

// Further validation for home_club_id (check if club exists)
if ($fieldName === 'home_club_id') {
    if (empty($fieldValue)) { // Allow unsetting a club if that's a desired feature, otherwise validate.
        // If unsetting is not allowed, or a value is provided, check it.
        // For this example, let's assume a club must be selected if the field is 'home_club_id'
         http_response_code(400);
         echo json_encode(['status' => 'error', 'message' => 'Home club cannot be empty.']);
         exit;
    }
    $stmt = $pdo->prepare("SELECT uuid FROM clubs WHERE uuid = ?");
    $stmt->execute([$fieldValue]);
    if ($stmt->rowCount() === 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid club selected.']);
        exit;
    }
}


// --- Database Update ---
try {
    // The column name is sanitized by checking against $allowedFields
    $sql = "UPDATE referees SET " . $fieldName . " = :field_value WHERE uuid = :referee_uuid";
    $stmt = $pdo->prepare($sql);

    $stmt->bindParam(':field_value', $fieldValue);
    $stmt->bindParam(':referee_uuid', $refereeUuid);

    if ($stmt->execute()) {
        if ($stmt->rowCount() > 0) {
            echo json_encode(['status' => 'success', 'message' => ucfirst(str_replace('_', ' ', $fieldName)) . ' updated successfully.']);
        } else {
            // No rows affected - could mean UUID not found or value was the same
            // Check if referee UUID exists to give a more specific message
            $checkStmt = $pdo->prepare("SELECT uuid FROM referees WHERE uuid = ?");
            $checkStmt->execute([$refereeUuid]);
            if ($checkStmt->rowCount() === 0) {
                 http_response_code(404); // Not Found
                 echo json_encode(['status' => 'error', 'message' => 'Referee not found.']);
            } else {
                // Value was likely the same, which isn't an error for the user.
                echo json_encode(['status' => 'success', 'message' => ucfirst(str_replace('_', ' ', $fieldName)) . ' is already set to this value.']);
            }
        }
    } else {
        http_response_code(500); // Internal Server Error
        error_log("Database error updating referee field: " . implode(";", $stmt->errorInfo()));
        echo json_encode(['status' => 'error', 'message' => 'Failed to update referee details. Database error.']);
    }
} catch (PDOException $e) {
    http_response_code(500); // Internal Server Error
    error_log("PDOException updating referee field: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred. Please try again.']);
}

?>
