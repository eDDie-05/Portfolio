# Bravado HRMS

Bravado Group's Laravel attendance and HRMS foundation. The implementation keeps physical-device status evidence-based: the two supplied ZKTeco machines are provisional records until their model, serial number, firmware, and supported connection are verified.

## Current implementation

- Branded sign-in and attendance dashboard with live database counts, date/branch/department filters, and filtered CSV event export.
- Role-gated workspace and system-administrator provisioning command.
- Employee directory with search and department/branch/status filters, filtered CSV export, contact and emergency-contact details, job and employment dates, employee account creation and deactivation, password changes, personal attendance views, and effective-dated device-user mapping history.
- Direct-report assignment for HR managers, manager-scoped employee, attendance, and biometric-mapping views, and system-administrator controls for granting current workspace roles.
- Managed department records with employee assignment, rename synchronization, safe deactivation, and audit history. Existing department labels are migrated without removing the original employee values.
- Managed branch names and timezones, with deactivation blocked while employees, attendance devices, or Windows computers remain assigned.
- Employee leave self-service with configurable leave categories, HR-managed calendar-year allocations, overlap and balance checks, scoped manager/HR approval, cancellation with balance restoration, and audit history. Initial categories carry no statutory day allowances.
- Private employee document uploads with server-validated PDF/JPEG/PNG files, HR-scoped access, employee sharing opt-in, access audit events, expiry metadata, and non-destructive archival.
- Recruitment pipeline with managed vacancies, public applications, private PDF resumes and offer letters, audited candidate stages, interview scheduling/feedback, signed expiring offer-response links, candidate acceptance/decline, and filtered CSV export.
- Onboarding workflow linked from hired applicants, assigned checklists, private document requests/uploads, versioned private PDF contracts with signed-copy return/decline tracking, and case completion rules.
- Probation review screen with upcoming/overdue counts, direct-report access for HR managers, extension and review outcomes, and audit history.
- Offboarding case management with restricted HR ownership, assigned department and asset clearance, exit interview outcomes, final-payroll status recording, cancellation history, and account deactivation after clearance and the last working day. Inactive accounts are logged out on their next web request.
- Tanzania-focused payroll with grade/scale/step salary assignments, effective-dated and versioned salary components, configurable statutory and overtime rules, employee-submitted and manager-approved overtime, approved/disbursed loan and advance repayment ledgers, encrypted tax identifiers, immutable payroll snapshots, separate processing/approval/payment/close, employee payslips, annual tax-preparation and department CSV reports, and generic bank payment export.
- Dar es Salaam and Dodoma branches with provisional `ZKT-001` and `ZKT-002` devices.
- Device registration, editing, deactivation, credential revocation, audit trail, and discovery checklist.
- Compatibility attestation gate before a device API credential can be issued.
- Immutable raw ZKTeco event storage, per-device authentication, validation, duplicate protection, employee mapping lookup, event receipts, and sync logs.
- Controlled CSV attendance imports. Invalid rows are retained in integration error logs and not represented as accepted events.
- Separate schema and authenticated API for Windows sign-in/sign-out records; the API allows only event IDs 4624, 4634, and 4647. Computer events remain separate from biometric events.
- C# Windows Service source and PowerShell installer that watch interactive Security logons, correlate sign-outs to known interactive sessions, and protect configuration and the local event queue with Windows DPAPI.
- PostgreSQL, Redis, queue worker, scheduler, and Nginx Docker Compose configuration.

## Still pending before production use

- Exact ZKTeco hardware identification and physical protocol compatibility testing. No ADMS endpoint, TCP port, SDK call, or hardware result is assumed.
- A production adapter implementation for a verified ZKTeco model/protocol. The CSV import is the working fallback.
- Full department/branch manager scopes, auditor permissions, fine-grained permission administration, and password-reset workflows. Current HR manager scope is direct reports only.
- Leave working-day/holiday policy, accrual automation, multi-level approval, calendar/report views, and leave notifications.
- Shift assignment UI and policy-approved attendance calculations, lateness, overtime, and correction approval workflows.
- Full dashboards for expected, missing, late, incomplete, Windows-agent, and comparison indicators. Current attendance figures are limited to actual event/device data and do not invent expected attendance.
- Document expiry reminders and version grouping, position/grade administration, transfer and promotion workflows, equipment allocation, orientation templates, onboarding reminders, probation notifications, progressive country tax tables, payroll corrections/reversals, automated attendance/overtime/leave proration, bank integration, PDF file generation, performance, training, inventory-backed assets, expenses, and notifications.
- Recruitment email delivery, vacancy approval workflows, and candidate-data retention/anonymization policies remain to be configured or implemented. Offer links are generated for HR to send through an approved channel; the app does not send recruitment email.
- Windows agent build, signed deployment package, endpoint security-log configuration validation, computer-account mapping screen, and testing on an authorized Windows machine.
- TLS certificates and a production HTTPS ingress/reverse proxy. Compose binds its development Nginx port to localhost; expose it only behind the company's HTTPS gateway.
- Backup automation, restore drills, retention policy, and deployment-specific secret rotation.
- Render routes private uploads to a private S3-compatible bucket. Before deployment, configure the bucket name, region, access key, secret key, and (when required) endpoint/path-style setting in Render; keep public access disabled, enable encryption at rest, and grant the app only the bucket permissions it needs. Copy any existing files from local storage to the bucket at the same relative paths before switching the production service. Set up bucket versioning/backup and retention according to the employer's data policy.
- Production database capacity, high availability, backup automation, restore drills, and retention still need a production-sized plan. The current free Render database/service settings are not a capacity guarantee for large datasets; choose paid resource limits from expected attendance volume and retention requirements before production use.

Do not report devices as connected until a credentialed event batch arrives from a compatibility-verified device. The connection-check action records a skipped check until a compatible adapter is configured.

## Payroll configuration and operating limits

Payroll processing keeps calculation logic server-side in `app/Payroll/`. The calculation pipeline uses the employee's effective-dated salary assignment, recurring components whose dates cover the whole period, approved overtime, documented draft adjustments, configured statutory rules, and decimal arithmetic. A component starting or ending mid-period blocks processing; record a reviewed pro-rata adjustment rather than applying a full-month value. Processing is transactional; a failed check rolls back the run. A processed period stores immutable payroll lines and snapshots of every applicable statutory and overtime rule version. Salary assignment changes keep prior assignments, require a reason/reference, and create an audit event.

Employees with an employee number are included in payroll unless their profile explicitly marks them `excluded`. The draft readiness check blocks processing if an included employee has no full-period matching assignment, an overlapping assignment, an incomplete required Tanzania profile, a missing effective statutory rule, unreviewed overtime, or approved non-full-pay leave without a manual leave adjustment. Excluded employees are intentionally left out. Configure that status only after confirming the employee is not payable in the period.

Before processing a Tanzania TZS period, configure one active rule that covers the entire period for each statutory code: PAYE, NSSF, PSSSF, NHIF, SDL, WCF, and HESLB. Enter rates and progressive PAYE bands only after checking the current official requirements for the selected Mainland or Zanzibar jurisdiction. Use an explicit zero rate only when the verified policy says there is no contribution. SDL also requires the verified employer headcount threshold. Configure the employee's tax residency, pension membership, NHIF enrollment, and HESLB billing status; payroll will not infer missing profile values. Updating a statutory rule creates a new version and closes an earlier open version at the previous day. Existing payroll snapshots are not changed.

Employee tax/member identifiers and bank account numbers use Laravel encrypted casts at rest in the database. They are excluded from model serialization and audit details. The generic bank export is available only after approval and requires bank name and account number for every payroll entry. It does not claim compatibility with CRDB, NMB, NBC, or another bank's proprietary layout. PAYE and other statutory CSV files are preparation reports, not government filing formats. Payslips support browser print / Save PDF; a server-side PDF generator is not included.

The period lifecycle implemented here is `draft → processed → approved → paid → closed`. Approval and payment must be performed by a different user from the processor. Loan requests require an independent terms approval and a separately recorded disbursement before scheduled deductions begin; repayments are marked paid only when the payroll period is marked paid. A closed run cannot be edited or reopened. There is no accounting ledger integration, so no separate `posted` state is claimed. Web routes use the existing role middleware; employee payslip access is restricted to that employee and approved/paid/closed periods. No payroll REST API is exposed because the existing API surface is reserved for device integrations and has no web-token authentication stack.

Statutory formulas and rates must be verified by the employer against the current TRA, social-security, health, WCF, SDL, and HESLB authorities; this repository does not ship a legal-rate seed. Progressive PAYE rules use annual brackets and annualize the configured pay-period base using the period frequency. Contribution rules support a configured basic/gross/taxable-pay basis, an exempt threshold, and an assessable ceiling. Verify every rule and output before production payroll use.

The annual tax CSV is preparation data, not an official P9/P10 form or TRA filing. Attendance is not auto-converted into pay deductions: the app has no verified payroll-approved work calendar/absence source, and approved unpaid/fractional leave requires an employer-reviewed monetary adjustment. Manual adjustments are not an attendance/proration engine. Other support not implemented includes asynchronous/batched payroll jobs and progress tracking (Render currently uses the synchronous queue), statutory API filing, bank-specific export formats, email delivery, full payroll anomaly/dashboard analytics, and audited post-close correction/reversal workflows. No large-dataset load test has been run, so no employee-count or throughput capacity is claimed.

### Payroll deployment and verification

The Render Docker entrypoint runs `php artisan migrate --force` during service startup. The added schema uses additive migrations and does not reset or delete payroll data. For local verification, use a non-production SQLite database and run:

```sh
php artisan migrate --pretend
php artisan migrate
php artisan test --compact --filter=PayrollWorkflowTest
npm run build
```

For production, back up the database before deployment, confirm that Render has PostgreSQL connectivity and `APP_DEBUG=false`, and verify all current statutory rules and employee profiles before processing. Render is configured for automatic deploys from the linked branch; after deploy, verify `/up`, login, payroll rule configuration, a non-production/test period, payslip authorization, and exports. The test suite uses fictitious rates only as calculation fixtures; they are not legal recommendations.

## Local development

Requirements: PHP 8.3+, Composer 2, and SQLite (included with the local PHP build used for development).

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan hrms:create-admin
php artisan serve
```

In a second terminal, run `php artisan queue:work` so asynchronous employee matching completes.

Open `http://127.0.0.1:8000/login`. The admin command prompts for a work email and password; no default credentials are shipped.

These local commands assume `.env` was copied from `.env.example`, which uses SQLite. If your `.env` instead has `DB_HOST=db`, it is configured for Docker Compose: run Artisan commands inside the app container using the Docker instructions below. The hostname `db` is only resolvable from containers on the Compose network.

## Docker deployment baseline

1. Copy `.env.example` to `.env` and set a unique `APP_KEY`, strong `DB_PASSWORD`, public `APP_URL`, `APP_DEBUG=false`, `SESSION_ENCRYPT=true`, and `SESSION_SECURE_COOKIE=true`. For production, set `PRIVATE_FILESYSTEM_DISK=s3` and configure the S3-compatible bucket credentials and endpoint. Keep `.env` private.
2. Run `docker compose up -d --build`.
3. Run `docker compose exec app php artisan migrate --force`.
4. Run `docker compose exec app php artisan hrms:create-admin`.
5. Place the Nginx service behind the organization's HTTPS gateway. Configure that gateway to forward the original `X-Forwarded-Proto: https` header. Never expose the integration API over plain HTTP.
6. Configure automated PostgreSQL and Redis backups, encryption, retention, and restore drills before production.

The Docker Compose file uses Postgres 17 and Redis 7. The database is not published to the host. The bundled Nginx listener is local-only on `127.0.0.1:8080` and does not itself terminate TLS.

## Device discovery and registration

In `/devices`, edit each provisional device after collecting the exact model, serial, firmware, branch assignment, and supported connection details. Verify the method using model/firmware-specific manufacturer documentation. The administrator's compatibility attestation is recorded with a note and audit entry. It does not perform a physical connectivity test.

After verification, issue a one-time integration token on the server:

```sh
php artisan hrms:rotate-device-token ZKT-001
```

The token is stored as a SHA-256 hash and shown once. Store it in the chosen adapter's secret manager. Editing a device clears its token and verification attestation. Deactivation revokes the token.

CSV files require `device_user_id` and `event_timestamp` headers. Optional headers are `event_identifier` and `event_type`. The original row is retained in the raw event payload. Map device users to HRMS employee IDs before relying on imported rows for attendance reports.

## Integration API

All integration requests require HTTPS, a registered active identifier in `X-Device-Code`, and a bearer token. Responses use standard JSON validation errors and rate limits.

`POST /api/attendance/devices/events`

```json
{
  "events": [
    {
      "device_user_id": "BIO-104",
      "event_identifier": "vendor-record-id",
      "event_timestamp": "2026-11-09T08:15:00+03:00",
      "event_type": "vendor-event-code",
      "payload": {"vendor_field": "retained as received"}
    }
  ]
}
```

The API returns accepted, duplicate, and unmatched counts. It does not infer arrival/departure from unknown biometric event types.

Windows endpoints are registered by an administrator on the server using `php artisan hrms:register-computer PC-DAR-001`, then receive a one-time credential with `php artisan hrms:rotate-computer-token PC-DAR-001`. Map the endpoint account using `php artisan hrms:map-computer-user PC-DAR-001`. Revoke access with `php artisan hrms:deactivate-computer PC-DAR-001`; reactivate with `php artisan hrms:activate-computer PC-DAR-001`, then issue a new credential.

## Windows agent

`windows-agent/` contains the .NET 8 Windows Worker source. Build on Windows with the .NET 8 SDK after package restore:

```powershell
dotnet publish .\Bravado.AttendanceAgent.csproj -c Release -r win-x64 --self-contained true -o .\publish
.\Install-Service.ps1 -HrmsUrl "https://hrms.company.tz" -ComputerCode "PC-DAR-001" -PublishPath .\publish
```

Register the computer and issue its one-time token using the server commands above before running the installer; it prompts for the token and writes a LocalMachine DPAPI-protected configuration file. The service monitors Security event IDs 4624, 4634, and 4647 and only stores interactive logons (types 2, 7, 10, 11); sign-outs are correlated to those sessions. The installer uses a dedicated virtual service account and adds it to Windows Event Log Readers. Verify that the account can read the Security log on the target Windows version. This agent and installer have not been built or tested on Windows in this environment.

## Tests

Run `php artisan test`. Payroll feature coverage includes configured progressive tax and contribution calculations, effective-dated rule snapshots, approval separation, employee payslip access, statutory and bank CSV exports, period closing, missing-rule failure handling, and encryption of identifiers. The full suite does not substitute for official payroll/legal review, live production acceptance, physical-device tests, or Windows agent tests.

## Time and data handling

Event timestamps are stored in UTC. `ATTENDANCE_TIMEZONE` sets the default display timezone to `Africa/Dar_es_Salaam`; selecting a branch uses its configured IANA timezone for date filtering and local event display. Raw event payloads are preserved for audit and should not be edited to correct attendance. Corrections must be implemented as separate audited records before operational use.
