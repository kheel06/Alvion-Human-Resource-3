# Hospital HR3 System

Hospital Management System with HR3 (Human Resource 3) module for Time & Attendance, Shift & Schedule, Timesheets, Leave, and Claims & Reimbursement.

## Tech Stack

- **Backend:** PHP 8+, PDO MySQL
- **Frontend:** Tailwind CSS, Flowbite
- **Auth:** Session-based, OTP verification, `department_accounts`
- **Timezone:** Asia/Manila

## Requirements

- PHP 8.0+
- MySQL 5.7+ or MariaDB 10.3+

## Environment Variables

Configure in your environment or `.env`:

| Variable | Description |
|----------|-------------|
| `BASE_URL` | Base URL for the app (e.g. `http://hospital-hr3-system.test`) |
| `HR3_QR_SECRET` | Secret for QR token signing (set in production) |
| `AI_API_KEY` | OpenAI API key (optional; enables LLM-powered chat) |
| `AI_API_URL` | Custom LLM endpoint (default: OpenAI) |
| `AI_MODEL` | LLM model name (default: `gpt-3.5-turbo`) |
| Database | Use `config/database.php` or env vars for DB host, name, user, password |

## Installation

1. Clone the repository and configure the database in `config/database.php`.

2. Run migrations:
   ```bash
   php database/migrations/run_migrations.php
   ```

3. Run HR3 seed data (optional; creates PH policy defaults, units, shift templates, holidays, leave types, claim categories):
   ```bash
   # Migrations include hr3_seed_data.sql
   ```

4. Run demo seed (creates demo employees and sample data):
   ```bash
   php database/seed_hr3_demo.php
   ```

5. **Tied data across all pages** — So Attendance, Timesheets, Leave, Claims, Reports, and Dashboard show the same employees and linked records:
   ```bash
   php database/seed_all.php
   ```
   This runs the demo seed (for all 10 employees: attendance, timesheets, leave, claims, rosters, biometric logs) then fills today's attendance. Optional: for a specific date run `php database/seed_attendance_today.php 2026-02-25`.

6. Point your web server to the project root or run:
   ```bash
   php -S localhost:8000
   ```

   If using Laravel Herd, ensure `BASE_URL` matches your `.test` domain.

## Demo Accounts

After running `php database/seed_hr3_demo.php`:

| Username | Role | Password |
|----------|------|----------|
| EMP001 | employee | Password123 |
| EMP002 | employee | Password123 |
| EMP003 | employee | Password123 |
| EMP004 | employee | Password123 |
| EMP005 | supervisor | Password123 |
| EMP006 | supervisor | Password123 |
| EMP007 | hr_admin | Password123 |
| EMP008 | hr_admin | Password123 |
| EMP009 | finance | Password123 |
| EMP010 | finance | Password123 |

Log in at `/auth/employee-login.php` using Employee ID (e.g. EMP001) and password.

## Features Implemented

### Time & Attendance
- [x] QR-based attendance with dynamic token rotation (HMAC-signed, configurable rotation)
- [x] Camera-based QR scanner (html5-qrcode) with manual token fallback
- [x] Admin QR display kiosk page (auto-refreshing QR codes per location)
- [x] Attendance punch API with duplicate scan prevention
- [x] Daily attendance records with time in/out, breaks, OT, ND tracking
- [x] Attendance exception detection (missing in/out, suspicious rapid scans)
- [x] Exception resolution workflow (resolve/dismiss with notes)
- [x] Configurable policy rules via `hr3_settings` (standard hours, OT premium, ND rate, etc.)

### Shift & Schedule Management
- [x] Shift templates (morning, afternoon, night with configurable times/breaks)
- [x] Roster creation, assignment, and publishing per unit/period
- [x] Shift swap requests with approve/reject workflow
- [x] Overtime requests with approval workflow

### Timesheet Management
- [x] Auto-generate timesheets from daily attendance data
- [x] Timesheet line items with regular/OT/ND hours, late/undertime minutes
- [x] Employee submission (draft → submitted)
- [x] Admin review queue with approve/reject actions
- [x] Cutoff period management (create/lock)
- [x] CSV export for payroll integration

### Leave Management
- [x] Configurable leave types (VL, SL, ML, PL, EL, SPL) with PH labor law defaults
- [x] Leave balance tracking per employee/year with entitlement/used/pending
- [x] Employee leave request submission with reason and date range
- [x] Admin approval queue with approve/reject (with rejection reason)
- [x] Automatic balance updates on approval/rejection
- [x] Leave calendar view (unit/department)

### Claims & Reimbursement
- [x] Claim categories with configurable max amounts and approval routes
- [x] Employee claim submission with receipt upload (multi-file)
- [x] Admin review queue with approve/reject actions
- [x] Disbursement tracking with mark-as-paid workflow
- [x] Claim audit trail

### AI HR Assistant
- [x] Natural language chat assistant for employees and admins
- [x] Context-aware responses using real employee data (attendance, leave, claims, schedule, timesheets)
- [x] OpenAI-compatible LLM integration (supports OpenAI, Azure OpenAI, local LLMs via LM Studio/Ollama)
- [x] Built-in rule-based fallback engine (works without any API key)
- [x] Intent detection for attendance, leave, claims, schedule, timesheet, and policy queries
- [x] Quick question buttons for common HR queries
- [x] Policy Q&A with PH labor law references
- [x] Markdown-formatted responses with tables, lists, and bold text
- [x] Admin AI Dashboard with 5 views: Overview, Smart Insights, Anomaly Detection, Workforce Analytics, AI Chat
- [x] Smart insights engine: attendance exceptions, leave forecasting, pending claims, OT alerts, staffing gaps
- [x] Anomaly detection: rapid punch (buddy punching), high claim amounts, missing attendance without leave, Monday absence patterns
- [x] Workforce analytics: attendance rate, leave utilization, OT distribution by department, claims by category, 14-day attendance trend
- [x] Audit logging of all AI interactions
- [x] Conversation history support (maintains context across messages)

### System
- [x] RBAC (EMPLOYEE, SUPERVISOR, UNIT_HEAD, HR_ADMIN, FINANCE)
- [x] Audit trail for sensitive actions
- [x] PH policy rules engine (configurable via `hr3_settings`)
- [x] Asia/Manila timezone
- [x] Seed data and demo accounts (10 employees across all roles)

## Project Structure

- `employee/modules/` – Employee self-service pages
- `admin/modules/` – Admin/HR management pages
- `api/` – REST JSON endpoints (attendance, scheduling, timesheets, leave, claims, ai)
- `includes/` – Helpers, sidebars, policy engine, QR token service, AI service
- `database/migrations/` – Schema and seed SQL

## AI Configuration

The AI assistant works in two modes:

1. **Smart Mode (default)** - Uses a built-in rule-based engine with intent detection and database queries. No API key required. Provides personalized responses using actual employee data.

2. **LLM Mode** - When `AI_API_KEY` is set, uses an OpenAI-compatible API for natural language understanding. Supports OpenAI, Azure OpenAI, or local LLMs (LM Studio, Ollama).

To enable LLM mode, set environment variables:
```bash
AI_API_KEY=sk-your-api-key-here
AI_API_URL=https://api.openai.com/v1/chat/completions  # or your local LLM endpoint
AI_MODEL=gpt-3.5-turbo  # or gpt-4, llama2, etc.
```

## Security Notes

- Set `HR3_QR_SECRET` in production (never expose in client code)
- RBAC enforced on all routes and APIs
- Session auth required for protected pages
- AI chat interactions are logged in `audit_logs` for compliance
