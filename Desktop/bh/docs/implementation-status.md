# Implementation status

## Implemented in this source tree

- Laravel 13 application, PostgreSQL-oriented schema, Redis queue/cache deployment wiring, migrations, and provisional Dar es Salaam/Dodoma device records.
- Login, role checks for current administrative screens, administrator provisioning, device administration, verification attestation, and audit logging for device lifecycle actions.
- Raw biometric event API with token hashing, HTTPS guard, validation, idempotency, employee mapping lookup, unmatched flags, and sync logs.
- CSV upload path with row validation, durable error records, raw payload retention, and duplicate handling.
- Windows event/API schema, computer registration/credential commands, health and event API, and Windows Worker source with local offline spool.
- Attendance dashboard with East Africa Time date-range, branch, and department filters and filtered CSV event export. Counts are based on received data and do not infer attendance outcomes.
- Employee directory/profile administration with search, department/branch/status filters, filtered CSV export, contact and emergency-contact details, job and employment dates, employee-only attendance and password screens, and effective-dated biometric account mapping with reassignment history.
- A common device adapter contract and configuration-driven registry; no unsupported protocol is enabled by default.

## Partial or not yet implemented

- Device adapter framework is represented by a verified device configuration and generic event endpoint, but no physical ZKTeco protocol adapter is implemented until the exact model and documentation are available.
- Windows agent source and a PowerShell service-install script are present but not compiled, signed, installed, or exercised on Windows.
- HR managers can be assigned direct reports; employee directory, edits, attendance event reports, and biometric mappings are scoped to those reports and their branches. Department-manager/branch-manager roles, manager hierarchy reporting, full branch/department policy, and auditor access policy need implementation. Computer-user mapping remains an audited command rather than a screen.
- Departments can be created, renamed, activated, and deactivated through an administrator screen. Existing department text values are migrated to managed department records, and department changes are audited.
- Branch names and timezones can be managed through an administrator screen. Branch deactivation is blocked while employee, device, or computer records reference that branch; selected-branch attendance dates and event displays use its configured timezone.
- Leave self-service supports applications, overlap checks, employee cancellation of pending/future-approved requests, configurable leave categories, HR-managed annual allocations, direct-report manager/HR approvals, balance checks and adjustments, review notes, and audit events. No statutory allowances are assigned by default.
- Employee documents can be uploaded as server-validated PDF/JPEG/PNG files to the private local storage disk. HR access follows employee manager scoping, employee downloads require an explicit visibility flag, downloads are audited, and archival preserves file data.
- Recruitment supports draft/open/closed vacancies, a public careers page and application form, private PDF resumes, email de-duplication per vacancy, HR-only candidate records, audited stage history, interviews and feedback, filtered CSV export, private PDF offer letters, and signed expiring candidate links. Accepting an offer records a hiring decision; declining records a declined stage.
- Onboarding can be started for a hired application by linking an existing employee account and assigning an HR owner. Owners add checklist tasks and required document requests with assignees/due dates; assignees complete tasks and employees upload requested PDF/JPEG/PNG files to private storage. HR can issue a private PDF employment contract for employee download and return; signed copies and declines are recorded. Case completion requires all tasks, document requests, and any issued contract to be complete. Employees and staff see only their assigned work, while HR case access follows owner/admin scope. Case, task, document request, upload, contract and download events are audited.
- Offboarding supports resignation, termination, retirement, end-of-contract and other exit cases, scoped HR ownership, assigned departmental/asset clearance items, employee clearance self-service, restricted exit interview notes, final payroll status recording, cancellation with history, and closure only after required steps and the last working day. Closing deactivates the account; web sessions for inactive accounts are invalidated on their next request. The case-level final-pay status is not automatically linked to a payroll period.
- Payroll supports employer-configured salary structures and components, effective-dated employee assignments, draft periods, fixed adjustments for bonuses/overtime/benefits/loan or advance repayments, BCMath decimal calculations, immutable processed line snapshots, separate approval with processor/approver separation, finance payment-reference tracking, employee payslips after approval, and approved-period CSV exports. Payroll and finance officer roles are available to administrators. No statutory rates are seeded or assumed.
- Shift rules and assignments are stored, but attendance calculation, overnight/flexible shift behavior, lateness, overtime, and incomplete-session policies are not implemented.
- Attendance adjustments and audit tables exist, but approval workflow screens and record mutation logic are not implemented.
- Leave day calculation currently counts inclusive calendar days. Working-day calendars, holidays, accrual automation, multi-level approval, team calendar and leave reports are incomplete.
- The attendance dashboard does not calculate expected, missing, or late employees and does not use Chart.js. It presents received event and integration counts only.
- Document version grouping and expiry reminders, positions/grades, transfer and promotion workflows, and employee history beyond current audit entries remain incomplete. Employee records currently store basic personal/contact and employment fields; they do not implement those lifecycle workflows.
- Progressive jurisdiction-specific tax tables, statutory rule effective dates, loan/advance account ledgers, automatic attendance/overtime/leave proration, payroll corrections/reversals, bank integrations, and PDF generation beyond browser print are not implemented. Employers must configure and independently verify every taxable/statutory component; no legal rates are provided. Payroll processing is a configurable gross-to-net calculation from assigned structures and manual adjustments, not a certified country payroll engine.
- Recruitment email delivery, vacancy approvals, interview conflict checks, offer-link revocation/resend, and automated candidate retention/anonymization are incomplete. HR must send the signed offer link through an approved channel.
- Production TLS, secrets management, automated backup/restore, retention controls, queue monitoring, and security review remain deployment tasks.
- Production must configure persistent private document storage, encryption at rest, and a tested backup/restore path for employee files.

## Pending hardware acceptance

No successful test against a physical ZKTeco machine is claimed. No Windows endpoint event has been received from the target computers. Hardware and endpoint acceptance tests must be run at both branches before activating payroll or attendance decisions based on this system.
