<?php
function renderRefereeDropdown($role, $match, $referees, $assignMode, $refereeSchedule, $refereeAvailabilityCache) {
    $refereeId = $match[$role] ?? null;
    $refereeName = 'N/A';
    $conflictClass = '';
    $conflictReason = '';

    // Find referee name and compute conflicts
    if ($refereeId) {
        foreach ($referees as $referee) {
            if ($referee['uuid'] === $refereeId) {
                $mainGrade = $referee['grade'] ?? 'N/A';
                $arGrade = $referee['ar_grade'] ?? 'N/A';
                $refereeName = htmlspecialchars($referee['first_name'] . ' ' . $referee['last_name'] . " ($mainGrade/$arGrade)");
                break; // Stop loop once found
            }
        }

        // Compute conflict (simplified; actual logic in match_conflicts.js)
        if (isset($refereeSchedule[$refereeId])) {
            $matchDate = $match['match_date'];
            $kickoffTime = $match['kickoff_time'];
            $matchId = $match['uuid'];

            foreach ($refereeSchedule[$refereeId] as $assignment) {
                if ($assignment['match_id'] === $matchId) continue;

                $otherMatchDate = $assignment['match_date_str'];
                $otherKickoffTime = $assignment['kickoff_time_str'];
                $timeDiff = (strtotime($otherMatchDate) - strtotime($matchDate)) / (60 * 60 * 24);

                if ($timeDiff === 0) {
                    $currentStart = strtotime($kickoffTime);
                    $currentEnd = $currentStart + (90 * 60); // 90 minutes
                    $otherStart = strtotime($otherKickoffTime);
                    $otherEnd = $otherStart + (90 * 60);

                    if ($currentStart < $otherEnd && $otherStart < $currentEnd) {
                        $conflictClass = 'conflict-red';
                        $conflictReason = 'Overlapping match on ' . $otherMatchDate . ' at ' . $otherKickoffTime;
                        break;
                    } elseif (!$conflictClass) {
                        $conflictClass = 'conflict-orange';
                        $conflictReason = 'Same-day match on ' . $otherMatchDate . ' at ' . $otherKickoffTime;
                    }
                } elseif (abs($timeDiff) <= 2 && !$conflictClass) {
                    $conflictClass = 'conflict-yellow';
                    $conflictReason = 'Match within 2 days on ' . $otherMatchDate . ' at ' . $otherKickoffTime;
                }
            }

            // Check weekend limits
            global $refereePreferences;
            if (isset($refereePreferences[$refereeId]) && (new DateTime($matchDate))->format('w') % 6 === 0) { // Saturday or Sunday
                $weekNumber = (new DateTime($matchDate))->format('o-\WW');
                $weekendAssignments = array_filter($refereeSchedule[$refereeId], function($a) use ($weekNumber) {
                    return (new DateTime($a['match_date_str']))->format('o-\WW') === $weekNumber &&
                        (new DateTime($a['match_date_str']))->format('w') % 6 === 0;
                });

                if (count($weekendAssignments) >= 3) {
                    $conflictClass = 'conflict-yellow';
                    $conflictReason = 'Exceeds 3 matches per weekend';
                } elseif ($refereePreferences[$refereeId]['max_matches_per_weekend'] === 1 && count($weekendAssignments) >= 1) {
                    $conflictClass = 'conflict-yellow';
                    $conflictReason = 'Exceeds max 1 match per weekend';
                } elseif ($refereePreferences[$refereeId]['max_days_per_weekend'] === 1) {
                    $uniqueDays = array_unique(array_map(function($a) { return $a['match_date_str']; }, $weekendAssignments));
                    if (count($uniqueDays) >= 1 && !in_array($matchDate, $uniqueDays)) {
                        $conflictClass = 'conflict-yellow';
                        $conflictReason = 'Exceeds max 1 day per weekend';
                    }
                }
            }
        }
    }

    if ($assignMode) {
        ?>
        <select name="assignments[<?= htmlspecialchars($match['uuid']) ?>][<?= htmlspecialchars($role) ?>]" class="referee-select form-select form-select-sm">
            <option value="">-- Select Referee --</option>
            <?php if (empty($referees)): ?>
                <option value="" disabled>No referees available</option>
            <?php else: ?>
                <?php foreach ($referees as $referee): ?>
                    <?php
                    // Determine availability based on refereeAvailabilityCache
                    $availability = 'available';
                    if (isset($refereeAvailabilityCache[$referee['uuid']])) {
                        $matchDate = new DateTime($match['match_date']);
                        $matchTime = new DateTime($match['kickoff_time']);
                        $weekday = $matchDate->format('w'); // 0 (Sunday) to 6 (Saturday)
                        $hour = (int)$matchTime->format('H');

                        // Check unavailability periods
                        foreach ($refereeAvailabilityCache[$referee['uuid']]['unavailability'] as $unavail) {
                            $startDate = new DateTime($unavail['start_date']);
                            $endDate = new DateTime($unavail['end_date']);
                            if ($matchDate >= $startDate && $matchDate <= $endDate) {
                                $availability = 'unavailable';
                                break;
                            }
                        }

                        // Check weekly availability
                        if ($availability === 'available' && isset($refereeAvailabilityCache[$referee['uuid']]['weekly'][$weekday])) {
                            $weekly = $refereeAvailabilityCache[$referee['uuid']]['weekly'][$weekday];
                            $isMorning = $hour < 12;
                            $isAfternoon = $hour >= 12 && $hour < 17;
                            $isEvening = $hour >= 17;

                            if (($isMorning && !$weekly['morning_available']) ||
                                ($isAfternoon && !$weekly['afternoon_available']) ||
                                ($isEvening && !$weekly['evening_available'])) {
                                $availability = 'unavailable';
                            }
                        }
                    }
                    ?>
                    <option value="<?= htmlspecialchars($referee['uuid']) ?>"
                        <?= $referee['uuid'] === $refereeId ? 'selected' : '' ?>
                            data-grade="<?= htmlspecialchars($referee['grade'] ?? 'N/A') ?>"
                            data-availability="<?= $availability ?>">
                        <?= htmlspecialchars($referee['first_name'] . ' ' . $referee['last_name'] . ' (' . ($referee['grade'] ?? 'N/A') . '/' . ($referee['ar_grade'] ?? 'N/A') . ')') ?>
                    </option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
        <?php
    } else {
        ?>
        <span class="referee-display <?= $conflictClass ?>"
              data-referee-id="<?= htmlspecialchars($refereeId ?? '') ?>"
              data-match-id="<?= htmlspecialchars($match['uuid']) ?>"
              data-toggle="tooltip"
              title="<?= htmlspecialchars($conflictReason ?: 'No conflicts') ?>">
            <?= $refereeName ?>
        </span>
        <?php
    }
}
?>