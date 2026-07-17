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
     * Render service selection UI — radio button style for patient, dropdown for doctor
     */
    renderServiceSelector(containerId, mode = 'patient') {
        const container = document.getElementById(containerId);
        if (!container) return;

        const selectedIds = new Set(this.selectedServices.map(s => String(s.service_id)));
        const count = this.selectedServices.length;

        if (mode === 'patient') {
            this._renderPatientServiceGrid(container, containerId, selectedIds, count);
        } else {
            this._renderDropdownSelector(container, containerId, selectedIds, count);
        }
    }

    _renderPatientServiceGrid(container, containerId, selectedIds, count) {
        if (!this.services.length) {
            container.innerHTML = '<div class="text-muted small">No services available.</div>';
            return;
        }

        const itemsHtml = this.services.map(s => {
            const isSelected = selectedIds.has(String(s.service_id));
            const dur = s.service_duration_minutes || s.default_duration_minutes || 20;
            return `
                <label class="svc-check-item${isSelected ? ' is-selected' : ''}" data-sid="${s.service_id}">
                    <input type="checkbox" class="form-check-input svc-check-input" value="${s.service_id}" ${isSelected ? 'checked' : ''}>
                    <span class="svc-check-info">
                        <span class="svc-check-name">${this.escapeHtml(s.service_name)}</span>
                        <span class="svc-check-meta">₱${parseFloat(s.initial_price).toFixed(0)} · ${dur}m</span>
                    </span>
                </label>`;
        }).join('');

        const durationHint = this.totalDuration > 0
            ? `<div class="service-duration-hint">${this.totalDuration} min total · ${this.getRequiredTimeslots()} timeslot${this.getRequiredTimeslots() === 1 ? '' : 's'}</div>`
            : '';

        const selectedInputs = this.selectedServices.map(s =>
            `<input type="hidden" name="selected_service_ids[]" value="${s.service_id}">`
        ).join('');

        const headerLabel = count > 0
            ? `${count} service${count > 1 ? 's' : ''} selected`
            : 'Tap to select services';

        const html = `
            <div class="svc-collapsed is-active" id="svcCollapsed">
                <span class="svc-collapsed-label">
                    <i class="bi bi-plus-circle"></i> ${headerLabel}
                </span>
            </div>
            <div class="svc-expanded" id="svcExpanded">
                <div class="svc-grid-list">${itemsHtml}</div>
                ${durationHint}
            </div>
            <div id="selectedServiceInputs">${selectedInputs}
                <input type="hidden" name="total_duration_minutes" value="${this.totalDuration}">
            </div>`;

        container.innerHTML = html;
        this.attachPatientGridListeners(containerId);
    }

    _renderDropdownSelector(container, containerId, selectedIds, count) {
        const itemsHtml = this.services.map(s => {
            const isSelected = selectedIds.has(String(s.service_id));
            const dur = s.service_duration_minutes || s.default_duration_minutes || 20;
            return `
                <div class="service-dropdown-item${isSelected ? ' is-selected' : ''}" data-sid="${s.service_id}">
                    <span class="svc-check">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    </span>
                    <span class="svc-name">${this.escapeHtml(s.service_name)}</span>
                    <span class="svc-meta">
                        <span class="svc-price">₱${parseFloat(s.initial_price).toFixed(0)}</span>
                        <span>${dur}m</span>
                    </span>
                </div>`;
        }).join('');

        const tagsHtml = this.selectedServices.map(s =>
            `<span class="service-tag" data-sid="${s.service_id}">
                ${this.escapeHtml(s.service_name)}
                <span class="tag-remove" data-remove="${s.service_id}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </span>
            </span>`
        ).join('');

        const durationHint = this.totalDuration > 0
            ? `<div class="service-duration-hint">${this.totalDuration} min total · ${this.getRequiredTimeslots()} timeslot${this.getRequiredTimeslots() === 1 ? '' : 's'}</div>`
            : '';

        const selectedInputs = this.selectedServices.map(s =>
            `<input type="hidden" name="selected_service_ids[]" value="${s.service_id}">`
        ).join('');

        const triggerText = count > 0
            ? `${count} service${count > 1 ? 's' : ''} selected`
            : 'Tap to select services';

        const html = `
            <div class="service-selector">
                <button type="button" class="service-dropdown-trigger" id="svcDropdownTrigger" aria-expanded="false">
                    <span class="trigger-text${count > 0 ? ' has-selection' : ''}">${triggerText}</span>
                    <span class="trigger-chevron">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                    </span>
                </button>
                <div class="service-dropdown-panel" id="svcDropdownPanel">
                    ${itemsHtml}
                    <div class="service-dropdown-footer">
                        <button type="button" class="btn btn-primary btn-sm w-100" id="svcConfirmBtn">Confirm Selection</button>
                    </div>
                </div>
                <div class="service-selected-tags" id="svcSelectedTags">${tagsHtml}</div>
                ${durationHint}
                <div id="selectedServiceInputs">${selectedInputs}
                    <input type="hidden" name="total_duration_minutes" value="${this.totalDuration}">
                </div>
            </div>`;

        container.innerHTML = html;
        this.attachServiceDropdownListeners(containerId);
    }

    /**
     * Attach event listeners to patient checkbox grid
     */
    attachPatientGridListeners(containerId) {
        const container = document.getElementById(containerId);
        const self = this;

        // Toggle collapsed/expanded
        const collapsed = container.querySelector('#svcCollapsed');
        if (collapsed) {
            collapsed.addEventListener('click', function(e) {
                // Don't toggle if clicking a checkbox inside
                if (e.target.closest('.svc-check-item')) return;
                const expanded = container.querySelector('#svcExpanded');
                if (expanded) {
                    expanded.classList.toggle('d-none');
                    collapsed.classList.toggle('is-active');
                }
            });
        }

        // Handle checkbox selection
        container.addEventListener('click', function(e) {
            const item = e.target.closest('.svc-check-item');
            if (!item) return;

            const input = item.querySelector('.svc-check-input');
            if (!input) return;

            // Let the native checkbox toggle, then sync state
            setTimeout(function() {
                const sid = String(input.value);
                if (input.checked) {
                    const svc = self.services.find(s => String(s.service_id) === sid);
                    if (svc && !self.selectedServices.find(s => String(s.service_id) === sid)) {
                        self.selectedServices.push(svc);
                    }
                    item.classList.add('is-selected');
                } else {
                    self.selectedServices = self.selectedServices.filter(s => String(s.service_id) !== sid);
                    item.classList.remove('is-selected');
                }
                self.recalcDuration();
                self.updateDropdownUI(containerId);
            }, 0);
        });
    }

    /**
     * Attach event listeners to custom dropdown
     */
    attachServiceDropdownListeners(containerId) {
        const container = document.getElementById(containerId);
        const trigger = container.querySelector('#svcDropdownTrigger');
        const panel = container.querySelector('#svcDropdownPanel');
        const tagsContainer = container.querySelector('#svcSelectedTags');
        const self = this;

        // Remove any old listeners first
        if (self._onDocClick) document.removeEventListener('click', self._onDocClick);

        function closePanel() {
            panel.classList.remove('is-open');
            trigger.classList.remove('is-open');
            trigger.setAttribute('aria-expanded', 'false');
        }

        // Toggle dropdown
        trigger.addEventListener('click', function(e) {
            e.stopPropagation();
            const isOpen = panel.classList.contains('is-open');
            panel.classList.toggle('is-open', !isOpen);
            trigger.classList.toggle('is-open', !isOpen);
            trigger.setAttribute('aria-expanded', String(!isOpen));
        });

        trigger.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                trigger.click();
            }
        });

        // Stop all clicks inside the panel from closing it
        panel.addEventListener('click', function(e) {
            e.stopPropagation();

            if (e.target.closest('#svcConfirmBtn')) {
                closePanel();
                return;
            }

            const item = e.target.closest('.service-dropdown-item');
            if (!item) return;

            const sid = String(item.dataset.sid);
            const idx = self.selectedServices.findIndex(s => String(s.service_id) === sid);

            if (idx >= 0) {
                self.selectedServices.splice(idx, 1);
                item.classList.remove('is-selected');
            } else {
                const svc = self.services.find(s => String(s.service_id) === sid);
                if (svc) {
                    self.selectedServices.push(svc);
                    item.classList.add('is-selected');
                }
            }

            self.recalcDuration();
            self.updateDropdownUI(containerId);
        });

        // Close on outside click
        self._onDocClick = closePanel;
        document.addEventListener('click', self._onDocClick);

        // Remove tag
        tagsContainer.addEventListener('click', function(e) {
            const removeBtn = e.target.closest('.tag-remove');
            if (!removeBtn) return;
            e.stopPropagation();

            const sid = String(removeBtn.dataset.remove);
            self.selectedServices = self.selectedServices.filter(s => String(s.service_id) !== sid);

            const item = panel.querySelector(`.service-dropdown-item[data-sid="${sid}"]`);
            if (item) item.classList.remove('is-selected');

            self.recalcDuration();
            self.updateDropdownUI(containerId);
        });
    }

    recalcDuration() {
        this.totalDuration = this.selectedServices.reduce((sum, s) => sum + this.getServiceDuration(s), 0);
        if (typeof window !== 'undefined') {
            window.patientSelectedServiceDuration = this.totalDuration;
            window.patientSelectedServiceIds = this.selectedServices.map(s => s.service_id);
            window.dispatchEvent(new CustomEvent('patient-service-duration-changed', {
                detail: { totalDuration: this.totalDuration, selectedServiceIds: this.selectedServices.map(s => s.service_id), selectedServices: this.selectedServices }
            }));
        }
    }

    updateDropdownUI(containerId) {
        const container = document.getElementById(containerId);
        if (!container) return;
        const count = this.selectedServices.length;

        // Patient grid mode — update collapsed header
        const collapsedLabel = container.querySelector('.svc-collapsed-label');
        if (collapsedLabel) {
            const headerLabel = count > 0
                ? `${count} service${count > 1 ? 's' : ''} selected`
                : 'Tap to select services';
            collapsedLabel.innerHTML = `<i class="bi bi-plus-circle"></i> ${headerLabel}`;
        }

        const durationHint = container.querySelector('.service-duration-hint');
        if (durationHint) {
            durationHint.innerHTML = this.totalDuration > 0
                ? `${this.totalDuration} min total · ${this.getRequiredTimeslots()} timeslot${this.getRequiredTimeslots() === 1 ? '' : 's'}`
                : '';
        }

        const hiddenInputs = container.querySelector('#selectedServiceInputs');
        if (hiddenInputs) {
            const inputs = this.selectedServices.map(s => `<input type="hidden" name="selected_service_ids[]" value="${s.service_id}">`).join('');
            hiddenInputs.innerHTML = inputs + `<input type="hidden" name="total_duration_minutes" value="${this.totalDuration}">`;
        }

        // Doctor dropdown mode
        const triggerText = container.querySelector('.trigger-text');
        const tagsContainer = container.querySelector('#svcSelectedTags');

        if (triggerText) {
            triggerText.textContent = count > 0 ? `${count} service${count > 1 ? 's' : ''} selected` : 'Tap to select services';
            triggerText.classList.toggle('has-selection', count > 0);
        }

        if (tagsContainer) {
            tagsContainer.innerHTML = this.selectedServices.map(s =>
                `<span class="service-tag" data-sid="${s.service_id}">
                    ${this.escapeHtml(s.service_name)}
                    <span class="tag-remove" data-remove="${s.service_id}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </span>
                </span>`
            ).join('');
        }

        if (durationHint) {
            durationHint.innerHTML = this.totalDuration > 0
                ? `${this.totalDuration} min total · ${this.getRequiredTimeslots()} timeslot${this.getRequiredTimeslots() === 1 ? '' : 's'}`
                : '';
        }

        if (hiddenInputs) {
            const inputs = this.selectedServices.map(s => `<input type="hidden" name="selected_service_ids[]" value="${s.service_id}">`).join('');
            hiddenInputs.innerHTML = inputs + `<input type="hidden" name="total_duration_minutes" value="${this.totalDuration}">`;
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

                if (typeof window.clearPendingAction === 'function') window.clearPendingAction();
                const success = typeof window.runWithActionLoading === 'function'
                    ? await window.runWithActionLoading(saveBtn, () => this.updateServices(serviceIds))
                    : await this.updateServices(serviceIds);
                
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
