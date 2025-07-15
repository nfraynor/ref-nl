<?php

// It's better to move these helper functions to a shared utility file (e.g., php/utils/referee_helpers.php)
// and include them in both fetch_matches.php and here. For now, duplicating for simplicity.

if (!function_exists('isRefereeAvailable_Cached')) {
    function isRefereeAvailable_Cached($refId, $matchDateStr, $kickoffTimeStr, $cache) {
        if (!isset($cache[$refId])) return true; // Default to available if no data for this ref

        // Check hard-blocked dates
        foreach ($cache[$refId]['unavailability'] as $block) {
            if ($matchDateStr >= $block['start_date'] && $matchDateStr <= $block['end_date']) {
                return false;
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
    function get_assignment_details_for_referee(
        $refereeIdToCheck,
        $currentMatchContext, // ['uuid', 'match_date', 'kickoff_time', 'location_uuid', 'assigned_roles' => [...], 'current_role_being_rendered' => 'role_name']
        $refereeSchedule,     // Precomputed schedule of other matches from DB
        $refereeAvailabilityCache // Precomputed availability from DB
    ) {
        $availability = isRefereeAvailable_Cached(
            $refereeIdToCheck,
            $currentMatchContext['match_date'],
            $currentMatchContext['kickoff_time'],
            $refereeAvailabilityCache
        );

        if (!$availability) {
            return ['conflict_type' => null, 'is_available' => false];
        }

        $conflictLevel = null;

        $currentMatchDateObj = new DateTime($currentMatchContext['match_date']);
        $currentMatchStartTimestamp = strtotime("1970-01-01T" . $currentMatchContext['kickoff_time']);
        $currentMatchEndTimestamp = $currentMatchStartTimestamp + (90 * 60); // Assuming 90 min match duration

        // Check for conflicts with other roles for $refereeIdToCheck in the *same* current match
        // $currentMatchContext['assigned_roles'] contains the state of the match *before* this specific dropdown's potential selection.
        foreach ($currentMatchContext['assigned_roles'] as $roleInSameMatch => $assignedRefIdInSameMatch) {
            if ($roleInSameMatch !== $currentMatchContext['current_role_being_rendered'] && // Must be a *different* role than the one we're rendering for
                $assignedRefIdInSameMatch === $refereeIdToCheck) { // And the referee is the one we're checking
                return ['conflict_type' => 'red', 'is_available' => true]; // Red conflict: already assigned to another role in this match
            }
        }

        // Check against other scheduled matches for this referee
        if (isset($refereeSchedule[$refereeIdToCheck])) {
            foreach ($refereeSchedule[$refereeIdToCheck] as $scheduledMatch) {
                // Skip if this scheduled item is for the exact same role in the exact same match
                // (This is relevant if $refereeIdToCheck is already assigned to $currentMatchContext['current_role_being_rendered'])
                if ($scheduledMatch['match_id'] === $currentMatchContext['uuid'] &&
                    $scheduledMatch['role'] === $currentMatchContext['current_role_being_rendered']) {
                    continue;
                }

                // If the scheduled item is for the *same match* but a *different role*
                // This indicates the referee is already assigned to another role in this match in the DB.
                if ($scheduledMatch['match_id'] === $currentMatchContext['uuid']) {
                    // (and $scheduledMatch['role'] !== $currentMatchContext['current_role_being_rendered'] is implied by above continue)
                    $conflictLevel = 'red'; // Already in another role in this same match (from DB schedule)
                    break; // Max conflict for this ref
                }

                // Different match, same referee
                $scheduledMatchDateObj = new DateTime($scheduledMatch['match_date_str']);
                $daysBetween = (int)$currentMatchDateObj->diff($scheduledMatchDateObj)->format('%r%a');

                if ($daysBetween === 0) { // Same day
                    $scheduledMatchStartTimestamp = strtotime("1970-01-01T" . $scheduledMatch['kickoff_time_str']);
                    $scheduledMatchEndTimestamp = $scheduledMatchStartTimestamp + (90 * 60);
                    if ($currentMatchStartTimestamp < $scheduledMatchEndTimestamp && $scheduledMatchStartTimestamp < $currentMatchEndTimestamp) {
                        $conflictLevel = 'red'; // Time overlap is always red
                        break;
                    } else { // Same day, no time overlap
                        if ($scheduledMatch['location_uuid'] !== $currentMatchContext['location_uuid']) {
                            $conflictLevel = 'red';
                            break;
                        } else {
                            if ($conflictLevel !== 'red') $conflictLevel = 'orange';
                        }
                    }
                } elseif (abs($daysBetween) <= 2) { // Within +/- 2 days (but not same day)
                    if ($conflictLevel !== 'red' && $conflictLevel !== 'orange') $conflictLevel = 'yellow';
                }
            }
        }
        return ['conflict_type' => $conflictLevel, 'is_available' => true];
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
    $role_being_rendered,         // e.g., "referee_id"
    $match_details_for_dropdown,  // The full $match array for the current row
    $all_referees_list,           // The full list of referee objects/arrays for options
    $assignMode,                  // Boolean
    $refereeSchedule_precomputed, // Precomputed schedule from fetch_matches.php
    $refereeAvailabilityCache_precomputed // Precomputed availability from fetch_matches.php
) {
    $currently_assigned_ref_id_for_this_role = $match_details_for_dropdown[$role_being_rendered] ?? null;

    // Prepare context for the conflict/availability checker
    // These are the roles already assigned in THIS match row from the DB ($match_details_for_dropdown)
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
        'location_uuid'  => $match_details_for_dropdown['location_uuid'],
        'assigned_roles' => $current_match_existing_assignments,
        'current_role_being_rendered' => $role_being_rendered
    ];

    $overall_style = ''; // For the select element (if assigned) or span (display mode)

    if ($currently_assigned_ref_id_for_this_role) {
        $assignmentInfo = get_assignment_details_for_referee(
            $currently_assigned_ref_id_for_this_role,
            $details_for_conflict_check,
            $refereeSchedule_precomputed,
            $refereeAvailabilityCache_precomputed
        );

        if (!$assignmentInfo['is_available']) {
            // This styling is for the select box itself or the span in display mode
            $overall_style = 'background-color: lightgrey; color: #333; text-decoration: line-through;';
        } elseif ($assignmentInfo['conflict_type'] === 'red') {
            $overall_style = 'background-color: red; color: white;';
        } elseif ($assignmentInfo['conflict_type'] === 'orange') {
            $overall_style = 'background-color: orange; color: black;';
        } elseif ($assignmentInfo['conflict_type'] === 'yellow') {
            // Yellow is often not applied to the main select/span, but to options.
            // However, if displaying an existing yellow, we might show it.
            $overall_style = 'background-color: yellow; color: black;';
        }
    }

    if ($assignMode) {
        $selectId = 'referee_' . $match_details_for_dropdown['uuid'] . '_' . $role_being_rendered;
        echo '<select id="' . $selectId . '" 
                    class="referee-select form-control" 
                    name="assignments[' . $match_details_for_dropdown['uuid'] . '][' . $role_being_rendered . ']" 
                    style="' . $overall_style . '">'; // Overall style for the select box
        echo '<option value="">-- Select Referee --</option>';

        foreach ($all_referees_list as $ref_option) {
            $selected_attr = ($ref_option['uuid'] === $currently_assigned_ref_id_for_this_role) ? 'selected' : '';

            // Get details for this specific referee option
            // The context's assigned_roles should be the DB state of the match for checking this option.
            $optionInfo = get_assignment_details_for_referee(
                $ref_option['uuid'],
                $details_for_conflict_check,
                $refereeSchedule_precomputed,
                $refereeAvailabilityCache_precomputed
            );

            $option_style_parts = [];
            $availability_data_attr = 'available';

            if (!$optionInfo['is_available']) {
                $option_style_parts[] = 'background-color: lightgrey';
                $option_style_parts[] = 'color: #6c757d'; // Bootstrap's muted text color
                // $option_style_parts[] = 'text-decoration: line-through'; // Optional for options
                $availability_data_attr = 'unavailable';
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
                  data-availability="' . $availability_data_attr . '">'
                . htmlspecialchars($ref_option['first_name'] . ' ' . $ref_option['last_name'] . ' (Grade: ' . $ref_option['grade'] . ')') .
                '</option>';
        }
        echo '</select>';
    } else { // Display mode
        echo '<select class="referee-select form-control" disabled style="' . $overall_style . '">';
        if ($currently_assigned_ref_id_for_this_role) {
            $refName = get_ref_name_from_list($all_referees_list, $currently_assigned_ref_id_for_this_role);
            echo '<option selected>' . htmlspecialchars($refName . ' (Grade: ' . $ref_option['grade'] . ')') . '</option>';
        } else {
            echo '<option>-- Not Assigned --</option>';
        }
        echo '</select>';
    }
}
?>
