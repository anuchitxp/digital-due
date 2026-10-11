# 08 — Security & Compliance

> Status: draft v0.1 · Covers: access control, audit trail, patient-data protection (PDPA-aware), session policy, offline-environment hardening

## 1. Principle

The system handles **identifiable patient data** and **reimbursement-sensitive decisions**. Design goals: least privilege, complete auditability, no data beyond what DUE requires (purpose limitation), and **zero internet dependency** (also a security feature).

## 2. Roles & access matrix (from [02 §1](02-requirements.md#1-users--roles))

| Action | clinic_staff | physician | due_reviewer | admin |
|---|---|---|---|---|
| Lookup patient (HIS) | ✔ | ✔ | ✔ | ✔ |
| Create/edit **own** draft | ✔ (patient/vitals only) | ✔ | ✔ | — |
| Edit submitted evaluation | ✖ | ✖ (new revision only) | ✖ | — |
| Submit evaluation | ✖ | ✔ | ✖ | — |
| Record decision | ✖ | ✖ | ✔ | ✖ |
| Publish criteria version | ✖ | ✖ | ✔ | ✖ |
| Run reports | ✖ | own unit only | ✔ | ✔ |
| Export audit | ✖ | ✖ | ✔ | ✔ |
| User/role management | ✖ | ✖ | ✖ | ✔ |

- Physicians see their own records + anonymized/aggregated unit views (confirm with committee).
- Separation of duties: the **creator of an evaluation cannot record its final decision** (enforced server-side).

## 3. Audit trail

- **Append-only** `audit_log` + `decisions` ([04 §2](04-data-model.md)): DB triggers deny `UPDATE`/`DELETE`; app account additionally denied DDL/DML on these tables.
- Events logged: login/logout (success+failure), HN lookups (who/when — **not** the full payload), create/update/submit with before/after diffs, decisions, criteria publish, report/export runs, admin changes.
- `login_name` denormalized so history survives account removal.
- Review: monthly spot-check (pharmacy + IT security, [07 §6](07-deployment.md#6-operations)); export function produces signed-ish CSV (hash column of the exported range).

## 4. Session & access policy

| Control | Setting (initial, confirm with IT security) |
|---|---|
| Transport | HTTPS/TLS 1.2+ only, internal CA cert; HSTS |
| Authentication | Windows AD preferred (Q3); else app accounts + password policy + lockout (5 tries / 15 min) |
| Idle timeout | 15 min (clinical workstations are shared) |
| Absolute session lifetime | 8 h (shift) |
| Concurrent sessions | 1 per user (same-account double login forces re-auth) |
| Network | Site restricted to hospital subnets; OPD PCs reach only intranet hosts (existing policy) |
| Brute force / enumeration | Generic login errors; HN search rate-limited; full-name search requires ≥ 3 chars and logs queries |
| Headers | Standard hardening headers (`X-Content-Type-Options`, `X-Frame-Options: DENY`, referrer policy) |

## 5. Patient data protection (PDPA-aware)

- **Purpose limitation**: store only DUE-relevant data ([02 FR-1](02-requirements.md#fr-1-patient-lookup-his-read-only)). No addresses, contact details, or unrelated diagnoses; lab snapshots limited to lipid-panel items + safety labs.
- **Data minimization in logs**: audit `details_json` masks patient identifiers where the event context suffices (entity id is enough).
- **On-screen privacy**: OPD workstations are semi-public — patient name partially masked by default (e.g., title + initial), full name on explicit reveal (logged); auto screen-lock via session timeout (§4).
- **Retention & archival** (to be ratified with hospital policy/PDPA officer):
  - Evaluation + decision + audit: retain per hospital record-retention policy (suggested: ≥ 10 years for reimbursement evidence — confirm).
  - `patient_snapshots`: refresh per evaluation; old snapshots retained with their evaluation (provenance), not independently browsable.
  - No deletion of audit history (legal hold).
- **No real data in non-production**: dev (Linux/Podman) uses synthetic patients only ([06 §3](06-dev-environment.md#3-database-bootstrap-repeatable)); staging either synthetic or freshly anonymized — never a production restore with identifiers unless formally approved.
- **Cross-border/transfers**: none — data stays on hospital servers (no cloud, no internet).

## 6. Offline-environment hardening notes

- Vendored third-party assets (JS/CSS/fonts) are **pinned versions with recorded hashes**; supply-chain check at vendoring time (license + integrity), re-verified at release ([05 §6](05-architecture.md#6-sequencing-note-why-offline-constraint-shapes-everything)).
- Server hardening follows hospital baseline: patched via offline WSUS, firewall between OPD VLAN and DB VLAN, SQL Server least-privilege service accounts.
- Backups encrypted at rest if supported by hospital tooling; backup media access controlled ([07 §1.7](07-deployment.md#1-prerequisites-to-confirm-with-it)).

## 7. Security review gates

| Gate | When | Checks |
|---|---|---|
| Threat-model walkthrough | End of design | SQL injection (parameterization audit), authz matrix tests, session fixation, misuse of HIS reads |
| Pre-go-live audit | Before production | §5 checklist items ([07 §5](07-deployment.md#5-go-live-checklist)) + append-only denial test + offline audit |
| Annual review | Yearly | Accounts, roles, retention policy, patch status, criteria integrity |
