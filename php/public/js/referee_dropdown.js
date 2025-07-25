$(document).ready(function () {
    let currentFilters = {
        grades: ['A', 'B', 'C', 'D', 'E'],
        availability: 'available'
    };

    // Custom matcher that filters based on grade and availability
    function refereeMatcher(params, data) {
        if (!data.id) return null; // skip optgroup

        const grade = $(data.element).attr('data-grade');
        const availability = $(data.element).attr('data-availability');

        const matchesGrade = currentFilters.grades.length === 0 || currentFilters.grades.includes(grade);
        const matchesAvailability = !currentFilters.availability || availability === currentFilters.availability;

        const matchesSearch = params.term == null || data.text.toLowerCase().includes(params.term.toLowerCase());

        if (matchesGrade && matchesAvailability && matchesSearch) {
            return data;
        }

        return null;
    }

    // Initialize all referee selects
    function initializeSelect2AndEvents() {
        $('.referee-select').select2({
            placeholder: "-- Select Referee --",
            width: 'style',
            dropdownParent: $('body'),
            matcher: refereeMatcher
        });

        // Rebind conflict detection
        $(document).off('change', 'select.referee-select').on('change', 'select.referee-select', function () {
            const selectedRef = this.value;
            console.log(`Trigger Conflict check for referee: ${selectedRef}`);
            refreshConflicts(this);
        });

        // Rebind select2:open event to inject filters
        $(document).off('select2:open').on('select2:open', '.referee-select', function () {
            currentSelectEl = this;
            const $dropdown = $('.select2-dropdown');

            if ($dropdown.find('.dropdown-filters').length > 0) return;

            const $filterContainer = $(`
            <div class="dropdown-filters p-2 border-bottom">
                <label><strong>Grade:</strong></label><br/>
                <label><input type="checkbox" class="grade-filter" value="A" checked> A</label>
                <label><input type="checkbox" class="grade-filter" value="B" checked> B</label>
                <label><input type="checkbox" class="grade-filter" value="C" checked> C</label>
                <label><input type="checkbox" class="grade-filter" value="D" checked> D</label>
                <label><input type="checkbox" class="grade-filter" value="E" checked> E</label>
                <br/>
                <label><strong>Availability:</strong></label><br/>
                <label><input type="radio" name="availability" class="availability-filter" value=""> All</label>
                <label><input type="radio" name="availability" class="availability-filter" value="available" checked> Available</label>
                <label><input type="radio" name="availability" class="availability-filter" value="unavailable"> Unavailable</label>
            </div>
        `);

            $dropdown.prepend($filterContainer);
        });

        // Optionally refresh conflicts on load for pre-filled refs
        $('select.referee-select').each(function () {
            if (this.value) {
                refreshConflicts(this);
            }
        });
    }

    window.initializeSelect2AndEvents = initializeSelect2AndEvents;

    // When filter checkboxes change, update and trigger filtering
    $(document).on('change', '.grade-filter, .availability-filter', function () {
        currentFilters.grades = $('.grade-filter:checked').map(function () {
            return this.value;
        }).get();

        currentFilters.availability = $('.availability-filter:checked').val();

        if (currentSelectEl) {
            const searchInput = $(currentSelectEl).data('select2').dropdown.$search;
            const term = searchInput.val();
            searchInput.trigger('input'); // force matcher to re-run
        }

    });
    initializeSelect2AndEvents();
});
