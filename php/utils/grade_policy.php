<?php
// utils/grade_policy.php

// Preferred grade by division
const PREFERRED_GRADE_BY_DIVISION = [
    'Ereklasse'        => 'A',
    'Futureklasse'     => 'B',
    'Ereklasse Dames'  => 'B',
    'Colts Cup'        => 'B',
    'Colts Plate'      => 'C',
    '1e Klasse'        => 'B',
    '2e Klasse'        => 'C',
    '3e Klasse'        => 'D',
    '4e Klasse'        => 'D',
    '1e Klasse Dames'  => 'C',
    '2e Klasse Dames'  => 'D',
    '3e Klasse Dames'  => 'D',
];

// Map grade letters to rank
function grade_to_rank(?string $g): int {
    static $map = ['A' => 4, 'B' => 3, 'C' => 2, 'D' => 1];
    $g = strtoupper(trim((string)$g));
    return $map[$g] ?? 0;
}

// Reverse map if needed
function rank_to_grade(int $r): ?string {
    static $rev = [1 => 'D', 2 => 'C', 3 => 'B', 4 => 'A'];
    return $rev[$r] ?? null;
}

/** Resolve expected grade letter from division. */
function expected_grade_for_division(?string $division): string {
    $div = trim((string)$division);

    if (isset(PREFERRED_GRADE_BY_DIVISION[$div])) {
        return PREFERRED_GRADE_BY_DIVISION[$div];
    }

    // default to D if unknown
    return 'D';
}

/** Main entry for matches_list.php: return numeric rank */
function expected_grade_rank_for_match(array $matchRow): int {
    $explicit = $matchRow['expected_grade'] ?? null;
    if ($explicit) return grade_to_rank($explicit);

    $div = $matchRow['division'] ?? '';
    $gradeLetter = expected_grade_for_division($div);
    return grade_to_rank($gradeLetter);
}
