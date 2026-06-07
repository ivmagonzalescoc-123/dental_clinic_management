@echo off
REM Run every 5-15 minutes via Windows Task Scheduler.
set PHP_EXE=C:\xampp\php\php.exe
set PROJECT_DIR=C:\xampp\htdocs\capstone_dental
"%PHP_EXE%" "%PROJECT_DIR%\scripts\send_reminders.php"
