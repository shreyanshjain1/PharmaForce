# PharmaForce

A tablet-first PHP/MySQL sales reporting CRM built for Pharmastar field teams, designed around real pharmaceutical sales workflows: doctor visits, field reports, daily visit planning, task scheduling, manager review, signatures, geotagging, doctor location mapping, visit verification, expenses, approvals, security, and KPI analytics.

![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-Database-4479A1?style=for-the-badge)
![PDO](https://img.shields.io/badge/PDO-Secure%20DB%20Access-0F766E?style=for-the-badge)
![JavaScript](https://img.shields.io/badge/JavaScript-Vanilla-F7DF1E?style=for-the-badge&logo=javascript&logoColor=111)
![Leaflet](https://img.shields.io/badge/Leaflet-Map%20Pin%20Setup-199900?style=for-the-badge&logo=leaflet&logoColor=white)
![XAMPP](https://img.shields.io/badge/XAMPP-Compatible-FB7A24?style=for-the-badge&logo=xampp&logoColor=white)

## Overview

PharmaForce is a clean rebuild of a medicine sales reporting system for internal Pharmastar field operations. It is built around the current production database structure and focuses on workflows that matter to pharmaceutical field teams: daily visit planning, doctor coverage, field reporting, task scheduling, manager approvals, expense reporting, signature capture, geotagging, visit verification, and activity auditing.

The interface is designed for Android tablet usage in hospital and field environments, with large touch-friendly controls, responsive layouts, clean navigation, and focused pages instead of cluttered all-in-one dashboards.

## Key Features

### Field Sales Reporting

- Create new doctor visit reports
- Edit existing reports
- View full report details
- Generate reports directly from tasks or doctor profiles
- Prefill report details from task and doctor records
- Capture and display doctor signatures
- Automatically capture signature geotag on signing
- Clear signature and geotag together for safer re-signing
- Display embedded map preview for captured report location
- Print/export report pages using the browser print flow
- Track report status for manager review
- Store report purpose, product/medicine, summary, and remarks

### Plan Your Day

- Select a visit date and area
- Load doctors from the selected area
- Select multiple doctors for the day
- Set visit time, purpose, product/medicine, and notes per doctor
- Add doctors from another area
- Add unlisted doctors that are not yet in the masterlist
- Create multiple planned visits/tasks in one submit
- Send planned visits into the existing task/calendar workflow

### Daily Call Report

- View daily planned visits and submitted reports
- Filter by date and sales representative
- Track planned visits, reported visits, pending visits, missed visits, signatures, and verified visits
- Match planned visits to submitted reports by doctor/date/user
- Fallback matching for unlisted doctors by doctor name and hospital
- Create reports directly from planned visits
- Open related task, report, or doctor profile from the DCR table

### Doctor Masterlist and Profiles

- View doctor directory records
- Generate reports from doctor profiles
- Prefill basic doctor details into new reports
- View doctor visit history and related tasks
- Schedule 7-day, 14-day, and 30-day follow-up tasks
- Use the existing `doctors_masterlist` table as the source of truth

### Doctor Map Pin Location

- Set a clinic pin on a map from the doctor profile
- Search Philippine clinics, hospitals, cities, and barangays
- Show search suggestions while typing
- Restrict map search and saving to the Philippines
- Use current device location while physically at the clinic
- Drag or click the map pin manually
- Save allowed visit radius per doctor
- Open saved clinic pin in Google Maps
- Clear saved clinic location when needed

### Visit Verification

- Compare doctor saved clinic pin against report signature geotag
- Calculate distance using server-side geofence logic
- Show automatic verification status on report view and DCR
- Support statuses such as:
  - Verified Visit
  - Outside Radius
  - Doctor Pin Missing
  - Signature Location Missing
  - Manual Review Needed
  - Manually Verified
  - Manually Rejected
- Allow managers to manually verify reports when a doctor clinic pin is missing
- Do not block reps from submitting reports when doctor pins are not yet configured

### Task Center

- Create scheduled field tasks
- Assign tasks to representatives
- Filter doctors by selected city during task creation
- Generate a report directly from a task
- Prefill report details from task and doctor records
- Track task status and follow-up workflows

### Expenses

- Create liquidation of expense reports
- Add itemized expense rows
- Upload and preview receipts
- Track totals by expense category
- Review expenses through manager workflows
- Keep expense reporting tablet-friendly and readable

### Approval Center

- Centralized approval workflow for reports and expenses
- Manager and district manager review support
- Final status synchronization with related reports or expenses
- Review comments synchronized back to the related record

### Dashboard

- Sales report overview cards
- Work calendar
- Recent report activity
- Analytics preview by representative
- Focused layout without SLA clutter or global quick-task panels

### Analytics

- Filter-aware KPI reporting
- Sales representative activity
- Report status overview
- Doctor coverage tracking
- Business-focused reporting without unnecessary placeholder charts

### User and Manager Workflows

- Existing user authentication
- Manager visibility and review workflow
- District manager visibility support
- Role-aware access logic
- Profile page and logout flow
- User activation/deactivation safety checks

### Security and Auditability

- Secure session and login hardening
- CSRF protection for forms
- Basic login rate limiting
- Safer upload validation
- Upload folder execution protection
- Security Center page for internal checks
- File Upload Security Center for reviewing uploaded files
- Activity logging for major actions
- Centralized permission matrix foundation
- Permission-based access checks for key modules
- Audit logs for login, logout, reports, expenses, tasks, users, doctors, approvals, file security, DCR views, and manual visit verification

## Why This Rebuild Exists

The original system had useful backend workflows, but the interface had become crowded and difficult to use on tablets. PharmaForce keeps the important business logic while removing unnecessary dashboard clutter, repeated widgets, SLA noise, and global quick-task sections that distracted users from the actual page they were using.

The goal is a cleaner internal CRM experience for real field teams, while gradually adding more pharmaceutical sales features such as day planning, DCR tracking, geotagged reports, doctor clinic mapping, and visit verification.

## Main Pages

| Page | Purpose |
| --- | --- |
| `login.php` | User login |
| `index.php` | Dashboard and calendar overview |
| `my_work.php` | User work center |
| `plan_day.php` | Plan daily doctor visits by area |
| `dcr.php` | Daily Call Report dashboard |
| `reports.php` | Reports workspace |
| `report_form.php` | Create or edit report |
| `report_view.php` | Report details, signature, geotag, visit verification, manager review, print/export |
| `tasks.php` | Task Center and task-to-report workflow |
| `expenses.php` | Expense reporting and receipt tracking |
| `approvals.php` | Report and expense approval center |
| `analytics.php` | KPI analytics and filtered reporting |
| `doctors.php` | Doctor masterlist |
| `doctor_profile.php` | Doctor details, visit history, follow-ups, and clinic map pin |
| `users.php` | User management |
| `profile.php` | User profile and logout access |
| `security.php` | Internal Security Center |
| `file_security.php` | Uploaded file security scanner |
| `permissions.php` | Role Permission Matrix overview |

## Database Compatibility

PharmaForce is designed around the current production database:

```txt
pharmastar_reports
```

Main existing tables used:

```txt
users
reports
events
doctors_masterlist
approval_records
expense_reports
expense_items
report_client_map
event_attendees
activity_logs
```

The app is built to stay compatible with production table differences where possible by checking available columns before writing optional fields.

## Important Migrations

Depending on which patches are installed, import the relevant migration files from:

```txt
database/migrations
```

Current feature migrations may include:

```txt
2026_05_25_create_expense_reports.sql
2026_05_26_create_approval_records.sql
2026_05_30_add_report_signature_geotag.sql
2026_06_09_internal_security_hardening.sql
2026_06_15_add_doctor_map_pin_location.sql
2026_06_15_add_visit_verification_manual_review.sql
```

Always import migrations into the same database used by the app.

## Tech Stack

| Layer | Technology |
| --- | --- |
| Backend | PHP |
| Database | MySQL |
| Database Access | PDO |
| Frontend | HTML, CSS, Vanilla JavaScript |
| Map Pin Setup | Leaflet.js + OpenStreetMap |
| Local Runtime | XAMPP |
| Build Tools | None required |

## Local Setup

Import the production SQL dump into phpMyAdmin and make sure the database name is:

```txt
pharmastar_reports
```

Place the project folder inside XAMPP `htdocs`, for example:

```txt
C:\xampp\htdocs\PharmaForce
```

Update database credentials only if your local XAMPP setup is different:

```txt
config/database.php
```

Default local database configuration:

```txt
host: localhost
database: pharmastar_reports
username: root
password: empty
```

Open the app locally:

```txt
http://localhost/PharmaForce/login.php
```

## Upload and Permission Notes

For security-related patches, make sure hidden `.htaccess` files are uploaded to the server. Some FTP clients hide these files by default.

Upload folders should block executable scripts and should only allow safe uploaded assets such as images and PDFs.

## Design Direction

PharmaForce is designed as a modern internal business app:

- Tablet-first layout
- Clean sidebar navigation
- Large touch-friendly controls
- Simple page hierarchy
- Focused dashboard
- Clear report cards and tables
- Signature-first report view
- Daily visit planning workflow
- DCR-based field tracking
- Map-assisted doctor clinic setup
- Geotag and verification-first reporting
- Responsive page structure
- Reduced visual clutter
- No unnecessary SLA sections
- No global quick-task panel on unrelated pages

## Current Build Notes

This version focuses on a clean PHP/MySQL rebuild of the core internal CRM flow:

- Dashboard
- Plan Your Day
- Daily Call Report
- Reports
- Report creation and editing
- Signature capture and geotagging
- Doctor clinic map pin setup
- Visit verification and manual manager review
- Task Center
- Task-to-report generation
- Doctor-to-report generation
- Doctor masterlist and profiles
- Expense reporting
- Approval Center
- Analytics
- Users
- Permissions
- Security Center
- File Upload Security Center
- Profile

## Future Improvements

Planned improvements may include:

- Product detailing and samples given
- DCR export to Excel/PDF
- Monthly Tour Plan
- Doctor visit performance dashboard
- Offline-first report saving
- Sync queue support
- Background upload retries
- Stronger field-mode support for hospital environments with unstable internet
- Commercial multi-tenant readiness
- Optional cloud storage for private uploads

## Project Status

Active internal CRM rebuild for Pharmastar sales reporting workflows.
