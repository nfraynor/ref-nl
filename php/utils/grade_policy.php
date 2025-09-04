<?php
// utils/grade_policy.php
declare(strict_types=1);

/**
 * Hardcoded policy mapping (your list).
 * Keys compared case-insensitively; we’ll normalize division names.
 */
const PREFERRED_GRADE_BY_DIVISION = [
    'Ereklasse'         => 'A',
    'Futureklasse'      => 'B',
    'Ereklasse Dames'   => 'B',
    'Colts Cup'         => 'B',
    '1e Klasse'         => 'B',
    '2e Klasse'         => 'C',
    '3e Klasse'         => 'D',
];

/** Map grade letters to rank (higher = harder) */
function grade_to_rank(?string $g): int {
    static $map = ['A'=>4, 'B'=>3, 'C'=>2, 'D'=>1];
    $g = strtoupper(trim((string)$g));
    return $map[$g] ?? 0;
}
function rank_to_grade(int $r): ?string {
    static $rev = [1=>'D',2=>'C',3=>'B',4=>'A'];
    return $rev[$r] ?? null;
}

/** Very small normalizer (so “1E klasse”, “1e-klasse”, etc. still match) */
function normalize_division_label(?string $s): string {
    $s = trim((string)$s);
    // lowercase, collapse spaces/dashes, fix common variants
    $s = preg_replace('~\s+~',' ', str_replace(['-','–','—','_'], ' ', mb_strtolower($s)));
    $s = preg_replace('~\b1e\b~','1e', $s);
    $s = preg_replace('~\b2e\b~','2e', $s);
    $s = preg_replace('~\b3e\b~','3e', $s);
    return $s;
}

/**
 * Resolve expected grade letter from a division name using the hardcoded policy.
 * - First: exact (case-insensitive) match on your keys
 * - Then: simple “contains” heuristics for common aliases
 */
function expected_grade_for_division(?string $division): ?string {
    $raw = (string)$division;
    $norm = normalize_division_label($raw);

    // exact match (case-insensitive) on provided keys
    foreach (PREFERRED_GRADE_BY_DIVISION as $k => $v) {
        if (normalize_division_label($k) === $norm) return $v;
    }

    // fallback contains-heuristics (cheap and cheerful)
    if (str_contains($norm, 'ereklasse dames')) return 'B';
    if (str_contains($norm, 'ereklasse'))       return 'A';
    if (str_contains($norm, 'future'))          return 'B';
    if (str_contains($norm, 'colts'))           return 'B';
    if (str_contains($norm, '1e'))              return 'B';
    if (str_contains($norm, '2e'))              return 'C';
    if (str_contains($norm, '3e'))              return 'D';

    return null; // unknown
}

/**
 * Preferred entry point for your scorer:
 * 1) If the match row already has m.expected_grade → trust that
 * 2) Else compute from division string via policy
 * Returns a RANK (int) to compare against referee rank.
 */
function expected_grade_rank_for_match(array $matchRow): int {
    $explicit = $matchRow['expected_grade'] ?? null;
    if ($explicit) return grade_to_rank($explicit);

    $div = $matchRow['division'] ?? null;
    $g = expected_grade_for_division($div);
    return grade_to_rank($g);
}
