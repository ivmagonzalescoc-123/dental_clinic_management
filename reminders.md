1. Default Appointment Pack — runs when an appointment is confirmed/scheduled:
- 24 hours prior → Email to patient (template: appt_24h)
- 1 hour prior → In-app notification to secretary (template: appt_1h_staff)
- 1 hour prior → In-app notification to patient (template: appt_1h_patient)
2. Missed Appointment Pack — queued by the cron worker when a slot passes without check-in:
- 5 minutes past the scheduled time → SMS to patient (template: appt_missed_patient)
3. No-Show Auto-Mark — runs at 11 PM daily:
- Any scheduled/confirmed appointment for today that hasn't started gets flipped to no_show
- Timeslot is freed back to available
- Triggers the no-show followup pack
4. No-Show Followup Pack — triggered after the auto-mark above:
- Immediately → Email to patient (template: appt_no_show_patient)
- Immediately → SMS to patient (template: appt_no_show_patient)
5. Billing Installment Reminders — from billing_helpers.php:
- Day before due date → Email to patient (due-soon reminder)
- Day after due date → Email to patient (overdue reminder)
6. Cron Worker Interval:
- Checks every 5 minutes via the auto-cron (local dev) or external service (production)
- Rate-limited: won't re-run within 5 minutes of last execution