<?php
// utils/grade_policy.php

// -------------------------------
// Preferred grade by division
// -------------------------------
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

// -------------------------------
// Grade helpers
// -------------------------------

/**
 * Map A/B/C/D (and noisy variants like "B2", "c+") to a rank 1..4.
 * A=4, B=3, C=2, D=1. Unknown -> 0.
 */
function grade_to_rank(?string $g): int {
    static $map = ['A'=>4,'B'=>3,'C'=>2,'D'=>1];
    $g = strtoupper(trim((string)$g));
    $g = ($g !== '') ? $g[0] : ''; // reduce to first letter
    return $map[$g] ?? 0;
}

/** Reverse of grade_to_rank (defaults to D). */
function rank_to_grade(int $rank): string {
    static $rev = [1=>'D', 2=>'C', 3=>'B', 4=>'A'];
    return $rev[$rank] ?? 'D';
}

// -------------------------------
// Expected grade policy
// -------------------------------

/**
 * Return the expected grade letter (A/B/C/D) for a match row.
 * Precedence:
 *  1) Explicit $row['expected_grade'] (first letter A-D)
 *  2) Division mapping (case-insensitive)
 *  3) Fallback 'D'
 */
function expected_grade_for_match_letter(array $row): string {
    static $ciMap = null;
    if ($ciMap === null) {
        $ciMap = [];
        foreach (PREFERRED_GRADE_BY_DIVISION as $k => $v) {
            $ciMap[mb_strtolower($k)] = $v;
        }
    }

    // 1) explicit per-match override
    $explicit = strtoupper(trim((string)($row['expected_grade'] ?? '')));
    if ($explicit !== '') {
        $first = $explicit[0];
        if (in_array($first, ['A','B','C','D'], true)) {
            return $first;
        }
    }

    // 2) from division mapping (case-insensitive)
    $division = trim((string)($row['division'] ?? ''));
    if ($division !== '') {
        $hit = $ciMap[mb_strtolower($division)] ?? null;
        if ($hit) return $hit;
    }

    // 3) fallback
    return 'D';
}

/** Convenience: rank (1..4) for the expected match grade. */
function expected_grade_rank(array $row): int {
    return grade_to_rank(expected_grade_for_match_letter($row));
}
