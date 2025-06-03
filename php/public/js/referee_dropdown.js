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

    let currentSelectEl = null; // Keep track of the currently interacted select element
    let modalRefSelect2 = null; // To store the Select2 instance within the modal

    // Modal HTML Structure
    const modalHTML = `
        <div id="refereeFilterModal" style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 10000;">
            <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background-color: white; padding: 20px; border-radius: 5px; min-width: 300px;">
                <h5 id="refereeModalTitle">Filter Referees</h5>
                <button id="closeRefereeModal" style="position: absolute; top: 10px; right: 10px;">&times;</button>

                <div class="modal-filters p-2 border-bottom">
                    <label><strong>Grade:</strong></label><br/>
                    <label><input type="checkbox" class="modal-grade-filter" value="A"> A</label>
                    <label><input type="checkbox" class="modal-grade-filter" value="B"> B</label>
                    <label><input type="checkbox" class="modal-grade-filter" value="C"> C</label>
                    <label><input type="checkbox" class="modal-grade-filter" value="D"> D</label>
                    <label><input type="checkbox" class="modal-grade-filter" value="E"> E</label>
                    <br/>
                    <label><strong>Availability:</strong></label><br/>
                    <label><input type="radio" name="modal_availability" class="modal-availability-filter" value=""> All</label>
                    <label><input type="radio" name="modal_availability" class="modal-availability-filter" value="available"> Available</label>
                    <label><input type="radio" name="modal_availability" class="modal-availability-filter" value="unavailable"> Unavailable</label>
                </div>

                <div class="modal-search-container p-2">
                    <select id="modalRefereeSearchInput" style="width:100%;"></select>
                </div>
            </div>
        </div>
    `;

    function openRefereeModal() {
        if ($('#refereeFilterModal').length === 0) {
            $('body').append(modalHTML);
            // Add event listener for the close button
            $('#closeRefereeModal').on('click', function() {
                $('#refereeFilterModal').hide();
                if (modalRefSelect2) {
                    modalRefSelect2.select2('destroy');
                    modalRefSelect2 = null;
                }
            });
        }

        // Populate filters based on currentFilters
        $('.modal-grade-filter').each(function() {
            $(this).prop('checked', currentFilters.grades.includes($(this).val()));
        });
        $(`.modal-availability-filter[value="${currentFilters.availability}"]`).prop('checked', true);

        $('#refereeFilterModal').show();

        // Initialize Select2 inside the modal
        // Clone options from the original select element to the modal's select2
        const originalOptions = $(currentSelectEl).find('option').clone();
        $('#modalRefereeSearchInput').empty().append(originalOptions);

        modalRefSelect2 = $('#modalRefereeSearchInput').select2({
            placeholder: "-- Search Referee --",
            width: 'resolve',
            dropdownParent: $('#refereeFilterModal div'), // Attach dropdown to modal
            matcher: refereeMatcher,
            // Allow clear must be false if there's no placeholder option
            // allowClear: true
        });

        // Focus the search input
        // setTimeout to allow modal to render, then open and focus select2
        setTimeout(function() {
            modalRefSelect2.select2('open');
            $('.select2-search__field').first().focus();
        }, 100);


        // Handle selection within the modal
        $('#modalRefereeSearchInput').off('select2:select').on('select2:select', function(e) {
            const selectedData = e.params.data;
            if (currentSelectEl && selectedData.id) {
                // Create a new option if it doesn't exist
                if ($(currentSelectEl).find("option[value='" + selectedData.id + "']").length === 0) {
                    var newOption = new Option(selectedData.text, selectedData.id, true, true);
                    // Append it to the select
                    $(currentSelectEl).append(newOption);
                }
                $(currentSelectEl).val(selectedData.id).trigger('change');
            }
            $('#refereeFilterModal').hide();
            if (modalRefSelect2) {
                modalRefSelect2.select2('destroy');
                modalRefSelect2 = null;
            }
        });
    }

    // Initialize event listeners for referee selects
    function initializeSelect2AndEvents() {
        // Remove any existing Select2 instances from .referee-select
        // $('.referee-select').each(function() {
        //     if ($(this).data('select2')) {
        //         $(this).select2('destroy');
        //     }
        // });

        // Click event to open the modal
        $(document).off('click', '.referee-select-container .select2-container').on('click', '.referee-select-container .select2-container', function(e) {
            e.preventDefault();
            // Find the original select element associated with this custom-looking container
            currentSelectEl = $(this).closest('.referee-select-container').find('.referee-select')[0];
            if(currentSelectEl){
                openRefereeModal();
            } else {
                console.error("Original select element not found for ", this);
            }
        });

        // To make existing .referee-select elements non-functional for direct select2 opening,
        // we can either disable them or rely on the click handler above for custom containers.
        // For now, we assume the click on the container/styled replacement is the primary interaction.
        // If .referee-select elements are directly interacted with, this might need adjustment.
        // We will make them non-focusable and visually hidden but accessible.
        $('.referee-select').select2({
            placeholder: "-- Select Referee --",
            width: 'resolve', // or '100%'
            // dropdownParent: $('body'), // This will be replaced by modal logic
            // matcher: refereeMatcher // Matcher will be used by modal's select2
        }).on('select2:opening', function(e) {
            // Prevent default Select2 dropdown from opening
            e.preventDefault();
            currentSelectEl = this;
            openRefereeModal();
        });


        // Rebind conflict detection - This should remain as is
        $(document).off('change', 'select.referee-select').on('change', 'select.referee-select', function () {
            const selectedRef = this.value;
            console.log(`Trigger Conflict check for referee: ${selectedRef}`);
            refreshConflicts(this);
        });

        // Optionally refresh conflicts on load for pre-filled refs
        $('select.referee-select').each(function () {
            if (this.value) {
                refreshConflicts(this);
            }
        });
    }

    window.initializeSelect2AndEvents = initializeSelect2AndEvents;

    // When filter checkboxes/radio buttons in the MODAL change, update and trigger filtering
    $(document).on('change', '.modal-grade-filter, .modal-availability-filter', function () {
        currentFilters.grades = $('.modal-grade-filter:checked').map(function () {
            return this.value;
        }).get();

        currentFilters.availability = $('.modal-availability-filter:checked').val();

        // Re-filter the Select2 in the modal
        if (modalRefSelect2) {
            // The matcher function `refereeMatcher` uses `currentFilters` directly.
            // Trigger a search event on the modal's Select2 instance.
            // This requires the Select2 dropdown to be open, or to open it, let it search, then potentially hide if no results.
            // A simpler way is to ensure the search input exists and trigger input on it.

            if (!modalRefSelect2.data('select2').isOpen()) {
                modalRefSelect2.select2('open');
                // It might be necessary to defer the trigger slightly if opening is async
                setTimeout(function() {
                    const searchInput = modalRefSelect2.data('select2').dropdown.$search || modalRefSelect2.data('select2').selection.$search;
                    if (searchInput) {
                        searchInput.trigger('input');
                    }
                }, 50); // Small delay to ensure search field is available
            } else {
                const searchInput = modalRefSelect2.data('select2').dropdown.$search || modalRefSelect2.data('select2').selection.$search;
                if (searchInput) {
                    searchInput.trigger('input');
                }
            }
        }
    });

    initializeSelect2AndEvents();
});
