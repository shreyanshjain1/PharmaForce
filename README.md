# PharmaForce

A tablet-first PHP/MySQL sales reporting CRM built for Pharmastar field teams, designed around real pharmaceutical sales workflows: doctor visits, field reports, task scheduling, manager review, signatures, and KPI analytics.

![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-Database-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![PDO](https://img.shields.io/badge/PDO-Secure%20DB%20Access-0F766E?style=for-the-badge)
![JavaScript](https://img.shields.io/badge/JavaScript-Vanilla-F7DF1E?style=for-the-badge&logo=javascript&logoColor=111)
![XAMPP](https://img.shields.io/badge/XAMPP-Compatible-FB7A24?style=for-the-badge&logo=xampp&logoColor=white)

## Overview

PharmaForce is a clean rebuild of a medicine sales reporting system for internal field operations. It is built around the existing production database structure and focuses on the workflows that matter most to a sales/admin team: creating reports, assigning tasks, managing doctor records, reviewing submitted reports, tracking activity, and generating report documentation with captured signatures.

The interface is designed for Android tablet usage in hospital and field environments, with large touch-friendly controls, responsive layouts, clean navigation, and focused pages instead of cluttered all-in-one dashboards.

## Key Features

### Field Sales Reporting

- Create new doctor visit reports
- Edit existing reports
- View full report details
- Capture and display doctor signatures
- Print/export report pages using the browser print flow
- Track report status for manager review
- Store report purpose, product/medicine, summary, and remarks

### Task Center

- Create scheduled field tasks
- Assign tasks to representatives
- Filter doctors by selected city during task creation
- Generate a report directly from a task
- Prefill report details from task and doctor records

### Doctor Masterlist

- View doctor directory records
- Generate reports from doctor profiles
- Prefill basic doctor details into new reports
- Use the existing `doctors_masterlist` table as the source of truth

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
- Role-aware access logic
- Profile page and logout flow

## Why This Rebuild Exists

The original system had useful backend workflows, but the interface had become crowded and difficult to use on tablets. PharmaForce keeps the important business logic while removing unnecessary dashboard clutter, repeated widgets, SLA noise, and global quick-task sections that distracted users from the actual page they were using.

The goal is a cleaner internal CRM experience for real field teams.

## Main Pages

| Page | Purpose |
| --- | --- |
| `login.php` | User login |
| `index.php` | Dashboard and calendar overview |
| `reports.php` | Reports workspace |
| `report_form.php` | Create or edit report |
| `report_view.php` | Report details, signature display, manager review, print/export |
| `tasks.php` | Task Center and task-to-report workflow |
| `analytics.php` | KPI analytics and filtered reporting |
| `doctors.php` | Doctor masterlist |
| `users.php` | User management |
| `profile.php` | User profile and logout access |

## Database Compatibility

PharmaForce is designed around the current production database dump named:

```txt
pharmastar_reports
```

Main existing tables used:

```txt
users
reports
events
doctors_masterlist
report_client_map
```

The app keeps the general database flow and avoids unnecessary migrations for the initial rebuild.

## Tech Stack

| Layer | Technology |
| --- | --- |
| Backend | PHP |
| Database | MySQL |
| Database Access | PDO |
| Frontend | HTML, CSS, Vanilla JavaScript |
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

## Design Direction

PharmaForce is designed as a modern internal business app:

- Tablet-first layout
- Clean sidebar navigation
- Large touch-friendly controls
- Simple page hierarchy
- Focused dashboard
- Clear report cards and tables
- Signature-first report view
- Responsive page structure
- Reduced visual clutter
- No unnecessary SLA sections
- No global quick-task panel on unrelated pages

## Current Build Notes

This version focuses on a clean PHP/MySQL rebuild of the core internal CRM flow:

- Dashboard
- Reports
- Report creation and editing
- Report details and print/export
- Task Center
- Task-to-report generation
- Doctor-to-report generation
- Doctor masterlist
- Analytics
- Users
- Profile

Future improvements may include offline-first report saving, sync queue support, background upload retries, and stronger field-mode support for hospital environments with unstable internet.

## Project Status

Active internal CRM rebuild for Pharmastar sales reporting workflows.
