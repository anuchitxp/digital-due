# 02 — Requirements

> Status: draft v0.1 · Related: [01-project-context](01-project-context.md), [03-clinical-criteria](03-clinical-criteria.md)

## 1. Users & roles

| Role | Code | Permissions |
|---|---|---|
| Physician (cardiology/endocrine staff) | `physician` | Create/edit own draft evaluations, submit, view own unit's records |
| Clinic nurse / OPD pharmacist | `clinic_staff` | Create draft, fill patient/vitals section, cannot submit final criteria decision |
| DUE committee pharmacist | `due_reviewer` | Review submissions, record decision (approve/request info/reject), manage criteria versions (content), run reports |
| System auditor / admin | `admin` | User & role management, full read access, audit export; cannot alter clinical answers silently |

> Exact role split to be confirmed with the hospital. Authentication method (Windows AD vs. local accounts) — see [05-architecture](05-architecture.md).

## 2. User stories (phase 1)

- **US-01** — As a physician, I enter a patient's **HN** and the system fetches demographics, latest lipid panel, diagnoses, and relevant prior prescriptions from HIS (read-only), so I don't retype data.
- **US-02** — As a physician, I select the drug (pitavastatin original / evolocumab / inclisiran) and the correct **criteria checklist version** is applied automatically.
- **US-03** — As a physician, I complete the checklist; items that can be **auto-evaluated** from lab data (e.g., LDL-C threshold, ≥3 months statin therapy) are pre-filled and flagged "auto".
- **US-04** — As a physician, I can save a **draft** and resume it later (OPD interruptions are normal).
- **US-05** — As a physician, I **submit** the evaluation to pharmacy; the system shows a summary and blocks submission if mandatory items are missing.
- **US-06** — As a DUE reviewer, I see the queue of submissions with patient, drug, unit, and pass/fail summary; I can **approve**, **request more information**, or **reject** with comments.
- **US-07** — As a DUE reviewer, I can **print/export** an approval package (PDF) for the CSMBS office.
- **US-08** — As a DUE reviewer, I can **publish a new criteria version** (effective-dated) without code changes; old evaluations keep their original version.
- **US-09** — As a committee member, I run **summary reports**: per drug — number of evaluations, approvals/rejections, reasons, unit breakdown, estimated drug cost.
- **US-10** — As an auditor, I export a complete **audit trail** for any evaluation (who changed what, when).
- **US-11** — As a physician, I get a warning when a patient already has an **active evaluation** for the same drug (duplicate/renewal detection).
- **US-12** — As a physician, for **continuation/renewal** (e.g., inclisiran q6-month dosing), I start a renewal evaluation that pre-fills from the previous approved record.

## 3. Functional requirements

### FR-1 Patient lookup (HIS read-only)
- FR-1.1 Search by exact HN (primary) and by name partial match (secondary, rate-limited).
- FR-1.2 Display: name (masked per [08-security](08-security-compliance.md) if policy requires), age/sex, weight, privilege scheme (**CSMBS flag is critical**), key diagnoses (ICD-10), latest lipid panel with dates, current relevant medications, statin dispensing history (≥12 months lookback).
- FR-1.3 If HIS view data is unavailable, the form must still allow **manual entry** with a "manual" data-source flag (system must not hard-fail when HIS is down).
- FR-1.4 All HIS reads are logged (who looked up whom, when).

### FR-2 Evaluation capture
- FR-2.1 One **Evaluation** = patient + drug + criteria version + checklist answers + free-text justification + attachments reference (e.g., existing paper consent) + status.
- FR-2.2 Checklist items are defined per drug per **criteria version** (see [03-clinical-criteria](03-clinical-criteria.md) and [04-data-model](04-data-model.md)): type (boolean / numeric-with-unit / single-choice / text / date), mandatory flag, auto-check binding (optional link to lab item code).
- FR-2.3 Auto-evaluation engine: compare bound lab values/thresholds and mark item `auto-pass` / `auto-fail` / `manual` (physician can override with justification — override is audited).
- FR-2.4 Draft autosave; drafts visible only to creator + reviewers.
- FR-2.5 Mandatory-item validation gate on submit.
- FR-2.6 Duplicate/active-evaluation detection per patient+drug (US-11).
- FR-2.7 Renewal evaluation type with pre-fill from prior approved evaluation (US-12).

### FR-3 Review & decision workflow
- FR-3.1 Statuses: `draft → submitted → under_review → approved | info_requested | rejected → (info_requested → resubmitted → …)`; renewals: `approved_expired` when the dosing interval lapses (configurable per drug).
- FR-3.2 Decisions are recorded with reviewer identity, timestamp, comments; immutable once saved (corrections = new decision row).
- FR-3.3 Printable/exportable approval package (PDF, Thai/English labels).

### FR-4 Criteria management
- FR-4.1 Criteria versions: per drug, effective-dated, publish/retire actions; content editable via admin UI or seeded scripts (TBC in implementation).
- FR-4.2 Evaluations pin the criteria version used; version history retained forever.

### FR-5 Reporting
- FR-5.1 Summary per drug: counts by status, by unit, by criteria item pass/fail rates, rejection reasons, estimated cost (drug unit price × duration; price table maintainable by admin).
- FR-5.2 Export to CSV (Excel-compatible) and print-friendly HTML; no external services.
- FR-5.3 Audit export per evaluation and per user.

### FR-6 Administration
- FR-6.1 User/role management (or AD group mapping — see architecture).
- FR-6.2 Drug/price table maintenance.
- FR-6.3 Lookback windows, LDL thresholds display, and session policy configurable (no code change).

## 4. Non-functional requirements

| ID | Category | Requirement |
|---|---|---|
| NFR-1 | **Offline/intranet** | Zero external network calls from the app; all JS/CSS/fonts/icons self-hosted; no telemetry; license-safe bundled assets (see 05) |
| NFR-2 | **Browser support** | Runs on the hospital's standard browser (TBC; assume Chromium-based and one legacy fallback); no plugins required |
| NFR-3 | **Performance** | Page interaction ≤ 2 s on OPD LAN; HIS lookup ≤ 5 s with visible progress + graceful fallback (FR-1.3) |
| NFR-4 | **Availability** | Degrade gracefully: if HIS read-only link is down, form remains usable with manual entry (FR-1.3); if DUE DB is down, show maintenance page |
| NFR-5 | **Auditability** | Every create/update/decision/lookup logged with user, timestamp (server time), before/after values; logs are append-only (08) |
| NFR-6 | **Security** | HTTPS on intranet (internal CA), role-based access, session timeout ≤ 15 min idle for clinical roles, IP-restricted to hospital subnet (08) |
| NFR-7 | **Data protection** | PDPA-aware: minimum necessary data, purpose limitation, retention policy defined with hospital (08) |
| NFR-8 | **Bilingual UI** | All UI labels available in Thai and English; language switch per user; criteria text may be Thai-first (committee wording) with English notes |
| NFR-9 | **Portability of dev** | Full dev/test environment reproducible on Linux + Podman (06); local SQL Server container with seeded test data |
| NFR-10 | **Backups** | DUE DB full backup daily + log backup per hospital DBA policy; restore tested quarterly (07) |
| NFR-11 | **Extensibility** | Adding a new drug = new drug row + criteria version + price row; no schema change |
| NFR-12 | **Time zone/clock** | Single authoritative server time (Asia/Bangkok); display in Thai Buddhist era where the hospital convention requires (TBC) |

## 5. Acceptance criteria (high level, phase 1 exit)

1. A physician can complete and submit an evolocumab DUE evaluation for a CSMBS patient using only HN entry + checklist, in ≤ 10 minutes for a new patient.
2. A reviewer can approve and produce a printable approval package without leaving the system.
3. Unplugging the HIS link does not break draft creation (manual-entry path works).
4. Publishing criteria v2 for evolocumab does not alter any saved v1 evaluation or its reports.
5. Full audit export for one evaluation shows every step from creation to decision.
6. The whole stack runs in the Linux/Podman dev environment with identical behavior to a local IIS rehearsal (staging) deployment.

## 6. Open questions (track with hospital)

| # | Question | Owner |
|---|---|---|
| Q1 | Exact SQL Server version & edition on hospital server | IT/DBA |
| Q2 | Standard browser + version on OPD PCs | IT |
| Q3 | AD available for authentication? | IT |
| Q4 | HIS database readable directly (views on same server / linked server / nightly sync)? | HIS team |
| Q5 | Who owns printing: approval package format for CSMBS office? | Pharmacy |
| Q6 | Renewal intervals per drug (inclisiran 6-month re-evaluation?) | Pharmacy/DUE committee |
