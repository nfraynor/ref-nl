// matches.js (Full Updated File with Full Conflict Refresh on Suggestions)

let currentFilters = {};
let tempSelected = {};
let suggestedAssignments = {};

function initializeCurrentFiltersFromURL() {
    const params = new URLSearchParams(window.location.search);
    for (const [key, value] of params.entries()) {
        if (key.endsWith('[]')) {
            const cleanKey = key.slice(0, -2);
            if (!currentFilters[cleanKey]) {
                currentFilters[cleanKey] = [];
            }
            currentFilters[cleanKey].push(value);
        } else {
            currentFilters[key] = value;
        }
    }
}

function buildParamsFromCurrentFilters() {
    const params = new URLSearchParams();
    for (const key in currentFilters) {
        if (currentFilters.hasOwnProperty(key)) {
            const value = currentFilters[key];
            if (Array.isArray(value)) {
                value.forEach(item => params.append(key + '[]', item));
            } else if (value !== null && value !== undefined && value !== '') {
                params.set(key, value);
            }
        }
    }
    return params;
}

function fetchAndUpdateMatches() {
    const params = buildParamsFromCurrentFilters();
    const queryString = params.toString();

    if (window.history.pushState) {
        const newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname + (queryString ? '?' + queryString : '');
        window.history.pushState({path: newUrl}, '', newUrl);
    }

    fetch(`/ajax/fetch_matches.php?${queryString}`)
        .then(res => {
            if (!res.ok) {
                throw new Error(`HTTP error! status: ${res.status}`);
            }
            return res.text();
        })
        .then(html => {
            const tableBody = document.getElementById('matchesTableBody');
            if (tableBody) {
                tableBody.innerHTML = html;
                initializeSelect2AndEvents();
                reapplySuggestions();
                window.fullRefreshConflicts(); // Ensure conflicts are rechecked
                updateActiveFilterIndicators();
            } else {
                console.error('Error: matchesTableBody element not found.');
            }
        })
        .catch(error => {
            console.error('Error fetching or updating matches:', error);
        });
}

function reapplySuggestions() {
    for (const matchId in suggestedAssignments) {
        for (const role in suggestedAssignments[matchId]) {
            const refId = suggestedAssignments[matchId][role];
            const select = document.querySelector(`select[name="assignments[${matchId}][${role}]"]`);
            if (select) {
                select.value = refId ?? "";
                const event = new Event('change', { bubbles: true });
                select.dispatchEvent(event);
            }
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    initializeCurrentFiltersFromURL();

    let allLocations = [];
    let allUsers = [];
    let editMatchFieldModalInstance = null;

    const modalElement = document.getElementById('editMatchFieldModal');
    if (modalElement) {
        editMatchFieldModalInstance = new bootstrap.Modal(modalElement);
    }

    function fetchSelectData() {
        fetch('/ajax/location_options.php')
            .then(response => response.json())
            .then(data => {
                allLocations = data;
            })
            .catch(error => console.error('Error fetching locations:', error));

        fetch('/ajax/user_options.php')
            .then(response => response.json())
            .then(data => {
                allUsers = data;
            })
            .catch(error => console.error('Error fetching users:', error));
    }

    fetchSelectData();

    document.body.addEventListener('click', function(event) {
        if (event.target.classList.contains('edit-icon')) {
            const icon = event.target;
            const cell = icon.closest('.editable-cell');
            const matchUuid = cell.dataset.matchUuid;
            const fieldType = cell.dataset.fieldType;
            const currentValue = cell.dataset.currentValue;

            const modalTitle = document.getElementById('editMatchFieldModalLabel');
            const modalBody = document.getElementById('editMatchFieldModalBody');
            const saveButton = document.getElementById('saveMatchFieldChange');

            modalBody.innerHTML = '';
            let selectElement = document.createElement('select');
            selectElement.classList.add('form-select');
            selectElement.id = 'modalSelectField';

            let defaultOption = document.createElement('option');
            defaultOption.value = '';
            defaultOption.textContent = `-- Select ${fieldType.replace('_', ' ')} --`;
            selectElement.appendChild(defaultOption);

            if (fieldType === 'location') {
                modalTitle.textContent = 'Edit Match Location';
                allLocations.forEach(loc => {
                    let option = document.createElement('option');
                    option.value = loc.uuid;
                    option.textContent = `${loc.name} (${loc.address_text})`;
                    if (loc.uuid === currentValue) {
                        option.selected = true;
                    }
                    selectElement.appendChild(option);
                });
            } else if (fieldType === 'referee_assigner') {
                modalTitle.textContent = 'Edit Referee Assigner';
                allUsers.forEach(user => {
                    let option = document.createElement('option');
                    option.value = user.uuid;
                    option.textContent = user.username;
                    if (user.uuid === currentValue) {
                        option.selected = true;
                    }
                    selectElement.appendChild(option);
                });
            }
            modalBody.appendChild(selectElement);

            saveButton.dataset.matchUuid = matchUuid;
            saveButton.dataset.fieldType = fieldType;
            saveButton.dataset.cellValueElement = `#matchesTableBody tr td[data-match-uuid='${matchUuid}'][data-field-type='${fieldType}'] span.cell-value`;
            saveButton.dataset.cellElement = `#matchesTableBody tr td[data-match-uuid='${matchUuid}'][data-field-type='${fieldType}']`;

            if (editMatchFieldModalInstance) {
                editMatchFieldModalInstance.show();
            }
        }

        if (event.target.id === 'saveMatchFieldChange') {
            const saveButton = event.target;
            const matchUuid = saveButton.dataset.matchUuid;
            const fieldType = saveButton.dataset.fieldType;
            const newValue = document.getElementById('modalSelectField').value;
            const cellValueSelector = saveButton.dataset.cellValueElement;
            const cellSelector = saveButton.dataset.cellElement;

            fetch('/ajax/update_match_field.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `match_uuid=${encodeURIComponent(matchUuid)}&field_type=${encodeURIComponent(fieldType)}&new_value=${encodeURIComponent(newValue)}`
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const cellValueElement = document.querySelector(cellValueSelector);
                        const cellElement = document.querySelector(cellSelector);
                        if (cellValueElement && cellElement) {
                            cellValueElement.innerHTML = data.newValueDisplay;
                            cellElement.dataset.currentValue = newValue;
                        }
                        if (editMatchFieldModalInstance) {
                            editMatchFieldModalInstance.hide();
                        }
                    } else {
                        alert('Error updating field: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An unexpected error occurred.');
                });
        }
    });

    // Apply active indicators on initial load
    updateActiveFilterIndicators();
});

function updateActiveFilterIndicators() {
    ['division', 'district', 'poule', 'location', 'referee_assigner'].forEach(paramName => {
        const toggleId = `${paramName}FilterToggle`;
        const toggleBtn = document.getElementById(toggleId);
        if (toggleBtn) {
            const isActive = currentFilters[paramName] && currentFilters[paramName].length > 0;
            if (isActive) {
                toggleBtn.classList.add('filter-active');
            } else {
                toggleBtn.classList.remove('filter-active');
            }
        }
    });
}

document.getElementById('clearAssignments')?.addEventListener('click', () => {
    document.querySelectorAll('select.referee-select').forEach(select => {
        select.value = "";
        select.removeAttribute('style');

        const selectId = select.id;
        if (selectId) {
            const select2Container = document.querySelector(`[aria-labelledby="select2-${selectId}-container"]`);
            if (select2Container && select2Container.parentElement) {
                const selectionElement = select2Container.parentElement.querySelector('.select2-selection');
                if (selectionElement) {
                    selectionElement.removeAttribute('style');
                }
            } else {
                let sibling = select.nextElementSibling;
                if (sibling && sibling.classList.contains('select2-container')) {
                    const selectionRendered = sibling.querySelector('.select2-selection');
                    if (selectionRendered) {
                        selectionRendered.removeAttribute('style');
                    }
                }
            }
        }

        const event = new Event('change', { bubbles: true });
        select.dispatchEvent(event);
    });
    suggestedAssignments = {};
    window.fullRefreshConflicts(); // Full refresh after clearing
});

document.getElementById('suggestAssignments')?.addEventListener('click', async (event) => {
    const suggestButton = event.target;
    const originalButtonText = suggestButton.textContent;
    suggestButton.disabled = true;
    suggestButton.textContent = 'Suggesting...';

    const progressBarContainer = document.getElementById('suggestionProgressBarContainer');
    const progressBar = document.getElementById('suggestionProgressBar');
    const progressText = document.getElementById('suggestionProgressText');

    progressBarContainer.style.display = 'block';
    progressText.style.display = 'block';
    progressBar.style.width = '0%';
    progressBar.setAttribute('aria-valuenow', 0);
    progressText.textContent = 'Starting...';

    const params = buildParamsFromCurrentFilters();
    const queryString = params.toString();

    try {
        const response = await fetch(`suggest_assignments.php${queryString ? '?' + queryString : ''}`);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        const processStream = async () => {
            while (true) {
                const { value, done } = await reader.read();
                if (done) {
                    break;
                }

                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop(); // Keep the last partial line

                for (const line of lines) {
                    if (line.trim() === '') continue;
                    try {
                        const data = JSON.parse(line);

                        // Update progress bar
                        progressBar.style.width = `${data.progress}%`;
                        progressBar.setAttribute('aria-valuenow', data.progress);
                        progressText.textContent = data.message;

                        // If final data is here
                        if (data.progress === 100 && data.suggestions) {
                            // Apply to dropdowns and store in global object
                            for (const matchId in data.suggestions) {
                                if (!suggestedAssignments[matchId]) {
                                    suggestedAssignments[matchId] = {};
                                }
                                const matchSuggestions = data.suggestions[matchId];

                                for (const role in matchSuggestions) {
                                    const refId = matchSuggestions[role];
                                    suggestedAssignments[matchId][role] = refId;

                                    const select = document.querySelector(`select[name="assignments[${matchId}][${role}]"]`);
                                    if (select) {
                                        select.value = refId ?? "";
                                        const event = new Event('change', { bubbles: true });
                                        select.dispatchEvent(event);
                                    }
                                }
                            }
                            window.fullRefreshConflicts(); // Full refresh after suggestions
                        }
                    } catch (e) {
                        console.error('Error parsing progress update:', e, 'Line:', line);
                    }
                }
            }
        };

        await processStream();

    } catch (error) {
        console.error('Error fetching suggestions:', error);
        alert('Error fetching suggestions. Please check the console for details or try again.');
        progressText.textContent = 'An error occurred.';
        progressBar.classList.add('bg-danger');
    } finally {
        suggestButton.disabled = false;
        suggestButton.textContent = originalButtonText;
        // Hide progress bar after a short delay
        setTimeout(() => {
            progressBarContainer.style.display = 'none';
            progressText.style.display = 'none';
            progressBar.classList.remove('bg-danger');
        }, 3000);
    }
});

// Handle date filter box
function updateDateFilters(startDate, endDate) {
    if (startDate) {
        currentFilters.start_date = startDate;
    } else {
        delete currentFilters.start_date;
    }
    if (endDate) {
        currentFilters.end_date = endDate;
    } else {
        delete currentFilters.end_date;
    }
    fetchAndUpdateMatches();
}

document.getElementById('ajaxStartDate')?.addEventListener('change', (event) => {
    const start = event.target.value;
    const end = document.getElementById('ajaxEndDate').value;
    updateDateFilters(start, end);
});

document.getElementById('ajaxEndDate')?.addEventListener('change', (event) => {
    const start = document.getElementById('ajaxStartDate').value;
    const end = event.target.value;
    updateDateFilters(start, end);
});

document.getElementById('clearDateFilter')?.addEventListener('click', () => {
    document.getElementById('ajaxStartDate').value = '';
    document.getElementById('ajaxEndDate').value = '';
    delete currentFilters.start_date;
    delete currentFilters.end_date;
    fetchAndUpdateMatches();
});

function updateTempSelected(paramName, checkboxClass) {
    tempSelected[paramName] = [];
    document.querySelectorAll(`.${checkboxClass}:checked`).forEach(cb => {
        tempSelected[paramName].push(cb.value);
    });
}

function applyFilter(paramName) {
    if (tempSelected[paramName] && tempSelected[paramName].length > 0) {
        currentFilters[paramName] = [...tempSelected[paramName]];
    } else {
        delete currentFilters[paramName];
    }
    fetchAndUpdateMatches();
}

function initializeSelect2AndEvents() {
    $('.referee-select').select2({
        placeholder: "-- Select Referee --",
        width: 'resolve',
        dropdownParent: $('body'),
        matcher: refereeMatcher
    });
}

function setupFilterDropdown(toggleId, boxId, optionsId, checkboxClass, paramName, clearId, applyId) {
    const dropdownElement = document.getElementById(boxId)?.closest('.dropdown');
    if (dropdownElement) {
        dropdownElement.addEventListener('show.bs.dropdown', () => {
            tempSelected[paramName] = currentFilters[paramName] ? [...currentFilters[paramName]] : [];

            // Build query params from currentFilters, not the URL
            const params = new URLSearchParams();
            if (currentFilters[paramName] && Array.isArray(currentFilters[paramName])) {
                currentFilters[paramName].forEach(item => params.append(paramName + '[]', item));
            }
            const queryString = params.toString();

            fetch(`/ajax/${paramName}_options.php?${queryString}`)
                .then(res => res.text())
                .then(html => {
                    document.getElementById(optionsId).innerHTML = html;
                    document.querySelectorAll('.' + checkboxClass).forEach(cb => {
                        cb.addEventListener('change', () => {
                            updateTempSelected(paramName, checkboxClass);
                        });
                    });
                });
        });
    }

    document.getElementById(clearId)?.addEventListener('click', () => {
        document.querySelectorAll('.' + checkboxClass).forEach(cb => cb.checked = false);
        tempSelected[paramName] = [];
    });

    document.getElementById(applyId)?.addEventListener('click', () => {
        applyFilter(paramName);
    });
}

// Setup each filter with applyId
setupFilterDropdown('divisionFilterToggle', 'divisionFilterBox', 'divisionFilterOptions', 'division-filter-checkbox', 'division', 'clearDivisionFilter', 'applyDivisionFilter');
setupFilterDropdown('districtFilterToggle', 'districtFilterBox', 'districtFilterOptions', 'district-filter-checkbox', 'district', 'clearDistrictFilter', 'applyDistrictFilter');
setupFilterDropdown('pouleFilterToggle', 'pouleFilterBox', 'pouleFilterOptions', 'poule-filter-checkbox', 'poule', 'clearPouleFilter', 'applyPouleFilter');
setupFilterDropdown('locationFilterToggle', 'locationFilterBox', 'locationFilterOptions', 'location-filter-checkbox', 'location', 'clearLocationFilter', 'applyLocationFilter');
setupFilterDropdown('refereeAssignerFilterToggle', 'refereeAssignerFilterBox', 'refereeAssignerFilterOptions', 'referee-assigner-filter-checkbox', 'referee_assigner', 'clearRefereeAssignerFilter', 'applyRefereeAssignerFilter');