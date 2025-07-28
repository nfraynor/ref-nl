let currentFilters = {};

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
            } else {
                console.error('Error: matchesTableBody element not found.');
            }
        })
        .catch(error => {
            console.error('Error fetching or updating matches:', error);
        });
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
    });

    document.getElementById('suggestAssignments')?.addEventListener('click', (event) => {
        const suggestButton = event.target;
        const originalButtonText = suggestButton.textContent;
        suggestButton.disabled = true;
        suggestButton.textContent = 'Suggesting...';

        fetch('suggest_assignments.php')
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
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
            })
            .catch(error => {
                console.error('Error fetching suggestions:', error);
                alert('Error fetching suggestions. Please check the console for details or try again.');
            })
            .finally(() => {
                suggestButton.disabled = false;
                suggestButton.textContent = originalButtonText;
            });
    });

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

    function loadDivisionFilterOptions() {
        const selectedDivisions = new URLSearchParams(window.location.search).getAll('division[]');
        fetch('/ajax/division_options.php?' + new URLSearchParams({ 'division[]': selectedDivisions }))
            .then(res => res.text())
            .then(html => {
                document.getElementById('divisionFilterOptions').innerHTML = html;

                document.querySelectorAll('.division-filter-checkbox').forEach(box => {
                    box.addEventListener('change', () => {
                        applyDivisionFilter();
                    });
                });
            });
    }

    function applyDivisionFilter() {
        const selectedDivisions = [];
        document.querySelectorAll('.division-filter-checkbox:checked').forEach(cb => {
            selectedDivisions.push(cb.value);
        });

        if (selectedDivisions.length > 0) {
            currentFilters.division = selectedDivisions;
        } else {
            delete currentFilters.division;
        }
        fetchAndUpdateMatches();
    }

    document.getElementById('divisionFilterToggle')?.addEventListener('click', () => {
        const box = document.getElementById('divisionFilterBox');
        box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'block' : 'none';
        loadDivisionFilterOptions();
    });

    document.getElementById('clearDivisionFilter')?.addEventListener('click', () => {
        document.querySelectorAll('.division-filter-checkbox').forEach(cb => cb.checked = false);
        delete currentFilters.division;
        fetchAndUpdateMatches();
    });

    document.addEventListener('click', function (e) {
        const filters = [
            { toggleId: 'divisionFilterToggle', boxId: 'divisionFilterBox' },
            { toggleId: 'districtFilterToggle', boxId: 'districtFilterBox' },
            { toggleId: 'pouleFilterToggle', boxId: 'pouleFilterBox' },
            { toggleId: 'locationFilterToggle', boxId: 'locationFilterBox' },
            { toggleId: 'refereeAssignerFilterToggle', boxId: 'refereeAssignerFilterBox' }
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
        const selectedValues = [];
        document.querySelectorAll(`.${checkboxClass}:checked`).forEach(cb => {
            selectedValues.push(cb.value);
        });

        if (selectedValues.length > 0) {
            currentFilters[paramName] = selectedValues;
        } else {
            delete currentFilters[paramName];
        }
        fetchAndUpdateMatches();
    }

    function loadFilterOptions(type, targetBoxId, targetHtmlId, checkboxClass, paramName) {
        const selected = new URLSearchParams(window.location.search).getAll(paramName + '[]');

        const box = document.getElementById(targetBoxId);
        box.style.display = 'block';

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
        console.log('Initializing Select2 for .referee-select elements');
        const selectElements = document.querySelectorAll('.referee-select');
        console.log(`Found ${selectElements.length} referee-select elements`);

        $('.referee-select').select2({
            placeholder: "-- Select Referee --",
            width: 'resolve',
            dropdownParent: $('body'),
            matcher: function(params, data) {
                if (!data.id) return null; // Skip optgroup

                const grade = $(data.element).attr('data-grade');
                const availability = $(data.element).attr('data-availability');
                const matchesGrade = window.currentFilters.grades.length === 0 || window.currentFilters.grades.includes(grade);
                const matchesAvailability = !window.currentFilters.availability || availability === window.currentFilters.availability;
                const matchesSearch = !params.term || data.text.toLowerCase().includes(params.term.toLowerCase());

                console.log(`Matcher for ${data.text}: grade=${grade}, availability=${availability}, matchesGrade=${matchesGrade}, matchesAvailability=${matchesAvailability}, matchesSearch=${matchesSearch}`);

                if (matchesGrade && matchesAvailability && matchesSearch) {
                    return data;
                }
                return null;
            }
        }).on('select2:open', function() {
            console.log('Select2 opened for:', this.name);
            const options = this.querySelectorAll('option[value]:not([value=""])');
            console.log(`Options for ${this.name}:`, Array.from(options).map(opt => ({
                text: opt.textContent,
                grade: opt.getAttribute('data-grade'),
                availability: opt.getAttribute('data-availability')
            })));
        }).on('select2:select', function() {
            console.log('Select2 selected value:', this.value, 'for:', this.name);
        });

        // Debug Select2 options
        selectElements.forEach(select => {
            const options = select.querySelectorAll('option[value]:not([value=""])');
            console.log(`Select ${select.name} has ${options.length} options:`, Array.from(options).map(opt => ({
                text: opt.textContent,
                grade: opt.getAttribute('data-grade'),
                availability: opt.getAttribute('data-availability')
            })));
        });
    }

    document.getElementById('districtFilterToggle')?.addEventListener('click', () => {
        loadFilterOptions('district', 'districtFilterBox', 'districtFilterOptions', 'district-filter-checkbox', 'district');
    });
    document.getElementById('clearDistrictFilter')?.addEventListener('click', () => {
        document.querySelectorAll('.district-filter-checkbox').forEach(cb => cb.checked = false);
        delete currentFilters.district;
        fetchAndUpdateMatches();
    });

    document.getElementById('pouleFilterToggle')?.addEventListener('click', () => {
        loadFilterOptions('poule', 'pouleFilterBox', 'pouleFilterOptions', 'poule-filter-checkbox', 'poule');
    });
    document.getElementById('clearPouleFilter')?.addEventListener('click', () => {
        document.querySelectorAll('.poule-filter-checkbox').forEach(cb => cb.checked = false);
        delete currentFilters.poule;
        fetchAndUpdateMatches();
    });

    document.getElementById('locationFilterToggle')?.addEventListener('click', () => {
        loadFilterOptions('location_filter', 'locationFilterBox', 'locationFilterOptions', 'location-filter-checkbox', 'location');
    });
    document.getElementById('clearLocationFilter')?.addEventListener('click', () => {
        document.querySelectorAll('.location-filter-checkbox').forEach(cb => cb.checked = false);
        delete currentFilters.location;
        fetchAndUpdateMatches();
    });

    document.getElementById('refereeAssignerFilterToggle')?.addEventListener('click', () => {
        loadFilterOptions('referee_assigner', 'refereeAssignerFilterBox', 'refereeAssignerFilterOptions', 'referee-assigner-filter-checkbox', 'referee_assigner');
    });
    document.getElementById('clearRefereeAssignerFilter')?.addEventListener('click', () => {
        document.querySelectorAll('.referee-assigner-filter-checkbox').forEach(cb => cb.checked = false);
        delete currentFilters.referee_assigner;
        fetchAndUpdateMatches();
    });
});