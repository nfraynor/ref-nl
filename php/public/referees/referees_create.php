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
    $stmt = $pdo->prepare("
    SELECT referee_id, email
    FROM referees
    WHERE TRIM(LOWER(email)) = TRIM(LOWER(?))
    LIMIT 1
");
    $stmt->execute([$email]);
    if ($conflict = $stmt->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(409);
        echo json_encode([
            'message' => 'A referee with this email already exists12.',
            'conflict_referee_id' => (int)$conflict['referee_id'],
            'conflict_email' => $conflict['email'], // handy while debugging; remove later if you like
        ]);
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
          INSERT INTO referees (uuid, first_name, last_name, email, grade, home_club_id)
          VALUES (UUID(), ?, ?, ?, ?, ?)
        ");
    $ins->execute([$first, $last, $email, $grade, $home_club_id]);

// Use the AI ref_number from this insert to set referee_id = REF###
    $refNumber = (int)$pdo->lastInsertId();  // requires referees.ref_number INT AUTO_INCREMENT
    if ($refNumber > 0) {
        $upd = $pdo->prepare("
          UPDATE referees
             SET referee_id = CONCAT('REF', LPAD(?, 3, '0'))
           WHERE ref_number = ?
             AND (referee_id IS NULL OR referee_id = '')
          LIMIT 1
        ");
        $upd->execute([$refNumber, $refNumber]);
    }

    $row = $pdo->prepare("
      SELECT 
        r.uuid, r.ref_number, r.referee_id,
        r.first_name, r.last_name, r.email, r.phone,
        c.club_name AS home_club_name,
        r.home_location_city, r.grade, r.ar_grade
      FROM referees r
      LEFT JOIN clubs c ON r.home_club_id = c.uuid
      WHERE r.ref_number = ?
      LIMIT 1
    ");
    $row->execute([$refNumber]);
    $ref = $row->fetch(PDO::FETCH_ASSOC);

    http_response_code(201);
    echo json_encode(['message' => 'Created', 'referee' => $ref]);
} catch (PDOException $e) {
    // Handle duplicate key if a UNIQUE index on email exists
    if ($e->getCode() === '23000') {
        http_response_code(409);
        echo json_encode(['message' => 'A referee with this email already exists222.']);
        exit;
    }
    http_response_code(500);
    echo json_encode(['message' => 'Database error.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Server error.']);
}
