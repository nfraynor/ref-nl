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

    function refreshConflicts(changedSelect) {
        const selectedRef = changedSelect.value;
        if (!selectedRef) return;

        const liveAssignments = getLiveAssignments();

        // Find all selects with the SAME referee value
        const matchingSelects = Array.from(document.querySelectorAll('select.referee-select'))
            .filter(select => select.value === selectedRef);

        matchingSelects.forEach(select => {
            const matchId = select.name.match(/\[(.*?)\]/)[1];
            const role = select.name.match(/\[(.*?)\]\[(.*?)\]/)[2];

            const matchRow = select.closest('tr');
            const matchDate = matchRow.querySelector('td:nth-child(1)').innerText;
            const kickoffTime = matchRow.querySelector('td:nth-child(2)').innerText;

            let conflict = null;

            // Check saved assignments
            conflict = checkConflict(matchId, matchDate, kickoffTime, selectedRef, existingAssignments);

            // Check live assignments
            const liveConflict = checkConflict(matchId, matchDate, kickoffTime, selectedRef, liveAssignments);
            if (liveConflict === 'red' || (liveConflict === 'orange' && conflict !== 'red') || (liveConflict === 'yellow' && !conflict)) {
                conflict = liveConflict;
            }

            // Check for duplicate roles in the same match
            const sameMatchRoles = liveAssignments.filter(a => a.matchId === matchId);
            const refsInThisMatch = sameMatchRoles.map(a => a.refereeId);
            const duplicates = refsInThisMatch.filter(ref => ref === selectedRef);
            if (duplicates.length > 1) {
                conflict = 'red';
            }

            const $select = $(select);
            const $container = $select.next('.select2-container').find('.select2-selection');

            $container.css({ backgroundColor: '', color: '' });

            if (conflict === 'yellow') {
                $container.css({ backgroundColor: 'yellow', color: 'black' });
            }
            if (conflict === 'orange') {
                $container.css({ backgroundColor: 'orange', color: 'black' });
            }
            if (conflict === 'red') {
                $container.css({ backgroundColor: 'red', color: 'white' });
            }
        });

        console.log(`Conflict check completed for referee: ${selectedRef}`);
    }


    // Setup event listeners
    $(document).on('change', 'select.referee-select', function () {
        const selectedRef = this.value;
        console.log(`Trigger Conflict check for referee: ${selectedRef}`);
        refreshConflicts(this);
    });

    window.refreshConflicts = refreshConflicts;
    window.getLiveAssignments = getLiveAssignments;
    window.getWeekNumber = getWeekNumber;
    window.checkConflict = checkConflict;


    function safeRefreshConflicts(attempts = 10) {
        if ($('.select2-selection').length > 0) {
            const selects = Array.from(document.querySelectorAll('select'))
                .filter(select => select.value); // Only those with a referee selected

            selects.forEach(select => {
                refreshConflicts(select); // Reuse the same function!
            });

            console.log("Initial safe refresh complete");
        } else if (attempts > 0) {
            setTimeout(() => safeRefreshConflicts(attempts - 1), 50); // slightly longer delay
        }
    }


// Call it
    safeRefreshConflicts();


});
