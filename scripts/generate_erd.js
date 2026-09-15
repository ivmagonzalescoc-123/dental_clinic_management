// Generate complete draw.io ERD for capstone_dental database
const fs = require('fs');

let cellId = 0;
function nid() { return 'c' + (++cellId); }

const C = {
  user:    { bg: '#dbeafe', stroke: '#93c5fd', font: '#1e40af' },
  patient: { bg: '#dcfce7', stroke: '#86efac', font: '#166534' },
  doctor:  { bg: '#ccfbf1', stroke: '#5eead4', font: '#115e59' },
  service: { bg: '#e0f2fe', stroke: '#7dd3fc', font: '#0369a1' },
  appt:    { bg: '#fce7f3', stroke: '#f9a8d4', font: '#9d174d' },
  clinical:{ bg: '#f3e8ff', stroke: '#c4b5fd', font: '#6b21a8' },
  odonto:  { bg: '#fef3c7', stroke: '#fcd34d', font: '#92400e' },
  billing: { bg: '#fee2e2', stroke: '#fca5a5', font: '#991b1b' },
  other:   { bg: '#f1f5f9', stroke: '#cbd5e1', font: '#475569' },
};

const ROW_H = 25;
const HEADER_H = 30;
const W = 200;  // default table width

function tableStyle(c) {
  return `shape=table;startSize=${HEADER_H};container=1;collapsible=1;childLayout=tableLayout;fontStyle=1;fontSize=11;fillColor=${c.bg};strokeColor=${c.stroke};fontColor=${c.font};html=1;`;
}

function colStyle(c) {
  return `shape=partialRectangle;connectable=0;fillColor=${c.bg};top=0;left=0;bottom=0;right=0;fontSize=10;overflow=hidden;html=1;`;
}

function edgeStyle(color) {
  return `edgeStyle=orthogonalEdgeStyle;rounded=1;orthogonalLoop=1;jettySize=auto;strokeColor=${color || '#94a3b8'};fontSize=9;html=1;`;
}

const cells = [];
const edges = [];

// Helper: add a table and return its column IDs
function addTable(id, name, x, y, cols, color, width) {
  const w = width || W;
  const h = HEADER_H + cols.length * ROW_H;
  cells.push({ id, value: name, style: tableStyle(color), vertex: 1, parent: '1',
    geom: { x, y, w, h } });
  const colIds = {};
  cols.forEach((col, i) => {
    const cid = nid();
    colIds[col.name] = cid;
    const isFK = col.type === 'FK';
    const isPK = col.type === 'PK';
    const label = (isPK ? 'PK ' : isFK ? 'FK ' : '') + col.name + (col.dtype ? ' ' + col.dtype : '');
    const s = colStyle(color) + (isFK ? 'fontColor=#0066CC;' : '');
    cells.push({ id: cid, value: label, style: s, vertex: 1, parent: id,
      geom: { x: 0, y: HEADER_H + i * ROW_H, w, h: ROW_H } });
  });
  return colIds;
}

function addEdge(id, src, tgt, label, color) {
  edges.push({ id, value: label || '', style: edgeStyle(color), edge: 1, source: src, target: tgt, parent: '1' });
}

// ========================================
// TABLES
// ========================================

// Col 1: Users & Auth (x=40)
const role = addTable('t_role', 'role', 40, 40, [
  { name: 'role_id', type: 'PK', dtype: 'INT' },
  { name: 'role_name', dtype: 'ENUM' },
  { name: 'description', dtype: 'TEXT' },
], C.user);

const users = addTable('t_users', 'users', 40, 185, [
  { name: 'user_id', type: 'PK', dtype: 'INT' },
  { name: 'first_name', dtype: 'VARCHAR' },
  { name: 'last_name', dtype: 'VARCHAR' },
  { name: 'middle_name', dtype: 'VARCHAR' },
  { name: 'email', dtype: 'VARCHAR UQ' },
  { name: 'password_hash', dtype: 'VARCHAR' },
  { name: 'phone_number', dtype: 'VARCHAR' },
  { name: 'date_of_birth', dtype: 'DATE' },
  { name: 'gender', dtype: 'VARCHAR' },
  { name: 'address', dtype: 'TEXT' },
  { name: 'role_id', type: 'FK', dtype: 'INT' },
  { name: 'added_by', type: 'FK', dtype: 'INT' },
  { name: 'is_active', dtype: 'TINYINT' },
  { name: 'work_type', dtype: 'ENUM' },
  { name: 'profile_image', dtype: 'VARCHAR' },
  { name: 'must_change_password', dtype: 'TINYINT' },
  { name: 'password_updated_at', dtype: 'DATETIME' },
  { name: 'failed_login_attempts', dtype: 'INT' },
  { name: 'locked_until', dtype: 'DATETIME' },
  { name: 'last_login_at', dtype: 'DATETIME' },
  { name: 'created_at', dtype: 'DATETIME' },
], C.user, 210);

const user_logs = addTable('t_ulog', 'user_logs', 40, 780, [
  { name: 'user_log_id', type: 'PK', dtype: 'INT' },
  { name: 'user_id', type: 'FK', dtype: 'INT' },
  { name: 'login_time', dtype: 'DATETIME' },
  { name: 'last_activity', dtype: 'DATETIME' },
  { name: 'logout_time', dtype: 'DATETIME' },
  { name: 'ip_address', dtype: 'VARCHAR' },
  { name: 'user_agent', dtype: 'VARCHAR' },
], C.user);

const password_resets = addTable('t_pwr', 'password_resets', 40, 1025, [
  { name: 'reset_id', type: 'PK', dtype: 'INT' },
  { name: 'user_type', dtype: 'ENUM' },
  { name: 'user_id', dtype: 'INT' },
  { name: 'email', dtype: 'VARCHAR' },
  { name: 'token_hash', dtype: 'CHAR(64)' },
  { name: 'otp_hash', dtype: 'CHAR(64)' },
  { name: 'expires_at', dtype: 'DATETIME' },
  { name: 'used_at', dtype: 'DATETIME' },
  { name: 'ip_address', dtype: 'VARCHAR' },
  { name: 'created_at', dtype: 'DATETIME' },
], C.user);

const system_logs = addTable('t_slog', 'system_logs', 40, 1345, [
  { name: 'system_log_id', type: 'PK', dtype: 'INT' },
  { name: 'user_id', type: 'FK', dtype: 'INT' },
  { name: 'action', dtype: 'VARCHAR' },
  { name: 'entity_type', dtype: 'VARCHAR' },
  { name: 'entity_id', dtype: 'INT' },
  { name: 'details', dtype: 'TEXT' },
  { name: 'ip_address', dtype: 'VARCHAR' },
  { name: 'user_agent', dtype: 'VARCHAR' },
  { name: 'created_at', dtype: 'DATETIME' },
], C.other);

const backup = addTable('t_bk', 'backup', 40, 1640, [
  { name: 'backup_id', type: 'PK', dtype: 'INT' },
  { name: 'user_id', type: 'FK', dtype: 'INT' },
  { name: 'backup_date', dtype: 'DATETIME' },
  { name: 'file_name', dtype: 'VARCHAR' },
  { name: 'file_path', dtype: 'VARCHAR' },
  { name: 'file_size', dtype: 'DECIMAL' },
], C.other);

// Col 2: Patients (x=300)
const patients = addTable('t_pat', 'patients', 300, 40, [
  { name: 'patient_id', type: 'PK', dtype: 'INT' },
  { name: 'first_name', dtype: 'VARCHAR' },
  { name: 'last_name', dtype: 'VARCHAR' },
  { name: 'middle_name', dtype: 'VARCHAR' },
  { name: 'email', dtype: 'VARCHAR' },
  { name: 'password_hash', dtype: 'VARCHAR' },
  { name: 'google_id', dtype: 'VARCHAR UQ' },
  { name: 'google_picture', dtype: 'VARCHAR' },
  { name: 'phone_number', dtype: 'VARCHAR' },
  { name: 'date_of_birth', dtype: 'DATE' },
  { name: 'gender', dtype: 'VARCHAR' },
  { name: 'address', dtype: 'TEXT' },
  { name: 'occupation', dtype: 'VARCHAR' },
  { name: 'added_by', type: 'FK', dtype: 'INT' },
  { name: 'is_active', dtype: 'TINYINT' },
  { name: 'profile_image', dtype: 'VARCHAR' },
  { name: 'must_change_password', dtype: 'TINYINT' },
  { name: 'password_updated_at', dtype: 'DATETIME' },
  { name: 'failed_login_attempts', dtype: 'INT' },
  { name: 'locked_until', dtype: 'DATETIME' },
  { name: 'last_login_at', dtype: 'DATETIME' },
  { name: 'created_at', dtype: 'DATETIME' },
], C.patient, 210);

const patient_profiling = addTable('t_pp', 'patient_profiling', 300, 660, [
  { name: 'profiling_id', type: 'PK', dtype: 'INT' },
  { name: 'patient_id', type: 'FK', dtype: 'INT' },
  { name: 'record_id', type: 'FK', dtype: 'INT' },
  { name: 'race', dtype: 'VARCHAR' },
  { name: 'occupation', dtype: 'VARCHAR' },
  { name: 'medical_history', dtype: 'TEXT' },
  { name: 'medications', dtype: 'TEXT' },
  { name: 'allergies', dtype: 'VARCHAR' },
  { name: 'smoking', dtype: 'VARCHAR' },
  { name: 'alcohol', dtype: 'VARCHAR' },
  { name: 'is_hypertensive', dtype: 'TINYINT' },
  { name: 'is_diabetic', dtype: 'TINYINT' },
  { name: 'is_asthmatic', dtype: 'TINYINT' },
  { name: 'has_heart_disease', dtype: 'TINYINT' },
  { name: 'is_hiv_positive', dtype: 'TINYINT' },
  { name: 'bleeding_disorder', dtype: 'VARCHAR' },
  { name: 'pregnancy_status', dtype: 'VARCHAR' },
  { name: 'previous_dental_treatment', dtype: 'TEXT' },
  { name: 'dental_anxiety', dtype: 'VARCHAR' },
  { name: 'emergency_contact', dtype: 'VARCHAR' },
  { name: 'emergency_phone', dtype: 'VARCHAR' },
  { name: 'background_notes', dtype: 'TEXT' },
  { name: 'created_at', dtype: 'DATETIME' },
  { name: 'updated_at', dtype: 'DATETIME' },
], C.patient, 210);

const patient_logs = addTable('t_plog', 'patient_logs', 300, 1330, [
  { name: 'patient_log_id', type: 'PK', dtype: 'INT' },
  { name: 'patient_id', type: 'FK', dtype: 'INT' },
  { name: 'login_time', dtype: 'DATETIME' },
  { name: 'last_activity', dtype: 'DATETIME' },
  { name: 'logout_time', dtype: 'DATETIME' },
  { name: 'ip_address', dtype: 'VARCHAR' },
  { name: 'user_agent', dtype: 'VARCHAR' },
], C.patient);

const patient_notifications = addTable('t_pn', 'patient_notifications', 300, 1575, [
  { name: 'patient_notification_id', type: 'PK', dtype: 'INT' },
  { name: 'patient_id', type: 'FK', dtype: 'INT' },
  { name: 'title', dtype: 'VARCHAR' },
  { name: 'body', dtype: 'TEXT' },
  { name: 'link', dtype: 'VARCHAR' },
  { name: 'is_read', dtype: 'TINYINT' },
  { name: 'created_at', dtype: 'TIMESTAMP' },
], C.patient);

const patient_feedback = addTable('t_pfb', 'patient_feedback', 300, 1820, [
  { name: 'feedback_id', type: 'PK', dtype: 'INT' },
  { name: 'patient_id', type: 'FK', dtype: 'INT UQ' },
  { name: 'appointment_id', type: 'FK', dtype: 'INT' },
  { name: 'doctor_id', type: 'FK', dtype: 'INT' },
  { name: 'rating', dtype: 'TINYINT' },
  { name: 'comment', dtype: 'TEXT' },
  { name: 'is_featured', dtype: 'TINYINT' },
  { name: 'created_at', dtype: 'DATETIME' },
  { name: 'updated_at', dtype: 'DATETIME' },
], C.patient, 220);

// Col 3: Doctor Management (x=560)
const specialization = addTable('t_spec', 'specialization', 560, 40, [
  { name: 'specialization_id', type: 'PK', dtype: 'INT' },
  { name: 'specialization_name', dtype: 'VARCHAR' },
  { name: 'description', dtype: 'TEXT' },
], C.doctor);

const dentist_specialization = addTable('t_dspec', 'dentist_specialization', 560, 185, [
  { name: 'doctor_specialized_id', type: 'PK', dtype: 'INT' },
  { name: 'user_id', type: 'FK', dtype: 'INT' },
  { name: 'specialization_id', type: 'FK', dtype: 'INT' },
  { name: 'UQ', dtype: '(user_id, spec_id)', },
], C.doctor, 210);

const doctor_availability = addTable('t_dav', 'doctor_availability', 560, 355, [
  { name: 'availability_id', type: 'PK', dtype: 'INT' },
  { name: 'user_id', type: 'FK', dtype: 'INT' },
  { name: 'weekday', dtype: 'TINYINT' },
  { name: 'start_time', dtype: 'TIME' },
  { name: 'end_time', dtype: 'TIME' },
  { name: 'status', dtype: 'ENUM' },
  { name: 'created_at', dtype: 'TIMESTAMP' },
], C.doctor);

const doctor_leaves = addTable('t_dlv', 'doctor_leaves', 560, 600, [
  { name: 'leave_id', type: 'PK', dtype: 'INT' },
  { name: 'user_id', type: 'FK', dtype: 'INT' },
  { name: 'leave_start_date', dtype: 'DATE' },
  { name: 'leave_end_date', dtype: 'DATE' },
  { name: 'reason', dtype: 'VARCHAR' },
  { name: 'status', dtype: 'ENUM' },
  { name: 'created_at', dtype: 'TIMESTAMP' },
], C.doctor);

const doctor_prescription_profiles = addTable('t_dpp', 'doctor_prescription_profiles', 560, 845, [
  { name: 'profile_id', type: 'PK', dtype: 'INT' },
  { name: 'user_id', type: 'FK', dtype: 'INT UQ' },
  { name: 'full_name', dtype: 'VARCHAR' },
  { name: 'professional_title', dtype: 'VARCHAR' },
  { name: 'licenses', dtype: 'LONGTEXT' },
  { name: 'signature_image', dtype: 'VARCHAR' },
  { name: 'signature_drawn', dtype: 'LONGTEXT' },
  { name: 'clinic_name', dtype: 'VARCHAR' },
  { name: 'clinic_address', dtype: 'VARCHAR' },
  { name: 'clinic_phone', dtype: 'VARCHAR' },
  { name: 'created_at', dtype: 'DATETIME' },
  { name: 'updated_at', dtype: 'DATETIME' },
], C.doctor, 250);

// Col 4: Services & Scheduling (x=820)
const services = addTable('t_svc', 'services', 820, 40, [
  { name: 'service_id', type: 'PK', dtype: 'INT' },
  { name: 'service_name', dtype: 'VARCHAR' },
  { name: 'initial_price', dtype: 'DECIMAL' },
  { name: 'description', dtype: 'TEXT' },
  { name: 'added_by', type: 'FK', dtype: 'INT' },
  { name: 'is_active', dtype: 'TINYINT' },
  { name: 'requires_schedule', dtype: 'TINYINT' },
  { name: 'default_duration_minutes', dtype: 'INT' },
  { name: 'created_at', dtype: 'DATETIME' },
], C.service);

const timeslot = addTable('t_slot', 'timeslot', 820, 335, [
  { name: 'timeslot_id', type: 'PK', dtype: 'INT' },
  { name: 'doctor_id', type: 'FK', dtype: 'INT' },
  { name: 'slot_date', dtype: 'DATE' },
  { name: 'start_time', dtype: 'TIME' },
  { name: 'end_time', dtype: 'TIME' },
  { name: 'status', dtype: 'ENUM' },
  { name: 'appointment_id', type: 'FK', dtype: 'INT' },
  { name: 'created_at', dtype: 'TIMESTAMP' },
], C.service);

const timeslot_service = addTable('t_tsvc', 'timeslot_service', 820, 605, [
  { name: 'id', type: 'PK', dtype: 'INT' },
  { name: 'service_id', type: 'FK', dtype: 'INT' },
  { name: 'duration_minutes', dtype: 'INT' },
  { name: 'buffer_minutes', dtype: 'INT' },
  { name: 'slots_per_day_limit', dtype: 'INT' },
  { name: 'created_at', dtype: 'TIMESTAMP' },
], C.service);

// Col 5: Appointments (x=1080)
const bookings = addTable('t_bk2', 'bookings', 1080, 40, [
  { name: 'booking_id', type: 'PK', dtype: 'INT' },
  { name: 'first_name', dtype: 'VARCHAR' },
  { name: 'last_name', dtype: 'VARCHAR' },
  { name: 'email', dtype: 'VARCHAR' },
  { name: 'phone_number', dtype: 'VARCHAR' },
  { name: 'date_of_birth', dtype: 'DATE' },
  { name: 'preferred_date', dtype: 'DATE' },
  { name: 'preferred_time', dtype: 'VARCHAR' },
  { name: 'reason', dtype: 'VARCHAR' },
  { name: 'otp_hash', dtype: 'CHAR(64)' },
  { name: 'otp_expires_at', dtype: 'DATETIME' },
  { name: 'status', dtype: 'ENUM' },
  { name: 'decline_reason', dtype: 'VARCHAR' },
  { name: 'converted_to_appointment_id', dtype: 'INT' },
  { name: 'converted_to_patient_id', dtype: 'INT' },
  { name: 'tracking_code', dtype: 'VARCHAR UQ' },
  { name: 'created_at', dtype: 'DATETIME' },
], C.appt, 250);

const appointments = addTable('t_apt', 'appointments', 1080, 535, [
  { name: 'appointment_id', type: 'PK', dtype: 'INT' },
  { name: 'appointment_date', dtype: 'DATE' },
  { name: 'patient_id', type: 'FK', dtype: 'INT' },
  { name: 'user_id', type: 'FK', dtype: 'INT' },
  { name: 'timeslot_id', type: 'FK', dtype: 'INT' },
  { name: 'booking_id', type: 'FK', dtype: 'INT' },
  { name: 'rescheduled_from', type: 'FK', dtype: 'INT' },
  { name: 'reason', dtype: 'VARCHAR' },
  { name: 'status', dtype: 'ENUM' },
  { name: 'is_online_appointment', dtype: 'TINYINT' },
  { name: 'is_emergency', dtype: 'TINYINT' },
  { name: 'needs_reassignment', dtype: 'TINYINT' },
  { name: 'notes', dtype: 'TEXT' },
  { name: 'cancellation_reason', dtype: 'VARCHAR' },
  { name: 'completed_by_doctor_at', dtype: 'DATETIME' },
  { name: 'session_started_at', dtype: 'DATETIME' },
  { name: 'secretary_records_completed_at', dtype: 'DATETIME' },
  { name: 'created_at', dtype: 'DATETIME' },
], C.appt, 250);

const arr = addTable('t_arr', 'appointment_reschedule_requests', 1080, 1055, [
  { name: 'request_id', type: 'PK', dtype: 'INT' },
  { name: 'appointment_id', type: 'FK', dtype: 'INT' },
  { name: 'patient_id', type: 'FK', dtype: 'INT' },
  { name: 'requested_doctor_id', type: 'FK', dtype: 'INT' },
  { name: 'requested_date', dtype: 'DATE' },
  { name: 'requested_timeslot_id', dtype: 'INT' },
  { name: 'request_note', dtype: 'VARCHAR' },
  { name: 'status', dtype: 'ENUM' },
  { name: 'reviewed_by', type: 'FK', dtype: 'INT' },
  { name: 'review_note', dtype: 'VARCHAR' },
  { name: 'reviewed_at', dtype: 'DATETIME' },
  { name: 'created_at', dtype: 'TIMESTAMP' },
], C.appt, 250);

const selected_services = addTable('t_ss', 'selected_services', 1080, 1425, [
  { name: 'selected_service_id', type: 'PK', dtype: 'INT' },
  { name: 'appointment_id', type: 'FK', dtype: 'INT' },
  { name: 'service_id', type: 'FK', dtype: 'INT' },
  { name: 'notes', dtype: 'TEXT' },
  { name: 'created_at', dtype: 'DATETIME' },
], C.appt, 250);

const appointment_media = addTable('t_am', 'appointment_media', 1080, 1620, [
  { name: 'media_id', type: 'PK', dtype: 'INT' },
  { name: 'appointment_id', type: 'FK', dtype: 'INT' },
  { name: 'file_name', dtype: 'VARCHAR' },
  { name: 'file_path', dtype: 'VARCHAR' },
  { name: 'file_type', dtype: 'ENUM' },
  { name: 'file_size', dtype: 'INT' },
  { name: 'media_order', dtype: 'INT' },
  { name: 'tooth_number', dtype: 'INT' },
  { name: 'uploaded_by', type: 'FK', dtype: 'INT' },
  { name: 'notes', dtype: 'TEXT' },
  { name: 'uploaded_at', dtype: 'DATETIME' },
], C.appt, 250);

const appointment_doctor_notes = addTable('t_adn', 'appointment_doctor_notes', 1080, 1965, [
  { name: 'note_id', type: 'PK', dtype: 'INT' },
  { name: 'appointment_id', type: 'FK', dtype: 'INT' },
  { name: 'doctor_id', type: 'FK', dtype: 'INT' },
  { name: 'note_text', dtype: 'TEXT' },
  { name: 'created_at', dtype: 'DATETIME' },
  { name: 'updated_at', dtype: 'DATETIME' },
], C.appt, 250);

const patient_schedules = addTable('t_ps', 'patient_schedules', 1080, 2185, [
  { name: 'schedule_id', type: 'PK', dtype: 'INT' },
  { name: 'appointment_id', type: 'FK', dtype: 'INT' },
  { name: 'user_id', type: 'FK', dtype: 'INT' },
  { name: 'scheduled_start', dtype: 'DATETIME' },
  { name: 'scheduled_end', dtype: 'DATETIME' },
  { name: 'status', dtype: 'ENUM' },
  { name: 'priority', dtype: 'TINYINT' },
  { name: 'created_at', dtype: 'TIMESTAMP' },
  { name: 'updated_at', dtype: 'TIMESTAMP' },
], C.appt, 250);

const schedule_reminders = addTable('t_sr', 'schedule_reminders', 1080, 2480, [
  { name: 'reminder_id', type: 'PK', dtype: 'INT' },
  { name: 'patient_schedule_id', type: 'FK', dtype: 'INT' },
  { name: 'remind_at', dtype: 'DATETIME' },
  { name: 'channel', dtype: 'ENUM' },
  { name: 'sent_at', dtype: 'DATETIME' },
  { name: 'payload', dtype: 'TEXT' },
], C.appt, 250);

// Col 6: Clinical Records (x=1390)
const clinical_records = addTable('t_cr', 'clinical_records', 1390, 40, [
  { name: 'record_id', type: 'PK', dtype: 'INT' },
  { name: 'appointment_id', type: 'FK', dtype: 'INT' },
  { name: 'doctor_id', type: 'FK', dtype: 'INT' },
  { name: 'patient_id', type: 'FK', dtype: 'INT' },
  { name: 'submitted_by', type: 'FK', dtype: 'INT' },
  { name: 'summary', dtype: 'TEXT' },
  { name: 'doctor_notes', dtype: 'TEXT' },
  { name: 'follow_up_date', dtype: 'DATE' },
  { name: 'status', dtype: 'ENUM' },
  { name: 'submitted_at', dtype: 'DATETIME' },
  { name: 'primary_image', dtype: 'VARCHAR' },
], C.clinical, 220);

const clinical_record_images = addTable('t_cri', 'clinical_record_images', 1390, 385, [
  { name: 'image_id', type: 'PK', dtype: 'INT' },
  { name: 'record_id', type: 'FK', dtype: 'INT' },
  { name: 'file_path', dtype: 'VARCHAR' },
  { name: 'file_type', dtype: 'ENUM' },
  { name: 'tooth_number', dtype: 'INT' },
  { name: 'uploaded_by', type: 'FK', dtype: 'INT' },
  { name: 'uploaded_at', dtype: 'DATETIME' },
], C.clinical, 220);

const clinical_record_services = addTable('t_crs', 'clinical_record_services', 1390, 630, [
  { name: 'id', type: 'PK', dtype: 'INT' },
  { name: 'record_id', type: 'FK', dtype: 'INT' },
  { name: 'service_id', type: 'FK', dtype: 'INT' },
  { name: 'notes', dtype: 'TEXT' },
], C.clinical, 220);

const treatment_plans = addTable('t_tp', 'treatment_plans', 1390, 800, [
  { name: 'treatment_id', type: 'PK', dtype: 'INT' },
  { name: 'record_id', type: 'FK', dtype: 'INT' },
  { name: 'service_id', type: 'FK', dtype: 'INT' },
  { name: 'tooth_number', dtype: 'INT' },
  { name: 'description', dtype: 'TEXT' },
  { name: 'status', dtype: 'ENUM' },
  { name: 'estimated_cost', dtype: 'DECIMAL' },
  { name: 'created_at', dtype: 'DATETIME' },
], C.clinical, 220);

const treatment_materials = addTable('t_tm', 'treatment_materials', 1390, 1070, [
  { name: 'material_id', type: 'PK', dtype: 'INT' },
  { name: 'record_id', type: 'FK', dtype: 'INT' },
  { name: 'material_name', dtype: 'VARCHAR' },
  { name: 'quantity', dtype: 'DECIMAL' },
  { name: 'unit', dtype: 'VARCHAR' },
  { name: 'cost', dtype: 'DECIMAL' },
  { name: 'notes', dtype: 'TEXT' },
], C.clinical, 220);

const prescriptions = addTable('t_rx', 'prescriptions', 1390, 1315, [
  { name: 'prescription_id', type: 'PK', dtype: 'INT' },
  { name: 'appointment_id', type: 'FK', dtype: 'INT' },
  { name: 'prescription_date', dtype: 'DATE' },
  { name: 'medicine_name', dtype: 'VARCHAR' },
  { name: 'dosage', dtype: 'VARCHAR' },
  { name: 'medicine_brand', dtype: 'VARCHAR' },
  { name: 'duration', dtype: 'VARCHAR' },
  { name: 'notes', dtype: 'TEXT' },
], C.clinical, 220);

const clinical_findings = addTable('t_cf', 'clinical_findings', 1390, 1585, [
  { name: 'finding_id', type: 'PK', dtype: 'INT' },
  { name: 'record_id', type: 'FK', dtype: 'INT UQ' },
  { name: 'patient_id', type: 'FK', dtype: 'INT' },
  { name: 'findings_json', dtype: 'TEXT' },
  { name: 'created_at', dtype: 'TIMESTAMP' },
  { name: 'updated_at', dtype: 'TIMESTAMP' },
], C.clinical, 220);

// Col 7: Odontogram (x=1660)
const tooth = addTable('t_tooth', 'tooth', 1660, 40, [
  { name: 'tooth_id', type: 'PK', dtype: 'INT' },
  { name: 'tooth_number', dtype: 'VARCHAR' },
  { name: 'upper_lower', dtype: 'TINYINT' },
], C.odonto);

const tooth_status_type = addTable('t_tst', 'tooth_status_type', 1660, 185, [
  { name: 'status_type_id', type: 'PK', dtype: 'INT' },
  { name: 'label', dtype: 'VARCHAR' },
  { name: 'category', dtype: 'ENUM' },
  { name: 'code', dtype: 'VARCHAR UQ' },
  { name: 'description', dtype: 'VARCHAR' },
], C.odonto);

const tooth_status = addTable('t_ts', 'tooth_status', 1660, 380, [
  { name: 'tooth_status_id', type: 'PK', dtype: 'INT' },
  { name: 'record_id', type: 'FK', dtype: 'INT' },
  { name: 'tooth_selected', type: 'FK', dtype: 'INT' },
  { name: 'status_type_id', type: 'FK', dtype: 'INT' },
  { name: 'tooth_surface', dtype: 'VARCHAR' },
  { name: 'status', dtype: 'ENUM' },
  { name: 'notes', dtype: 'TEXT' },
], C.odonto);

// Col 8: Billing (x=1920)
const billing = addTable('t_bill', 'billing', 1920, 40, [
  { name: 'billing_id', type: 'PK', dtype: 'INT' },
  { name: 'appointment_id', type: 'FK', dtype: 'INT UQ' },
  { name: 'user_id', type: 'FK', dtype: 'INT' },
  { name: 'processed_by', type: 'FK', dtype: 'INT' },
  { name: 'total_amount', dtype: 'DECIMAL' },
  { name: 'amount_paid', dtype: 'DECIMAL' },
  { name: 'balance', dtype: 'DECIMAL' },
  { name: 'subtotal_amount', dtype: 'DECIMAL' },
  { name: 'discount_type', dtype: 'ENUM' },
  { name: 'discount_percent', dtype: 'DECIMAL' },
  { name: 'discount_notes', dtype: 'VARCHAR' },
  { name: 'status', dtype: 'ENUM' },
  { name: 'created_at', dtype: 'DATETIME' },
], C.billing, 210);

const billing_line_items = addTable('t_bli', 'billing_line_items', 1920, 435, [
  { name: 'item_id', type: 'PK', dtype: 'INT' },
  { name: 'billing_id', type: 'FK', dtype: 'INT' },
  { name: 'appointment_id', type: 'FK', dtype: 'INT' },
  { name: 'service_id', type: 'FK', dtype: 'INT' },
  { name: 'description', dtype: 'VARCHAR' },
  { name: 'amount', dtype: 'DECIMAL' },
  { name: 'item_type', dtype: 'ENUM' },
  { name: 'created_at', dtype: 'TIMESTAMP' },
], C.billing, 210);

const billing_installments = addTable('t_bi', 'billing_installments', 1920, 705, [
  { name: 'installment_id', type: 'PK', dtype: 'INT' },
  { name: 'billing_id', type: 'FK', dtype: 'INT' },
  { name: 'total_amount', dtype: 'DECIMAL' },
  { name: 'down_payment_percent', dtype: 'DECIMAL' },
  { name: 'down_payment_amount', dtype: 'DECIMAL' },
  { name: 'num_terms', dtype: 'INT' },
  { name: 'created_at', dtype: 'TIMESTAMP' },
], C.billing, 210);

const billing_installment_terms = addTable('t_bit', 'billing_installment_terms', 1920, 950, [
  { name: 'term_id', type: 'PK', dtype: 'INT' },
  { name: 'installment_id', type: 'FK', dtype: 'INT' },
  { name: 'term_number', dtype: 'INT' },
  { name: 'due_date', dtype: 'DATE' },
  { name: 'amount', dtype: 'DECIMAL' },
  { name: 'paid_amount', dtype: 'DECIMAL' },
  { name: 'status', dtype: 'ENUM' },
  { name: 'paid_at', dtype: 'DATETIME' },
  { name: 'due_soon_reminder_sent_at', dtype: 'DATETIME' },
  { name: 'overdue_reminder_sent_at', dtype: 'DATETIME' },
], C.billing, 210);

const payments = addTable('t_pay', 'payments', 1920, 1270, [
  { name: 'payment_id', type: 'PK', dtype: 'INT' },
  { name: 'billing_id', type: 'FK', dtype: 'INT' },
  { name: 'amount', dtype: 'DECIMAL' },
  { name: 'payment_method', dtype: 'VARCHAR' },
  { name: 'or_number', dtype: 'VARCHAR' },
  { name: 'notes', dtype: 'VARCHAR' },
  { name: 'recorded_by', type: 'FK', dtype: 'INT' },
  { name: 'paid_at', dtype: 'DATETIME' },
], C.billing, 210);

const invoices = addTable('t_inv', 'invoices', 1920, 1540, [
  { name: 'invoice_id', type: 'PK', dtype: 'INT' },
  { name: 'billing_id', type: 'FK', dtype: 'INT' },
  { name: 'invoice_number', dtype: 'VARCHAR UQ' },
  { name: 'issued_at', dtype: 'DATETIME' },
  { name: 'issued_by', type: 'FK', dtype: 'INT' },
], C.billing, 210);

// Col 9: Other (x=2190)
const notifications = addTable('t_notif', 'notifications', 2190, 40, [
  { name: 'notification_id', type: 'PK', dtype: 'INT' },
  { name: 'user_id', type: 'FK', dtype: 'INT' },
  { name: 'title', dtype: 'VARCHAR' },
  { name: 'body', dtype: 'TEXT' },
  { name: 'link', dtype: 'VARCHAR' },
  { name: 'is_read', dtype: 'TINYINT' },
  { name: 'created_at', dtype: 'DATETIME' },
], C.other);

const contact_messages = addTable('t_cm', 'contact_messages', 2190, 285, [
  { name: 'message_id', type: 'PK', dtype: 'INT' },
  { name: 'full_name', dtype: 'VARCHAR' },
  { name: 'email', dtype: 'VARCHAR' },
  { name: 'phone_number', dtype: 'VARCHAR' },
  { name: 'message', dtype: 'TEXT' },
  { name: 'status', dtype: 'ENUM' },
  { name: 'source', dtype: 'VARCHAR' },
  { name: 'gmail_uid', dtype: 'VARCHAR' },
  { name: 'read_by', type: 'FK', dtype: 'INT' },
  { name: 'read_at', dtype: 'DATETIME' },
  { name: 'created_at', dtype: 'TIMESTAMP' },
], C.other, 210);

const clinic_settings = addTable('t_cs', 'clinic_settings', 2190, 630, [
  { name: 'setting_key', type: 'PK', dtype: 'VARCHAR' },
  { name: 'setting_value', dtype: 'TEXT' },
  { name: 'description', dtype: 'VARCHAR' },
  { name: 'updated_at', dtype: 'DATETIME' },
], C.other);

const rate_limit_hits = addTable('t_rlh', 'rate_limit_hits', 2190, 800, [
  { name: 'hit_id', type: 'PK', dtype: 'INT' },
  { name: 'bucket', dtype: 'VARCHAR' },
  { name: 'identifier', dtype: 'VARCHAR' },
  { name: 'created_at', dtype: 'DATETIME' },
], C.other, 210);

// ========================================
// RELATIONSHIPS (Edges)
// ========================================
const eColor = '#94a3b8';

// Users & Auth relationships
addEdge('e1', role.role_id, users.role_id, '1:N', eColor);
addEdge('e2', users.user_id, users.added_by, 'self', '#a78bfa');
addEdge('e3', users.user_id, patients.added_by, '1:N', eColor);
addEdge('e4', users.user_id, user_logs.user_id, '1:N', eColor);
addEdge('e5', users.user_id, system_logs.user_id, '1:N', eColor);
addEdge('e6', users.user_id, backup.user_id, '1:N', eColor);

// Patient relationships
addEdge('e10', patients.patient_id, patient_profiling.patient_id, '1:N', '#86efac');
addEdge('e11', patients.patient_id, patient_logs.patient_id, '1:N', '#86efac');
addEdge('e12', patients.patient_id, patient_notifications.patient_id, '1:N', '#86efac');

// Doctor relationships
addEdge('e20', users.user_id, dentist_specialization.user_id, '1:N', '#5eead4');
addEdge('e21', specialization.specialization_id, dentist_specialization.specialization_id, '1:N', '#5eead4');
addEdge('e22', users.user_id, doctor_availability.user_id, '1:N', '#5eead4');
addEdge('e23', users.user_id, doctor_leaves.user_id, '1:N', '#5eead4');

// Services relationships
addEdge('e30', users.user_id, services.added_by, '1:N', '#7dd3fc');
addEdge('e31', services.service_id, timeslot_service.service_id, '1:1', '#7dd3fc');
addEdge('e32', users.user_id, timeslot.doctor_id, '1:N', '#7dd3fc');

// Appointment relationships
addEdge('e40', patients.patient_id, appointments.patient_id, '1:N', '#f9a8d4');
addEdge('e41', users.user_id, appointments.user_id, '1:N', '#f9a8d4');
addEdge('e42', timeslot.timeslot_id, appointments.timeslot_id, '1:N', '#f9a8d4');
addEdge('e43', bookings.booking_id, appointments.booking_id, '1:N', '#f9a8d4');
addEdge('e44', appointments.appointment_id, appointments.rescheduled_from, 'self', '#a78bfa');
addEdge('e45', appointments.appointment_id, arr.appointment_id, '1:N', '#f9a8d4');
addEdge('e46', patients.patient_id, arr.patient_id, '1:N', '#f9a8d4');
addEdge('e47', users.user_id, arr.requested_doctor_id, '1:N', '#f9a8d4');
addEdge('e48', users.user_id, arr.reviewed_by, '1:N', '#f9a8d4');
addEdge('e49', appointments.appointment_id, selected_services.appointment_id, '1:N', '#f9a8d4');
addEdge('e50', services.service_id, selected_services.service_id, '1:N', '#f9a8d4');
addEdge('e51', appointments.appointment_id, appointment_media.appointment_id, '1:N', '#f9a8d4');
addEdge('e52', users.user_id, appointment_media.uploaded_by, '1:N', '#f9a8d4');
addEdge('e53', appointments.appointment_id, appointment_doctor_notes.appointment_id, '1:N', '#f9a8d4');
addEdge('e54', users.user_id, appointment_doctor_notes.doctor_id, '1:N', '#f9a8d4');
addEdge('e55', appointments.appointment_id, patient_schedules.appointment_id, '1:N', '#f9a8d4');
addEdge('e56', patient_schedules.schedule_id, schedule_reminders.patient_schedule_id, '1:N', '#f9a8d4');

// Clinical Records relationships
addEdge('e60', appointments.appointment_id, clinical_records.appointment_id, '1:N', '#c4b5fd');
addEdge('e61', users.user_id, clinical_records.doctor_id, '1:N', '#c4b5fd');
addEdge('e62', patients.patient_id, clinical_records.patient_id, '1:N', '#c4b5fd');
addEdge('e63', clinical_records.record_id, clinical_record_images.record_id, '1:N', '#c4b5fd');
addEdge('e64', clinical_records.record_id, clinical_record_services.record_id, '1:N', '#c4b5fd');
addEdge('e65', clinical_records.record_id, treatment_plans.record_id, '1:N', '#c4b5fd');
addEdge('e66', clinical_records.record_id, treatment_materials.record_id, '1:N', '#c4b5fd');
addEdge('e67', clinical_records.record_id, patient_profiling.record_id, '1:N', '#c4b5fd');

// Odontogram relationships
addEdge('e70', tooth.tooth_id, tooth_status.tooth_selected, '1:N', '#fcd34d');
addEdge('e71', tooth_status_type.status_type_id, tooth_status.status_type_id, '1:N', '#fcd34d');
addEdge('e72', clinical_records.record_id, tooth_status.record_id, '1:N', '#fcd34d');

// Billing relationships
addEdge('e80', appointments.appointment_id, billing.appointment_id, '1:1', '#fca5a5');
addEdge('e81', users.user_id, billing.user_id, '1:N', '#fca5a5');
addEdge('e82', billing.billing_id, billing_line_items.billing_id, '1:N', '#fca5a5');
addEdge('e83', services.service_id, billing_line_items.service_id, '1:N', '#fca5a5');
addEdge('e84', billing.billing_id, billing_installments.billing_id, '1:1', '#fca5a5');
addEdge('e85', billing_installments.installment_id, billing_installment_terms.installment_id, '1:N', '#fca5a5');
addEdge('e86', billing.billing_id, payments.billing_id, '1:N', '#fca5a5');
addEdge('e87', billing.billing_id, invoices.billing_id, '1:1', '#fca5a5');

// Other relationships
addEdge('e90', users.user_id, notifications.user_id, '1:N', '#cbd5e1');
addEdge('e91', users.user_id, contact_messages.read_by, '1:N', '#cbd5e1');

// Prescription -> appointments
addEdge('e92', appointments.appointment_id, prescriptions.appointment_id, '1:N', '#c4b5fd');

// New tables (patient_feedback, doctor_prescription_profiles, clinical_findings, rate_limit_hits)
addEdge('e93', patients.patient_id, patient_feedback.patient_id, '1:1', '#86efac');
addEdge('e94', appointments.appointment_id, patient_feedback.appointment_id, '1:N', '#86efac');
addEdge('e95', users.user_id, patient_feedback.doctor_id, '1:N', '#86efac');
addEdge('e96', users.user_id, doctor_prescription_profiles.user_id, '1:1', '#5eead4');
addEdge('e97', clinical_records.record_id, clinical_findings.record_id, '1:1', '#c4b5fd');
addEdge('e98', patients.patient_id, clinical_findings.patient_id, '1:N', '#c4b5fd');

// Timeslot -> appointment_id (already shown via appointments FK)

// ========================================
// BUILD XML
// ========================================

function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

let xml = `<?xml version="1.0" encoding="UTF-8"?>
<mxfile host="app.diagrams.net" modified="${new Date().toISOString()}" agent="MiMoCode" version="24.0.0" type="device">
  <diagram id="erd-dental-complete" name="Complete ERD">
    <mxGraphModel dx="3400" dy="2800" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="3200" pageHeight="2800" math="0" shadow="0">
      <root>
        <mxCell id="0"/>
        <mxCell id="1" parent="0"/>
`;

// Add group labels (background boxes)
const groups = [
  { label: 'USERS & AUTH', x: 20, y: 10, w: 230, h: 1840, color: '#dbeafe' },
  { label: 'PATIENTS', x: 280, y: 10, w: 230, h: 2095, color: '#dcfce7' },
  { label: 'DOCTOR MANAGEMENT', x: 540, y: 10, w: 240, h: 1195, color: '#ccfbf1' },
  { label: 'SERVICES & SCHEDULING', x: 800, y: 10, w: 240, h: 805, color: '#e0f2fe' },
  { label: 'APPOINTMENTS', x: 1060, y: 10, w: 270, h: 2680, color: '#fce7f3' },
  { label: 'CLINICAL RECORDS', x: 1370, y: 10, w: 240, h: 1785, color: '#f3e8ff' },
  { label: 'ODONTOGRAM', x: 1640, y: 10, w: 220, h: 605, color: '#fef3c7' },
  { label: 'BILLING', x: 1900, y: 10, w: 230, h: 1715, color: '#fee2e2' },
  { label: 'OTHER', x: 2170, y: 10, w: 230, h: 950, color: '#f1f5f9' },
];

groups.forEach(g => {
  const id = nid();
  cells.push({
    id, value: `<b>${g.label}</b>`, 
    style: `rounded=1;whiteSpace=wrap;html=1;fillColor=${g.color};strokeColor=none;verticalAlign=top;fontSize=14;fontColor=#333333;spacingTop=5;opacity=30;`,
    vertex: 1, parent: '1',
    geom: { x: g.x, y: g.y, w: g.w, h: g.h }
  });
});

// Output cells
cells.forEach(c => {
  const g = c.geom;
  xml += `        <mxCell id="${c.id}" value="${esc(c.value)}" style="${c.style}" vertex="${c.vertex || 0}" parent="${c.parent}">\n`;
  xml += `          <mxGeometry x="${g.x}" y="${g.y}" width="${g.w}" height="${g.h}" as="geometry"/>\n`;
  xml += `        </mxCell>\n`;
});

// Output edges
edges.forEach(e => {
  xml += `        <mxCell id="${e.id}" value="${esc(e.value)}" style="${e.style}" edge="${e.edge}" source="${e.source}" target="${e.target}" parent="${e.parent}">\n`;
  xml += `          <mxGeometry relative="1" as="geometry"/>\n`;
  xml += `        </mxCell>\n`;
});

xml += `      </root>
    </mxGraphModel>
  </diagram>
</mxfile>
`;

fs.writeFileSync('C:\\xampp\\htdocs\\capstone_dental\\capstone_dental_erd.drawio', xml, 'utf8');
console.log(`ERD generated with ${cells.length} cells and ${edges.length} edges`);
console.log('File: capstone_dental_erd.drawio');
