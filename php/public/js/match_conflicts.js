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

        document.querySelectorAll('select.referee-select').forEach(select => {
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
                        conflict = { level: 'red', reason: `Overlapping match on ${assign.matchDate} at ${assign.kickoffTime}` };
                    } else if (conflict?.level !== 'red') {
                        conflict = { level: 'orange', reason: `Same-day match on ${assign.matchDate} at ${assign.kickoffTime}` };
                    }
                } else if (Math.abs(dayDiff) <= 2 && !conflict) {
                    conflict = { level: 'yellow', reason: `Match within 2 days on ${assign.matchDate} at ${assign.kickoffTime}` };
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
            return { level: 'yellow', reason: 'Exceeds 3 matches per weekend' };
        }

        // Check max matches per weekend
        if (maxMatches === 1 && weekendAssignments.length >= 1) {
            return { level: 'yellow', reason: 'Exceeds max 1 match per weekend' };
        }

        // Check max days per weekend
        if (maxDays === 1) {
            const uniqueDays = new Set(weekendAssignments.map(a => a.matchDate));
            const isNewDay = !uniqueDays.has(matchDate);
            if (isNewDay && uniqueDays.size >= 1) {
                return { level: 'yellow', reason: 'Exceeds max 1 day per weekend' };
            }
        }

        return conflict;
    }

    function refreshConflicts(changedSelect, previousRefereeId) {
        const currentRefereeId = changedSelect?.value;
        const liveAssignments = getLiveAssignments();

        console.log(`--- Refresh Start --- Changed: ${changedSelect?.name || 'initial'}, Prev Ref: ${previousRefereeId || 'none'}, Curr Ref: ${currentRefereeId || 'none'}`);

        const updateSpecificElement = (element, isSelect = true) => {
            const refereeIdForThisElement = isSelect ? element.value : element.dataset.refereeId;
            if (!refereeIdForThisElement) return;

            const matchRow = element.closest('tr');
            if (!matchRow) {
                console.error("Could not find match row for element:", element);
                return;
            }
            const matchId = isSelect ? element.name.match(/\[(.*?)\]/)[1] : element.dataset.matchId;
            const matchDate = matchRow.querySelector('td:nth-child(1)')?.innerText;
            const kickoffTime = matchRow.querySelector('td:nth-child(2)')?.innerText;

            if (!matchId || !matchDate || !kickoffTime) {
                console.error("Could not extract matchId/date/time for element:", element);
                return;
            }

            let conflict = null;
            const severity = { red: 3, orange: 2, yellow: 1, null: 0 };

            const $element = $(element);
            const $container = isSelect ? $element.next('.select2-container').find('.select2-selection') : $element;

            // Reset style
            $container.css({ backgroundColor: '', color: '' });
            if (!isSelect) {
                $container.removeClass('conflict-red conflict-orange conflict-yellow');
                $container.attr('title', 'No conflicts');
            }

            // Time-based conflicts
            const initialConflict = checkConflict(matchId, matchDate, kickoffTime, refereeIdForThisElement, existingAssignments);
            const dynamicConflict = checkConflict(matchId, matchDate, kickoffTime, refereeIdForThisElement, liveAssignments);

            if (dynamicConflict?.level === 'red' || initialConflict?.level === 'red') {
                conflict = dynamicConflict || initialConflict;
            } else if (dynamicConflict?.level === 'orange' || initialConflict?.level === 'orange') {
                if (severity[conflict?.level || 'null'] < severity['orange']) conflict = dynamicConflict || initialConflict;
            } else if (dynamicConflict?.level === 'yellow' || initialConflict?.level === 'yellow') {
                if (severity[conflict?.level || 'null'] < severity['yellow']) conflict = dynamicConflict || initialConflict;
            }

            // Weekend limits check
            if (window.refereePreferences && (window.refereePreferences[refereeIdForThisElement]?.max_matches_per_weekend !== undefined ||
                window.refereePreferences[refereeIdForThisElement]?.max_days_per_weekend !== undefined)) {
                const maxMatches = window.refereePreferences[refereeIdForThisElement].max_matches_per_weekend;
                const maxDays = window.refereePreferences[refereeIdForThisElement].max_days_per_weekend;
                const combinedAssignments = [...existingAssignments, ...liveAssignments];
                const weekendConflict = checkWeekendLimits(matchId, matchDate, refereeIdForThisElement, combinedAssignments, maxMatches, maxDays);
                if (weekendConflict && severity[weekendConflict.level] > severity[conflict?.level || 'null']) {
                    conflict = weekendConflict;
                }
            }

            // Duplicate roles check (only for select elements)
            if (isSelect) {
                const sameMatchLiveAssignments = liveAssignments.filter(a => a.matchId === matchId && a.refereeId === refereeIdForThisElement);
                if (sameMatchLiveAssignments.length > 1) {
                    conflict = { level: 'red', reason: 'Assigned multiple roles in the same match' };
                }
            }

            // Apply styles
            if (conflict) {
                if (conflict.level === 'yellow') {
                    $container.css({ backgroundColor: 'yellow', color: 'black' });
                    if (!isSelect) $container.addClass('conflict-yellow');
                } else if (conflict.level === 'orange') {
                    $container.css({ backgroundColor: 'orange', color: 'black' });
                    if (!isSelect) $container.addClass('conflict-orange');
                } else if (conflict.level === 'red') {
                    $container.css({ backgroundColor: 'red', color: 'white' });
                    if (!isSelect) $container.addClass('conflict-red');
                }
                if (!isSelect) {
                    $container.attr('title', conflict.reason);
                }
            }
        };

        // Update select elements (assign mode)
        if (changedSelect) {
            updateSpecificElement(changedSelect, true);
            if (previousRefereeId && previousRefereeId !== currentRefereeId) {
                document.querySelectorAll('select.referee-select').forEach(s => {
                    if (s !== changedSelect && s.value === previousRefereeId) {
                        updateSpecificElement(s, true);
                    }
                });
            }
            if (currentRefereeId) {
                document.querySelectorAll('select.referee-select').forEach(s => {
                    if (s !== changedSelect && s.value === currentRefereeId) {
                        updateSpecificElement(s, true);
                    }
                });
            }
        }

        // Update display elements (non-assign mode)
        document.querySelectorAll('.referee-display[data-referee-id]').forEach(span => {
            updateSpecificElement(span, false);
        });
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
        if ($('.select2-container').length > 0 || $('.referee-display').length > 0) {
            console.log("Attempting initial safe refresh conflicts...");
            const allRefereeSelects = Array.from(document.querySelectorAll('select.referee-select'));
            const allRefereeDisplays = Array.from(document.querySelectorAll('.referee-display[data-referee-id]'));

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

            allRefereeDisplays.forEach(displayElement => {
                updateSpecificElement(displayElement, false);
            });

            console.log("Initial safe refresh complete for " + (allRefereeSelects.length + allRefereeDisplays.length) + " elements. Previous values map:", selectPreviousValues);

            // Initialize Bootstrap tooltips
            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                document.querySelectorAll('[data-toggle="tooltip"]').forEach(element => {
                    new bootstrap.Tooltip(element);
                });
            }
        } else if (attempts > 0) {
            setTimeout(() => safeRefreshConflicts(attempts - 1), 50);
        }
    }

    safeRefreshConflicts();
});