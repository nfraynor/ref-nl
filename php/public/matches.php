<?php
require_once __DIR__ . '/../utils/db.php';
include 'includes/header.php';
include 'includes/nav.php';
include 'components/referee_dropdown.php';

$assignMode = isset($_GET['assign_mode']);
$pdo = Database::getConnection();

$referees = $pdo->query("
    SELECT 
        uuid, 
        first_name, 
        last_name, 
        grade
    FROM referees 
    ORDER BY first_name
")->fetchAll();

// Fetch matches
$matches = $pdo->query("
    SELECT 
        m.*,
        hc.club_name AS home_club_name,
        ht.team_name AS home_team_name,
        ac.club_name AS away_club_name,
        at.team_name AS away_team_name
    FROM matches m
    JOIN teams ht ON m.home_team_id = ht.uuid
    JOIN clubs hc ON ht.club_id = hc.uuid
    JOIN teams at ON m.away_team_id = at.uuid
    JOIN clubs ac ON at.club_id = ac.uuid
    ORDER BY m.match_date ASC, m.kickoff_time ASC
")->fetchAll();

// Conflict checker
function checkConflict($matches, $refId, $thisMatchId, $matchDate, $kickoffTime) {
    $conflictType = null;

    $currentDate = new DateTime($matchDate);
    $currentStart = strtotime("1970-01-01T" . $kickoffTime);
    $currentEnd = $currentStart + (90 * 60); // 90 min

    foreach ($matches as $match) {
        $sameMatch = $match['uuid'] === $thisMatchId;

        foreach (['referee_id', 'ar1_id', 'ar2_id', 'commissioner_id'] as $role) {
            if ($match[$role] == $refId) {

                // 🟥 Same match: double role
                if ($sameMatch) {
                    $refCount = 0;
                    foreach (['referee_id', 'ar1_id', 'ar2_id', 'commissioner_id'] as $checkRole) {
                        if ($match[$checkRole] === $refId) $refCount++;
                    }
                    if ($refCount > 1) return 'red';
                    continue;
                }

                $otherDate = new DateTime($match['match_date']);
                $intervalDays = (int)$currentDate->diff($otherDate)->format('%r%a');

                // 🟥 Same day: check overlap
                if ($intervalDays === 0) {
                    $otherStart = strtotime("1970-01-01T" . $match['kickoff_time']);
                    $otherEnd = $otherStart + (90 * 60);

                    if ($currentStart < $otherEnd && $otherStart < $currentEnd) {
                        return 'red';
                    } else {
                        $conflictType = $conflictType !== 'red' ? 'orange' : 'red';
                    }
                }

                // 🟡 Within 2 days before
                elseif ($intervalDays > 0 && $intervalDays <= 2) {
                    if ($conflictType !== 'red' && $conflictType !== 'orange') {
                        $conflictType = 'yellow';
                    }
                }
            }
        }
    }

    return $conflictType;
}



function getRefName($referees, $uuid) {
    foreach ($referees as $ref) {
        if ($ref['uuid'] === $uuid) return $ref['first_name'] . ' ' . $ref['last_name'];
    }
    return "Unknown";
}
?>

<h1>Matches</h1>
<script src="/js/referee_dropdown.js"></script>
<?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success">Assignments saved successfully.</div>
<?php endif; ?>

<?php if ($assignMode): ?>
    <a href="matches.php" class="btn btn-sm btn-secondary mb-3">Disable Assign Mode</a>
    <button type="button" id="suggestAssignments" class="btn btn-sm btn-info mb-3">Suggest Assignments</button>
    <button type="button" id="clearAssignments" class="btn btn-sm btn-danger mb-3">Clear Assignments</button>
<?php else: ?>
    <a href="matches.php?assign_mode=1" class="btn btn-sm btn-warning mb-3">Enable Assign Mode</a>
<?php endif; ?>
<script>
    document.getElementById('clearAssignments')?.addEventListener('click', () => {
        document.querySelectorAll('select').forEach(select => {
            select.value = "";
            // Trigger change so conflict coloring resets
            const event = new Event('change', { bubbles: true });
            select.dispatchEvent(event);
        });
    });
</script>

<form method="POST" action="bulk_assign.php">
    <?php if ($assignMode): ?>
        <button type="submit" class="btn btn-success">Save Assignments</button>
    <?php endif; ?>

    <table class="table table-bordered">
        <thead>
        <tr>
            <th>Date</th>
            <th>Kickoff</th>
            <th>Home Team</th>
            <th>Away Team</th>
            <th>Division</th>
            <th>Referee</th>
            <th>AR1</th>
            <th>AR2</th>
            <th>Commissioner</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($matches as $match): ?>
            <tr>
                <td><?= htmlspecialchars($match['match_date']) ?></td>
                <td><?= htmlspecialchars(substr($match['kickoff_time'], 0, 5)) ?></td>
                <td><?= htmlspecialchars($match['home_club_name'] . " - " . $match['home_team_name']) ?></td>
                <td><?= htmlspecialchars($match['away_club_name'] . " - " . $match['away_team_name']) ?></td>
                <td><?= htmlspecialchars($match['division']) ?></td>
                <td><?php renderRefereeDropdown("referee_id", $match, $referees, $assignMode, $matches); ?></td>
                <td><?php renderRefereeDropdown("ar1_id", $match, $referees, $assignMode, $matches); ?></td>
                <td><?php renderRefereeDropdown("ar2_id", $match, $referees, $assignMode, $matches); ?></td>
                <td><?php renderRefereeDropdown("commissioner_id", $match, $referees, $assignMode, $matches); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($assignMode): ?>
        <button type="submit" class="btn btn-success" style="position: fixed; bottom: 20px; right: 20px; z-index: 999;">Save Assignments</button>
    <?php endif; ?>
</form>

<script>
    const existingAssignments = <?= json_encode($matches); ?>;
</script>
<script>
    document.getElementById('suggestAssignments')?.addEventListener('click', () => {
        fetch('suggest_assignments.php')
            .then(response => response.json())
            .then(data => {
                for (const matchId in data) {
                    const matchSuggestions = data[matchId];

                    for (const role in matchSuggestions) {
                        const refId = matchSuggestions[role];
                        const select = document.querySelector(`select[name="assignments[${matchId}][${role}]"]`);

                        if (select) {
                            select.value = refId ?? "";
                            // Trigger change event to refresh conflict colors
                            const event = new Event('change', { bubbles: true });
                            select.dispatchEvent(event);
                        }
                    }
                }
            });
    });
</script>

<script src="/js/match_conflicts.js"></script>
<?php include 'includes/footer.php'; ?>
