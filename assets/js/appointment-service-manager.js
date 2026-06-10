/**
 * Appointment Services Manager
 * Handles service selection, modification, and timeslot calculation
 */

class AppointmentServiceManager {
    constructor(options = {}) {
        this.appointmentId = options.appointmentId || 0;
        this.mode = options.mode || 'patient'; // 'patient' or 'doctor' or 'secretary'
        this.csrfToken = options.csrfToken || '';
        this.onServicesChanged = options.onServicesChanged || null;
        
        // Allow explicit API endpoint override
        if (options.apiEndpoint) {
            this.apiEndpoint = options.apiEndpoint;
        } else {
            // Try to detect based on script location or URL
            const currentPath = window.location.pathname;
            let apiPath = '../assets/api/appointments/manage_services.php';
            
            // If we have an explicit basePath, use it
            if (options.apiBasePath) {
                apiPath = options.apiBasePath + '/manage_services.php';
            } else {
                // Fallback to relative path detection
                if (currentPath.includes('/secretary/') || currentPath.includes('secretary.php')) {
                    apiPath = '../assets/api/appointments/manage_services.php';
                } else if (currentPath.includes('/patient/') || currentPath.includes('patient.php')) {
                    apiPath = '../assets/api/appointments/manage_services.php';
                } else if (currentPath.includes('/doctor/') || currentPath.includes('doctor.php')) {
                    apiPath = '../assets/api/appointments/manage_services.php';
                } else if (currentPath.includes('/admin/')) {
                    apiPath = '../../assets/api/appointments/manage_services.php';
                }
            }
            
            this.apiEndpoint = apiPath;
        }
        
        this.services = [];
        this.selectedServices = [];
        this.totalDuration = 0;
        
        console.log(`[AppointmentServiceManager] Initialized - Mode: ${this.mode}, Path: ${window.location.pathname}, API: ${this.apiEndpoint}`);
    }
    
    /**
     * Load available services
     */
    async loadServices() {
        try {
            const url = `${this.apiEndpoint}?action=services&appointment_id=${this.appointmentId}`;
            console.log(`[AppointmentServiceManager] Loading services from: ${url}`);
            
            const response = await fetch(url);
            const data = await response.json();
            
            console.log(`[AppointmentServiceManager] Services response:`, data);
            
            if (data.success) {
                this.services = data.services;
                this.selectedServices = data.selected_services;
                this.totalDuration = data.total_duration_minutes;
                console.log(`[AppointmentServiceManager] Loaded ${this.services.length} services`);
                return true;
            } else {
                console.error('[AppointmentServiceManager] Failed to load services:', data.message);
                return false;
            }
        } catch (error) {
            console.error('[AppointmentServiceManager] Error loading services:', error);
            return false;
        }
    }
    
    /**
     * Update selected services
     */
    async updateServices(serviceIds) {
        try {
            const formData = new FormData();
            formData.append('action', 'update-services');
            formData.append('appointment_id', this.appointmentId);
            formData.append('csrf_token', this.csrfToken);
            
            // Handle array of service IDs
            if (Array.isArray(serviceIds)) {
                serviceIds.forEach((id, index) => {
                    formData.append(`service_ids[${index}]`, id);
                });
            }
            
            const response = await fetch(this.apiEndpoint, {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.totalDuration = data.total_duration_minutes;
                
                // Reload services to reflect changes
                await this.loadServices();
                
                // Call callback if provided
                if (this.onServicesChanged) {
                    this.onServicesChanged({
                        appointmentId: this.appointmentId,
                        serviceIds: serviceIds,
                        totalDuration: this.totalDuration
                    });
                }
                
                return true;
            } else {
                console.error('Failed to update services:', data.message);
                if (typeof window.showToast === 'function') window.showToast('Error: ' + data.message, 'danger');
                return false;
            }
        } catch (error) {
            console.error('Error updating services:', error);
            if (typeof window.showToast === 'function') window.showToast('Error updating services', 'danger');
            return false;
        }
    }
    
    /**
     * Get required timeslot count based on service duration
     * Assumes default timeslot duration is 20 minutes
     */
    getRequiredTimeslots(durationMinutes = null) {
        const duration = durationMinutes !== null ? durationMinutes : this.totalDuration;
        const timeslotDuration = 20; // minutes
        return Math.ceil(duration / timeslotDuration);
    }

    getServiceDuration(service) {
        return parseInt(service?.service_duration_minutes ?? service?.default_duration_minutes ?? 20, 10) || 20;
    }

    formatDuration(totalMinutes) {
        const minutes = Math.max(0, parseInt(totalMinutes, 10) || 0);
        const hours = Math.floor(minutes / 60);
        const remainder = minutes % 60;
        if (hours > 0 && remainder > 0) {
            return `${hours} hr ${remainder} min`;
        }
        if (hours > 0) {
            return `${hours} hr`;
        }
        return `${remainder} min`;
    }
    
    /**
     * Render service selection UI
     */
    renderServiceSelector(containerId, mode = 'patient') {
        console.log(`[AppointmentServiceManager] renderServiceSelector - Container: ${containerId}, Mode: ${mode}, Services: ${this.services.length}`);
        
        const container = document.getElementById(containerId);
        if (!container) {
            console.error(`[AppointmentServiceManager] Container not found: ${containerId}`);
            return;
        }

        const selectedIds = this.selectedServices.map(service => String(service.service_id));
        const selectedSummary = this.selectedServices.length > 0
            ? this.selectedServices.map(service => `
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="badge bg-primary">${this.escapeHtml(service.service_name)}</span>
                    <span class="text-muted small">${this.getServiceDuration(service)} min</span>
                </div>
            `).join('')
            : '<span class="text-muted small">No services selected.</span>';

        const selectedInputs = this.selectedServices.length > 0
            ? this.selectedServices.map(service => `
                <input type="hidden" name="selected_service_ids[]" value="${service.service_id}">
            `).join('')
            : '';
        
        const html = `
            <div class="service-selector">
                <!-- Service List Container (initially visible) -->
                <div id="serviceListContainer">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                        <div>
                            <label class="form-label fw-bold mb-1">
                                <i class="bi bi-hospital"></i> Select Services
                            </label>
                            <div class="text-muted small">Choose one or more services.</div>
                        </div>
                    </div>

                    <div class="services-list-wrap" id="serviceListWrap">
                        <div class="services-list">
                            ${this.services.map(service => {
                                const isSelected = selectedIds.includes(String(service.service_id));
                                return `
                                    <div class="service-item mb-2">
                                        <div class="form-check">
                                            <input 
                                                class="form-check-input service-checkbox" 
                                                type="checkbox" 
                                                id="service_${service.service_id}"
                                                value="${service.service_id}"
                                                ${isSelected ? 'checked' : ''}
                                                ${(mode === 'patient' || mode === 'secretary') ? '' : 'disabled'}
                                            >
                                            <label class="form-check-label" for="service_${service.service_id}">
                                                <span class="service-name fw-500">${this.escapeHtml(service.service_name)}</span>
                                                <span class="service-info ms-2">
                                                    <span class="text-muted small">
                                                        ₱${parseFloat(service.initial_price).toFixed(2)}
                                                    </span>
                                                    <span class="text-muted small ms-1">
                                                        ${service.service_duration_minutes || service.default_duration_minutes || '20'} min
                                                    </span>
                                                </span>
                                            </label>
                                        </div>
                                    </div>
                                `;
                            }).join('')}
                        </div>
                        <!-- Confirm button at bottom of list -->
                        ${(mode === 'patient' || mode === 'secretary') ? `
                            <div class="d-flex gap-2 mt-3 pt-3 border-top">
                                <button type="button" class="btn btn-primary btn-sm flex-grow-1" id="confirmServiceSelectionBtn">
                                    <i class="bi bi-check2-circle"></i> Confirm Services
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="clearServiceSelectionBtn">
                                    Clear
                                </button>
                            </div>
                        ` : ''}
                    </div>
                </div>

                <!-- Selected Summary Container (hidden until confirm) -->
                <div id="servicesSummaryContainer" class="d-none">
                    <div class="border rounded-3 p-3 bg-light">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                            <div>
                                <div class="fw-semibold mb-1">Selected Services</div>
                                <div id="selectedServicesList" class="mb-2">
                                    ${selectedSummary}
                                </div>
                                <div class="d-flex flex-wrap align-items-center gap-3">
                                    <div>
                                        <span class="text-muted small">
                                            <i class="bi bi-clock"></i> Estimated time: <span id="total-duration-display">${this.formatDuration(this.totalDuration)}</span>
                                        </span>
                                    </div>
                                    <div>
                                        <span class="text-muted small">
                                            <span id="timeslot-count-display">${this.getRequiredTimeslots()}</span> timeslot${this.getRequiredTimeslots() === 1 ? '' : 's'}
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="editServiceSelectionBtn">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                        </div>
                    </div>
                </div>

                <div id="selectedServiceInputs">
                    ${selectedInputs}
                    <input type="hidden" name="total_duration_minutes" value="${this.totalDuration}">
                </div>
            </div>
        `;
        
        container.innerHTML = html;
        
        // Add event listeners
        if (mode === 'patient') {
            this.attachServiceCheckboxListeners(containerId);
        }
    }
    
    /**
     * Attach event listeners to service checkboxes
     */
    attachServiceCheckboxListeners(containerId) {
        const container = document.getElementById(containerId);
        const checkboxes = container.querySelectorAll('.service-checkbox');
        const selectedListEl = container.querySelector('#selectedServicesList');
        const hiddenInputsEl = container.querySelector('#selectedServiceInputs');
        const listContainer = container.querySelector('#serviceListContainer');
        const summaryContainer = container.querySelector('#servicesSummaryContainer');
        const confirmBtn = container.querySelector('#confirmServiceSelectionBtn');
        const clearBtn = container.querySelector('#clearServiceSelectionBtn');
        const editBtn = container.querySelector('#editServiceSelectionBtn');
        
        const updateDurationDisplay = () => {
            const selected = Array.from(checkboxes)
                .filter(cb => cb.checked)
                .map(cb => parseInt(cb.value));
            const selectedSet = new Set(selected.map(id => String(id)));
            const selectedServices = this.services.filter(s => selectedSet.has(String(s.service_id)));
            const total = selectedServices.reduce((sum, s) => sum + this.getServiceDuration(s), 0);

            this.selectedServices = selectedServices;
            this.totalDuration = total;

            if (typeof window !== 'undefined') {
                window.patientSelectedServiceDuration = total;
                window.patientSelectedServiceIds = selected.slice();
                window.dispatchEvent(new CustomEvent('patient-service-duration-changed', {
                    detail: {
                        totalDuration: total,
                        selectedServiceIds: selected.slice(),
                        selectedServices: selectedServices
                    }
                }));
            }
            
            const durationDisplay = document.getElementById('total-duration-display');
            if (durationDisplay) {
                durationDisplay.textContent = this.formatDuration(total);
            }
            
            const timeslotDisplay = document.getElementById('timeslot-count-display');
            if (timeslotDisplay) {
                timeslotDisplay.textContent = this.getRequiredTimeslots(total);
            }

            if (selectedListEl) {
                selectedListEl.innerHTML = selectedServices.length > 0
                    ? selectedServices.map(service => `
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge bg-primary">${this.escapeHtml(service.service_name)}</span>
                            <span class="text-muted small">${this.getServiceDuration(service)} min</span>
                        </div>
                    `).join('')
                    : '<span class="text-muted small">No services selected.</span>';
            }

            if (hiddenInputsEl) {
                const selectedInputs = selected.map(id => `
                    <input type="hidden" name="selected_service_ids[]" value="${id}">
                `).join('');
                hiddenInputsEl.innerHTML = `${selectedInputs}
                    <input type="hidden" name="total_duration_minutes" value="${total}">
                `;
            }

            if (confirmBtn) {
                confirmBtn.disabled = selected.length === 0;
            }
        };
        
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateDurationDisplay);
        });

        if (confirmBtn) {
            confirmBtn.addEventListener('click', () => {
                updateDurationDisplay();
                const selected = Array.from(checkboxes)
                    .filter(cb => cb.checked)
                    .map(cb => parseInt(cb.value));

                if (selected.length === 0) {
                    if (typeof window.showToast === 'function') window.showToast('Please select at least one service', 'warning');
                    return;
                }

                // Hide service list, show summary
                if (listContainer && summaryContainer) {
                    listContainer.classList.add('d-none');
                    summaryContainer.classList.remove('d-none');
                }

                if (typeof window.showToast === 'function') {
                    window.showToast('Services confirmed successfully.', 'success');
                }
            });
        }

        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                checkboxes.forEach(checkbox => {
                    checkbox.checked = false;
                });
                updateDurationDisplay();
            });
        }

        if (editBtn) {
            editBtn.addEventListener('click', () => {
                // Show service list, hide summary
                if (listContainer && summaryContainer) {
                    listContainer.classList.remove('d-none');
                    summaryContainer.classList.add('d-none');
                }
            });
        }

        updateDurationDisplay();
    }
    
    /**
     * Render doctor's service modification UI
     */
    renderDoctorServiceModifier(containerId) {
        const container = document.getElementById(containerId);
        if (!container) return;
        
        const html = `
            <div class="doctor-service-modifier">
                <h6 class="mb-3">
                    <i class="bi bi-pen"></i> Modify Services
                </h6>
                
                <div class="selected-services mb-3">
                    <label class="form-label">Patient Selected Services:</label>
                    <div class="services-list">
                        ${this.selectedServices.length > 0 ? 
                            this.selectedServices.map(service => `
                                <div class="service-badge d-inline-block me-2 mb-2">
                                    <span class="badge bg-primary">
                                        ${this.escapeHtml(service.service_name)}
                                        <button type="button" class="btn-close btn-close-white remove-service ms-1" 
                                            data-service-id="${service.service_id}" 
                                            style="font-size: 0.6rem; padding: 0;">
                                        </button>
                                    </span>
                                </div>
                            `).join('') 
                            : '<small class="text-muted">No services selected by patient</small>'
                        }
                    </div>
                </div>
                
                <div class="add-services">
                    <label class="form-label">Add More Services:</label>
                    <div class="services-checkbox-group">
                        ${this.services.map(service => {
                            const isSelected = this.selectedServices.some(s => s.service_id == service.service_id);
                            return `
                                <div class="form-check">
                                    <input 
                                        class="form-check-input doctor-service-checkbox" 
                                        type="checkbox" 
                                        id="doctor_service_${service.service_id}"
                                        value="${service.service_id}"
                                        ${!isSelected ? '' : 'disabled'}
                                    >
                                    <label class="form-check-label" for="doctor_service_${service.service_id}">
                                        ${this.escapeHtml(service.service_name)} - ₱${parseFloat(service.initial_price).toFixed(2)}
                                    </label>
                                </div>
                            `;
                        }).join('')}
                    </div>
                </div>
                
                <div class="alert alert-info mt-3">
                    <small>
                        <strong>Total Duration:</strong> <span id="doctor-total-duration">${this.totalDuration}</span> minutes
                    </small>
                </div>
                
                <button type="button" class="btn btn-success btn-sm mt-2" id="save-doctor-services-btn">
                    <i class="bi bi-save"></i> Save Service Changes
                </button>
            </div>
        `;
        
        container.innerHTML = html;
        this.attachDoctorServiceListeners(containerId);
    }
    
    /**
     * Attach listeners for doctor service modification
     */
    attachDoctorServiceListeners(containerId) {
        const container = document.getElementById(containerId);
        const removeButtons = container.querySelectorAll('.remove-service');
        const addCheckboxes = container.querySelectorAll('.doctor-service-checkbox');
        const saveBtn = container.querySelector('#save-doctor-services-btn');
        
        removeButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const serviceId = btn.dataset.serviceId;
                const index = this.selectedServices.findIndex(s => s.service_id == serviceId);
                if (index > -1) {
                    this.selectedServices.splice(index, 1);
                    this.renderDoctorServiceModifier(containerId);
                }
            });
        });
        
        if (saveBtn) {
            saveBtn.addEventListener('click', async () => {
                const serviceIds = this.selectedServices.map(s => s.service_id);
                
                // Add any newly checked services
                addCheckboxes.forEach(checkbox => {
                    if (checkbox.checked && !serviceIds.includes(parseInt(checkbox.value))) {
                        serviceIds.push(parseInt(checkbox.value));
                    }
                });
                
                saveBtn.disabled = true;
                saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';
                
                const success = await this.updateServices(serviceIds);
                
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="bi bi-save"></i> Save Service Changes';
                
                if (success) {
                    this.renderDoctorServiceModifier(containerId);
                }
            });
        }
    }
    
    /**
     * Utility: Escape HTML
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AppointmentServiceManager;
}
