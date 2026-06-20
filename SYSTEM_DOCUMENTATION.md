# Azucena Dental Clinic Management System — Technical Documentation

## System Overview

A web-based dental clinic management system built with PHP 8.3, MySQL, and Bootstrap 5. Supports 4 user roles: Admin, Doctor, Secretary, and Patient. Deployed on InfinityFree shared hosting.

**Tech Stack:** PHP 8.3 | MySQL | XAMPP (dev) | InfinityFree (prod) | Bootstrap 5 | FullCalendar

---

## Features

### Multi-Role Access Control
- **Admin** — Dashboard, user management, doctor management, reports, services, system logs, AI analytics
- **Doctor** — Appointments, EHR (Electronic Health Records), patient records, odontogram, leave requests
- **Secretary** — Appointments, billing, scheduling, patient management, contact messages
- **Patient** — Self-registration, appointment booking, reschedule requests, billing view, transactions

### Core Modules
- **Appointment System** — Online booking, timeslot management, status workflow (scheduled → confirmed → checked_in → in_progress → completed)
- **Reschedule Workflow** — Patient requests → Secretary approves/rejects → Real-time UI updates
- **Electronic Health Records (EHR)** — Clinical records, treatment plans, odontogram, photo uploads
- **Billing & Payments** — Line items, discounts (senior/PWD/custom), partial payments, installment tracking
- **Odontogram** — Interactive 2D/3D dental chart with SVG rendering
- **Notifications** — In-app notifications for all roles, workflow-driven
- **Reports** — Revenue analytics, appointment statistics, doctor performance
- **AI Chat** — Groq-powered analytics chatbot for admin

---

## Security Implementation

### Authentication & Authorization
| Feature | Implementation |
|---------|---------------|
| Password Hashing | `PASSWORD_BCRYPT` with automatic rehash via `password_needs_rehash()` |
| Password Policy | Min 8 chars, uppercase, lowercase, number, special character |
| Account Lockout | 5 failed attempts → 15-minute lock (users & patients) |
| CAPTCHA | Math-based CAPTCHA after failed login attempts |
| Session Regeneration | `session_regenerate_id(true)` on every login |
| Role-Based Access | `check_session()` enforces allowed roles per page |
| Forced Password Change | Admin-generated passwords require change on first login |

### Session Security
| Setting | Value |
|---------|-------|
| `httponly` | true |
| `samesite` | Lax |
| `use_strict_mode` | 1 |
| `use_only_cookies` | 1 |
| Idle Timeout (Patient) | 15 minutes |
| Idle Timeout (Admin) | 60 minutes |
| Idle Timeout (Doctor/Secretary) | Session-based |

### Input/Output Protection
| Threat | Protection |
|--------|------------|
| SQL Injection | 769+ prepared statements with `bind_param()` |
| XSS (Cross-Site Scripting) | 422+ `htmlspecialchars()` calls on all output |
| CSRF (Cross-Site Request Forgery) | Token validation via `hash_equals()` on all forms/APIs |
| MIME Sniffing | `X-Content-Type-Options: nosniff` header |
| Clickjacking | `X-Frame-Options: SAMEORIGIN` header |
| Referrer Leakage | `Referrer-Policy: strict-origin-when-cross-origin` |

### File Upload Security
| Check | Implementation |
|-------|---------------|
| MIME Validation | `finfo_open(FILEINFO_MIME_TYPE)` — not just extension check |
| Size Limits | 2MB (profile), 5MB (clinical images) |
| Upload Guard | `is_uploaded_file()` verification |
| Script Execution Block | `.htaccess` in `assets/uploads/` denies PHP execution |
| Random Filenames | `bin2hex(random_bytes())` prevents path traversal |

### Infrastructure Security
| Layer | Protection |
|-------|------------|
| Environment Variables | `.env` for credentials, excluded from git |
| Database Credentials | Never hardcoded, loaded via `env()` |
| `.htaccess` Rules | Blocks access to `config/`, `scripts/`, `.env`, `.sql` |
| HTTPS Enforcement | Auto-redirect on production (skips localhost) |
| Secure Cookies | `SECURE_COOKIES=true` on production |
| Audit Logging | `user_logs`, `patient_logs`, `system_logs` with IP tracking |

---

## Performance Optimizations

### Client-Side Caching (`assets/js/dental-cache.js`)
- **SessionStorage cache** with configurable TTL per data type
- **`DentalCache.cachedFetch(url, { ttl })`** — drop-in replacement for `fetch()`
- **`DentalCache.invalidate('pattern')`** — clear cache after data mutations

| Data Type | TTL | Rationale |
|-----------|-----|-----------|
| Available dates | 60s | Changes rarely |
| Timeslots | 15s | Changes occasionally |
| Appointment history | 10s | Changes on actions |
| Change detection | 2s | Near real-time |

### Two-Tier Polling
- **Lightweight check** (`check_patient_changes.php`) every 3 seconds — returns a signature hash (max IDs + count)
- **Full data fetch** only when signature changes — avoids redundant queries
- **Database index** `(patient_id, appointment_date DESC)` for optimized queries

### Optimistic UI (`assets/js/dental-ui.js`)
- **`DentalUI.btnLoading(btn, text)`** — instant spinner feedback on buttons
- **`DentalUI.btnRestore(btn)`** — restore button state after completion
- **`DentalUI.debounce(fn, delay)`** — prevents rapid-fire API calls
- **`DentalUI.showSkeleton(container, rows, cols)`** — skeleton loading for tables

### Before vs After
| Metric | Before | After |
|--------|--------|-------|
| Repeated API calls | Every page load | Cached (TTL-based) |
| Change detection | Full query every 15s | Signature check every 3s |
| Button feedback | Delayed (wait for response) | Instant (loading state) |
| Data freshness | Manual refresh needed | Auto-updates within 3 seconds |

---

## Real-Time Features

### Appointment Reschedule Flow
1. Patient submits reschedule request → Secretary gets in-app notification
2. Secretary clicks notification → Redirected to Reschedule Requests tab
3. Secretary approves/rejects → Appointment updated in database
4. Patient's page auto-detects change within 3 seconds → Table updates automatically

### Implementation
- **Change Detection API** (`check_patient_changes.php`) — lightweight signature comparison
- **History API** (`get_patient_history.php`) — full appointment data with joins
- **Polling** — 3-second interval with hash comparison, DOM update only on change
- **Cache Invalidation** — clears cache after mutations (create, reschedule, approve)

---

## Email & Notifications

### Email Delivery
| Mode | Method | Use Case |
|------|--------|----------|
| `smtp` | Gmail SMTP via raw sockets | Production (InfinityFree) |
| `brevo` | Brevo API (HTTPS) | SMS reminders |
| `mail` | PHP native `mail()` | Fallback (blocked on InfinityFree) |
| `log` | Error log only | Development |

### Notification Types
| Event | Patient | Doctor | Secretary |
|-------|---------|--------|-----------|
| Appointment created | ✓ | ✓ | ✓ |
| Appointment approved | ✓ | ✓ | — |
| Reschedule requested | ✓ | ✓ | ✓ |
| Reschedule approved/rejected | ✓ | ✓ | — |
| Patient arrived | ✓ | ✓ | — |
| Doctor finished | — | — | ✓ |
| Record submitted | ✓ | ✓ | ✓ |

### External Cron (InfinityFree)
- **Endpoint:** `scripts/cron_reminders.php?key=<secret>`
- **Service:** cron-job.org (free tier)
- **Schedule:** Every 5 minutes
- **Security:** Secret key + rate limiting (max once per 5 min)

---

## Database Design

### Key Tables
| Table | Purpose |
|-------|---------|
| `appointments` | Core appointment records with status workflow |
| `appointment_reschedule_requests` | Patient reschedule requests with approval workflow |
| `patients` | Patient profiles with auth credentials |
| `users` | Staff accounts (admin, doctor, secretary) |
| `role` | Role definitions |
| `timeslot` | Available time slots per doctor per date |
| `patient_schedules` | Scheduled appointment times |
| `billing` / `billing_line_items` | Financial records |
| `clinical_records` | EHR data with JSON fields |
| `notifications` / `patient_notifications` | In-app notification system |
| `schedule_reminders` | Queued email/SMS/push reminders |
| `system_logs` | Audit trail |
| `user_logs` / `patient_logs` | Session tracking |

### Indexes
- `appointments(patient_id, appointment_date DESC)` — optimized patient history
- `appointment_reschedule_requests(patient_id, status)` — reschedule lookups
- `appointment_reschedule_requests(appointment_id, status)` — appointment reschedule checks

---

## Deployment

### InfinityFree Configuration
| Setting | Value |
|---------|-------|
| PHP Version | 8.3 |
| Database | MySQL (InfinityFree hosted) |
| Email | Gmail SMTP |
| Cron | cron-job.org (external) |
| SSL | InfinityFree free SSL |
| File Manager | VistaPanel |

### Environment Variables (.env)
```
DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT
APP_ENV, APP_TIMEZONE, APP_URL
SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS
CRON_SECRET_KEY
ADMIN_EMAIL, ADMIN_PASSWORD
GOOGLE_CLIENT_ID
GROQ_API_KEY
```

---

## Files Modified/Created (This Session)

| File | Type | Purpose |
|------|------|---------|
| `.htaccess` | Modified | Security headers, upload protection |
| `assets/uploads/.htaccess` | New | Block PHP execution in uploads |
| `scripts/.htaccess` | New | Allow only cron endpoint |
| `scripts/cron_reminders.php` | New | Web-accessible cron endpoint |
| `assets/js/dental-cache.js` | New | Client-side caching utility |
| `assets/js/dental-ui.js` | New | Loading states & debounce utility |
| `assets/api/appointments/check_patient_changes.php` | New | Lightweight change detection |
| `assets/api/appointments/get_patient_history.php` | New | Patient history API with index |
| `config/workflow_notifications.php` | Fixed | Corrected notification links |
| `config/reminders.php` | Existing | SMTP support (already implemented) |
| `assets/api/ehr/save_clinical_record.php` | Fixed | Directory permissions (0777→0755) |
| `admin/admin.php` | Modified | Added cache/UI scripts |
| `doctor/doctor.php` | Modified | Added cache/UI scripts |
| `secretary/secretary.php` | Modified | Added cache/UI scripts |
| `patient/patient.php` | Modified | Added cache/UI scripts |
| `patient/patient_modules/appointments.php` | Modified | Caching, polling, optimistic UI |

---

**Last Updated:** June 18, 2026
