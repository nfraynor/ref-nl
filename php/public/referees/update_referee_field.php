<?php
require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$refereeUuid = $_POST['referee_uuid'] ?? null;
$fieldName = $_POST['field_name'] ?? null;
$fieldValue = $_POST['field_value'] ?? null;

// --- Basic Validation ---
if (empty($refereeUuid) || empty($fieldName) || !isset($fieldValue)) {
    http_response_code(400);
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
    'max_travel_distance',
    'district_id',
    'home_lat',
    'home_lon',
    'max_matches_per_weekend',
    'max_days_per_weekend'
];

if (!in_array($fieldName, $allowedFields)) {
    http_response_code(400);
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
    if (!is_numeric($fieldValue) && $fieldValue !== '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Max travel distance must be a number.']);
        exit;
    }
    if ($fieldValue !== '' && (int)$fieldValue < 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Max travel distance cannot be negative.']);
        exit;
    }
    $fieldValue = ($fieldValue === '') ? null : (int)$fieldValue;
} elseif ($fieldName === 'max_matches_per_weekend') {
    if ($fieldValue !== '' && $fieldValue !== '1') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Max matches per weekend must be 1 or empty (multiple).']);
        exit;
    }
    $fieldValue = ($fieldValue === '') ? null : (int)$fieldValue;
} elseif ($fieldName === 'max_days_per_weekend') {
    if ($fieldValue !== '' && !in_array((int)$fieldValue, [1, 2])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Max days per weekend must be 1 or 2.']);
        exit;
    }
    $fieldValue = ($fieldValue === '') ? null : (int)$fieldValue;
} elseif ($fieldName === 'home_lat' || $fieldName === 'home_lon') {
    if ($fieldValue !== '' && !is_numeric($fieldValue)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => ucfirst(str_replace('_', ' ', $fieldName)) . ' must be a number.']);
        exit;
    }
    $fieldValue = ($fieldValue === '') ? null : (float)$fieldValue;
} elseif (($fieldName === 'grade' || $fieldName === 'ar_grade')) {
    $allowedGrades = ["A", "B", "C", "D", "E"];
    if (trim($fieldValue) === '') {
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
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => ucfirst(str_replace('_', ' ', $fieldName)) . ' cannot be empty.']);
    exit;
}

// Further validation for home_club_id
if ($fieldName === 'home_club_id') {
    if (empty($fieldValue)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Home club cannot be empty.']);
        exit;
    }
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("SELECT uuid FROM clubs WHERE uuid = ?");
    $stmt->execute([$fieldValue]);
    if ($stmt->rowCount() === 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid club selected.']);
        exit;
    }
}

// Further validation for district_id
if ($fieldName === 'district_id') {
    if (empty($fieldValue)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'District cannot be empty.']);
        exit;
    }
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("SELECT id FROM districts WHERE id = ?");
    $stmt->execute([$fieldValue]);
    if ($stmt->rowCount() === 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid district selected.']);
        exit;
    }
}

// --- Database Update ---
try {
    $pdo = Database::getConnection();
    $sql = "UPDATE referees SET " . $fieldName . " = :field_value WHERE uuid = :referee_uuid";
    $stmt = $pdo->prepare($sql);

    $stmt->bindParam(':field_value', $fieldValue);
    $stmt->bindParam(':referee_uuid', $refereeUuid);

    if ($stmt->execute()) {
        if ($stmt->rowCount() > 0) {
            // Prepare display value for response
            $displayValue = ($fieldValue === null) ? 'N/A' : $fieldValue;
            if ($fieldName === 'home_club_id') {
                $stmt = $pdo->prepare("SELECT club_name FROM clubs WHERE uuid = ?");
                $stmt->execute([$fieldValue]);
                $displayValue = $stmt->fetchColumn() ?: 'N/A';
            } elseif ($fieldName === 'district_id') {
                $stmt = $pdo->prepare("SELECT name FROM districts WHERE id = ?");
                $stmt->execute([$fieldValue]);
                $displayValue = $stmt->fetchColumn() ?: 'N/A';
            } elseif ($fieldName === 'max_matches_per_weekend') {
                $displayValue = ($fieldValue === null) ? 'Multiple (up to 3)' : '1 Match';
            } elseif ($fieldName === 'max_days_per_weekend') {
                $displayValue = ($fieldValue === null) ? 'N/A (Both Days)' : $fieldValue . ' Day(s)';
            }
            echo json_encode([
                'status' => 'success',
                'message' => ucfirst(str_replace('_', ' ', $fieldName)) . ' updated successfully.',
                'newValueDisplay' => $displayValue
            ]);
        } else {
            $checkStmt = $pdo->prepare("SELECT uuid FROM referees WHERE uuid = ?");
            $checkStmt->execute([$refereeUuid]);
            if ($checkStmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Referee not found.']);
            } else {
                echo json_encode(['status' => 'success', 'message' => ucfirst(str_replace('_', ' ', $fieldName)) . ' is already set to this value.']);
            }
        }
    } else {
        http_response_code(500);
        error_log("Database error updating referee field: " . implode(";", $stmt->errorInfo()));
        echo json_encode(['status' => 'error', 'message' => 'Failed to update referee details. Database error.']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    error_log("PDOException updating referee field: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred. Please try again.']);
}
?>