<?php
// public/ajax/update_match_assignment.php
declare(strict_types=1);

require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';
require_once __DIR__ . '/../../utils/grade_policy.php'; // <- we reuse grade policy

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success'=>false, 'message'=>'Method not allowed']); exit;
    }

    $matchUuid = trim($_POST['match_uuid'] ?? '');
    $field     = trim($_POST['field'] ?? '');
    $value     = trim($_POST['value'] ?? '');

    if ($matchUuid === '' || $field === '') {
        http_response_code(400);
        echo json_encode(['success'=>false, 'message'=>'Missing parameters']); exit;
    }

    // Permit list
    $allowed = ['referee_id','ar1_id','ar2_id','commissioner_id','referee_assigner_uuid'];
    if (!in_array($field, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['success'=>false, 'message'=>'Invalid field']); exit;
    }

    $pdo = Database::getConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1) Persist the change
    $sql = "UPDATE matches SET {$field} = :val WHERE uuid = :uuid";
    $stmt = $pdo->prepare($sql);
    $paramVal = ($value === '') ? null : $value;
    if ($paramVal === null) {
        $stmt->bindValue(':val', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':val', $paramVal, PDO::PARAM_STR);
    }
    $stmt->bindValue(':uuid', $matchUuid, PDO::PARAM_STR);
    $stmt->execute();

    // 2) Build a tiny “patch” with refreshed fit info for all roles
    //
    // Minimal logic:
    //  - Use PREFERRED_GRADE_BY_DIVISION and grade_to_rank()
    //  - Mark 'below_grade' when referee grade rank < preferred rank
    //  - Set *_fit_score to 100 normally, or 65 if below grade (keeps
    //    your existing conflict overrides meaningful: 0/70/85)
    //
    // If a role is unassigned or grade unknown -> score=null, flags=[]
    //
    // NOTE: You can extend this later (e.g. recent_team) without touching the client.

    // Fetch match division + assigned uuids
    $m = $pdo->prepare("
        SELECT division, referee_id, ar1_id, ar2_id, commissioner_id
        FROM matches
        WHERE uuid = :uuid
        LIMIT 1
    ");
    $m->execute([':uuid' => $matchUuid]);
    $match = $m->fetch(PDO::FETCH_ASSOC);

    if (!$match) {
        echo json_encode(['success'=>true]); // nothing else we can do
        exit;
    }

    $division = (string)($match['division'] ?? '');
    $roleToField = [
        'referee'      => 'referee_id',
        'ar1'          => 'ar1_id',
        'ar2'          => 'ar2_id',
        'commissioner' => 'commissioner_id',
    ];

    // Collect unique non-null referee ids
    $ids = [];
    foreach ($roleToField as $role => $col) {
        $v = $match[$col] ?? null;
        if (!empty($v)) $ids[] = $v;
    }
    $ids = array_values(array_unique($ids));

    // Map uuid -> grade (single trip)
    $grades = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $q  = $pdo->prepare("SELECT uuid, grade FROM referees WHERE uuid IN ($in)");
        $q->execute($ids);
        while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
            $grades[$r['uuid']] = (string)($r['grade'] ?? '');
        }
    }

    // Helper: compute per-role fit
    $preferredRank = 0;
    if ($division !== '' && isset(PREFERRED_GRADE_BY_DIVISION[$division])) {
        $prefGrade = PREFERRED_GRADE_BY_DIVISION[$division] ?? null;
        if ($prefGrade !== null) $preferredRank = grade_to_rank($prefGrade);
    }

    $patch = ['uuid' => $matchUuid];

    foreach ($roleToField as $prefix => $col) {
        $rid   = $match[$col] ?? null;
        $score = null;
        $flags = [];

        if (!empty($rid)) {
            $gStr = $grades[$rid] ?? '';
            $gRank = grade_to_rank($gStr);
            if ($preferredRank > 0 && $gRank > 0 && $gRank < $preferredRank) {
                $flags[] = 'below_grade';
                $score   = 65; // conservative baseline; conflicts can still clamp further client-side
            } else {
                $score   = 100;
            }
        }

        $patch["{$prefix}_fit_score"] = $score;
        $patch["{$prefix}_fit_flags"] = $flags;
    }

    // Optional: provide a stable structure for __unavail (kept false here;
    // extend later if you have an availability table you want to check)
    $patch['__unavail'] = [
        'referee_id'      => false,
        'ar1_id'          => false,
        'ar2_id'          => false,
        'commissioner_id' => false,
    ];

    echo json_encode(['success'=>true, 'patch'=>$patch]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false, 'message'=>'Server error']);
}
