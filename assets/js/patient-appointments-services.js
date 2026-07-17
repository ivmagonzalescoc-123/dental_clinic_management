/**
 * Patient Appointment Service Selector Initialization
 * Services only load when the patient clicks to expand — no auto-populate
 */

function initPatientServiceSelectorIfPresent() {
    const el = document.getElementById('serviceSelector');
    if (el && !el.querySelector('.svc-collapsed')) {
        initializePatientServiceSelector();
    }
}

document.addEventListener('DOMContentLoaded', initPatientServiceSelectorIfPresent);
window.addEventListener('page-loaded', initPatientServiceSelectorIfPresent);

async function initializePatientServiceSelector() {
    const serviceSelector = document.getElementById('serviceSelector');
    if (!serviceSelector) return;

    const timeslotHint = document.getElementById('patientTimeslotHint');
    let servicesLoaded = false;

    function renderTrigger() {
        serviceSelector.innerHTML = `
            <div class="svc-collapsed" id="svcCollapsed">
                <span class="svc-collapsed-label">
                    <i class="bi bi-plus-circle"></i> Tap to select services
                </span>
            </div>
            <div class="svc-expanded d-none" id="svcExpanded">
                <div class="text-muted small mb-2">Loading services...</div>
            </div>
            <div id="selectedServiceInputs"><input type="hidden" name="total_duration_minutes" value="0"></div>
        `;

        document.getElementById('svcCollapsed').addEventListener('click', async function() {
            if (servicesLoaded) {
                // Just toggle
                const expanded = document.getElementById('svcExpanded');
                expanded.classList.toggle('d-none');
                this.classList.toggle('is-active');
                return;
            }

            // First click — load services
            this.classList.add('is-loading');
            this.querySelector('.svc-collapsed-label').innerHTML =
                '<span class="spinner-border spinner-border-sm"></span> Loading...';

            const sm = new AppointmentServiceManager({
                appointmentId: 0,
                mode: 'patient',
                onServicesChanged: function(data) {
                    updateTimeslotRequirements(data.totalDuration);
                }
            });

            const ok = await sm.loadServices();
            if (ok) {
                servicesLoaded = true;
                sm.renderServiceSelector('serviceSelector', 'patient');
            } else {
                this.querySelector('.svc-collapsed-label').innerHTML =
                    '<i class="bi bi-plus-circle"></i> Tap to select services';
            }
        });
    }

    renderTrigger();

    function updateTimeslotRequirements(totalDuration) {
        const timeslotDuration = 20;
        const requiredSlots = Math.ceil(totalDuration / timeslotDuration);
        if (timeslotHint) {
            timeslotHint.textContent = totalDuration > 0
                ? `Selected services require ${totalDuration} minutes (${requiredSlots} time slot${requiredSlots === 1 ? '' : 's'}).`
                : 'Select services to see the required appointment duration.';
        }
    }

    window.removeEventListener('patient-service-duration-changed', window._patientServiceDurationHandler);
    window._patientServiceDurationHandler = function(event) {
        updateTimeslotRequirements(Number(event?.detail?.totalDuration || 0));
    };
    window.addEventListener('patient-service-duration-changed', window._patientServiceDurationHandler);
}
