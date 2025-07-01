<?php

function isRefereeAvailable($refId, $matchDate, $kickoffTime, $pdo) {
    // 1. Check for hard-blocked dates
    $stmt = $pdo->prepare("
        SELECT 1 FROM referee_unavailability
        WHERE referee_id = :refId AND :matchDate BETWEEN start_date AND end_date
        LIMIT 1
    ");
    $stmt->execute(['refId' => $refId, 'matchDate' => $matchDate]);
    if ($stmt->fetch()) return false;

    // 2. Check weekly availability
    $weekday = date('w', strtotime($matchDate)); // Sunday = 0 ... Saturday = 6
    $time = strtotime($kickoffTime);
    $hour = (int)date('H', $time);

    // Determine slot
    if ($hour < 12) $slot = 'morning_available';
    elseif ($hour < 17) $slot = 'afternoon_available';
    else $slot = 'evening_available';

    $stmt = $pdo->prepare("
        SELECT $slot FROM referee_weekly_availability
        WHERE referee_id = :refId AND weekday = :weekday
        LIMIT 1
    ");
    $stmt->execute(['refId' => $refId, 'weekday' => $weekday]);

    $row = $stmt->fetch();
    return $row && $row[$slot]; // true if set
}

function renderRefereeDropdown($role, $match, $referees, $assignMode, $matches) {
    global $pdo;
    $refId = $match[$role] ?? null;

    $conflict = null;
    if ($refId) {
        $conflict = checkConflict($matches, $refId, $match['uuid'], $match['match_date'], $match['kickoff_time']);
    }

    $colorStyle = '';
    if ($conflict === 'orange') $colorStyle = 'background-color: orange; color: black;';
    if ($conflict === 'red') $colorStyle = 'background-color: red; color: white;';

    if ($assignMode) {
        // Generate a unique ID for each dropdown
        $selectId = 'referee_' . $match['uuid'] . '_' . $role;

        echo '<select id="' . $selectId . '" 
                    class="referee-select form-control" 
                    name="assignments[' . $match['uuid'] . '][' . $role . ']" 
                    style="' . $colorStyle . '">';
        echo '<option value="">-- Select Referee --</option>';

        foreach ($referees as $ref) {
            $selected = ($ref['uuid'] === $refId) ? 'selected' : '';
            $isAvailable = isRefereeAvailable($ref['uuid'], $match['match_date'], $match['kickoff_time'], $pdo);
            $availability = $isAvailable ? 'available' : 'unavailable';

            echo '<option value="' . $ref['uuid'] . '" ' . $selected . ' 
                  data-grade="' . htmlspecialchars($ref['grade']) . '" 
                  data-availability="' . $availability . '">'
                . htmlspecialchars($ref['first_name'] . ' ' . $ref['last_name']) .
                '</option>';
        }

        echo '</select>';
    } else {
        if ($refId) {
            $conflict = checkConflict($matches, $refId, $match['uuid'], $match['match_date'], $match['kickoff_time']);

            $colorStyle = '';
            if ($conflict === 'yellow') $colorStyle = 'background-color: yellow; color: black;';
            if ($conflict === 'orange') $colorStyle = 'background-color: orange; color: black;';
            if ($conflict === 'red') $colorStyle = 'background-color: red; color: white;';


            echo '<span style="' . $colorStyle . '">' . htmlspecialchars(getRefName($referees, $refId)) . '</span>';
        }
        else {
            echo '<a href="assign.php?match_id=' . $match['uuid'] . '&role=' . $role . '" class="false-a btn btn-sm btn-primary">Assign</a>';
        }
    }
}
?>
