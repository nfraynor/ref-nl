// matches.js (Updated: Tom Select + Global Conflict Sweep, no Select2)

let currentFilters = {};
let tempSelected = {};
let suggestedAssignments = {};

// ----- Conflict severity ranking (higher wins) -----
const SEV = { NONE:0, YELLOW:1, ORANGE:2, RED:3, UNAVAILABLE:4 };
const SEV_FROM_TEXT = { none:SEV.NONE, yellow:SEV.YELLOW, orange:SEV.ORANGE, red:SEV.RED };

// Keep Tom Select instances for cleanup
const tomSelectRegistry = new Map(); // key: select element, value: TomSelect instance

// Debounce utility
function debounce(fn, ms=60) {
    let t; return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
}

function tsControlEl(selectEl){
    const inst = selectEl.tomselect;
    return inst ? inst.control : null; // .ts-control
}

function setSelectionSeverity(selectEl, sev){
    const el = tsControlEl(selectEl);
    if (!el) return;
    el.classList.remove('conflict-red','conflict-orange','conflict-yellow','unavailable');
    if (sev === SEV.UNAVAILABLE) el.classList.add('unavailable');
    else if (sev === SEV.RED)    el.classList.add('conflict-red');
    else if (sev === SEV.ORANGE) el.classList.add('conflict-orange');
    else if (sev === SEV.YELLOW) el.classList.add('conflict-yellow');
}

function bump(map, key, sev){
    const cur = map.get(key) ?? SEV.NONE;
    if (sev > cur) map.set(key, sev);
}

function normalizeAddress(s){
    if (!s) return '';
    return String(s)
        .toLowerCase()
        .replace(/[.,;:()\-]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function parseDateTime(dateStr, timeStr){
    // Expect 'YYYY-MM-DD' and 'HH:MM[:SS]'
    const t = (timeStr || '00:00').slice(0,5);
    return new Date(`${dateStr}T${t}:00`);
}

function dayDiff(aYYYYMMDD, bYYYYMMDD){
    const a = new Date(aYYYYMMDD + 'T00:00:00');
    const b = new Date(bYYYYMMDD + 'T00:00:00');
    return Math.round((b - a) / 86400000);
}

// ----- The global sweep -----
function scanAssignmentsAndFlagConflicts(scope=document){
    const sels = Array.from(scope.querySelectorAll('.referee-select'));
    const recs = [];

    // Build records for currently SELECTED refs only
    sels.forEach((sel) => {
        const refId = sel.value;
        const opt  = sel.selectedOptions && sel.selectedOptions[0];

        // always start by clearing
        setSelectionSeverity(sel, SEV.NONE);

        if (!refId) return; // nothing selected, nothing to score

        const baseAvail    = opt ? opt.getAttribute('data-availability') : null;            // 'available' | 'unavailable'
        const baseConflict = opt ? (opt.getAttribute('data-conflict') || 'none') : 'none';  // 'red'|'orange'|'yellow'|'none'

        const matchId   = sel.getAttribute('data-match-id');
        const dateStr   = sel.getAttribute('data-match-date');
        const kickoff   = sel.getAttribute('data-kickoff');
        const role      = sel.getAttribute('data-role');
        const locNorm   = normalizeAddress(sel.getAttribute('data-location-address'));

        const start = parseDateTime(dateStr, kickoff);
        const end   = new Date(start.getTime() + 90*60*1000);

        recs.push({
            sel, refId, matchId, role, dateStr, start, end, locNorm,
            baseAvail, baseConflictSev: SEV_FROM_TEXT[String(baseConflict)] ?? SEV.NONE
        });
    });

    // Map each selection to its current worst severity
    const severity = new Map();
    for (const r of recs) {
        // Base severity from server (availability beats everything)
        if (r.baseAvail === 'unavailable') bump(severity, r.sel, SEV.UNAVAILABLE);
        bump(severity, r.sel, r.baseConflictSev);
    }

    // Group by referee and compute LIVE conflicts across selections
    const byRef = {};
    for (const r of recs) { (byRef[r.refId] ||= []).push(r); }

    for (const refId in byRef) {
        const arr = byRef[refId];
        arr.sort((a,b)=> a.start - b.start);

        // Same match, different role -> RED for all those picks
        const byMatch = {};
        for (const r of arr) (byMatch[r.matchId] ||= []).push(r);
        for (const mid in byMatch) {
            if (byMatch[mid].length > 1) {
                for (const r of byMatch[mid]) bump(severity, r.sel, SEV.RED);
            }
        }

        // Pairwise day/time/venue checks
        for (let i=0;i<arr.length;i++){
            for (let j=i+1;j<arr.length;j++){
                const a = arr[i], b = arr[j];
                const dd = dayDiff(a.dateStr, b.dateStr);

                if (dd === 0) {
                    // overlap -> RED
                    if (a.start < b.end && b.start < a.end) {
                        bump(severity, a.sel, SEV.RED); bump(severity, b.sel, SEV.RED);
                        continue;
                    }
                    // same day, different venues -> RED
                    if (a.locNorm && b.locNorm && a.locNorm !== b.locNorm) {
                        bump(severity, a.sel, SEV.RED); bump(severity, b.sel, SEV.RED);
                        continue;
                    }
                    // same day, same venue, no overlap -> ORANGE
                    bump(severity, a.sel, SEV.ORANGE); bump(severity, b.sel, SEV.ORANGE);
                } else if (Math.abs(dd) <= 2) {
                    // within ±2 days -> YELLOW (unless already worse)
                    bump(severity, a.sel, SEV.YELLOW); bump(severity, b.sel, SEV.YELLOW);
                }
            }
        }
    }

    // Apply classes
    for (const [sel, sev] of severity.entries()) {
        setSelectionSeverity(sel, sev);
    }
}

// ------------- Filters, fetch, and UI orchestration -------------

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
        if (Object.prototype.hasOwnProperty.call(currentFilters, key)) {
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
    if (!params.has('page')) {
        params.set('page', '1'); // Default to page 1 if not set
    }
    const queryString = params.toString();

    if (window.history.pushState) {
        const newUrl = `${window.location.pathname}?${queryString}`;
        window.history.pushState({path: newUrl}, '', newUrl);
    }

    fetch(`/ajax/fetch_matches.php?${queryString}`)
        .then(res => {
            if (!res.ok) throw new Error(`HTTP error! status: ${res.status}`);
            return res.json(); // Expect JSON response
        })
        .then(data => {
            const tableBody = document.getElementById('matchesTableBody');
            const paginationControls = document.getElementById('paginationControls');

            if (tableBody) {
                // Destroy old TomSelect instances before replacing HTML
                destroyTomSelectInScope(tableBody);
                tableBody.innerHTML = data.matchesHtml;
            } else {
                console.error('Error: matchesTableBody element not found.');
            }

            if (paginationControls) {
                paginationControls.innerHTML = data.paginationHtml;
            } else {
                console.error('Error: paginationControls element not found.');
            }

            // Rebuild Tom Select and rescan conflicts, then reapply suggestions
            prepareAssignUI(document);
            reapplySuggestions();

            // If your conflict script updates option data, run it, then rescan
            if (typeof window.fullRefreshConflicts === 'function') {
                window.fullRefreshConflicts();
                scanAssignmentsAndFlagConflicts(document);
            }

            updateActiveFilterIndicators();
        })
        .catch(error => {
            console.error('Error fetching or updating matches:', error);
        });
}

function reapplySuggestions() {
    // Apply suggestions into selects
    for (const matchId in suggestedAssignments) {
        for (const role in suggestedAssignments[matchId]) {
            const refId = suggestedAssignments[matchId][role];
            const select = document.querySelector(`select[name="assignments[${matchId}][${role}]"]`);
            if (select) {
                setSelectValue(select, refId ?? "");
            }
        }
    }
    // Rescan after all changes
    scanAssignmentsAndFlagConflicts(document);
}

function setSelectValue(select, value){
    // Use TomSelect API if present for better sync
    if (select.tomselect) {
        if (value === "" || value === null || typeof value === "undefined") {
            select.tomselect.clear(true);
        } else {
            select.tomselect.setValue(String(value), true);
        }
    } else {
        select.value = value ?? "";
        const event = new Event('change', { bubbles: true });
        select.dispatchEvent(event);
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
            .then(data => { allLocations = data; })
            .catch(error => console.error('Error fetching locations:', error));

        fetch('/ajax/user_options.php')
            .then(response => response.json())
            .then(data => { allUsers = data; })
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
                    if (loc.uuid === currentValue) option.selected = true;
                    selectElement.appendChild(option);
                });
            } else if (fieldType === 'referee_assigner') {
                modalTitle.textContent = 'Edit Referee Assigner';
                allUsers.forEach(user => {
                    let option = document.createElement('option');
                    option.value = user.uuid;
                    option.textContent = user.username;
                    if (user.uuid === currentValue) option.selected = true;
                    selectElement.appendChild(option);
                });
            }
            modalBody.appendChild(selectElement);

            // Enhance modal select with Tom Select (single + search)
            setTimeout(() => {
                new TomSelect('#modalSelectField', { create:false, maxItems:1, plugins:[] });
            }, 0);

            saveButton.dataset.matchUuid = matchUuid;
            saveButton.dataset.fieldType = fieldType;
            saveButton.dataset.cellValueElement = `#matchesTableBody tr td[data-match-uuid='${matchUuid}'][data-field-type='${fieldType}'] span.cell-value`;
            saveButton.dataset.cellElement = `#matchesTableBody tr td[data-match-uuid='${matchUuid}'][data-field-type='${fieldType}']`;

            if (editMatchFieldModalInstance) editMatchFieldModalInstance.show();
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
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
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
                        if (editMatchFieldModalInstance) editMatchFieldModalInstance.hide();
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

    // Prepare Tom Select and run initial sweep
    prepareAssignUI(document);
});

// Re-scan conflicts whenever a selection changes (Tom Select fires native 'change' on <select>)
document.addEventListener('change', debounce((e) => {
    if (e.target && e.target.classList && e.target.classList.contains('referee-select')) {
        scanAssignmentsAndFlagConflicts(document);
    }
}, 60));

function updateActiveFilterIndicators() {
    ['division', 'district', 'poule', 'location', 'referee_assigner'].forEach(paramName => {
        const toggleId = `${paramName}FilterToggle`;
        const toggleBtn = document.getElementById(toggleId);
        if (toggleBtn) {
            const isActive = currentFilters[paramName] && currentFilters[paramName].length > 0;
            if (isActive) toggleBtn.classList.add('filter-active');
            else toggleBtn.classList.remove('filter-active');
        }
    });
}

document.getElementById('clearAssignments')?.addEventListener('click', () => {
    document.querySelectorAll('select.referee-select').forEach(select => {
        if (select.tomselect) {
            select.tomselect.clear(true);
        } else {
            select.value = "";
            const event = new Event('change', { bubbles: true });
            select.dispatchEvent(event);
        }
        // Remove conflict visuals
        setSelectionSeverity(select, SEV.NONE);
    });
    suggestedAssignments = {};

    if (typeof window.fullRefreshConflicts === 'function') {
        window.fullRefreshConflicts();
    }
    // Ensure pills are reset
    scanAssignmentsAndFlagConflicts(document);
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
    progressBar.setAttribute('aria-valuenow', 0);
    progressText.textContent = 'Starting...';

    const params = buildParamsFromCurrentFilters();
    const queryString = params.toString();

    try {
        const response = await fetch(`suggest_assignments.php${queryString ? '?' + queryString : ''}`);
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        const processStream = async () => {
            while (true) {
                const { value, done } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop(); // Keep the last partial line

                for (const line of lines) {
                    if (line.trim() === '') continue;
                    try {
                        const data = JSON.parse(line);

                        // Update progress bar
                        progressBar.style.setProperty('--progress-width', `${data.progress}%`);
                        progressBar.setAttribute('aria-valuenow', data.progress);
                        progressText.textContent = data.message;

                        // Final suggestions payload
                        if (data.progress === 100 && data.suggestions) {
                            for (const matchId in data.suggestions) {
                                if (!suggestedAssignments[matchId]) suggestedAssignments[matchId] = {};
                                const matchSuggestions = data.suggestions[matchId];

                                for (const role in matchSuggestions) {
                                    const refId = matchSuggestions[role];
                                    suggestedAssignments[matchId][role] = refId;

                                    const select = document.querySelector(`select[name="assignments[${matchId}][${role}]"]`);
                                    if (select) {
                                        setSelectValue(select, refId ?? "");
                                    }
                                }
                            }

                            if (typeof window.fullRefreshConflicts === 'function') {
                                window.fullRefreshConflicts();
                            }
                            scanAssignmentsAndFlagConflicts(document);
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
        setTimeout(() => {
            progressBarContainer.style.display = 'none';
            progressText.style.display = 'none';
            progressBar.classList.remove('bg-danger');
        }, 3000);
    }
});

// Handle date filter box
function updateDateFilters(startDate, endDate) {
    if (startDate) currentFilters.start_date = startDate;
    else delete currentFilters.start_date;

    if (endDate) currentFilters.end_date = endDate;
    else delete currentFilters.end_date;

    fetchAndUpdateMatches();
}

document.addEventListener('click', function(event) {
    // Check if a pagination link was clicked
    const link = event.target.closest('.page-link');
    if (link && link.dataset.page) {
        event.preventDefault();
        const page = link.dataset.page;
        currentFilters.page = page;
        fetchAndUpdateMatches();
    }
});

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

// Initialize Tom Select on referee selects (checkbox multi + search if multi; single otherwise)
function initRefereeTom(scope=document){
    const sels = Array.from(scope.querySelectorAll('.referee-select'));
    if (!sels.length) return;

    sels.forEach((sel) => {
        if (sel.tomselect) return; // already initialized

        const isMulti = sel.hasAttribute('multiple');
        const plugins = [];

        // For multi-selects, show checkboxes and remove buttons
        if (isMulti) {
            plugins.push('checkbox_options', 'remove_button');
        }

        const ts = new TomSelect(sel, {
            create: false,
            maxItems: isMulti ? null : 1,
            searchField: ['text', 'value'],
            persist: false,
            plugins
        });

        tomSelectRegistry.set(sel, ts);

        // When Tom Select changes, ensure conflict rescans
        sel.addEventListener('change', debounce(() => scanAssignmentsAndFlagConflicts(document), 60));
    });
}

function destroyTomSelectInScope(scope=document){
    const sels = Array.from(scope.querySelectorAll('.referee-select'));
    sels.forEach((sel) => {
        if (sel.tomselect) {
            try { sel.tomselect.destroy(); } catch(e) {}
        }
        tomSelectRegistry.delete(sel);
    });
}

// Orchestrator: init Tom Select then sweep
const prepareAssignUI = (scope=document) => {
    initRefereeTom(scope);
    // next tick guarantees TS controls exist
    setTimeout(() => scanAssignmentsAndFlagConflicts(scope), 0);
};

// Setup each filter with applyId (unchanged; still checkbox dropdowns via AJAX)
setupFilterDropdown('divisionFilterToggle', 'divisionFilterBox', 'divisionFilterOptions', 'division-filter-checkbox', 'division', 'clearDivisionFilter', 'applyDivisionFilter');
setupFilterDropdown('districtFilterToggle', 'districtFilterBox', 'districtFilterOptions', 'district-filter-checkbox', 'district', 'clearDistrictFilter', 'applyDistrictFilter');
setupFilterDropdown('pouleFilterToggle', 'pouleFilterBox', 'pouleFilterOptions', 'poule-filter-checkbox', 'poule', 'clearPouleFilter', 'applyPouleFilter');
setupFilterDropdown('locationFilterToggle', 'locationFilterBox', 'locationFilterOptions', 'location-filter-checkbox', 'location', 'clearLocationFilter', 'applyLocationFilter');
setupFilterDropdown('refereeAssignerFilterToggle', 'refereeAssignerFilterBox', 'refereeAssignerFilterOptions', 'referee-assigner-filter-checkbox', 'referee_assigner', 'clearRefereeAssignerFilter', 'applyRefereeAssignerFilter');
