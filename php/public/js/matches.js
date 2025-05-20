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
        });
    initializeSelect2AndEvents();
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
