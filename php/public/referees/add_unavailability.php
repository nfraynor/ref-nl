<?php
require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';

$pdo = Database::getConnection();

$refereeUuid = $_POST['referee_id'] ?? '';
$startDate = $_POST['start_date'] ?? '';
$endDate = $_POST['end_date'] ?? '';
$reason = $_POST['reason'] ?? ''; // Reason can be empty

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($refereeUuid && $startDate && $endDate) {
    try {
        $insertStmt = $pdo->prepare("
            INSERT INTO referee_unavailability (uuid, referee_id, start_date, end_date, reason)
            VALUES (UUID(), ?, ?, ?, ?)
        ");
        $insertStmt->execute([$refereeUuid, $startDate, $endDate, $reason]);

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                "status" => "success",
                "data" => [
                    "start_date" => $startDate,
                    "end_date" => $endDate,
                    "reason" => $reason
                ]
            ]);
        } else {
            // Traditional form submission: Fetch numeric ID for redirect
            $idStmt = $pdo->prepare("SELECT referee_id FROM referees WHERE uuid = ?");
            $idStmt->execute([$refereeUuid]);
            $refereeNumericId = $idStmt->fetchColumn();

            if ($refereeNumericId) {
                header("Location: referee_detail.php?id=" . urlencode($refereeNumericId) . "&status=success");
            } else {
                // Fallback if numeric ID not found (should be rare if UUID is valid)
                header("Location: referees.php?error=notfound");
            }
        }
    } catch (PDOException $e) {
        if ($isAjax) {
            header('Content-Type: application/json');
            http_response_code(500); // Internal Server Error
            echo json_encode([
                "status" => "error",
                "message" => "Database error: " . $e->getMessage()
            ]);
        } else {
            // Traditional form submission error
            // For simplicity, redirect to a generic error page or list with an error flag
            // In a real app, might use session flash messages
            error_log("Database error for traditional form submission: " . $e->getMessage());
            header("Location: referees.php?error=db");
        }
    }
} else {
    // Missing fields
    $missingFields = [];
    if (!$refereeUuid) $missingFields[] = 'referee_id';
    if (!$startDate) $missingFields[] = 'start_date';
    if (!$endDate) $missingFields[] = 'end_date';
    $errorMessage = "Missing required fields: " . implode(', ', $missingFields);

    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(400); // Bad Request
        echo json_encode([
            "status" => "error",
            "message" => $errorMessage
        ]);
    } else {
        // Traditional form submission error for missing fields
        // Redirect back to referee list with an error or display a message
        // Using a query param for simplicity here.
        header("Location: referees.php?error=missingfields&fields=" . urlencode(implode(',', $missingFields)));
    }
}
exit;
