# 05 — Architecture

> Status: draft v0.1 · Constraints recap: hospital Windows Server with **IIS + MS SQL Server**; OPD workstations = **browser only, intranet, no internet**; dev = **Linux + Podman**.

## 1. Logical architecture

```
OPD PC workstation (browser)                Hospital Windows Server
┌────────────────────────────┐              ┌──────────────────────────────────────────┐
│  DUE web UI (bilingual)    │   HTTPS      │  IIS                                     │
│  - HN lookup               │─────────────►│   └─ DUE web app (stack §4)              │
│  - criteria checklist form │  intranet    │        │  reads                    writes│
│  - review queue (pharmacy) │              │        ▼                          ▼      │
│  - reports / print         │              │  HIS read-only views           DUE_DB    │
└────────────────────────────┘              │  (vw_DUE_*)  ──────►  (MS SQL Server)    │
                                            └──────────────────────────────────────────┘
```

Key decisions:

1. **Thin client, server-rendered pages with progressive enhancement.** OPD PCs are locked down; avoid fat browser runtimes, plugins, or WebSockets-only designs. Everything must work with a plain form-post round-trip first; JS enhances (autosave, auto-check highlighting) but is not required for correctness.
2. **Read-only HIS access over SQL views** with a frozen contract ([04 §3](04-data-model.md#3-read-only-his-view-contract-to-agree-with-his-team)). The app's DB role in HIS has `SELECT` only.
3. **DUE_DB is the system of record** for evaluations, criteria, decisions, and audit.
4. **No outbound internet**: no CDN assets, no web fonts from external hosts, no telemetry, no auto-update calls. All static assets bundled and self-hosted with license-safe files (NFR-1).

## 2. Deployment view (production)

| Component | Host | Notes |
|---|---|---|
| IIS site `due.hosp.local` | Hospital Windows Server (existing IIS) | HTTPS with internal CA cert; app pool with dedicated identity |
| `DUE_DB` | Same or dedicated SQL Server instance | SQL auth or Windows auth from app pool (decide with DBA) |
| HIS views | HIS SQL Server | Read-only account, network path/firewall rule from app server |
| Browser clients | OPD PC workstations | URL whitelist/intranet zone; standard browser (Q2) |
| Backups | SQL Server maintenance plan / DBA tooling | [NFR-10](02-requirements.md#4-non-functional-requirements) |

Environments: **dev (Linux/Podman)** → **staging (IIS rehearsal, synthetic data)** → **production**. Staging is essential because the production parity points (IIS, real SQL Server version) cannot be reproduced in dev containers.

## 3. Cross-cutting choices

- **Authentication**: prefer **Windows AD / Windows Authentication** if AD is available (Q3) — no separate passwords for physicians; fall back to app-managed accounts + password policy if not. Map roles via AD groups → `roles` table.
- **Sessions**: server-side session or signed cookie, 15-min idle timeout for clinical roles ([08 §4](08-security-compliance.md#4-session--access-policy)).
- **PDF/print**: server-side HTML→print CSS (print-friendly page) for the approval package; avoid heavyweight PDF libraries unless licensing/size allows — decision at implementation.
- **Localization**: PHP array dictionaries in `inc/`, Thai-first for criteria text; user-level language toggle (NFR-8).
- **Time**: all timestamps server-side `datetime2` in server-local Asia/Bangkok time; display layer formats Buddhist era if required (NFR-12).

## 4. Web technology stack — decision: PHP 8 via IIS FastCGI (no ASP.NET)

**Update:** ASP.NET is **not permitted** on the hospital server; hospital IT has **approved PHP**. The stack is therefore **PHP 8 served by IIS via the built-in FastCGI module**, talking to MS SQL Server through the **official Microsoft PHP drivers** (`pdo_sqlsrv`) + ODBC Driver 18 ([07 §3](07-deployment.md#3-iis-site-deployment)). Full decision record, risks and phased plan: [09-implementation-plan](09-implementation-plan.md).

Historical comparison (kept for the record; superseded by the constraint + IT approval):

| Criterion | A. ASP.NET Core (Razor Pages/MVC) | B. Classic ASP.NET (Web Forms/MVC5) | C. PHP + sqlsrv | D. Node.js + mssql |
|---|---|---|---|---|
| Developable on **Linux** (C5) | ✔ first-class (`dotnet` SDK) | ✖ Windows-only tooling | ✔ | ✔ |
| Runs on hospital **IIS** | ✔ (ASP.NET Core Module) | ✔ native | Possible (CGI/FastCGI) but unidiomatic on IIS | Possible via reverse proxy; more moving parts |
| **MS SQL Server** driver | Microsoft.SqlServer (SqlClient) — official | ✔ | Official `sqlsrv`/`pdo_sqlsrv` | `mssql` (tedious) — official-ish |
| Long-term support | LTS versions, MS-supported | Legacy mode; Web Forms in maintenance | Community + MS driver | Community |
| Fit for server-rendered, form-heavy clinical UI | ✔✔ Razor Pages designed for it | ✔ (but aging) | ✔ | ✔ (with SSR framework) |
| Team availability in Thai hospitals | Moderate (C# common) | High (legacy familiarity) | High | Lower |
| Offline asset bundling | Straightforward (env-tagged helpers) | Bundling/ScriptManager | Manual | Manual/bundler |
| Dev/test on Podman + SQL container (06) | ✔✔ | ✖ | ✔ | ✔ |

**Decision: PHP 8 via IIS FastCGI** — IT-approved, backed by the **official Microsoft PHP drivers for SQL Server**, and uniquely allowing **native Linux + Podman development**: the same PHP + drivers stack runs in the dev container (`dev/compose.yaml`), so Linux tests match the IIS deployment. No Windows VM is needed; IIS-side specifics (FastCGI registration, Windows Auth → `REMOTE_USER`, php.ini) are rehearsed in staging ([07 §3](07-deployment.md#3-iis-site-deployment)). All UI is server-rendered HTML with progressive enhancement — the prototypes already follow that pattern.

## 5. Application structure (stack-agnostic)

```
app/                       IIS site root (PHP)
  *.php                    UI pages: lookup, evaluation form, review queue, reports, print
  inc/db.php               PDO sqlsrv data layer (prepared statements only)
  inc/criteria.php         Domain: criteria resolution + auto-check engine
  inc/auth.php             REMOTE_USER (Windows Auth) + role mapping; status state machine
  assets/                  Vendored Chart.js + due.css (offline)
dev/                       Containerfile.php + compose.yaml (PHP + SQL Server, pinned)
db/                        DDL + seed scripts (doc 04), numbered
prototypes/                UX reference (browser demos)
docs/                      This documentation set
```

Rules:

1. All SQL is **prepared statements via PDO sqlsrv** (no interpolated SQL) — mandatory ([08](08-security-compliance.md)); enforced by `inc/db.php` being the only module that touches the database.
2. Auto-check engine reads `criteria_items.auto_binding_json` and the HIS views only; no hard-coded lab logic ([03 §1](03-clinical-criteria.md#1-design-principle-criteria-as-data-not-code)).
3. HIS access goes through read-only synonym targets with per-call timeout + fallback flag (FR-1.3).
4. State machine for `evaluations.status` lives in one function in `inc/auth.php`'s sibling module — enforced server-side (client never trusts status).

## 6. Sequencing note (why offline constraint shapes everything)

Because OPD PCs cannot fetch anything from the internet, **every third-party asset must be vendored into the repo at dev time** (Linux side), reviewed for license, and deployed as part of the IIS site. There is no "npm install / CDN at runtime" escape hatch on the server either if the server subnet is equally restricted — confirm with IT (Q2) whether the **server** has outbound access for updates; if not, patching follows the hospital's offline WSUS/package process ([07 §4](07-deployment.md#4-updates-without-internet)).
