# 09 — Implementation Plan (PHP 8 on IIS FastCGI)

> Status: v2.0 · Scope: production DUE system · Stack: **PHP 8 via IIS FastCGI** (permitted by hospital IT) + MS SQL Server via official Microsoft PHP drivers · Dev: Linux + Podman (native, no VM) · Related: [05-architecture](05-architecture.md), [06-dev-environment](06-dev-environment.md), [07-deployment](07-deployment.md)

## 1. Decision record — PHP 8 via IIS FastCGI

### 1.1 Constraint & approval
ASP.NET is **not permitted**. Hospital IT has confirmed **PHP is permitted** on the IIS server (runtime zip + official Microsoft PHP drivers installed from offline media). FastCGI (`FastCgiModule`) is **built into IIS** — no third-party IIS modules required.

### 1.2 Options considered

| Option | Server install needed | Verdict |
|---|---|---|
| ASP.NET (Core or classic) | ASP.NET module / runtime | ✖ prohibited |
| **PHP 8 via FastCGI** | PHP runtime (zip) + Microsoft PHP drivers + ODBC driver | ✔ **chosen — IT-approved** |
| Classic ASP (built-in) | None | ✖ superseded: no Linux dev parity, legacy risk |
| Node.js behind IIS | HttpPlatformHandler/ARR + runtime | ✖ not approved |

### 1.3 Why PHP wins under the constraints
1. **Official Microsoft SQL Server drivers** (`sqlsrv` / `pdo_sqlsrv`) — first-class, maintained by Microsoft.
2. **Full Linux + Podman dev parity**: the exact same PHP + drivers stack runs in a dev container, so what is tested on Linux is what ships to IIS — no Windows VM needed for daily development.
3. Modern language: `PDO` prepared statements, `json_encode`, `IntlDateFormatter` (Thai dates), sessions.
4. Minimal IIS footprint: one FastCGI handler mapping + `web.config`; app is a plain folder of `.php` files + static assets.

### 1.4 Risks & mitigations

| Risk | Mitigation |
|---|---|
| Server now needs a runtime install (PHP zip + drivers + ODBC) — new but **permitted** | Offline installers only; versions **pinned** and recorded in `docs/07`; install steps rehearsed in staging first |
| Version drift between dev container and server | Pin PHP 8.3.x, drivers 5.11.x, ODBC 18 in `dev/Containerfile.php` and `docs/07`; smoke check script prints versions |
| SQL injection | PDO **prepared statements only**; `inc/db.php` is the single DB module; review grep forbids interpolated SQL |
| Thai text corruption | Files UTF-8, `default_charset="UTF-8"`, DB `nvarchar`, `CharacterSet => UTF-8` in PDO options |
| Session fixation/idle | `session.gc_maxlifetime=900`, `session.use_strict_mode=1`, regenerate ID on login ([08 §4](08-security-compliance.md#4-session--access-policy)) |
| HIS outage | Patient lookup degrades to manual entry with `source=manual` flag (FR-1.3) |

### 1.5 What stays true from earlier docs
- Data model, criteria-as-data, roles, audit, offline-asset rules, security posture: **unchanged** (`docs/02–04, 08`).
- Prototypes (`prototypes/`) remain the UX reference; PHP pages mirror their layout and verdict logic server-side.

## 2. Target structure (repo)

```
app/                        IIS site root / dev container docroot
  index.php                 Role-aware home
  patient_lookup.php        HN lookup (vw_DUE_* + audit)
  evaluation_form.php       Criteria checklist renderer (per drug/version)
  evaluation_save.php       POST: validate → save evaluation + answers + audit → redirect
  review_queue.php          Reviewer queue
  review_decision.php       POST: decision (append-only)
  reports.php               KPIs + Chart.js (datasets via json_encode)
  print_approval.php        Printable approval package
  admin_criteria.php        Reviewer-only: publish/retire criteria versions
  web.config                IIS: default document + PHP FastCGI sample
  inc/
    config.php.example      DSN, app constants (real config.php = git-ignored)
    db.php                  PDO sqlsrv layer — prepared statements only
    auth.php                REMOTE_USER (IIS Windows Auth) + roles + dev impersonation flag
    audit.php               Audit writer
    criteria.php            Criteria loader + auto-check engine (auto_binding_json)
    layout_top/bottom.php   Thai-first bilingual chrome
  assets/                   Vendored Chart.js + due.css (offline)
dev/
  Containerfile.php         php:8.3-cli + msodbcsql18 + sqlsrv/pdo_sqlsrv (pinned)
  compose.yaml              app (8080→8080) + db (mssql 2022, ./db mounted at /db read-only)
db/                         Numbered T-SQL scripts (docs/04) — run in the dev db container
docs/                       This set (09 added)
prototypes/                 UX reference
```

## 3. Phases

### P0 — Environment & contracts (no code)
| Item | Owner | Exit criteria |
|---|---|---|
| PHP version + drivers pinned & staged (PHP 8.3 NTS zip, msphpsql 5.11.x, ODBC 18) | IT + Dev | Staging IIS serves `phpinfo()` page; `sqlsrv_client_info()` shows ODBC 18 |
| Dev stack green on Linux | Dev | `podman compose -f dev/compose.yaml up` → app answers on :8080, db reachable |
| HIS read-only views contract signed off (`docs/04 §3`) | HIS team | Frozen names/columns + SELECT-only login |
| Criteria v1.0 content validated (three drugs) | Pharmacy/DUE committee | Signed sources recorded in `criteria_versions.source_ref` |
| Auth mode agreed | IT | IIS Windows Auth → `REMOTE_USER`; AD groups → roles mapping table |

### P1 — DB + evaluation CRUD (weeks 1–2)
Deliverables: `db/` scripts final; `evaluation_form.php` + `evaluation_save.php` create/edit drafts with answers; `patient_lookup.php`.
Acceptance: physician looks up fake HN, completes checklist, saves draft, resumes; optimistic concurrency via `row_version`; mandatory-item gate blocks submit; duplicate check warns.

### P2 — Criteria engine + auto-check (weeks 2–3)
Deliverables: `inc/criteria.php` loader + auto-check against `auto_binding_json` (latest LDL from `vw_DUE_LabLipid`, therapy months from `vw_DUE_Dispense`, ICD match from `vw_DUE_Diagnoses`); server-side verdict; override requires justification (audited); criteria version pinned to evaluation.
Acceptance: same fake patient → same verdict as prototype logic; overrides visible in audit.

### P3 — Review workflow + audit (weeks 3–4)
Deliverables: `review_queue.php`, `review_decision.php` (immutable decisions — DB triggers deny UPDATE/DELETE), `inc/audit.php` wired to every action, creator ≠ final decider enforced server-side.
Acceptance: full trail creation → decision; append-only denial test passes; role matrix (`docs/08 §2`) enforced.

### P4 — Reports + print (weeks 4–5)
Deliverables: `reports.php` (KPIs, monthly trend, reasons A–E, drug/unit splits via vendored Chart.js), `print_approval.php` (Thai print CSS), CSV export.
Acceptance: numbers match hand-count on seeded data; print sheet accepted by pharmacy (trial print).

### P5 — Staging rehearsal, hardening, go-live (weeks 5–6)
Deliverables: deploy to real Windows/IIS staging (`docs/07`); security gates (`docs/08 §7`); offline audit; training; go-live + hypercare.
Acceptance: `docs/07 §5` checklist ticked; committee sign-off on criteria v1.0; manual-entry fallback demonstrated (FR-1.3).

## 4. Effort estimate (rough, 1 senior dev + 0.5 reviewer)

| Phase | Estimate |
|---|---|
| P0 | 1–2 days (no VM; stack converges fast) |
| P1 | 5–7 days |
| P2 | 4–5 days |
| P3 | 3–4 days |
| P4 | 3–4 days |
| P5 | 5 days + committee calendar |
| Total | ~5–6 weeks calendar, ~22 dev-days |

## 5. Coding standards (PHP)

1. Files UTF-8 (no BOM); `declare(strict_types=1)` in `inc/`; PHP 8.3, no framework, **no Composer dependencies** (offline rule — `docs/05 §6`).
2. **All SQL through `inc/db.php`**: PDO sqlsrv, **prepared statements only** (`?` placeholders); never interpolate user input; review grep enforces.
3. Sessions: `session.use_strict_mode=1`, idle 15 min, `session_regenerate_id(true)` on login; `REMOTE_USER` identity from IIS Windows Auth; `config.php` dev-impersonation flag must be **off** in staging/prod (checked in go-live list).
4. Every state change calls `inc/audit.php` with actor, entity, before/after (PHI minimized); status transitions via one `NextStatus()` helper.
5. Output escaping: `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')` for all user/HIS-sourced values.
6. Errors: `display_errors=Off` in staging/prod, log server-side, friendly error page; never leak SQL/DSN.
7. Assets: vendored only (`docs/05 §6`); zero external URLs anywhere in `app/`.

## 6. Traceability

- FR/NFR: `docs/02`; roles/audit/session: `docs/08`; schema: `docs/04`; criteria: `docs/03`.
- Prototype parity: layout/verdict behavior matches `prototypes/due-form.html`, `due-dashboard.html` (client-side demo logic replaced by server-side criteria engine).
