<?php
function renderRefereeDropdown($role, $match, $referees, $assignMode, $refereeSchedule = [], $refereeAvailabilityCache = []) {
    if (!$assignMode) {
        $refereeId = $match[$role];
        if ($refereeId) {
            foreach ($referees as $referee) {
                if ($referee['uuid'] === $refereeId) {
                    echo htmlspecialchars($referee['first_name'] . ' ' . $referee['last_name']);
                    return;
                }
            }
        }
        echo 'N/A';
        return;
    }

    echo '<select name="assignments[' . htmlspecialchars($match['uuid']) . '][' . htmlspecialchars($role) . ']" class="referee-select" data-match-id="' . htmlspecialchars($match['uuid']) . '" data-role="' . htmlspecialchars($role) . '">';
    echo '<option value="">-- Select Referee --</option>';
    foreach ($referees as $referee) {
        $selected = ($match[$role] === $referee['uuid']) ? ' selected' : '';
        echo '<option value="' . htmlspecialchars($referee['uuid']) . '"' . $selected . '>' . htmlspecialchars($referee['first_name'] . ' ' . $referee['last_name'] . ' (' . $referee['grade'] . ')') . '</option>';
    }
    echo '</select>';
}
?>
