document.addEventListener('DOMContentLoaded', () => {

    const existingAssignments = window.existingAssignments || [];

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
                } else if (Math.abs(dayDiff) <= 2) { // Within 2 days (before or after)
                    // Check if a more severe conflict (red or orange) already exists from another rule (e.g. same day conflict checked above)
                    // Or if a yellow conflict was already found for this referee from another match.
                    // This specific check is for yellow, so it should only set yellow if no higher conflict is present.
                    if (conflict !== 'red' && conflict !== 'orange') {
                        conflict = 'yellow';
                    }
                }
            }
        });

        return conflict;
    }

    function refreshConflicts(changedSelect, previousRefereeId) {
        const currentRefereeId = changedSelect.value; // Get current value from the actual select element
        const liveAssignments = getLiveAssignments(); // Get current state of all assignments

        console.log(`--- Refresh Start --- Changed: ${changedSelect.name}, Prev Ref: ${previousRefereeId}, Curr Ref: ${currentRefereeId}`);

        // Helper function to update a single select element's conflict display
        const updateSpecificSelect = (selectToEvaluate) => {
            const refereeIdForThisSelect = selectToEvaluate.value; // Current value of the select being evaluated
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

            const $select = $(selectToEvaluate);
            const $container = $select.next('.select2-container').find('.select2-selection');

            // Reset style first
            $container.css({ backgroundColor: '', color: '' });

            if (refereeIdForThisSelect) { // Only check for conflicts if a referee is selected
                // Check conflicts against existing (saved) assignments
                const initialConflict = checkConflict(matchId, matchDate, kickoffTime, refereeIdForThisSelect, existingAssignments);
                // Check conflicts against current live assignments on the page
                const dynamicConflict = checkConflict(matchId, matchDate, kickoffTime, refereeIdForThisSelect, liveAssignments);

                // Determine the most severe conflict
                if (dynamicConflict === 'red' || initialConflict === 'red') {
                    conflict = 'red';
                } else if (dynamicConflict === 'orange' || initialConflict === 'orange') {
                    if (conflict !== 'red') conflict = 'orange'; // Don't downgrade from red
                } else if (dynamicConflict === 'yellow' || initialConflict === 'yellow') {
                    if (conflict !== 'red' && conflict !== 'orange') conflict = 'yellow'; // Don't downgrade
                }
                // else conflict remains null if none of the above matched

                // Final override: check for duplicate roles for THIS referee in THIS match from live assignments
                const sameMatchLiveAssignments = liveAssignments.filter(a => a.matchId === matchId && a.refereeId === refereeIdForThisSelect);
                if (sameMatchLiveAssignments.length > 1) {
                    conflict = 'red'; // This is a hard red conflict
                }

                // Apply styles based on the determined conflict level
                if (conflict === 'yellow') {
                    $container.css({ backgroundColor: 'yellow', color: 'black' });
                } else if (conflict === 'orange') {
                    $container.css({ backgroundColor: 'orange', color: 'black' });
                } else if (conflict === 'red') {
                    $container.css({ backgroundColor: 'red', color: 'white' });
                }
            }
            // console.log(`Evaluated ${selectToEvaluate.name} (Ref: ${refereeIdForThisSelect}): Conflict=${conflict}`);
        };

        // --- Three-Stage Update ---

        // Stage 1: Always update the select element that was directly changed.
        // console.log(`Stage 1: Updating changedSelect: ${changedSelect.name}`);
        updateSpecificSelect(changedSelect);

        // Stage 2: If a referee was previously assigned to changedSelect (and it's different from the new one),
        // re-evaluate all OTHER select elements that are currently assigned to that PREVIOUS referee.
        if (previousRefereeId && previousRefereeId !== currentRefereeId) {
            // console.log(`Stage 2: Re-evaluating OTHERS for previous referee: ${previousRefereeId}`);
            document.querySelectorAll('select.referee-select').forEach(s => {
                if (s !== changedSelect && s.value === previousRefereeId) {
                    updateSpecificSelect(s);
                }
            });
        }

        // Stage 3: If a new referee is assigned to changedSelect,
        // re-evaluate all OTHER select elements that are currently assigned to this NEW referee.
        // This also implicitly handles the case where previousRefereeId === currentRefereeId (no actual referee change),
        // ensuring the rest of the group is correctly updated.
        if (currentRefereeId) {
            // console.log(`Stage 3: Re-evaluating OTHERS for current referee: ${currentRefereeId}`);
            document.querySelectorAll('select.referee-select').forEach(s => {
                if (s !== changedSelect && s.value === currentRefereeId) {
                    updateSpecificSelect(s);
                }
            });
        }
        // console.log(`--- Refresh End ---`);
    }

    let selectPreviousValues = {}; // Global map to store previous values by select name

    // Use Select2's 'select2:opening' event to capture value BEFORE it changes.
    // This event fires on the original select element.
    $(document).on('select2:opening', 'select.referee-select', function (e) {
        const selectName = e.target.name;
        const currentValue = e.target.value;
        selectPreviousValues[selectName] = currentValue;
        console.log(`Select2 Opening: Stored previous value for ${selectName}: ${currentValue}. Map:`, JSON.parse(JSON.stringify(selectPreviousValues)));
    });

    // Setup event listeners for actual change using direct binding.
    // This script MUST run after Select2 has initialized the elements.
    $('select.referee-select').on('change', function () {
        const selectName = this.name;
        const previousRefereeId = selectPreviousValues[selectName]; // Retrieve from our map, populated by 'select2:opening' or initial load
        const currentRefereeId = this.value; // New value after change

        // Warning if previousRefereeId is undefined (though 'select2:opening' should prevent this for manual changes)
        if (typeof previousRefereeId === 'undefined') {
            console.warn(`Previous referee ID is undefined for ${selectName} during change event. This may indicate 'select2:opening' did not fire or the select was not initialized in the map.`);
        }

        console.log(`Directly Bound Change event for ${selectName}. Prev from map: ${previousRefereeId}, Curr: ${currentRefereeId}`);
        refreshConflicts(this, previousRefereeId);

        // Update the map with the new current value. This makes it the "previous"
        // for the next 'select2:opening' or if this change handler is triggered again.
        selectPreviousValues[selectName] = currentRefereeId;
        // console.log(`Direct Change event end for ${selectName}. Updated selectPreviousValues['${selectName}'] to ${currentRefereeId}.`);
    });

    window.refreshConflicts = refreshConflicts;
    window.getLiveAssignments = getLiveAssignments;
    // window.getWeekNumber = getWeekNumber; // No longer needed
    window.checkConflict = checkConflict;


    function safeRefreshConflicts(attempts = 10) {
        if ($('.select2-container').length > 0 && $('select.referee-select').length > 0) { // Ensure select2 and selects are ready
            console.log("Attempting initial safe refresh conflicts...");
            const allRefereeSelects = Array.from(document.querySelectorAll('select.referee-select'));

            allRefereeSelects.forEach(selectElement => {
                // Initialize our global map with the current value, making it the "previous" for the first potential change.
                selectPreviousValues[selectElement.name] = selectElement.value;
                // console.log(`SafeRefresh: Initialized selectPreviousValues for ${selectElement.name} to ${selectElement.value}`);

                if (selectElement.value) {
                    // For initial load, treat each selected dropdown as being "changed"
                    // from its own current value to its current value.
                    // This allows refreshConflicts' Stage 3 to correctly group and update
                    // all elements belonging to the same referee.
                    // Stage 2 will be skipped because previousRefereeId === currentRefereeId in this call.
                    // console.log(`Initial refresh for ${selectElement.name} with value ${selectElement.value}`);
                    refreshConflicts(selectElement, selectElement.value);
                } else {
                    // Ensure selects that are initially empty also have their styles cleared
                    const $select = $(selectElement);
                    const $container = $select.next('.select2-container').find('.select2-selection');
                    if ($container.length) {
                        $container.css({ backgroundColor: '', color: '' });
                    }
                }
            });

            console.log("Initial safe refresh complete for " + allRefereeSelects.length + " select elements. Previous values map:", selectPreviousValues);
        } else if (attempts > 0) {
            setTimeout(() => safeRefreshConflicts(attempts - 1), 50); // slightly longer delay
        }
    }


// Call it
    safeRefreshConflicts();


});
