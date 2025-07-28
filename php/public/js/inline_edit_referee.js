document.addEventListener('DOMContentLoaded', function () {
    const messagesDiv = document.getElementById('referee-details-messages');

    document.querySelectorAll('.edit-icon').forEach(icon => {
        icon.addEventListener('click', function () {
            const fieldName = this.dataset.field;

            // If the field is 'exempt_clubs', do nothing here as it's handled by custom script in referee_detail.php
            if (fieldName === 'exempt_clubs') {
                return;
            }

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
            controlsWrapper.classList.add('edit-controls', 'd-flex', 'align-items-center');

            let inputElement;

            if (fieldName === 'home_club_id') {
                inputElement = document.createElement('select');
                inputElement.classList.add('form-control', 'form-control-sm', 'mr-2');
                inputElement.style.flexGrow = '1';
                const currentClubId = ddElement.dataset.currentClubId;

                const loadingOption = document.createElement('option');
                loadingOption.textContent = 'Loading clubs...';
                inputElement.appendChild(loadingOption);

                fetch('../ajax/club_options.php')
                    .then(response => response.json())
                    .then(clubs => {
                        inputElement.innerHTML = '';
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
            } else if (fieldName === 'district_id') {
                inputElement = document.createElement('select');
                inputElement.classList.add('form-control', 'form-control-sm', 'mr-2');
                inputElement.style.flexGrow = '1';
                const currentDistrictId = ddElement.dataset.currentDistrictId;

                const loadingOption = document.createElement('option');
                loadingOption.textContent = 'Loading districts...';
                inputElement.appendChild(loadingOption);

                fetch('../ajax/district_options.php')
                    .then(response => response.json())
                    .then(districts => {
                        inputElement.innerHTML = '';
                        const pleaseSelectOption = document.createElement('option');
                        pleaseSelectOption.value = '';
                        pleaseSelectOption.textContent = 'Please select a district';
                        inputElement.appendChild(pleaseSelectOption);

                        districts.forEach(district => {
                            const option = document.createElement('option');
                            option.value = district.id;
                            option.textContent = district.name;
                            if (district.id === currentDistrictId) {
                                option.selected = true;
                            }
                            inputElement.appendChild(option);
                        });
                    })
                    .catch(error => {
                        console.error('Error fetching districts:', error);
                        inputElement.innerHTML = '<option>Error loading districts</option>';
                        displayMessage('Error loading districts. Please try again.', 'danger');
                    });
            } else if (fieldName === 'grade' || fieldName === 'ar_grade') {
                inputElement = document.createElement('select');
                inputElement.classList.add('form-control', 'form-control-sm', 'mr-2');
                inputElement.style.flexGrow = '1';
                const grades = ["A", "B", "C", "D", "E", ""];

                const pleaseSelectOption = document.createElement('option');
                pleaseSelectOption.value = '';
                pleaseSelectOption.textContent = `Select ${fieldName.replace('_', ' ')}`;
                inputElement.appendChild(pleaseSelectOption);

                grades.forEach(grade => {
                    const option = document.createElement('option');
                    option.value = grade;
                    option.textContent = grade || "Clear selection";
                    if (grade === currentValue) {
                        option.selected = true;
                    }
                    inputElement.appendChild(option);
                });
            } else if (fieldName === 'max_matches_per_weekend') {
                inputElement = document.createElement('select');
                inputElement.classList.add('form-control', 'form-control-sm', 'mr-2');
                inputElement.style.flexGrow = '1';
                const options = [
                    { value: '', text: 'Multiple (up to 3)' },
                    { value: '1', text: '1 Match' }
                ];

                options.forEach(opt => {
                    const option = document.createElement('option');
                    option.value = opt.value;
                    option.textContent = opt.text;
                    if ((opt.value === '' && currentValue.includes('Multiple')) || (opt.value === '1' && currentValue === '1 Match')) {
                        option.selected = true;
                    }
                    inputElement.appendChild(option);
                });
            } else if (fieldName === 'max_days_per_weekend') {
                inputElement = document.createElement('select');
                inputElement.classList.add('form-control', 'form-control-sm', 'mr-2');
                inputElement.style.flexGrow = '1';
                const options = [
                    { value: '', text: 'N/A (Both Days)' },
                    { value: '1', text: '1 Day' },
                    { value: '2', text: 'Both Days' }
                ];

                options.forEach(opt => {
                    const option = document.createElement('option');
                    option.value = opt.value;
                    option.textContent = opt.text;
                    if ((opt.value === '' && currentValue.includes('N/A')) || (opt.value === currentValue.replace(' Day(s)', ''))) {
                        option.selected = true;
                    }
                    inputElement.appendChild(option);
                });
            } else if (fieldName === 'max_travel_distance' || fieldName === 'home_lat' || fieldName === 'home_lon') {
                inputElement = document.createElement('input');
                inputElement.type = 'number';
                inputElement.value = currentValue === 'N/A' ? '' : currentValue;
                inputElement.min = fieldName === 'max_travel_distance' ? '0' : undefined;
                inputElement.classList.add('form-control', 'form-control-sm', 'mr-2');
                inputElement.style.flexGrow = '1';
            } else {
                inputElement = document.createElement('input');
                inputElement.type = (fieldName === 'email') ? 'email' : 'text';
                inputElement.value = currentValue;
                inputElement.classList.add('form-control', 'form-control-sm', 'mr-2');
                inputElement.style.flexGrow = '1';
            }
            inputElement.dataset.field = fieldName;

            const saveIcon = document.createElement('i');
            saveIcon.classList.add('bi', 'bi-check-lg', 'save-icon', 'text-success', 'mr-2');
            saveIcon.style.cursor = 'pointer';
            saveIcon.title = 'Save';

            const cancelIcon = document.createElement('i');
            cancelIcon.classList.add('bi', 'bi-x-lg', 'cancel-icon', 'text-danger');
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
                // Client-side validation
                if (fieldName === 'email' && newValue && !isValidEmail(newValue)) {
                    displayMessage('Invalid email format.', 'danger');
                    return;
                }
                if (!newValue.trim() && ['first_name', 'last_name', 'home_location_city', 'home_club_id', 'district_id'].includes(fieldName)) {
                    displayMessage(fieldName.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase()) + ' cannot be empty.', 'danger');
                    return;
                }
                if (fieldName === 'max_travel_distance' && newValue.trim() !== '' && parseInt(newValue) < 0) {
                    displayMessage('Max travel distance cannot be negative.', 'danger');
                    return;
                }
                if (fieldName === 'max_matches_per_weekend' && newValue && newValue !== '1') {
                    displayMessage('Max matches per weekend must be 1 or empty (multiple).', 'danger');
                    return;
                }
                if (fieldName === 'max_days_per_weekend' && newValue && ![1, 2].includes(parseInt(newValue))) {
                    displayMessage('Max days per weekend must be 1 or 2.', 'danger');
                    return;
                }

                const formData = new FormData();
                formData.append('referee_uuid', refereeUUID);
                formData.append('field_name', fieldName);
                formData.append('field_value', newValue);

                fetch('update_referee_field.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            displayValueSpan.textContent = data.newValueDisplay || (newValue === '' ? 'N/A' : newValue);
                            displayValueSpan.dataset.originalValue = newValue;
                            if (fieldName === 'home_club_id') {
                                ddElement.dataset.currentClubId = newValue;
                            }
                            if (fieldName === 'district_id') {
                                ddElement.dataset.currentDistrictId = newValue;
                            }
                            displayMessage(data.message || 'Field updated successfully.', 'success');
                            toggleToViewMode();
                        } else {
                            displayMessage(data.message || 'Error updating field.', 'danger');
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
                icon.style.display = '';
            }
        });
    });

    function displayMessage(message, type = 'info') {
        if (!messagesDiv) return;
        messagesDiv.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close">
            </button>
        </div>`;
        setTimeout(() => {
            const alert = messagesDiv.querySelector('.alert');
            if (alert) {
                if (typeof(bootstrap) !== 'undefined' && bootstrap.Alert) {
                    const bsAlert = bootstrap.Alert.getInstance(alert) || new bootstrap.Alert(alert);
                    bsAlert.close();
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