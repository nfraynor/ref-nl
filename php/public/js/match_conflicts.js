document.addEventListener('DOMContentLoaded', () => {
    const existingAssignments = window.existingAssignments || [];

    function getWeekNumber(dateStr) {
        const date = new Date(dateStr);
        const year = date.getFullYear();
        const firstDayOfYear = new Date(year, 0, 1);
        const pastDaysOfYear = (date - firstDayOfYear) / 86400000;
        const weekNo = Math.ceil((pastDaysOfYear + firstDayOfYear.getDay() + 1) / 7);
        return `${year}-W${weekNo.toString().padStart(2, '0')}`;
    }

    function isWeekendDay(dateStr) {
        const date = new Date(dateStr);
        return date.getDay() === 0 || date.getDay() === 6; // Sunday (0) or Saturday (6)
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
        const currentMatchDateObj = new Date(matchDate);
        const currentStart = new Date(`1970-01-01T${kickoffTime}`);
        const currentEnd = new Date(currentStart.getTime() + 90 * 60000); // Assuming 90 minutes match duration

        allAssignments.forEach(assign => {
            if (assign.matchId === matchId) return; // Don't compare a match with itself

            if (assign.refereeId === refereeId) {
                const otherMatchDateObj = new Date(assign.matchDate);
                const timeDiff = otherMatchDateObj.getTime() - currentMatchDateObj.getTime();
                const dayDiff = Math.round(timeDiff / (1000 * 60 * 60 * 24));

                if (dayDiff === 0) { // Same day
                    const otherStart = new Date(`1970-01-01T${assign.kickoffTime}`);
                    const otherEnd = new Date(otherStart.getTime() + 90 * 60000);

                    if (currentStart < otherEnd && otherStart < currentEnd) {
                        conflict = 'red'; // Overlapping matches
                    } else if (conflict !== 'red') {
                        conflict = 'orange'; // Same day, non-overlapping
                    }
                } else if (Math.abs(dayDiff) <= 2) {
                    if (conflict !== 'red' && conflict !== 'orange') {
                        conflict = 'yellow';
                    }
                }
            }
        });

        return conflict;
    }

    function checkWeekendLimits(matchId, matchDate, refereeId, allAssignments, maxMatches, maxDays) {
        if (!isWeekendDay(matchDate)) return null; // Only check for weekend days
        let conflict = null;

        const weekKey = getWeekNumber(matchDate);
        const weekendAssignments = allAssignments.filter(a =>
            a.refereeId === refereeId &&
            getWeekNumber(a.matchDate) === weekKey &&
            isWeekendDay(a.matchDate) &&
            a.matchId !== matchId
        );

        // Check total matches (hard limit of 3)
        if (weekendAssignments.length >= 3) {
            return 'yellow';
        }

        // Check max matches per weekend
        if (maxMatches === 1 && weekendAssignments.length >= 1) {
            return 'yellow';
        }

        // Check max days per weekend
        if (maxDays === 1) {
            const uniqueDays = new Set(weekendAssignments.map(a => a.matchDate));
            const isNewDay = !uniqueDays.has(matchDate);
            if (isNewDay && uniqueDays.size >= 1) {
                return 'yellow';
            }
        }

        return conflict;
    }

    function refreshConflicts(changedSelect, previousRefereeId) {
        const currentRefereeId = changedSelect.value;
        const liveAssignments = getLiveAssignments();

        console.log(`--- Refresh Start --- Changed: ${changedSelect.name}, Prev Ref: ${previousRefereeId}, Curr Ref: ${currentRefereeId}`);

        const updateSpecificSelect = (selectToEvaluate) => {
            const refereeIdForThisSelect = selectToEvaluate.value;
            const matchIdMatch = selectToEvaluate.name.match(/\[(.*?)\]/);
            if (!matchIdMatch || !matchIdMatch[1]) {
                console.error("Could not extract matchId from select name:", selectToEvaluate.name);
                return;
            }
            const matchId = matchIdMatch[1];

            const matchRow = selectToEvaluate.closest('tr');
            if (!matchRow) {
                console.error("Could not find match row for select:", selectToEvaluate);
                return;
            }
            const matchDateElem = matchRow.querySelector('td:nth-child(1)');
            const kickoffTimeElem = matchRow.querySelector('td:nth-child(2)');

            if (!matchDateElem || !kickoffTimeElem) {
                console.error("Could not find date/time elements for select:", selectToEvaluate.name, "in row:", matchRow);
                return;
            }
            const matchDate = matchDateElem.innerText;
            const kickoffTime = kickoffTimeElem.innerText;

            let conflict = null;
            const severity = { red: 3, orange: 2, yellow: 1, null: 0 };

            const $select = $(selectToEvaluate);
            const $container = $select.next('.select2-container').find('.select2-selection');

            // Reset style first
            $container.css({ backgroundColor: '', color: '' });

            if (refereeIdForThisSelect) {
                // Time-based conflicts
                const initialConflict = checkConflict(matchId, matchDate, kickoffTime, refereeIdForThisSelect, existingAssignments);
                const dynamicConflict = checkConflict(matchId, matchDate, kickoffTime, refereeIdForThisSelect, liveAssignments);

                if (dynamicConflict === 'red' || initialConflict === 'red') {
                    conflict = 'red';
                } else if (dynamicConflict === 'orange' || initialConflict === 'orange') {
                    if (severity[conflict] < severity['orange']) conflict = 'orange';
                } else if (dynamicConflict === 'yellow' || initialConflict === 'yellow') {
                    if (severity[conflict] < severity['yellow']) conflict = 'yellow';
                }

                // Weekend limits check
                if (window.refereePreferences && (window.refereePreferences[refereeIdForThisSelect]?.max_matches_per_weekend !== undefined ||
                    window.refereePreferences[refereeIdForThisSelect]?.max_days_per_weekend !== undefined)) {
                    const maxMatches = window.refereePreferences[refereeIdForThisSelect].max_matches_per_weekend;
                    const maxDays = window.refereePreferences[refereeIdForThisSelect].max_days_per_weekend;
                    const combinedAssignments = [...existingAssignments, ...liveAssignments];
                    const weekendConflict = checkWeekendLimits(matchId, matchDate, refereeIdForThisSelect, combinedAssignments, maxMatches, maxDays);
                    if (weekendConflict && severity[weekendConflict] > severity[conflict]) {
                        conflict = weekendConflict;
                    }
                }

                // Duplicate roles check
                const sameMatchLiveAssignments = liveAssignments.filter(a => a.matchId === matchId && a.refereeId === refereeIdForThisSelect);
                if (sameMatchLiveAssignments.length > 1) {
                    conflict = 'red';
                }

                // Apply styles
                if (conflict === 'yellow') {
                    $container.css({ backgroundColor: 'yellow', color: 'black' });
                } else if (conflict === 'orange') {
                    $container.css({ backgroundColor: 'orange', color: 'black' });
                } else if (conflict === 'red') {
                    $container.css({ backgroundColor: 'red', color: 'white' });
                }
            }
        };

        // Three-Stage Update
        updateSpecificSelect(changedSelect);

        if (previousRefereeId && previousRefereeId !== currentRefereeId) {
            document.querySelectorAll('select.referee-select').forEach(s => {
                if (s !== changedSelect && s.value === previousRefereeId) {
                    updateSpecificSelect(s);
                }
            });
        }

        if (currentRefereeId) {
            document.querySelectorAll('select.referee-select').forEach(s => {
                if (s !== changedSelect && s.value === currentRefereeId) {
                    updateSpecificSelect(s);
                }
            });
        }
    }

    let selectPreviousValues = {};

    $(document).on('select2:opening', 'select.referee-select', function (e) {
        const selectName = e.target.name;
        const currentValue = e.target.value;
        selectPreviousValues[selectName] = currentValue;
        console.log(`Select2 Opening: Stored previous value for ${selectName}: ${currentValue}. Map:`, JSON.parse(JSON.stringify(selectPreviousValues)));
    });

    $('select.referee-select').on('change', function () {
        const selectName = this.name;
        const previousRefereeId = selectPreviousValues[selectName];
        const currentRefereeId = this.value;

        if (typeof previousRefereeId === 'undefined') {
            console.warn(`Previous referee ID is undefined for ${selectName} during change event.`);
        }

        console.log(`Directly Bound Change event for ${selectName}. Prev from map: ${previousRefereeId}, Curr: ${currentRefereeId}`);
        refreshConflicts(this, previousRefereeId);

        selectPreviousValues[selectName] = currentRefereeId;
    });

    window.refreshConflicts = refreshConflicts;
    window.getLiveAssignments = getLiveAssignments;
    window.checkConflict = checkConflict;
    window.checkWeekendLimits = checkWeekendLimits;

    function safeRefreshConflicts(attempts = 10) {
        if ($('.select2-container').length > 0 && $('select.referee-select').length > 0) {
            console.log("Attempting initial safe refresh conflicts...");
            const allRefereeSelects = Array.from(document.querySelectorAll('select.referee-select'));

            allRefereeSelects.forEach(selectElement => {
                selectPreviousValues[selectElement.name] = selectElement.value;

                if (selectElement.value) {
                    refreshConflicts(selectElement, selectElement.value);
                } else {
                    const $select = $(selectElement);
                    const $container = $select.next('.select2-container').find('.select2-selection');
                    if ($container.length) {
                        $container.css({ backgroundColor: '', color: '' });
                    }
                }
            });

            console.log("Initial safe refresh complete for " + allRefereeSelects.length + " select elements. Previous values map:", selectPreviousValues);
        } else if (attempts > 0) {
            setTimeout(() => safeRefreshConflicts(attempts - 1), 50);
        }
    }

    safeRefreshConflicts();
});