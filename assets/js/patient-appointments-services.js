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
    let servicesLoaded = false;

    // Render the dropdown trigger immediately so it's visible right away
    serviceSelector.innerHTML = `
        <div class="service-selector">
            <div class="service-dropdown-trigger" id="svcDropdownTrigger">
                <span class="trigger-text">Tap to select services</span>
                <span class="trigger-chevron">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </div>
            <div class="service-dropdown-panel" id="svcDropdownPanel"></div>
            <div class="service-selected-tags" id="svcSelectedTags"></div>
            <div id="selectedServiceInputs"></div>
        </div>`;

    async function loadAndRenderServices() {
        if (servicesLoaded) return;
        serviceManager = new AppointmentServiceManager({
            appointmentId: currentAppointmentId,
            mode: 'patient',
            csrfToken: csrfToken,
            onServicesChanged: function(data) {
                updateTimeslotRequirements(data.totalDuration);
            }
        });
        const success = await serviceManager.loadServices();
        if (success) {
            serviceManager.renderServiceSelector('serviceSelector', 'patient');
            servicesLoaded = true;
        }
    }

    // Open dropdown on trigger click — load services on first open
    serviceSelector.addEventListener('click', async function(e) {
        const trigger = e.target.closest('#svcDropdownTrigger');
        if (!trigger) return;
        e.stopPropagation();

        if (!servicesLoaded) {
            trigger.querySelector('.trigger-text').textContent = 'Loading...';
            await loadAndRenderServices();
            return; // renderServiceSelector re-renders everything, no need to toggle
        }

        const panel = serviceSelector.querySelector('#svcDropdownPanel');
        const isOpen = panel && panel.classList.contains('is-open');
        if (panel) panel.classList.toggle('is-open', !isOpen);
        trigger.classList.toggle('is-open', !isOpen);
    });

    // Close on outside click (only once)
    if (!window._svcOutsideClickBound) {
        window._svcOutsideClickBound = true;
        document.addEventListener('click', function() {
            var panel = document.querySelector('#serviceSelector #svcDropdownPanel');
            var trigger = document.querySelector('#serviceSelector #svcDropdownTrigger');
            if (panel) panel.classList.remove('is-open');
            if (trigger) trigger.classList.remove('is-open');
        });
    }

    doctorSelect.removeEventListener('change', doctorSelect._patientServiceHandler);
    doctorSelect._patientServiceHandler = async function() {
        if (!serviceManager) {
            await loadAndRenderServices();
        }
    };
    doctorSelect.addEventListener('change', doctorSelect._patientServiceHandler);

    function updateTimeslotRequirements(totalDuration) {
        const timeslotDuration = 20;
        const requiredSlots = Math.ceil(totalDuration / timeslotDuration);

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
