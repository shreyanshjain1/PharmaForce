# Pharmastar Medicine Sales CRM — New App Build

A clean PHP/MySQL tablet-first rebuild designed around the current production database dump: `pharmastar_reports`.

## What this new app keeps

- Existing `users` authentication with password hashes
- Existing `reports` table and report flow
- Existing `events` table as the Task Center / schedule system
- Existing `doctors_masterlist` as the doctor directory
- Existing manager / district manager / employee visibility concept

## What was removed

- Approval SLA dashboard clutter
- Global Quick Task panel appearing on every page
- Repeated dashboard widgets on unrelated pages
- Fake/empty chart frames
- Overloaded navigation

## Main pages

- `login.php` — Login
- `index.php` — Dashboard
- `reports.php` — Reports workspace
- `report_form.php` — Create/edit report
- `report_view.php` — Report details and manager review
- `tasks.php` — Task Center
- `analytics.php` — Filter-aware KPI analytics
- `doctors.php` — Doctor masterlist
- `users.php` — User management
- `profile.php` — Profile/logout

## Install on XAMPP

1. Create/import the database in phpMyAdmin using your production SQL dump.
2. Put this folder in `htdocs`, for example:

```txt
C:\xampp\htdocs\pharmastar_crm_new_app
```

3. Edit `config/database.php` only if your database credentials are not the XAMPP default.

Default config:

```php
host: localhost
database: pharmastar_reports
username: root
password: empty
```

4. Open:

```txt
http://localhost/pharmastar_crm_new_app/login.php
```

## Notes

- No Composer required.
- No Node/Vite/build step required.
- No database migration required.
- Uses plain PHP, PDO, CSS, and vanilla JavaScript.
- Designed for Android tablet use with large touch targets and responsive layouts.

## Commit message

```bash
git add .
git commit -m "feat: rebuild CRM as clean tablet-first PHP app"
```
