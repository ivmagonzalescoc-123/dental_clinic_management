/**
 * Patient Appointment Service Selector Initialization
 * Runs on the patient appointment modal/page
 */

document.addEventListener('DOMContentLoaded', function() {
    // Only run on appointment booking page
    if (document.getElementById('createApptForm') && document.getElementById('serviceSelector')) {
        initializePatientServiceSelector();
    }
});

async function initializePatientServiceSelector() {
    const form = document.getElementById('createApptForm');
    const doctorSelect = document.getElementById('user_id');
    const serviceSelector = document.getElementById('serviceSelector');
    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
    
    // Create a temporary appointment ID (0 for new appointment)
    let currentAppointmentId = 0;
    let serviceManager = null;
    
    // Initialize service manager on doctor selection
    async function initializeServiceManager() {
        if (serviceManager) {
            return; // Already initialized
        }
        
        serviceManager = new AppointmentServiceManager({
            appointmentId: currentAppointmentId,
            mode: 'patient',
            csrfToken: csrfToken,
            onServicesChanged: function(data) {
                console.log('Services updated:', data);
                // Trigger timeslot recalculation if needed
                updateTimeslotRequirements(data.totalDuration);
            }
        });
        
        // Load services
        const success = await serviceManager.loadServices();
        if (success) {
            serviceManager.renderServiceSelector('serviceSelector', 'patient');
        } else {
            serviceSelector.innerHTML = '<div class="alert alert-danger">Failed to load services</div>';
        }
    }
    
    // Handle doctor selection
    if (doctorSelect) {
        doctorSelect.addEventListener('change', async function() {
            if (this.value) {
                await initializeServiceManager();
            } else {
                // Reset if no doctor selected
                serviceSelector.innerHTML = '<small class="text-muted">Please select a doctor first</small>';
            }
        });
    }
    
    // Update form submission to include services
    if (form) {
        const originalSubmit = form.onsubmit;
        form.onsubmit = async function(e) {
            e.preventDefault();
            
            // Get selected services
            const selectedServices = [];
            document.querySelectorAll('.service-checkbox:checked').forEach(checkbox => {
                selectedServices.push(parseInt(checkbox.value));
            });
            
            if (selectedServices.length === 0) {
                alert('Please select at least one service');
                return false;
            }
            
            // Add services to form data before submission
            form.setAttribute('data-selected-services', JSON.stringify(selectedServices));
            form.setAttribute('data-service-duration', serviceManager?.totalDuration || 0);
            
            // Continue with original submission
            if (typeof originalFormSubmitHandler === 'function') {
                originalFormSubmitHandler(e);
            } else {
                // Fallback: submit normally
                form.submit();
            }
        };
    }
    
    /**
     * Update timeslot requirements based on service duration
     */
    function updateTimeslotRequirements(totalDuration) {
        const timeslotDuration = 20; // minutes
        const requiredSlots = Math.ceil(totalDuration / timeslotDuration);
        
        // You can use this to show message or adjust UI
        console.log(`Required timeslots: ${requiredSlots} (${totalDuration} minutes)`);
        
        // Update timeslot selection UI if needed
        const timeslotInput = document.getElementById('timeslot_id');
        if (timeslotInput) {
            // Store the required duration in a data attribute
            timeslotInput.setAttribute('data-required-duration', totalDuration);
        }
    }
}
