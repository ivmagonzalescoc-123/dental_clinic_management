/**
 * Appointment Services Manager
 * Handles service selection, modification, and timeslot calculation
 */

class AppointmentServiceManager {
    constructor(options = {}) {
        this.appointmentId = options.appointmentId || 0;
        this.mode = options.mode || 'patient'; // 'patient' or 'doctor'
        this.csrfToken = options.csrfToken || '';
        this.onServicesChanged = options.onServicesChanged || null;
        this.apiEndpoint = '../assets/api/appointments/manage_services.php';
        
        this.services = [];
        this.selectedServices = [];
        this.totalDuration = 0;
    }
    
    /**
     * Load available services
     */
    async loadServices() {
        try {
            const url = `${this.apiEndpoint}?action=services&appointment_id=${this.appointmentId}`;
            const response = await fetch(url);
            const data = await response.json();
            
            if (data.success) {
                this.services = data.services;
                this.selectedServices = data.selected_services;
                this.totalDuration = data.total_duration_minutes;
                return true;
            } else {
                console.error('Failed to load services:', data.message);
                return false;
            }
        } catch (error) {
            console.error('Error loading services:', error);
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
                alert('Error: ' + data.message);
                return false;
            }
        } catch (error) {
            console.error('Error updating services:', error);
            alert('Error updating services');
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
    
    /**
     * Render service selection UI
     */
    renderServiceSelector(containerId, mode = 'patient') {
        const container = document.getElementById(containerId);
        if (!container) return;
        
        const html = `
            <div class="service-selector">
                <label class="form-label fw-bold mb-3">
                    <i class="bi bi-hospital"></i> Select Services
                </label>
                
                <div class="services-list">
                    ${this.services.map(service => {
                        const isSelected = this.selectedServices.some(s => s.service_id == service.service_id);
                        return `
                            <div class="service-item mb-2">
                                <div class="form-check">
                                    <input 
                                        class="form-check-input service-checkbox" 
                                        type="checkbox" 
                                        id="service_${service.service_id}"
                                        value="${service.service_id}"
                                        ${isSelected ? 'checked' : ''}
                                        ${mode === 'patient' ? '' : 'disabled'}
                                    >
                                    <label class="form-check-label" for="service_${service.service_id}">
                                        <span class="service-name fw-500">${this.escapeHtml(service.service_name)}</span>
                                        <span class="service-info ms-2">
                                            <span class="badge bg-light text-dark">
                                                ₱${parseFloat(service.initial_price).toFixed(2)}
                                            </span>
                                            <span class="badge bg-info ms-1">
                                                ${service.default_duration_minutes || '20'} min
                                            </span>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        `;
                    }).join('')}
                </div>
                
                <div class="alert alert-info mt-3">
                    <small>
                        <strong>Total Duration:</strong> <span id="total-duration-display">${this.totalDuration}</span> minutes
                        (<span id="timeslot-count-display">${this.getRequiredTimeslots()}</span> timeslots)
                    </small>
                </div>
                
                ${mode === 'patient' ? `
                    <button type="button" class="btn btn-primary btn-sm mt-2" id="update-services-btn">
                        <i class="bi bi-check2"></i> Confirm Services
                    </button>
                ` : ''}
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
        const updateBtn = container.querySelector('#update-services-btn');
        
        const updateDurationDisplay = () => {
            const selected = Array.from(checkboxes)
                .filter(cb => cb.checked)
                .map(cb => parseInt(cb.value));
            
            const total = this.services
                .filter(s => selected.includes(s.service_id))
                .reduce((sum, s) => sum + (parseInt(s.default_duration_minutes) || 20), 0);
            
            document.getElementById('total-duration-display').textContent = total;
            document.getElementById('timeslot-count-display').textContent = this.getRequiredTimeslots(total);
        };
        
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateDurationDisplay);
        });
        
        if (updateBtn) {
            updateBtn.addEventListener('click', async () => {
                const selected = Array.from(checkboxes)
                    .filter(cb => cb.checked)
                    .map(cb => parseInt(cb.value));
                
                if (selected.length === 0) {
                    alert('Please select at least one service');
                    return;
                }
                
                updateBtn.disabled = true;
                updateBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';
                
                const success = await this.updateServices(selected);
                
                updateBtn.disabled = false;
                updateBtn.innerHTML = '<i class="bi bi-check2"></i> Confirm Services';
                
                if (success) {
                    alert('Services updated successfully');
                }
            });
        }
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
