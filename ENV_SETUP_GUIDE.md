# Environment Configuration Guide

## Overview
The Capstone Dental system now uses environment variables for configuration management. This allows you to:
- Keep sensitive credentials out of version control
- Easily switch between development and production settings
- Deploy to different environments without code changes

## Setup Instructions

### 1. Local Development (Already Configured)

Your `.env` file is already set up for local development:

```env
DB_HOST=localhost
DB_USER=root
DB_PASS=
DB_NAME=capstone_dental
DB_PORT=3306
APP_ENV=development
APP_TIMEZONE=Asia/Manila
```

**No action needed** — the system will automatically load these values.

### 2. Production Deployment

When deploying to production:

1. **Copy the template:**
   ```bash
   cp .env.example .env
   ```

2. **Edit `.env` with production values:**
   ```env
   DB_HOST=prod-db-server.example.com
   DB_USER=prod_user
   DB_PASS=strong_password_here
   DB_NAME=capstone_dental_prod
   DB_PORT=3306
   APP_ENV=production
   APP_TIMEZONE=Asia/Manila
   APP_URL=https://clinic.example.com
   SECURE_COOKIES=true
   SESSION_DOMAIN=.clinic.example.com
   ```

3. **Secure the `.env` file:**
   ```bash
   # Restrict file permissions (Linux/Mac)
   chmod 600 .env
   
   # Or set restricted access via server configuration
   ```

4. **Ensure `.env` is in `.gitignore`** (already done):
   ```
   .env
   .env.local
   ```

## Environment Variables Reference

| Variable | Description | Default | Example |
|----------|-------------|---------|---------|
| `DB_HOST` | Database server host | `localhost` | `db.example.com` |
| `DB_USER` | Database username | `root` | `clinic_user` |
| `DB_PASS` | Database password | `` (empty) | `SecureP@ss123` |
| `DB_NAME` | Database name | `capstone_dental` | `clinic_prod` |
| `DB_PORT` | Database port | `3306` | `3306` |
| `APP_ENV` | Application environment | `development` | `production` |
| `APP_TIMEZONE` | Server timezone | `Asia/Manila` | `UTC`, `America/New_York` |
| `APP_URL` | Application URL | `http://localhost/capstone_dental` | `https://clinic.example.com` |
| `SESSION_LIFETIME` | Cookie lifetime on issue; renewed on every active request (sliding window) | `1800` | `3600` |
| `SESSION_GC_MAXLIFETIME` | How long PHP keeps server-side session data with no site-wide activity — must be ≥ the longest role idle timeout (secretary: 2h) or PHP's garbage collector can delete a session before the app's own timeout logic runs | `21600` | `21600` |
| `SECURE_COOKIES` | Use secure cookies (HTTPS only) | `false` | `true` |
| `SESSION_DOMAIN` | Session domain | `` (empty) | `.example.com` |
| `SESSION_PATH` | Session path | `/` | `/clinic` |
| `ADMIN_EMAIL` | Admin account email | `admin@dentalclinic.com` | `admin@clinic.com` |
| `ADMIN_FIRST_NAME` | Admin first name | `Admin` | `Jane` |
| `ADMIN_LAST_NAME` | Admin last name | `User` | `Doe` |
| `ADMIN_PASSWORD` | Admin account password | `` (must set) | `SecureAdmin@123` |

## How It Works

### Environment Loading

The system uses two mechanisms to load environment variables:

1. **`.env` file** — Loaded automatically when `config/env.php` is included
2. **System environment variables** — Fallback if `.env` is not found (useful for Docker, cloud platforms)

### Usage in Code

To access environment variables in your code:

```php
require_once 'config/database.php';  // Already loads env via database.php

// Access variables using the env() function
$timezone = env('APP_TIMEZONE', 'Asia/Manila');
$db_host = env('DB_HOST', 'localhost');
$secure = env('SECURE_COOKIES', false);
```

### Auto-Loading

Environment variables are automatically loaded in:
- `config/database.php` — Database credentials and timezone
- `config/security.php` — Session and security settings

## Deployment Platforms

### Admin Account Seeding

The system can automatically create or update the admin account from environment variables using the seeding script:

```bash
php scripts/seed_admin.php
```

**When to use:**
- Initial deployment setup
- Resetting admin credentials
- Batch deployments with CI/CD
- Creating admin account programmatically

**Requirements:**
1. `ADMIN_EMAIL` — Valid email address
2. `ADMIN_PASSWORD` — Min 8 chars, 1 uppercase, 1 lowercase, 1 number, 1 special char

**Examples:**

Development:
```bash
# Set env vars, then run seeding
export ADMIN_EMAIL="admin@dentalclinic.com"
export ADMIN_PASSWORD="Admin@1234"
php scripts/seed_admin.php
```

Production (via Docker):
```yaml
environment:
  ADMIN_EMAIL: admin@clinic.com
  ADMIN_PASSWORD: ${ADMIN_PASS_SECRET}  # From secrets manager
```

Then run after deployment:
```bash
docker exec capstone-dental php scripts/seed_admin.php
```

**Output:**
```
=== Admin Account Seeding ===
Email: admin@dentalclinic.com
Name: Admin User

✓ Created new admin account (ID: 1)
✓ User ID: 1

=== Seeding Complete ===
Admin Login:
  Email: admin@dentalclinic.com
  Password: ****
```

### Docker
Set environment variables via `docker run -e` or `docker-compose.yml`:
```yaml
environment:
  DB_HOST: db-service
  DB_USER: clinic_user
  DB_PASS: ${DATABASE_PASSWORD}
  APP_ENV: production
```

### Cloud Platforms (Azure, AWS, Heroku)
1. Set environment variables in the platform's dashboard
2. Ensure `.env` file is not deployed (already in `.gitignore`)
3. The `env()` function will read from system environment variables

### Shared Hosting
1. Copy `.env.example` to `.env`
2. Edit `.env` with your credentials
3. Upload to server (ensure `.env` is in `.gitignore` if using Git)
4. Set file permissions: `chmod 600 .env`

## Security Best Practices

✅ **DO:**
- Keep `.env` in `.gitignore` (already done)
- Use strong database passwords
- Change default credentials in production
- Rotate credentials regularly
- Set `SECURE_COOKIES=true` in production
- Restrict `.env` file permissions to owner only

❌ **DON'T:**
- Commit `.env` to version control
- Share `.env` credentials via email
- Use default credentials in production
- Store `.env` in public directories

## Troubleshooting

**Issue:** "Connection Error: Unable to connect to database"
- Check `.env` file exists in the root directory
- Verify database credentials in `.env`
- Ensure database server is running
- Check database name matches

**Issue:** "Session configuration not loading"
- Verify `config/env.php` is being loaded
- Check `.env` has correct `SESSION_*` variables
- Clear browser cookies and try again

**Issue:** "Timezone not changing"
- Edit `APP_TIMEZONE` in `.env`
- Restart your PHP application
- Verify timezone is valid (see `php --info` for list)

**Issue:** "seed_admin.php: ERROR - ADMIN_PASSWORD must be set in .env"
- Ensure `.env` file has `ADMIN_PASSWORD=` set
- Password must be at least 8 characters
- Password must include: uppercase, lowercase, number, special character

**Issue:** "seed_admin.php: ERROR - ADMIN_EMAIL must be a valid email address"
- Update `ADMIN_EMAIL` in `.env` with a valid email format
- Example: `ADMIN_EMAIL=admin@yourdomain.com`

**Issue:** "seed_admin.php: Failed to update admin account"
- Check database connection
- Verify `users` table exists
- Check database user has UPDATE privileges

## Migration from Hardcoded Config

If you're upgrading from an older version:

1. Note your current database credentials
2. Note your current admin credentials
3. Create `.env` file using the values from this guide
4. Update `ADMIN_EMAIL` and `ADMIN_PASSWORD` with your current admin account
5. Test on localhost first:
   ```bash
   php scripts/seed_admin.php
   ```
6. Deploy `.env.example` (not `.env`) to staging/production
7. Manually create `.env` on production servers with production credentials
8. Run the seeding script to update admin account:
   ```bash
   php scripts/seed_admin.php
   ```

---

**Last Updated:** 2026-05-11
