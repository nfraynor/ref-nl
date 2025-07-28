$(document).ready(function () {
    // Store filter state per dropdown
    let filterStates = {};
    let currentSelectEl = null;

    // Default filter settings
    const defaultFilters = {
        grades: ['A', 'B', 'C', 'D', 'E'],
        availability: 'available'
    };

    // Custom matcher that filters based on grade and availability
    function refereeMatcher(params, data, selectName) {
        if (!data.id) {
            console.log(`Skipping optgroup for ${selectName}`);
            return null; // Skip optgroup
        }

        const filters = filterStates[selectName] || defaultFilters;
        const grade = $(data.element).attr('data-grade') || 'N/A';
        const availability = $(data.element).attr('data-availability') || 'available';

        const matchesGrade = filters.grades.length === 0 || filters.grades.includes(grade);
        const matchesAvailability = !filters.availability || availability === filters.availability;
        const matchesSearch = !params.term || data.text.toLowerCase().includes(params.term.toLowerCase());

        console.log(`Matcher for ${data.text} in ${selectName}: grade=${grade}, availability=${availability}, filters=`, filters, `matchesGrade=${matchesGrade}, matchesAvailability=${matchesAvailability}, matchesSearch=${matchesSearch}`);

        return matchesGrade && matchesAvailability && matchesSearch ? data : null;
    }

    // Initialize all referee selects
    function initializeSelect2AndEvents() {
        $('.referee-select').each(function () {
            const selectEl   = this;           // <select>
            const selectName = selectEl.name;  // cache the name once

            $(selectEl)
                .select2({
                    placeholder: "-- Select Referee --",
                    width: 'resolve',
                    dropdownParent: $('body'),
                    matcher: function (params, data) {
                        // uses the cached name instead of this.name (faster & correct)
                        return refereeMatcher(params, data, selectName);
                    }
                })

                .on('select2:open', function () {
                    console.log('Select2 opened for:', selectName);
                    currentSelectEl = selectEl;      // keep a reference to this <select>

                    // --------------------------------------------------------------
                    // initialise / fetch saved filters for this dropdown
                    // --------------------------------------------------------------
                    if (!filterStates[selectName]) {
                        filterStates[selectName] = { ...defaultFilters };
                        console.log(`Initialized filters for ${selectName}:`, filterStates[selectName]);
                    } else {
                        console.log(`Existing filters for ${selectName}:`, filterStates[selectName]);
                    }

                    const $dropdown  = $('.select2-dropdown');
                    $dropdown.find('.dropdown-filters').remove();   // remove any previous UI

                    const selectId   = selectEl.id || `select-${Math.random().toString(36).substr(2, 9)}`;
                    const filters    = filterStates[selectName];

                    // build the filter UI
                    const $filterContainer = $(`
                <div class="dropdown-filters p-2 border-bottom" data-select-id="${selectId}">
                    <label><strong>Grade:</strong></label><br/>
                    ${['A','B','C','D','E'].map(g =>
                        `<label><input type="checkbox" class="grade-filter"
                                       value="${g}"
                                       ${filters.grades.includes(g) ? 'checked' : ''}
                                       data-select-id="${selectId}"> ${g}</label>`
                    ).join('')}
                    <br/>
                    <label><strong>Availability:</strong></label><br/>
                    ${['','available','unavailable'].map(v =>
                        `<label><input type="radio"
                                       name="availability_${selectId}"
                                       class="availability-filter"
                                       value="${v}"
                                       ${filters.availability === v ? 'checked' : ''}
                                       data-select-id="${selectId}"> ${v ? v.charAt(0).toUpperCase()+v.slice(1) : 'All'}</label>`
                    ).join('')}
                </div>
            `);

                    $dropdown.prepend($filterContainer);

                    // --------------------------------------------------------------
                    // change-handlers for the dynamic filters
                    // --------------------------------------------------------------
                    $filterContainer.on('change', '.grade-filter', function () {
                        filterStates[selectName].grades = $filterContainer
                            .find('.grade-filter:checked')
                            .map(function () { return this.value; })
                            .get();
                        console.log(`Updated grades for ${selectName}:`, filterStates[selectName]);

                        const $searchInput = $(selectEl).data('select2').dropdown.$search;
                        $searchInput.val($searchInput.val()).trigger('input'); // re-run matcher
                    });

                    $filterContainer.on('change', '.availability-filter', function () {
                        filterStates[selectName].availability = $filterContainer
                            .find('.availability-filter:checked')
                            .val() || '';
                        console.log(`Updated availability for ${selectName}:`, filterStates[selectName]);

                        const $searchInput = $(selectEl).data('select2').dropdown.$search;
                        $searchInput.val($searchInput.val()).trigger('input'); // re-run matcher
                    });
                })

                .on('select2:select', function () {
                    console.log('Select2 selected value:', this.value, 'for:', selectName);
                });
        });

        // Rebind conflict detection
        $(document).off('change', 'select.referee-select').on('change', 'select.referee-select', function () {
            const selectedRef = this.value;
            console.log(`Trigger Conflict check for referee: ${selectedRef}`);
            window.refreshConflicts(this);
        });

        // Debug Select2 options
        $('.referee-select').each(function() {
            const options = this.querySelectorAll('option[value]:not([value=""])');
            console.log(`Select ${this.name} has ${options.length} options:`, Array.from(options).map(opt => ({
                text: opt.textContent,
                grade: opt.getAttribute('data-grade'),
                availability: opt.getAttribute('data-availability')
            })));
        });

        // Refresh conflicts on load for pre-filled refs
        $('select.referee-select').each(function () {
            if (this.value) {
                window.refreshConflicts(this);
            }
        });
    }

    window.initializeSelect2AndEvents = initializeSelect2AndEvents;

    initializeSelect2AndEvents();
});