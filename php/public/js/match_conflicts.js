document.addEventListener('DOMContentLoaded', () => {

    const existingAssignments = window.existingAssignments || [];

    // Configurable constants
    const MATCH_DURATION_MS = 90 * 60 * 1000; // 90 minutes in milliseconds
    const BUFFER_MS = 30 * 60 * 1000; // 30 minutes buffer
    const CONFLICT_COLORS = {
        red: { bg: 'red', text: 'white' },
        orange: { bg: 'orange', text: 'black' },
        yellow: { bg: 'yellow', text: 'black' }
    };

    function getLiveAssignments() {
        const liveAssignments = [];

        document.querySelectorAll('select.referee-select').forEach(select => {
            const matchIdMatch = select.name.match(/$$ (.*?) $$/);
            if (!matchIdMatch) return;
            const matchId = matchIdMatch[1];

            const roleMatch = select.name.match(/\]$$ (.*?) $$/);
            if (!roleMatch) return;
            const role = roleMatch[1];

            const selectedRef = select.value;
            if (!selectedRef) return;

            const matchRow = select.closest('tr');
            const matchDateElem = matchRow.querySelector('td:nth-child(1)');
            const kickoffTimeElem = matchRow.querySelector('td:nth-child(2)');
            const locationCell = matchRow.querySelector('td[data-field-type="location"]');
            if (!matchDateElem || !kickoffTimeElem || !locationCell) return;

            const matchDate = matchDateElem.innerText.trim();
            const kickoffTime = kickoffTimeElem.innerText.trim();
            // Extract location from cell's data attribute or text
            const locationUuid = locationCell.dataset.currentValue || '';

            liveAssignments.push({
                matchId,
                role,
                refereeId: selectedRef,
                matchDate,
                kickoffTime,
                locationUuid
            });
        });

        return liveAssignments;
    }

    function checkConflict(matchId, matchDate, kickoffTime, refereeId, locationUuid, allAssignments) {
        let highestConflict = null;
        let conflictDetails = [];

        const currentMatchDateTime = new Date(`${matchDate}T${kickoffTime}`);
        if (isNaN(currentMatchDateTime.getTime())) return null;

        const currentStart = currentMatchDateTime.getTime();
        const currentEnd = currentStart + MATCH_DURATION_MS;

        allAssignments.forEach(assign => {
            if (assign.matchId === matchId) return;

            if (assign.refereeId === refereeId) {
                const otherMatchDateTime = new Date(`${assign.matchDate}T${assign.kickoffTime}`);
                if (isNaN(otherMatchDateTime.getTime())) return;

                const otherStart = otherMatchDateTime.getTime();
                const otherEnd = otherStart + MATCH_DURATION_MS;

                const timeDiff = otherMatchDateTime - currentMatchDateTime;
                const dayDiff = Math.floor(timeDiff / (24 * 60 * 60 * 1000));

                let detail = `Conflict with match on ${assign.matchDate} at ${assign.kickoffTime}`;

                if (dayDiff === 0) {
                    // Check location conflict
                    if (locationUuid && assign.locationUuid && locationUuid !== assign.locationUuid) {
                        highestConflict = 'red';
                        detail += ' (different location)';
                    } else if (currentStart < (otherEnd + BUFFER_MS) && otherStart < (currentEnd + BUFFER_MS)) {
                        highestConflict = 'red';
                        detail += ' (overlapping time)';
                    } else if (highestConflict !== 'red') {
                        highestConflict = 'orange';
                        detail += ' (same day, non-overlapping)';
                    }
                } else if (Math.abs(dayDiff) <= 2) {
                    if (highestConflict !== 'red' && highestConflict !== 'orange') {
                        highestConflict = 'yellow';
                        detail += ` (within ${Math.abs(dayDiff)} days)`;
                    }
                }

                if (detail) conflictDetails.push(detail);
            }
        });

        return { level: highestConflict, details: conflictDetails.join('; ') };
    }

    function refreshConflicts(changedSelect, previousRefereeId) {
        const currentRefereeId = changedSelect.value;
        const liveAssignments = getLiveAssignments();

        const updateSpecificSelect = (selectToEvaluate) => {
            const refereeIdForThisSelect = selectToEvaluate.value;
            if (!refereeIdForThisSelect) {
                resetSelectStyle(selectToEvaluate);
                return;
            }

            const matchIdMatch = selectToEvaluate.name.match(/$$ (.*?) $$/);
            if (!matchIdMatch) return;
            const matchId = matchIdMatch[1];

            const matchRow = selectToEvaluate.closest('tr');
            const matchDate = matchRow.querySelector('td:nth-child(1)')?.innerText.trim();
            const kickoffTime = matchRow.querySelector('td:nth-child(2)')?.innerText.trim();
            const locationCell = matchRow.querySelector('td[data-field-type="location"]');
            const locationUuid = locationCell?.dataset.currentValue || '';

            if (!matchDate || !kickoffTime) return;

            // Check against existing and live
            const initialConflict = checkConflict(matchId, matchDate, kickoffTime, refereeIdForThisSelect, locationUuid, existingAssignments);
            const dynamicConflict = checkConflict(matchId, matchDate, kickoffTime, refereeIdForThisSelect, locationUuid, liveAssignments);

            let highestLevel = null;
            let combinedDetails = [];

            if (initialConflict?.level) {
                highestLevel = initialConflict.level;
                combinedDetails.push(initialConflict.details);
            }
            if (dynamicConflict?.level) {
                if (['red'].includes(dynamicConflict.level) || (highestLevel !== 'red' && dynamicConflict.level === 'orange') || (!highestLevel && dynamicConflict.level === 'yellow')) {
                    highestLevel = dynamicConflict.level;
                }
                combinedDetails.push(dynamicConflict.details);
            }

            // Duplicate role in same match
            const sameMatchLive = liveAssignments.filter(a => a.matchId === matchId && a.refereeId === refereeIdForThisSelect);
            if (sameMatchLive.length > 1) {
                highestLevel = 'red';
                combinedDetails.push('Duplicate role in same match');
            }

            applySelectStyle(selectToEvaluate, highestLevel, combinedDetails.filter(d => d).join('; '));
        };

        // Update all dropdowns for the affected referees
        const refereesToUpdate = new Set([currentRefereeId, previousRefereeId].filter(id => id));
        document.querySelectorAll('select.referee-select').forEach(s => {
            if (s.value && refereesToUpdate.has(s.value)) {
                updateSpecificSelect(s);
            }
        });
    }

    function resetSelectStyle(select) {
        const $select = $(select);
        const $container = $select.next('.select2-container').find('.select2-selection');
        $container.css({ backgroundColor: '', color: '' }).removeAttr('title data-bs-toggle data-bs-placement');
    }

    function applySelectStyle(select, level, details) {
        const $select = $(select);
        const $container = $select.next('.select2-container').find('.select2-selection');

        if (level) {
            const color = CONFLICT_COLORS[level];
            $container.css({ backgroundColor: color.bg, color: color.text })
                .attr('data-bs-toggle', 'tooltip')
                .attr('data-bs-placement', 'top')
                .attr('title', details || 'Conflict detected');
            new bootstrap.Tooltip($container[0]); // Initialize native Bootstrap tooltip
        } else {
            resetSelectStyle(select);
        }
    }

    function fullRefreshConflicts() {
        const liveAssignments = getLiveAssignments();
        const refereesWithAssignments = new Set(liveAssignments.map(a => a.refereeId));

        document.querySelectorAll('select.referee-select').forEach(s => {
            if (s.value && refereesWithAssignments.has(s.value)) {
                const selectToEvaluate = s;
                const refereeIdForThisSelect = selectToEvaluate.value;

                const matchIdMatch = selectToEvaluate.name.match(/$$ (.*?) $$/);
                if (!matchIdMatch) return;
                const matchId = matchIdMatch[1];

                const matchRow = selectToEvaluate.closest('tr');
                const matchDate = matchRow.querySelector('td:nth-child(1)')?.innerText.trim();
                const kickoffTime = matchRow.querySelector('td:nth-child(2)')?.innerText.trim();
                const locationCell = matchRow.querySelector('td[data-field-type="location"]');
                const locationUuid = locationCell?.dataset.currentValue || '';

                if (!matchDate || !kickoffTime) return;

                const initialConflict = checkConflict(matchId, matchDate, kickoffTime, refereeIdForThisSelect, locationUuid, existingAssignments);
                const dynamicConflict = checkConflict(matchId, matchDate, kickoffTime, refereeIdForThisSelect, locationUuid, liveAssignments);

                let highestLevel = null;
                let combinedDetails = [];

                if (initialConflict?.level) {
                    highestLevel = initialConflict.level;
                    combinedDetails.push(initialConflict.details);
                }
                if (dynamicConflict?.level) {
                    if (['red'].includes(dynamicConflict.level) || (highestLevel !== 'red' && dynamicConflict.level === 'orange') || (!highestLevel && dynamicConflict.level === 'yellow')) {
                        highestLevel = dynamicConflict.level;
                    }
                    combinedDetails.push(dynamicConflict.details);
                }

                const sameMatchLive = liveAssignments.filter(a => a.matchId === matchId && a.refereeId === refereeIdForThisSelect);
                if (sameMatchLive.length > 1) {
                    highestLevel = 'red';
                    combinedDetails.push('Duplicate role in same match');
                }

                applySelectStyle(selectToEvaluate, highestLevel, combinedDetails.filter(d => d).join('; '));
            } else {
                resetSelectStyle(s);
            }
        });
    }

    let selectPreviousValues = {};

    $(document).on('select2:opening', 'select.referee-select', function (e) {
        const selectName = e.target.name;
        selectPreviousValues[selectName] = e.target.value;
    });

    $('select.referee-select').on('change', function () {
        const selectName = this.name;
        const previousRefereeId = selectPreviousValues[selectName];
        refreshConflicts(this, previousRefereeId);
        selectPreviousValues[selectName] = this.value;
    });

    window.refreshConflicts = refreshConflicts;
    window.getLiveAssignments = getLiveAssignments;
    window.checkConflict = checkConflict;
    window.fullRefreshConflicts = fullRefreshConflicts;

    function safeRefreshConflicts(attempts = 10) {
        if ($('.select2-container').length > 0 && $('select.referee-select').length > 0) {
            fullRefreshConflicts();
        } else if (attempts > 0) {
            setTimeout(() => safeRefreshConflicts(attempts - 1), 50);
        }
    }

    safeRefreshConflicts();
});