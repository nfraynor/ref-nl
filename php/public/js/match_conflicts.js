document.addEventListener('DOMContentLoaded', () => {
    let existingAssignments = window.existingAssignments || [];
    let processedExistingAssignments = [];

    // Process existingAssignments to flat list
    existingAssignments.forEach(match => {
        const roles = ['referee_id', 'ar1_id', 'ar2_id', 'commissioner_id'];
        roles.forEach(role => {
            if (match[role]) {
                processedExistingAssignments.push({
                    matchId: match.uuid,
                    role: role,
                    refereeId: match[role],
                    matchDate: match.match_date,
                    kickoffTime: match.kickoff_time.substring(0, 5),
                    locationUuid: match.location_uuid || ''
                });
            }
        });
    });

    // Configurable constants
    const MATCH_DURATION_MS = 90 * 60 * 1000;
    const BUFFER_MS = 30 * 60 * 1000;
    const CONFLICT_COLORS = {
        red: { bg: 'red', text: 'white' },
        orange: { bg: 'orange', text: 'black' },
        yellow: { bg: 'yellow', text: 'black' }
    };

    const CONFLICT_PRIORITY = {
        red: 3,
        orange: 2,
        yellow: 1
    };

    function getLiveAssignments() {
        const liveAssignments = [];
        document.querySelectorAll('select.referee-select').forEach(select => {
            const matchIdMatch = select.name.match(/\[(.*?)\]/);
            if (!matchIdMatch) return;
            const matchId = matchIdMatch[1];

            const roleMatch = select.name.match(/\]\[(.*?)\]/);
            if (!roleMatch) return;
            const role = roleMatch[1];

            const selectedRef = select.value;
            if (!selectedRef) return;

            const matchRow = select.closest('tr');
            const matchDateElem = matchRow.querySelector('td:nth-child(1)');
            const kickoffTimeElem = matchRow.querySelector('td:nth-child(2)');
            const locationCell = matchRow.querySelector('td[data-field-type="location"]');
            if (!matchDateElem || !kickoffTimeElem || !locationCell) return;

            let kickoffTime = kickoffTimeElem.innerText.trim();
            if (kickoffTime.length === 5) kickoffTime += ':00';

            const matchDate = matchDateElem.innerText.trim();
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
        let highestLevel = null;
        let conflictDetails = [];

        let kickoffTimeFull = kickoffTime;
        if (kickoffTimeFull.length === 5) kickoffTimeFull += ':00';

        const currentMatchDateTime = new Date(`${matchDate}T${kickoffTimeFull}`);
        if (isNaN(currentMatchDateTime.getTime())) return null;

        const currentStart = currentMatchDateTime.getTime();
        const currentEnd = currentStart + MATCH_DURATION_MS;

        allAssignments.forEach(assign => {
            if (assign.matchId === matchId) return;

            if (assign.refereeId === refereeId) {
                let assignKickoffTimeFull = assign.kickoffTime;
                if (assignKickoffTimeFull.length === 5) assignKickoffTimeFull += ':00';

                const otherMatchDateTime = new Date(`${assign.matchDate}T${assignKickoffTimeFull}`);
                if (isNaN(otherMatchDateTime.getTime())) return;

                const otherStart = otherMatchDateTime.getTime();
                const otherEnd = otherStart + MATCH_DURATION_MS;

                let detail = `Conflict with match on ${assign.matchDate} at ${assign.kickoffTime}`;
                let thisLevel = null;

                if (matchDate === assign.matchDate) {
                    if (locationUuid && assign.locationUuid && locationUuid !== assign.locationUuid) {
                        thisLevel = 'red';
                        detail += ' (different location)';
                    } else if (currentStart < otherEnd + BUFFER_MS && otherStart < currentEnd + BUFFER_MS) {
                        thisLevel = 'red';
                        detail += ' (overlapping time)';
                    } else {
                        thisLevel = 'orange';
                        detail += ' (same day, non-overlapping)';
                    }
                } else {
                    const currentDateObj = new Date(matchDate);
                    const otherDateObj = new Date(assign.matchDate);
                    const timeDiffDays = otherDateObj - currentDateObj;
                    const dayDiffAbs = Math.abs(timeDiffDays / (1000 * 60 * 60 * 24));
                    if (dayDiffAbs <= 2 && dayDiffAbs > 0) {
                        thisLevel = 'yellow';
                        detail += ` (within ${Math.round(dayDiffAbs)} days)`;
                    }
                }

                if (thisLevel) {
                    conflictDetails.push(detail);
                    const thisPri = CONFLICT_PRIORITY[thisLevel];
                    const currentPri = CONFLICT_PRIORITY[highestLevel] || 0;
                    if (thisPri > currentPri) {
                        highestLevel = thisLevel;
                    }
                }
            }
        });

        return { level: highestLevel, details: conflictDetails.join('; ') };
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

            const matchIdMatch = selectToEvaluate.name.match(/\[(.*?)\]/);
            if (!matchIdMatch) return;
            const matchId = matchIdMatch[1];

            const matchRow = selectToEvaluate.closest('tr');
            const matchDate = matchRow.querySelector('td:nth-child(1)')?.innerText.trim();
            let kickoffTime = matchRow.querySelector('td:nth-child(2)')?.innerText.trim();
            if (kickoffTime.length === 5) kickoffTime += ':00';
            const locationCell = matchRow.querySelector('td[data-field-type="location"]');
            const locationUuid = locationCell?.dataset.currentValue || '';

            if (!matchDate || !kickoffTime) return;

            const initialConflict = checkConflict(matchId, matchDate, kickoffTime, refereeIdForThisSelect, locationUuid, processedExistingAssignments);
            const dynamicConflict = checkConflict(matchId, matchDate, kickoffTime, refereeIdForThisSelect, locationUuid, liveAssignments);

            const initialPri = CONFLICT_PRIORITY[initialConflict?.level] || 0;
            const dynamicPri = CONFLICT_PRIORITY[dynamicConflict?.level] || 0;
            const maxPri = Math.max(initialPri, dynamicPri);
            let highestLevel = Object.keys(CONFLICT_PRIORITY).find(key => CONFLICT_PRIORITY[key] === maxPri) || null;

            let combinedDetails = [];
            if (initialConflict?.details) combinedDetails.push(initialConflict.details);
            if (dynamicConflict?.details) combinedDetails.push(dynamicConflict.details);

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
        $container.css({ backgroundColor: '', color: '' }).removeAttr('data-bs-toggle data-bs-placement title');
        const tooltipInstance = bootstrap.Tooltip.getInstance($container[0]);
        if (tooltipInstance) tooltipInstance.dispose();
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
            new bootstrap.Tooltip($container[0]);
        } else {
            resetSelectStyle(select);
        }
    }

    function fullRefreshConflicts() {
        const liveAssignments = getLiveAssignments();
        const refereesWithAssignments = new Set(liveAssignments.map(a => a.refereeId));

        document.querySelectorAll('select.referee-select').forEach(s => {
            if (s.value && refereesWithAssignments.has(s.value)) {
                const selectToEvaluate = s; // Use the function from refreshConflicts
                const refereeIdForThisSelect = selectToEvaluate.value;

                const matchIdMatch = selectToEvaluate.name.match(/\[(.*?)\]/);
                if (!matchIdMatch) return;
                const matchId = matchIdMatch[1];

                const matchRow = selectToEvaluate.closest('tr');
                const matchDate = matchRow.querySelector('td:nth-child(1)')?.innerText.trim();
                let kickoffTime = matchRow.querySelector('td:nth-child(2)')?.innerText.trim();
                if (kickoffTime.length === 5) kickoffTime += ':00';
                const locationCell = matchRow.querySelector('td[data-field-type="location"]');
                const locationUuid = locationCell?.dataset.currentValue || '';

                if (!matchDate || !kickoffTime) return;

                const initialConflict = checkConflict(matchId, matchDate, kickoffTime, refereeIdForThisSelect, locationUuid, processedExistingAssignments);
                const dynamicConflict = checkConflict(matchId, matchDate, kickoffTime, refereeIdForThisSelect, locationUuid, liveAssignments);

                const initialPri = CONFLICT_PRIORITY[initialConflict?.level] || 0;
                const dynamicPri = CONFLICT_PRIORITY[dynamicConflict?.level] || 0;
                const maxPri = Math.max(initialPri, dynamicPri);
                let highestLevel = Object.keys(CONFLICT_PRIORITY).find(key => CONFLICT_PRIORITY[key] === maxPri) || null;

                let combinedDetails = [];
                if (initialConflict?.details) combinedDetails.push(initialConflict.details);
                if (dynamicConflict?.details) combinedDetails.push(dynamicConflict.details);

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