document.addEventListener('DOMContentLoaded', function () {
    const messagesDiv = document.getElementById('referee-details-messages');

    document.querySelectorAll('.edit-icon').forEach(icon => {
        icon.addEventListener('click', function () {
            const fieldName = this.dataset.field;
            const ddElement = this.closest('.editable-field') || this.closest('h1'); // Handle h1 or dd
            let displayValueSpan;

            if (ddElement.tagName === 'H1') {
                displayValueSpan = ddElement.querySelector(`span[data-field="${fieldName}"]`);
            } else {
                displayValueSpan = ddElement.querySelector(`.display-value[data-field="${fieldName}"]`);
            }

            const currentValue = displayValueSpan.textContent;
            const originalValue = displayValueSpan.dataset.originalValue || currentValue; // Store original value or use current

            // Hide display span and edit icon
            displayValueSpan.style.display = 'none';
            this.style.display = 'none';

            // Remove any existing input/select and save/cancel icons
            const existingControls = ddElement.querySelector('.edit-controls');
            if (existingControls) {
                existingControls.remove();
            }

            const controlsWrapper = document.createElement('div');
            controlsWrapper.classList.add('edit-controls', 'd-flex', 'align-items-center'); // Added d-flex for alignment

            let inputElement;

            if (fieldName === 'home_club_id') {
                inputElement = document.createElement('select');
                inputElement.classList.add('form-control', 'form-control-sm', 'mr-2'); // Added mr-2 for spacing
                inputElement.style.flexGrow = '1'; // Allow select to take available space
                const currentClubId = ddElement.dataset.currentClubId;

                // Add a default "loading" option
                const loadingOption = document.createElement('option');
                loadingOption.textContent = 'Loading clubs...';
                inputElement.appendChild(loadingOption);

                fetch('../ajax/club_options.php') // Adjusted path
                    .then(response => response.json())
                    .then(clubs => {
                        inputElement.innerHTML = ''; // Clear loading option
                        const pleaseSelectOption = document.createElement('option');
                        pleaseSelectOption.value = '';
                        pleaseSelectOption.textContent = 'Please select a club';
                        inputElement.appendChild(pleaseSelectOption);

                        clubs.forEach(club => {
                            const option = document.createElement('option');
                            option.value = club.uuid;
                            option.textContent = club.club_name;
                            if (club.uuid === currentClubId) {
                                option.selected = true;
                            }
                            inputElement.appendChild(option);
                        });
                    })
                    .catch(error => {
                        console.error('Error fetching clubs:', error);
                        inputElement.innerHTML = '<option>Error loading clubs</option>';
                         displayMessage('Error loading clubs. Please try again.', 'danger');
                    });
            } else if (fieldName === 'grade' || fieldName === 'ar_grade') {
                inputElement = document.createElement('select');
                inputElement.classList.add('form-control', 'form-control-sm', 'mr-2');
                inputElement.style.flexGrow = '1';
                const grades = ["A", "B", "C", "D", "E"];

                const pleaseSelectOption = document.createElement('option');
                pleaseSelectOption.value = '';
                pleaseSelectOption.textContent = `Select ${fieldName.replace('_', ' ')}`;
                inputElement.appendChild(pleaseSelectOption);

                grades.forEach(grade => {
                    const option = document.createElement('option');
                    option.value = grade;
                    option.textContent = grade;
                    if (grade === currentValue) {
                        option.selected = true;
                    }
                    inputElement.appendChild(option);
                });
            } else {
                inputElement = document.createElement('input');
                inputElement.type = (fieldName === 'email') ? 'email' : 'text';
                inputElement.value = currentValue;
                inputElement.classList.add('form-control', 'form-control-sm', 'mr-2'); // Added mr-2
                inputElement.style.flexGrow = '1'; // Allow input to take available space
            }
            inputElement.dataset.field = fieldName; // Keep track of the field

            const saveIcon = document.createElement('i');
            saveIcon.classList.add('bi', 'bi-check-lg', 'save-icon', 'text-success', 'mr-2'); // Bootstrap Icon
            saveIcon.style.cursor = 'pointer';
            saveIcon.title = 'Save';

            const cancelIcon = document.createElement('i');
            cancelIcon.classList.add('bi', 'bi-x-lg', 'cancel-icon', 'text-danger'); // Bootstrap Icon
            cancelIcon.style.cursor = 'pointer';
            cancelIcon.title = 'Cancel';

            controlsWrapper.appendChild(inputElement);
            controlsWrapper.appendChild(saveIcon);
            controlsWrapper.appendChild(cancelIcon);
            displayValueSpan.parentNode.insertBefore(controlsWrapper, displayValueSpan.nextSibling);
            inputElement.focus();


            // Event listener for Save
            saveIcon.addEventListener('click', function () {
                const newValue = inputElement.value;
                // Client-side validation (basic)
                if (fieldName === 'email' && !isValidEmail(newValue)) {
                    displayMessage('Invalid email format.', 'danger');
                    return;
                }
                if (!newValue.trim() && (fieldName === 'first_name' || fieldName === 'last_name' || fieldName === 'grade' || fieldName === 'ar_grade' || fieldName === 'home_location_city')) {
                    displayMessage(fieldName.replace('_', ' ') + ' cannot be empty.', 'danger');
                    return;
                }
                if (fieldName === 'home_club_id' && !newValue) {
                    displayMessage('Please select a club.', 'danger');
                    return;
                }


                const formData = new FormData();
                formData.append('referee_uuid', refereeUUID); // refereeUUID should be available globally from referee_detail.php
                formData.append('field_name', fieldName);
                formData.append('field_value', newValue);

                fetch('update_referee_field.php', { // Assuming this script is in the same directory
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        displayValueSpan.textContent = (fieldName === 'home_club_id' && inputElement.selectedIndex > 0) ? inputElement.options[inputElement.selectedIndex].text : newValue;
                        displayValueSpan.dataset.originalValue = newValue; // Update original value store
                        if(fieldName === 'home_club_id') {
                            ddElement.dataset.currentClubId = newValue; // Update current club ID for next edit
                        }
                        displayMessage(data.message || 'Field updated successfully.', 'success');
                        toggleToViewMode();
                    } else {
                        displayMessage(data.message || 'Error updating field.', 'danger');
                        // Optionally, don't revert and let user correct
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    displayMessage('An unexpected error occurred. Please try again.', 'danger');
                });
            });

            // Event listener for Cancel
            cancelIcon.addEventListener('click', function () {
                toggleToViewMode(originalValue);
            });

            function toggleToViewMode(valueToRestore = null) {
                if (valueToRestore !== null) {
                     displayValueSpan.textContent = valueToRestore;
                }
                controlsWrapper.remove();
                displayValueSpan.style.display = '';
                icon.style.display = ''; // Show the original edit icon
            }
        });
    });

    function displayMessage(message, type = 'info') {
        if (!messagesDiv) return;
        messagesDiv.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>`;
        // Auto-dismiss after 5 seconds
        setTimeout(() => {
            const alert = messagesDiv.querySelector('.alert');
            if (alert) {
                // bootstrap's close method if available, otherwise just remove
                if (typeof(bootstrap) !== 'undefined' && bootstrap.Alert) {
                     const bsAlert = bootstrap.Alert.getInstance(alert);
                     if (bsAlert) bsAlert.close();
                } else {
                    alert.remove();
                }
            }
        }, 5000);
    }

    function isValidEmail(email) {
        const re = /^(([^<>()\[\]\\.,;:\s@"]+(\.[^<>()\[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
        return re.test(String(email).toLowerCase());
    }
});
