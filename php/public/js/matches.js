/* Referee-App /matches.js – updated 2025-07-31
 * - Streamlined filter handling with search functionality
 * - Maintains all original functionality
 * - Improved visual consistency with Tailwind CSS
 */

let currentFilters = {};

function initializeCurrentFiltersFromURL() {
    const params = new URLSearchParams(window.location.search);
    for (const [key, value] of params.entries()) {
        if (key.endsWith('[]')) {
            const cleanKey = key.slice(0, -2);
            (currentFilters[cleanKey] ??= []).push(value);
        } else {
            currentFilters[key] = value;
        }
    }
}

function buildParamsFromCurrentFilters() {
    const params = new URLSearchParams();
    for (const key in currentFilters) {
        if (!Object.prototype.hasOwnProperty.call(currentFilters, key)) continue;
        const value = currentFilters[key];
        if (Array.isArray(value)) {
            value.forEach(v => params.append(key + '[]', v));
        } else if (value !== '' && value != null) {
            params.set(key, value);
        }
    }
    return params;
}

function fetchAndUpdateMatches() {
    const qs = buildParamsFromCurrentFilters().toString();
    const newUrl = `${location.protocol}//${location.host}${location.pathname}${qs ? '?' + qs : ''}`;
    history.pushState?.({ path: newUrl }, '', newUrl);

    fetch(`/ajax/fetch_matches.php?${qs}`)
        .then(r => r.ok ? r.json() : Promise.reject(r.statusText))
        .then(data => {
            document.getElementById('matchesTableBody').innerHTML = data.matchesHtml;
            document.getElementById('paginationControls').innerHTML = data.paginationHtml;
            initializeSelect2AndEvents();
        })
        .catch(err => {
            console.error('Update failed:', err);
            document.getElementById('matchesTableBody').innerHTML = '<tr><td colspan="13" class="text-center text-danger">Failed to load matches. Please try again.</td></tr>';
        });
}

function setupPaginationHandler() {
    document.getElementById('paginationControls')?.addEventListener('click', e => {
        const link = e.target.closest('a.page-link');
        if (!link?.dataset.page) return;
        e.preventDefault();
        currentFilters.page = +link.dataset.page;
        fetchAndUpdateMatches();
    });
}

/* ---- Filter Search Functionality ---- */
function setupFilterSearch(filterId, optionsId) {
    const searchInput = document.getElementById(`${filterId}Search`);
    searchInput?.addEventListener('input', () => {
        const term = searchInput.value.toLowerCase();
        const options = document.querySelectorAll(`#${optionsId} .filter-checkbox`);
        options.forEach(opt => {
            const text = opt.parentElement.textContent.toLowerCase();
            opt.parentElement.style.display = text.includes(term) ? '' : 'none';
        });
    });
}

/* ---- Filter Dropdown Helpers ---- */
function loadFilterOptions(type, optionsId, paramName) {
    const selected = new URLSearchParams(location.search).getAll(paramName + '[]');
    fetch(`/ajax/${type}_options.php?` + new URLSearchParams({ [paramName + '[]']: selected }))
        .then(r => r.text())
        .then(html => {
            document.getElementById(optionsId).innerHTML = html;
            setupFilterSearch(`${type}Filter`, optionsId);
        });
}

function applyMultiFilter(paramName, checkboxClass) {
    const selected = Array.from(document.querySelectorAll(`.${checkboxClass}:checked`), cb => cb.value);
    selected.length ? currentFilters[paramName] = selected : delete currentFilters[paramName];
    fetchAndUpdateMatches();
}

function setupFilter(name, toggleId, optionsId, checkboxClass, clearId, applyId) {
    const toggle = document.getElementById(toggleId);
    toggle?.addEventListener('click', () => loadFilterOptions(name, optionsId, name));

    document.getElementById(clearId)?.addEventListener('click', () => {
        document.querySelectorAll(`.${checkboxClass}`).forEach(cb => cb.checked = false);
        delete currentFilters[name];
        fetchAndUpdateMatches();
    });

    document.getElementById(applyId)?.addEventListener('click', () => {
        applyMultiFilter(name, checkboxClass);
        bootstrap.Dropdown.getOrCreateInstance(toggle).hide();
    });
}

/* ---- DOMContentLoaded Bootstrap ---- */
document.addEventListener('DOMContentLoaded', () => {
    initializeCurrentFiltersFromURL();
    setupPaginationHandler();

    /* ---- Modal Edit Helpers ---- */
    let allLocations = [], allUsers = [];
    const editModal = new bootstrap.Modal('#editMatchFieldModal', {});

    fetch('/ajax/location_options.php').then(r => r.json()).then(d => allLocations = d);
    fetch('/ajax/user_options.php').then(r => r.json()).then(d => allUsers = d);

    document.body.addEventListener('click', e => {
        /* open modal */
        if (e.target.classList.contains('edit-icon')) {
            const cell = e.target.closest('.editable-cell');
            const { matchUuid, fieldType, currentValue } = cell.dataset;
            const body = document.getElementById('editMatchFieldModalBody');
            const title = document.getElementById('editMatchFieldModalLabel');
            const save = document.getElementById('saveMatchFieldChange');

            body.innerHTML = '';
            const sel = document.createElement('select');
            sel.className = 'form-select';
            sel.id = 'modalSelectField';
            sel.innerHTML = `<option value="">-- Select ${fieldType.replace('_',' ')} --</option>`;

            (fieldType === 'location' ? allLocations : allUsers).forEach(opt => {
                const o = document.createElement('option');
                o.value = opt.uuid;
                o.textContent = fieldType === 'location' ? `${opt.name} (${opt.address_text})` : opt.username;
                if (opt.uuid === currentValue) o.selected = true;
                sel.appendChild(o);
            });

            title.textContent = fieldType === 'location' ? 'Edit Match Location' : 'Edit Referee Assigner';
            body.appendChild(sel);

            Object.assign(save.dataset, {
                matchUuid,
                fieldType,
                cellValueSelector: `#matchesTableBody td[data-match-uuid='${matchUuid}'][data-field-type='${fieldType}'] span.cell-value`,
                cellSelector: `#matchesTableBody td[data-match-uuid='${matchUuid}][data-field-type='${fieldType}']`
            });
            editModal.show();
        }

        /* save modal */
        if (e.target.id === 'saveMatchFieldChange') {
            const { matchUuid, fieldType, cellValueSelector, cellSelector } = e.target.dataset;
            const newValue = document.getElementById('modalSelectField').value;

            fetch('/ajax/update_match_field.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ match_uuid: matchUuid, field_type: fieldType, new_value: newValue })
            })
                .then(r => r.json())
                .then(d => {
                    if (!d.success) throw new Error(d.message);
                    document.querySelector(cellValueSelector).innerHTML = d.newValueDisplay;
                    document.querySelector(cellSelector).dataset.currentValue = newValue;
                    editModal.hide();
                })
                .catch(err => alert(`Error updating: ${err.message}`));
        }
    });

    /* ---- Assignment Helper Buttons ---- */
    document.getElementById('clearAssignments')?.addEventListener('click', () => {
        document.querySelectorAll('select.referee-select').forEach(s => {
            s.value = '';
            s.nextElementSibling?.querySelector('.select2-selection')?.removeAttribute('style');
            s.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });

    document.getElementById('suggestAssignments')?.addEventListener('click', e => {
        const btn = e.target;
        const txt = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Suggesting…';

        fetch('suggest_assignments.php')
            .then(r => r.json())
            .then(data => {
                for (const mId in data) for (const role in data[mId]) {
                    const sel = document.querySelector(`select[name="assignments[${mId}][${role}]"]`);
                    if (sel) {
                        sel.value = data[mId][role] ?? '';
                        sel.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
            })
            .catch(err => alert('Error fetching suggestions: ' + err))
            .finally(() => {
                btn.disabled = false;
                btn.textContent = txt;
            });
    });

    /* ---- Date Filter ---- */
    function updateDateFilters() {
        const s = document.getElementById('ajaxStartDate').value;
        const e = document.getElementById('ajaxEndDate').value;
        s ? currentFilters.start_date = s : delete currentFilters.start_date;
        e ? currentFilters.end_date = e : delete currentFilters.end_date;
        fetchAndUpdateMatches();
    }

    document.getElementById('ajaxStartDate')?.addEventListener('change', updateDateFilters);
    document.getElementById('ajaxEndDate')?.addEventListener('change', updateDateFilters);
    document.getElementById('clearDateFilter')?.addEventListener('click', () => {
        document.getElementById('ajaxStartDate').value = '';
        document.getElementById('ajaxEndDate').value = '';
        updateDateFilters();
    });

    /* ---- Setup Filters ---- */
    setupFilter('division', 'divisionFilterToggle', 'divisionFilterOptions', 'division-filter-checkbox', 'clearDivisionFilter', 'applyDivisionFilter');
    setupFilter('district', 'districtFilterToggle', 'districtFilterOptions', 'district-filter-checkbox', 'clearDistrictFilter', 'applyDistrictFilter');
    setupFilter('poule', 'pouleFilterToggle', 'pouleFilterOptions', 'poule-filter-checkbox', 'clearPouleFilter', 'applyPouleFilter');
    setupFilter('location', 'locationFilterToggle', 'locationFilterOptions', 'location-filter-checkbox', 'clearLocationFilter', 'applyLocationFilter');
    setupFilter('referee_assigner', 'refereeAssignerFilterToggle', 'refereeAssignerFilterOptions', 'referee-assigner-filter-checkbox', 'clearRefereeAssignerFilter', 'applyRefereeAssignerFilter');

    /* ---- Select2 Init ---- */
    function initializeSelect2AndEvents() {
        $('.referee-select').select2({
            placeholder: '-- Select Referee --',
            width: 'resolve',
            dropdownParent: $('body')
        });
    }

    /* ---- Sticky-Header Dropdown Fix ---- */
    let stickyDropdownParent = null;
    document.addEventListener('shown.bs.dropdown', e => {
        const toggle = e.target;
        const menu = toggle.parentElement.querySelector('.dropdown-menu');
        if (!menu) return;
        stickyDropdownParent = toggle.parentElement;
        menu.classList.add('bs-table-dropdown');
        document.body.appendChild(menu);
        const r = toggle.getBoundingClientRect();
        Object.assign(menu.style, { position: 'absolute', top: `${r.bottom + scrollY}px`, left: `${r.left + scrollX}px` });
    });
    document.addEventListener('hide.bs.dropdown', () => {
        const menu = document.querySelector('.bs-table-dropdown');
        if (menu && stickyDropdownParent) stickyDropdownParent.appendChild(menu);
        menu?.removeAttribute('style');
        menu?.classList.remove('bs-table-dropdown');
        stickyDropdownParent = null;
    });
});