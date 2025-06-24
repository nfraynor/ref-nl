// Clear all dropdowns
document.getElementById('clearAssignments')?.addEventListener('click', () => {
    document.querySelectorAll('select').forEach(select => {
        select.value = "";
        const event = new Event('change', { bubbles: true });
        select.dispatchEvent(event);
    });
});

// Suggest assignments
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
                        const event = new Event('change', { bubbles: true });
                        select.dispatchEvent(event);
                    }
                }
            }
        });
});

// Toggle date filter box
const toggle = document.getElementById('dateFilterToggle');
const box = document.getElementById('dateFilterBox');

document.addEventListener('click', function (event) {
    if (toggle?.contains(event.target)) {
        box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'block' : 'none';
    } else if (!box?.contains(event.target)) {
        if (box) {
            box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'block' : 'none';
        }
    }
});

// Handle all the date stuff
function fetchMatchesWithDates(startDate, endDate) {
    const params = new URLSearchParams({
        start_date: startDate,
        end_date: endDate,
        assign_mode: new URLSearchParams(window.location.search).get("assign_mode") || ''
    });

    fetch(`/ajax/fetch_matches.php?${params.toString()}`)
        .then(res => res.text())
        .then(html => {
            document.getElementById('matchesTableBody').innerHTML = html;
            initializeSelect2AndEvents(); // ← add this here
        });
}

document.getElementById('ajaxStartDate')?.addEventListener('change', () => {
    const start = document.getElementById('ajaxStartDate').value;
    const end = document.getElementById('ajaxEndDate').value;
    fetchMatchesWithDates(start, end);

});

document.getElementById('ajaxEndDate')?.addEventListener('change', () => {
    const start = document.getElementById('ajaxStartDate').value;
    const end = document.getElementById('ajaxEndDate').value;
    fetchMatchesWithDates(start, end);
});

document.getElementById('clearDateFilter')?.addEventListener('click', () => {
    document.getElementById('ajaxStartDate').value = '';
    document.getElementById('ajaxEndDate').value = '';
    fetchMatchesWithDates('', '');
});

//Handle the division filters

function loadDivisionFilterOptions() {
    const selectedDivisions = new URLSearchParams(window.location.search).getAll('division[]');
    fetch('/ajax/division_options.php?' + new URLSearchParams({ 'division[]': selectedDivisions }))
        .then(res => res.text())
        .then(html => {
            document.getElementById('divisionFilterOptions').innerHTML = html;

            // Hook into checkbox changes
            document.querySelectorAll('.division-filter-checkbox').forEach(box => {
                box.addEventListener('change', () => {
                    applyDivisionFilter();
                });
            });
        });
}

function applyDivisionFilter() {
    const params = new URLSearchParams(window.location.search);
    params.delete('division[]'); // reset before repopulating

    document.querySelectorAll('.division-filter-checkbox:checked').forEach(cb => {
        params.append('division[]', cb.value);
    });

    fetch('/ajax/fetch_matches.php?' + params.toString())
        .then(res => res.text())
        .then(html => {
            document.getElementById('matchesTableBody').innerHTML = html;
            initializeSelect2AndEvents(); // ← add this here
        });
}

document.getElementById('divisionFilterToggle')?.addEventListener('click', () => {
    const box = document.getElementById('divisionFilterBox');
    box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'block' : 'none';
    loadDivisionFilterOptions();
});

document.getElementById('clearDivisionFilter')?.addEventListener('click', () => {
    document.querySelectorAll('.division-filter-checkbox').forEach(cb => cb.checked = false);
    applyDivisionFilter();
});

document.addEventListener('click', function (e) {
    const filters = [
        { toggleId: 'divisionFilterToggle', boxId: 'divisionFilterBox' },
        { toggleId: 'districtFilterToggle', boxId: 'districtFilterBox' },
        { toggleId: 'pouleFilterToggle', boxId: 'pouleFilterBox' }
    ];

    filters.forEach(({ toggleId, boxId }) => {
        const toggle = document.getElementById(toggleId);
        const box = document.getElementById(boxId);
        if (box && toggle && !box.contains(e.target) && !toggle.contains(e.target)) {
            box.style.display = 'none';
        }
    });
});


function applyMultiFilter(paramName, checkboxClass) {
    const params = new URLSearchParams(window.location.search);
    params.delete(paramName + '[]');

    document.querySelectorAll(`.${checkboxClass}:checked`).forEach(cb => {
        params.append(paramName + '[]', cb.value);
    });

    fetch('/ajax/fetch_matches.php?' + params.toString())
        .then(res => res.text())
        .then(html => {
            document.getElementById('matchesTableBody').innerHTML = html;
            initializeSelect2AndEvents();

        });
}

function loadFilterOptions(type, targetBoxId, targetHtmlId, checkboxClass, paramName) {
    const selected = new URLSearchParams(window.location.search).getAll(paramName + '[]');

    const box = document.getElementById(targetBoxId);
    box.style.display = 'block'; // ✅ always open the box on toggle

    fetch(`/ajax/${type}_options.php?${new URLSearchParams({ [paramName + '[]']: selected })}`)
        .then(res => res.text())
        .then(html => {
            document.getElementById(targetHtmlId).innerHTML = html;

            document.querySelectorAll('.' + checkboxClass).forEach(cb => {
                cb.addEventListener('change', () => {
                    applyMultiFilter(paramName, checkboxClass);
                });
            });
        });
}
function initializeSelect2AndEvents() {
    $('.referee-select').select2({
        placeholder: "-- Select Referee --",
        width: 'resolve',
        dropdownParent: $('body'),
        matcher: refereeMatcher
    });
}

document.getElementById('districtFilterToggle')?.addEventListener('click', () => {
    loadFilterOptions('district', 'districtFilterBox', 'districtFilterOptions', 'district-filter-checkbox', 'district');
});
document.getElementById('clearDistrictFilter')?.addEventListener('click', () => {
    document.querySelectorAll('.district-filter-checkbox').forEach(cb => cb.checked = false);
    applyMultiFilter('district', 'district-filter-checkbox');
});

document.getElementById('pouleFilterToggle')?.addEventListener('click', () => {
    loadFilterOptions('poule', 'pouleFilterBox', 'pouleFilterOptions', 'poule-filter-checkbox', 'poule');
});
document.getElementById('clearPouleFilter')?.addEventListener('click', () => {
    document.querySelectorAll('.poule-filter-checkbox').forEach(cb => cb.checked = false);
    applyMultiFilter('poule', 'poule-filter-checkbox');
});

// --- Inline Editing Functionality ---
document.addEventListener('DOMContentLoaded', () => {
    let allLocations = [];
    let allUsers = [];
    let editMatchFieldModalInstance = null;

    // Get modal instance
    const modalElement = document.getElementById('editMatchFieldModal');
    if (modalElement) {
        editMatchFieldModalInstance = new bootstrap.Modal(modalElement);
    }

    // Fetch initial data for dropdowns
    function fetchSelectData() {
        fetch('/ajax/location_options.php') // Assuming a new endpoint that returns all locations as JSON
            .then(response => response.json())
            .then(data => {
                allLocations = data;
            })
            .catch(error => console.error('Error fetching locations:', error));

        fetch('/ajax/user_options.php') // Assuming a new endpoint that returns all users as JSON
            .then(response => response.json())
            .then(data => {
                allUsers = data;
            })
            .catch(error => console.error('Error fetching users:', error));
    }

    // Call fetchSelectData on page load
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

            modalBody.innerHTML = ''; // Clear previous content
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

            // Store context on save button
            saveButton.dataset.matchUuid = matchUuid;
            saveButton.dataset.fieldType = fieldType;
            saveButton.dataset.cellValueElement = `#matchesTableBody tr td[data-match-uuid='${matchUuid}'][data-field-type='${fieldType}'] span.cell-value`;
            saveButton.dataset.cellElement = `#matchesTableBody tr td[data-match-uuid='${matchUuid}'][data-field-type='${fieldType}']`;


            if(editMatchFieldModalInstance) {
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
                        cellValueElement.innerHTML = data.newValueDisplay; // Update display text
                        cellElement.dataset.currentValue = newValue; // Update current value for next edit
                    }
                    if(editMatchFieldModalInstance) {
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
});
