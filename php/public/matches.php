<?php
require_once __DIR__ . '/../utils/db.php';
include 'includes/header.php';
include 'includes/nav.php';

$assignMode = isset($_GET['assign_mode']);
$pdo = Database::getConnection();

$referees = $pdo->query("SELECT uuid, first_name, last_name FROM referees ORDER BY first_name")->fetchAll();

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
    $currentStart = strtotime($kickoffTime);
    $currentEnd = $currentStart + (90 * 60);

    foreach ($matches as $match) {
        if ($match['uuid'] == $thisMatchId || $match['match_date'] != $matchDate) continue;

        foreach (['referee_id', 'ar1_id', 'ar2_id', 'commissioner_id'] as $role) {
            if ($match[$role] == $refId) {
                $otherStart = strtotime($match['kickoff_time']);
                $otherEnd = $otherStart + (90 * 60);

                if ($currentStart < $otherEnd && $otherStart < $currentEnd) {
                    return 'red';
                } else {
                    $conflictType = 'orange';
                }
            }
        }
    }

    return $conflictType;
}

function renderRole($role, $match, $referees, $assignMode, $matches) {
    $refId = $match[$role] ?? null;

    $conflict = null;
    if ($refId) {
        $conflict = checkConflict($matches, $refId, $match['uuid'], $match['match_date'], $match['kickoff_time']);
    }

    $colorStyle = '';
    if ($conflict === 'orange') $colorStyle = 'background-color: orange; color: black;';
    if ($conflict === 'red') $colorStyle = 'background-color: red; color: white;';

    if ($assignMode) {
        echo '<select name="assignments[' . $match['uuid'] . '][' . $role . ']" style="' . $colorStyle . '">';
        echo '<option value="">-- Select Referee --</option>';
        foreach ($referees as $ref) {
            $selected = ($ref['uuid'] === $refId) ? 'selected' : '';
            echo '<option value="' . $ref['uuid'] . '" ' . $selected . '>' . htmlspecialchars($ref['first_name'] . ' ' . $ref['last_name']) . '</option>';
        }
        echo '</select>';
    } else {
        if ($refId) {
            echo '<span style="' . $colorStyle . '">' . htmlspecialchars(getRefName($referees, $refId)) . '</span>';
        } else {
            echo '<a href="assign.php?match_id=' . $match['uuid'] . '&role=' . $role . '" class="btn btn-sm btn-primary">Assign</a>';
        }
    }
}

function getRefName($referees, $uuid) {
    foreach ($referees as $ref) {
        if ($ref['uuid'] === $uuid) return $ref['first_name'] . ' ' . $ref['last_name'];
    }
    return "Unknown";
}
?>

<h1>Matches</h1>

<?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success">Assignments saved successfully.</div>
<?php endif; ?>

<?php if ($assignMode): ?>
    <a href="matches.php" class="btn btn-sm btn-secondary mb-3">Disable Assign Mode</a>
    <button type="button" id="suggestAssignments" class="btn btn-sm btn-info mb-3">Suggest Assignments</button>
<?php else: ?>
    <a href="matches.php?assign_mode=1" class="btn btn-sm btn-warning mb-3">Enable Assign Mode</a>
<?php endif; ?>



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
                <td><?php renderRole("referee_id", $match, $referees, $assignMode, $matches); ?></td>
                <td><?php renderRole("ar1_id", $match, $referees, $assignMode, $matches); ?></td>
                <td><?php renderRole("ar2_id", $match, $referees, $assignMode, $matches); ?></td>
                <td><?php renderRole("commissioner_id", $match, $referees, $assignMode, $matches); ?></td>
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
