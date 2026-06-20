/**
 * Patient Appointment Service Selector Initialization
 * Runs on the patient appointment modal/page
 */

function initPatientServiceSelectorIfPresent() {
    if (document.getElementById('createApptForm') && document.getElementById('serviceSelector')) {
        initializePatientServiceSelector();
    }
}

document.addEventListener('DOMContentLoaded', initPatientServiceSelectorIfPresent);
window.addEventListener('page-loaded', initPatientServiceSelectorIfPresent);

async function initializePatientServiceSelector() {
    const form = document.getElementById('createApptForm');
    const doctorSelect = document.getElementById('user_id');
    const serviceSelector = document.getElementById('serviceSelector');
    if (!form || !doctorSelect || !serviceSelector) return;

    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
    const timeslotHint = document.getElementById('patientTimeslotHint');
    
    let currentAppointmentId = 0;
    let serviceManager = null;
    
    async function initializeServiceManager() {
        serviceManager = new AppointmentServiceManager({
            appointmentId: currentAppointmentId,
            mode: 'patient',
            csrfToken: csrfToken,
            onServicesChanged: function(data) {
                console.log('Services updated:', data);
                updateTimeslotRequirements(data.totalDuration);
            }
        });
        
        const success = await serviceManager.loadServices();
        if (success) {
            serviceManager.renderServiceSelector('serviceSelector', 'patient');
        } else {
            serviceSelector.innerHTML = '<div class="alert alert-danger">Failed to load services</div>';
        }
    }
    
    doctorSelect.removeEventListener('change', doctorSelect._patientServiceHandler);
    doctorSelect._patientServiceHandler = async function() {
        if (this.value) {
            await initializeServiceManager();
        } else {
            serviceSelector.innerHTML = '<small class="text-muted">Please select a doctor first</small>';
        }
    };
    doctorSelect.addEventListener('change', doctorSelect._patientServiceHandler);
    
    function updateTimeslotRequirements(totalDuration) {
        const timeslotDuration = 20;
        const requiredSlots = Math.ceil(totalDuration / timeslotDuration);
        
        console.log(`Required timeslots: ${requiredSlots} (${totalDuration} minutes)`);
        
        if (timeslotHint) {
            if (totalDuration > 0) {
                timeslotHint.textContent = `Selected services require ${totalDuration} minutes (${requiredSlots} time slot${requiredSlots === 1 ? '' : 's'}).`;
            } else {
                timeslotHint.textContent = 'Select services to see the required appointment duration.';
            }
        }
    }

    window.removeEventListener('patient-service-duration-changed', window._patientServiceDurationHandler);
    window._patientServiceDurationHandler = function(event) {
        const totalDuration = Number(event?.detail?.totalDuration || 0);
        updateTimeslotRequirements(totalDuration);
    };
    window.addEventListener('patient-service-duration-changed', window._patientServiceDurationHandler);
}
