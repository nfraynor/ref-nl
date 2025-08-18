<?php

// Helper: safe venue comparison using address strings
if (!function_exists('normalizeVenue')) {
    function normalizeVenue(?string $address): string {
        $address = (string)$address;
        $address = preg_replace('/\s+/', ' ', trim($address));
        return mb_strtolower($address);
    }
}
if (!function_exists('sameVenue')) {
    function sameVenue(?string $a, ?string $b): bool {
        if ($a === null || $a === '' || $b === null || $b === '') return false;
        return normalizeVenue($a) === normalizeVenue($b);
    }
}

if (!function_exists('normalize_address')) {
    function normalize_address(?string $s): ?string {
        if ($s === null) return null;
        $s = mb_strtolower(trim($s));
        // strip punctuation that often varies
        $s = preg_replace('/[.,;:()\-]/u', ' ', $s);
        // collapse whitespace
        $s = preg_replace('/\s+/u', ' ', $s);
        return $s;
    }
}

if (!function_exists('isRefereeAvailable_Cached')) {
    function isRefereeAvailable_Cached($refId, $matchDateStr, $kickoffTimeStr, $cache) {
        if (!isset($cache[$refId])) return true; // Default to available if no data for this ref

        // Check hard-blocked dates (guard if key missing)
        if (!empty($cache[$refId]['unavailability'])) {
            foreach ($cache[$refId]['unavailability'] as $block) {
                if ($matchDateStr >= $block['start_date'] && $matchDateStr <= $block['end_date']) {
                    return false;
                }
            }
        }

        // Check weekly availability
        $weekday = date('w', strtotime($matchDateStr));
        $hour = (int)date('H', strtotime("1970-01-01T" . $kickoffTimeStr)); // Base on 1970 for hour extraction

        $slot = '';
        if ($hour < 12) $slot = 'morning_available';
        elseif ($hour < 17) $slot = 'afternoon_available';
        else $slot = 'evening_available';

        if (isset($cache[$refId]['weekly'][$weekday])) {
            return (bool)$cache[$refId]['weekly'][$weekday][$slot];
        }
        return true; // Default if no specific weekly rule for that day
    }
}

if (!function_exists('get_assignment_details_for_referee')) {
    /**
     * $currentMatchContext expects:
     *  - uuid
     *  - match_date
     *  - kickoff_time
     *  - location_address    <-- replaced location_uuid
     *  - assigned_roles      (array of role => referee_uuid)
     *  - current_role_being_rendered
     *
     * $refereeSchedule items (precomputed) may include:
     *  - match_id
     *  - match_date_str
     *  - kickoff_time_str
     *  - role
     *  - location_address    <-- replaced location_uuid
     */
    if (!function_exists('get_assignment_details_for_referee')) {
        function get_assignment_details_for_referee(
            $refereeIdToCheck,
            $currentMatchContext,      // has 'location_address'
            $refereeSchedule,
            $refereeAvailabilityCache
        ) {
            // availability
            if (!isRefereeAvailable_Cached(
                $refereeIdToCheck,
                $currentMatchContext['match_date'],
                $currentMatchContext['kickoff_time'],
                $refereeAvailabilityCache
            )) {
                return ['conflict_type' => null, 'is_available' => false];
            }

            // already assigned to another role in THIS match
            foreach ($currentMatchContext['assigned_roles'] as $role => $assignedRefId) {
                if ($role !== $currentMatchContext['current_role_being_rendered'] &&
                    $assignedRefId === $refereeIdToCheck) {
                    return ['conflict_type' => 'red', 'is_available' => true];
                }
            }

            $conflictLevel = null;
            $curDateObj = new DateTime($currentMatchContext['match_date']);
            $curStartTS = strtotime("1970-01-01T" . $currentMatchContext['kickoff_time']);
            $curEndTS   = $curStartTS + 90*60;

            $curAddrN = normalize_address($currentMatchContext['location_address'] ?? null);

            if (isset($refereeSchedule[$refereeIdToCheck])) {
                foreach ($refereeSchedule[$refereeIdToCheck] as $s) {
                    // same match + same role? skip
                    if ($s['match_id'] === $currentMatchContext['uuid'] &&
                        $s['role']     === $currentMatchContext['current_role_being_rendered']) {
                        continue;
                    }
                    // same match but different role → red
                    if ($s['match_id'] === $currentMatchContext['uuid']) {
                        return ['conflict_type' => 'red', 'is_available' => true];
                    }

                    $sDateObj = new DateTime($s['match_date_str']);
                    $daysBetween = (int)$curDateObj->diff($sDateObj)->format('%r%a');

                    if ($daysBetween === 0) {
                        // time overlap → red
                        $sStartTS = strtotime("1970-01-01T" . $s['kickoff_time_str']);
                        $sEndTS   = $sStartTS + 90*60;
                        if ($curStartTS < $sEndTS && $sStartTS < $curEndTS) {
                            return ['conflict_type' => 'red', 'is_available' => true];
                        }

                        // same day, different venues? compare address only
                        $sAddrN = normalize_address($s['location_address'] ?? null);
                        if ($curAddrN !== null && $sAddrN !== null) {
                            if ($curAddrN !== $sAddrN) {
                                return ['conflict_type' => 'red', 'is_available' => true];
                            } else {
                                // same venue, same day, no overlap → orange
                                if ($conflictLevel !== 'red') $conflictLevel = 'orange';
                            }
                        } else {
                            // venue unknown for one side → caution (orange) but not red
                            if ($conflictLevel === null) $conflictLevel = 'orange';
                        }
                    } elseif (abs($daysBetween) <= 2) {
                        if ($conflictLevel === null) $conflictLevel = 'yellow';
                    }
                }
            }

            return ['conflict_type' => $conflictLevel, 'is_available' => true];
        }
    }

}

// Helper to get referee name, assuming $all_referees_list is available
if (!function_exists('get_ref_name_from_list')) {
    function get_ref_name_from_list($referees_list, $uuid) {
        foreach ($referees_list as $ref) {
            if ($ref['uuid'] === $uuid) return $ref['first_name'] . ' ' . $ref['last_name'];
        }
        return "Unknown";
    }
}

function renderRefereeDropdown(
    $role_being_rendered,
    $match_details_for_dropdown,
    $all_referees_list,
    $assignMode,
    $refereeSchedule_precomputed,
    $refereeAvailabilityCache_precomputed
) {
    $currently_assigned_ref_id_for_this_role = $match_details_for_dropdown[$role_being_rendered] ?? null;

    // Current match DB state (before this selection)
    $current_match_existing_assignments = [
        'referee_id'      => $match_details_for_dropdown['referee_id'] ?? null,
        'ar1_id'          => $match_details_for_dropdown['ar1_id'] ?? null,
        'ar2_id'          => $match_details_for_dropdown['ar2_id'] ?? null,
        'commissioner_id' => $match_details_for_dropdown['commissioner_id'] ?? null
    ];

    $details_for_conflict_check = [
        'uuid'           => $match_details_for_dropdown['uuid'],
        'match_date'     => $match_details_for_dropdown['match_date'],
        'kickoff_time'   => $match_details_for_dropdown['kickoff_time'],
        'location_address'=> $match_details_for_dropdown['location_address'] ?? null, // << only address
        'assigned_roles' => $current_match_existing_assignments,
        'current_role_being_rendered' => $role_being_rendered
    ];


    $overall_style = '';

    if ($currently_assigned_ref_id_for_this_role) {
        $assignmentInfo = get_assignment_details_for_referee(
            $currently_assigned_ref_id_for_this_role,
            $details_for_conflict_check,
            $refereeSchedule_precomputed,
            $refereeAvailabilityCache_precomputed
        );

        if (!$assignmentInfo['is_available']) {
            $overall_style = 'background-color: lightgrey; color: #333; text-decoration: line-through;';
        } elseif ($assignmentInfo['conflict_type'] === 'red') {
            $overall_style = 'background-color: red; color: white;';
        } elseif ($assignmentInfo['conflict_type'] === 'orange') {
            $overall_style = 'background-color: orange; color: black;';
        } elseif ($assignmentInfo['conflict_type'] === 'yellow') {
            $overall_style = 'background-color: yellow; color: black;';
        }
    }

    if ($assignMode) {
        $selectId = 'referee_' . $match_details_for_dropdown['uuid'] . '_' . $role_being_rendered;

        echo '<select id="' . $selectId . '"
                class="referee-select form-control"
                name="assignments[' . $match_details_for_dropdown['uuid'] . '][' . $role_being_rendered . ']"
                data-match-id="' . htmlspecialchars($match_details_for_dropdown['uuid']) . '"
                data-match-date="' . htmlspecialchars($match_details_for_dropdown['match_date']) . '"
                data-kickoff="' . htmlspecialchars($match_details_for_dropdown['kickoff_time']) . '"
                data-location-address="' . htmlspecialchars($match_details_for_dropdown['location_address'] ?? '') . '"
                data-role="' . htmlspecialchars($role_being_rendered) . '">';
        echo '<option value="">-- Select Referee --</option>';

        foreach ($all_referees_list as $ref_option) {
            $selected_attr = ($ref_option['uuid'] === $currently_assigned_ref_id_for_this_role) ? 'selected' : '';

            $optionInfo = get_assignment_details_for_referee(
                $ref_option['uuid'],
                $details_for_conflict_check,
                $refereeSchedule_precomputed,
                $refereeAvailabilityCache_precomputed
            );

            $availability_data_attr = $optionInfo['is_available'] ? 'available' : 'unavailable';
            $conflict_data_attr     = $optionInfo['conflict_type'] ?? 'none';

            $option_style_parts = [];
            if (!$optionInfo['is_available']) {
                $option_style_parts[] = 'background-color: lightgrey';
                $option_style_parts[] = 'color: #6c757d';
            } else {
                if ($optionInfo['conflict_type'] === 'red') {
                    $option_style_parts[] = 'background-color: red';
                    $option_style_parts[] = 'color: white';
                } elseif ($optionInfo['conflict_type'] === 'orange') {
                    $option_style_parts[] = 'background-color: orange';
                    $option_style_parts[] = 'color: black';
                } elseif ($optionInfo['conflict_type'] === 'yellow') {
                    $option_style_parts[] = 'background-color: yellow';
                    $option_style_parts[] = 'color: black';
                }
            }
            $option_style_str = implode('; ', $option_style_parts);
            if ($option_style_str) $option_style_str .= ';';

            echo '<option value="' . htmlspecialchars($ref_option['uuid']) . '" ' . $selected_attr . '
                  style="' . $option_style_str . '"
                  data-grade="' . htmlspecialchars($ref_option['grade']) . '"
                  data-availability="' . $availability_data_attr . '"
                  data-conflict="' . $conflict_data_attr . '">'
                . htmlspecialchars($ref_option['first_name'] . ' ' . $ref_option['last_name'] . ' (' . $ref_option['grade'] . ')') .
                '</option>';
        }
        echo '</select>';
    } else {
        $displayText = '-- Not Assigned --';
        $grade = '';

        if ($currently_assigned_ref_id_for_this_role) {
            $assigned_ref = null;
            foreach ($all_referees_list as $ref) {
                if ($ref['uuid'] === $currently_assigned_ref_id_for_this_role) {
                    $assigned_ref = $ref; break;
                }
            }
            if ($assigned_ref) {
                $displayText = $assigned_ref['first_name'] . ' ' . $assigned_ref['last_name'];
                $grade = $assigned_ref['grade'];
            } else {
                $displayText = "Unknown Referee";
            }
        }

        $final_style = 'padding: .375rem .75rem; border: 1px solid #ced4da; border-radius: .25rem;';
        if (!empty($overall_style)) {
            // conflict color should win
            $final_style .= ' ' . $overall_style;
        } else {
            // neutral display
            $final_style .= ' background-color: #e9ecef;';
        }

        echo '<div class="form-control" style="' . $final_style . '">';
        echo htmlspecialchars($displayText);
        if ($grade) {
            echo ' <span class="text-muted" style="font-size: 0.9em;">(' . htmlspecialchars($grade) . ')</span>';
        }
        echo '</div>';
    }
}
?>
