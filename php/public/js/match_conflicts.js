document.addEventListener('DOMContentLoaded', () => {

    const existingAssignments = window.existingAssignments || [];

    function getWeekNumber(dateString) {
        const date = new Date(dateString);
        const oneJan = new Date(date.getFullYear(),0,1);
        const numberOfDays = Math.floor((date - oneJan) / (24 * 60 * 60 * 1000));
        return Math.ceil((date.getDay() + 1 + numberOfDays) / 7);
    }

    function getLiveAssignments() {
        const liveAssignments = [];

        document.querySelectorAll('select').forEach(select => {
            const matchId = select.name.match(/\[(.*?)\]/)[1];
            const role = select.name.match(/\[(.*?)\]\[(.*?)\]/)[2];
            const selectedRef = select.value;

            const matchRow = select.closest('tr');
            const matchDate = matchRow.querySelector('td:nth-child(1)').innerText;
            const kickoffTime = matchRow.querySelector('td:nth-child(2)').innerText;

            if (!selectedRef) return;

            liveAssignments.push({
                matchId,
                role,
                refereeId: selectedRef,
                matchDate,
                kickoffTime
            });
        });

        return liveAssignments;
    }

    function checkConflict(matchId, matchDate, kickoffTime, refereeId, allAssignments) {
        let conflict = null;
        const currentStart = new Date(`1970-01-01T${kickoffTime}`);
        const currentEnd = new Date(currentStart.getTime() + 90 * 60000);

        const currentWeek = getWeekNumber(matchDate);

        allAssignments.forEach(assign => {
            if (assign.matchId === matchId) return;

            const assignWeek = getWeekNumber(assign.matchDate);

            if (assign.refereeId === refereeId) {

                if (assign.matchDate === matchDate) {
                    // Same day → overlap or not
                    const otherStart = new Date(`1970-01-01T${assign.kickoffTime}`);
                    const otherEnd = new Date(otherStart.getTime() + 90 * 60000);

                    if (currentStart < otherEnd && otherStart < currentEnd) {
                        conflict = 'red';
                    } else if (conflict !== 'red') {
                        conflict = 'orange';
                    }

                } else if (assignWeek === currentWeek) {
                    // Same weekend but not same day
                    if (conflict !== 'red' && conflict !== 'orange') {
                        conflict = 'yellow';
                    }
                }
            }
        });

        return conflict;
    }

    function refreshConflicts() {
        const liveAssignments = getLiveAssignments();

        document.querySelectorAll('select').forEach(select => {
            const matchId = select.name.match(/\[(.*?)\]/)[1];
            const role = select.name.match(/\[(.*?)\]\[(.*?)\]/)[2];
            const selectedRef = select.value;

            const matchRow = select.closest('tr');
            const matchDate = matchRow.querySelector('td:nth-child(1)').innerText;
            const kickoffTime = matchRow.querySelector('td:nth-child(2)').innerText;

            let conflict = null;

            if (selectedRef) {
                // Check saved
                conflict = checkConflict(matchId, matchDate, kickoffTime, selectedRef, existingAssignments);

                // Check live
                const liveConflict = checkConflict(matchId, matchDate, kickoffTime, selectedRef, liveAssignments);
                if (liveConflict === 'red' || (liveConflict === 'orange' && conflict !== 'red') || (liveConflict === 'yellow' && !conflict)) {
                    conflict = liveConflict;
                }

                // Check SAME MATCH → duplicate referee for different roles
                const sameMatchRoles = liveAssignments.filter(a => a.matchId === matchId);
                const refsInThisMatch = sameMatchRoles.map(a => a.refereeId);
                const duplicates = refsInThisMatch.filter(ref => ref === selectedRef);

                if (duplicates.length > 1) {
                    conflict = 'red';
                }
            }

            select.style.backgroundColor = '';
            select.style.color = '';

            if (conflict === 'yellow') {
                select.style.backgroundColor = 'yellow';
                select.style.color = 'black';
            }
            if (conflict === 'orange') {
                select.style.backgroundColor = 'orange';
                select.style.color = 'black';
            }
            if (conflict === 'red') {
                select.style.backgroundColor = 'red';
                select.style.color = 'white';
            }
        });
    }

    // Setup event listeners
    document.querySelectorAll('select').forEach(select => {
        select.addEventListener('change', refreshConflicts);
    });

    // Initial run
    refreshConflicts();
});
