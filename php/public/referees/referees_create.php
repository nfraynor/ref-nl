<?php
// php/public/ajax/referees_create.php
declare(strict_types=1);

require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['message' => 'Method not allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $first = trim($input['first_name'] ?? '');
    $last  = trim($input['last_name'] ?? '');
    $email = trim($input['email'] ?? '');
    $grade = strtoupper(trim($input['grade'] ?? ''));

    // Home club optional: accept null/empty
    $home_club_id_raw = $input['home_club_id'] ?? null;
    $home_club_id = is_string($home_club_id_raw) ? trim($home_club_id_raw) : $home_club_id_raw;
    if ($home_club_id === '') {
        $home_club_id = null;
    }

    // Required fields (home_club_id is NOT required)
    if ($first === '' || $last === '' || $email === '' || $grade === '') {
        http_response_code(400);
        echo json_encode(['message' => 'Missing required fields.']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['message' => 'Invalid email address.']);
        exit;
    }

    if (!in_array($grade, ['A','B','C','D'], true)) {
        http_response_code(400);
        echo json_encode(['message' => 'Grade must be A, B, C, or D.']);
        exit;
    }

    $pdo = Database::getConnection();

    // Unique email check
    $stmt = $pdo->prepare("SELECT 1 FROM referees WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) {
        http_response_code(409);
        echo json_encode(['message' => 'A referee with this email already exists.']);
        exit;
    }

    // If a club was provided, verify it exists
    if ($home_club_id !== null) {
        $chk = $pdo->prepare("SELECT 1 FROM clubs WHERE uuid = ? LIMIT 1");
        $chk->execute([$home_club_id]);
        if (!$chk->fetchColumn()) {
            http_response_code(400);
            echo json_encode(['message' => 'Selected club was not found.']);
            exit;
        }
    }

    // Insert (home_club_id may be NULL)
    $ins = $pdo->prepare("
        INSERT INTO referees (first_name, last_name, email, grade, home_club_id)
        VALUES (?, ?, ?, ?, ?)
    ");
    $ins->execute([$first, $last, $email, $grade, $home_club_id]);

    $newId = (int)$pdo->lastInsertId();

    // Return the row in the same shape your table expects
    $row = $pdo->prepare("
        SELECT 
            r.referee_id,
            r.first_name,
            r.last_name,
            r.email,
            r.phone,
            c.club_name AS home_club_name,
            r.home_location_city,
            r.grade,
            r.ar_grade
        FROM referees r
        LEFT JOIN clubs c ON r.home_club_id = c.uuid
        WHERE r.referee_id = ?
        LIMIT 1
    ");
    $row->execute([$newId]);
    $ref = $row->fetch(PDO::FETCH_ASSOC);

    http_response_code(201);
    echo json_encode(['message' => 'Created', 'referee' => $ref]);
} catch (PDOException $e) {
    // Handle duplicate key if a UNIQUE index on email exists
    if ($e->getCode() === '23000') {
        http_response_code(409);
        echo json_encode(['message' => 'A referee with this email already exists.']);
        exit;
    }
    http_response_code(500);
    echo json_encode(['message' => 'Database error.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Server error.']);
}
