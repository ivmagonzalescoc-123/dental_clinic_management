# Azucena's Dental Clinic Management System

Web-based system for appointments, patient records, dental charts (odontogram), billing and reports.
Roles: Admin, Doctor, Secretary and Patient.

**Stack:** PHP 8.2+, MySQL / MariaDB, Apache (XAMPP or Laragon locally, InfinityFree in production), Bootstrap 5.

- How to use the system (for clinic staff and patients): [handbook/](handbook/)
- Technical overview: [SYSTEM_DOCUMENTATION.md](SYSTEM_DOCUMENTATION.md)
- Every setting in `.env` explained: [ENV_SETUP_GUIDE.md](ENV_SETUP_GUIDE.md)

## Local setup

1. Put the project in your web root, e.g. `C:\xampp\htdocs\capstone_dental` or `C:\laragon\www\capstone_dental`.
2. Install the PHP libraries (PDF receipts, Gmail inbox sync):
   ```
   composer install
   ```
3. Create the settings file and fill in your values:
   ```
   copy .env.example .env
   ```
   The defaults work for a local XAMPP/Laragon database named `capstone_dental` with user `root` and no password.
4. Create an empty database named `capstone_dental` (utf8mb4) and import
   `sql/final/dental_management_production_v1.sql`. It has the full schema and no patient data.
5. Open `http://localhost/capstone_dental`.

### Starter accounts

The starter database has three staff accounts: `admin@gmail.com`, `doctor@gmail.com` and `secretary@gmail.com`.
Change their emails and passwords right after the first login. To set a new admin password from the command line:

```
php scripts/reset_admin_password.php --email admin@gmail.com "NewPassword123!"
```

## Going live (InfinityFree or other shared hosting)

1. Upload all files **except** `.env` from your computer; create a new `.env` on the server from `.env.example`.
2. In `.env` set `APP_ENV=production`, `APP_URL` / `RESET_PASSWORD_URL` to the live address,
   `EMAIL_MODE=smtp`, `REMINDER_EMAIL_MODE=smtp` (or `brevo`), `REMINDER_SMS_MODE=brevo`,
   and real Turnstile, SMTP and API keys.
3. Import `sql/final/dental_management_production_v1.sql` through phpMyAdmin.
4. Reminders and backups do not run on their own in production. Create two jobs on
   [cron-job.org](https://cron-job.org) (every 5 minutes for reminders, daily for backups):
   ```
   https://YOUR-DOMAIN/scripts/cron_reminders.php?key=CRON_SECRET_KEY
   https://YOUR-DOMAIN/scripts/cron_backup.php?key=CRON_SECRET_KEY
   ```

## Database changes

Schema changes made after the starter database are in `sql/migrations/` (already included in
`dental_management_production_v1.sql`). Run new ones in date order on an existing database.

## Folders

| Folder | What is in it |
|---|---|
| `admin/`, `doctor/`, `secretary/`, `patient/` | The pages for each role |
| `assets/api/` | Endpoints the pages call |
| `config/` | Settings, helpers, security, email and reminder logic |
| `odontogram/` | Dental chart |
| `scripts/` | Cron jobs and maintenance scripts (blocked from the web except the cron endpoints) |
| `backups/` | Database backups made from the Admin > Backups page (not in Git, blocked from the web) |
| `sql/` | Starter database and migrations |

Never commit `.env`, database dumps or the `backups/` folder. They contain passwords and patient data.
