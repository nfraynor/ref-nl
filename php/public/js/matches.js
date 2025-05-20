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
        box.style.display = 'none';
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

// Hide on outside click
document.addEventListener('click', function (e) {
    const toggle = document.getElementById('divisionFilterToggle');
    const box = document.getElementById('divisionFilterBox');
    if (!box.contains(e.target) && !toggle.contains(e.target)) {
        box.style.display = 'none';
    }
});


