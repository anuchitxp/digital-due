# 07 — Deployment (Hospital Windows Server / IIS)

> Status: draft v0.1 · Audience: hospital IT + project team · Environment chain: **dev (Linux/Podman, doc 06) → staging (Windows rehearsal) → production (OPD-facing)**

## 1. Prerequisites (to confirm with IT)

| # | Item | Target |
|---|---|---|
| 1 | Windows Server version, IIS role available | Hospital app server |
| 2 | SQL Server version/edition (2019 or 2022, Q1) + instance name | DBA |
| 3 | **PHP 8.3 (NTS, x64) runtime** — zip deploy to `C:\PHP`, offline download; **Microsoft PHP drivers for SQL Server** (`php_sqlsrv_83_nts_x64.dll`, `php_pdo_sqlsrv_83_nts_x64.dll`) + **ODBC Driver 18** — all offline installers, versions pinned to the dev container (`docs/06 §7`) | Server |
| 4 | TLS certificate from hospital internal CA for the intranet hostname | IT security |
| 5 | Firewall rules: OPD subnet → app server (443); app server → SQL (1433); app server → HIS SQL (1433, read-only account) | Network |
| 6 | Service accounts: app pool identity (and SQL login mapping) | AD/DBA |
| 7 | Backup schedule & restore ownership | DBA |

## 2. Database deployment

1. Run `db/` scripts in numbered order on production SQL (`sqlcmd` from DBA workstation or deployment user).
2. Create app login/user with **least privilege**: `db_datareader` + `db_datawriter` on `DUE_DB` only, **plus DDL-deny** on `decisions`/`audit_log` (append-only enforcement lives in DB triggers too, [04 §2](04-data-model.md#2-due-application-database)).
3. HIS read-only access: `SELECT` grant on `vw_DUE_*` views only, mapped per [04 §3](04-data-model.md#3-read-only-his-view-contract-to-agree-with-his-team).
4. Smoke test script: insert synthetic HN through the app in staging → verify rows + audit entries.

## 3. IIS site deployment

Outline (PHP per [09-implementation-plan](09-implementation-plan.md)):

0. Install PHP (per §1.3): unzip to `C:\PHP`; copy `php.ini-production` → `php.ini`; set `extension_dir`, enable `php_sqlsrv_83_nts_x64.dll` + `php_pdo_sqlsrv_83_nts_x64.dll`, `session.gc_maxlifetime=900`, `session.use_strict_mode=1`, `default_charset="UTF-8"`, `display_errors=Off`.
0b. Register FastCGI (built-in `FastCgiModule`): `appcmd set config /section:system.webServer/fastCGI "/+[fullPath='C:\\PHP\\php-cgi.exe']"` and a handler mapping `*.php` → FastCGI (or ship it in the site's `web.config` — sample included in `app/web.config`).

1. Release bundle = the `app/` folder (`.php` files + assets) + `db/` migration scripts; transfer via hospital-approved media/transfer channel — **no public internet pulls on the server**. Files must remain UTF-8 (verify after transfer).
2. IIS: create site `due.hosp.local` → physical folder → app pool (no managed code / integrated as appropriate).
   - Bindings: HTTPS 443 only; HTTP→HTTPS redirect; host-name binding.
   - TLS: internal CA cert; enable modern protocols only (TLS 1.2+).
3. Windows Authentication: enable on the site, disable Anonymous for PHP pages → identity arrives as `REMOTE_USER` (verify with a probe page). App pool identity: dedicated AD service account for static content / process token; SQL access uses the dedicated `due_app` SQL login from `inc/config.php`, **not** the app-pool identity.
4. Static assets: served by IIS with cache headers; **verify no external URLs** in rendered pages (offline audit step, §5).
5. Restrict site by IP: allow only hospital subnets (OPD PCs + pharmacy) at IIS or network level.
6. App config: DSN/credentials in `inc/config.php` (git-ignored; `config.php.example` committed); secrets via server-side file only — never in source control. Session: `session.gc_maxlifetime=900` in php.ini matches NFR-6.

## 4. Updates without internet

- All updates (app, hosting bundle, SQL patches, browser on OPD PCs) arrive via hospital offline channels (WSUS/SCCM offline, approved media).
- Keep a **versioned release bundle**: artifact + `db/` migration scripts + release notes + rollback script.
- Rollback plan: previous artifact folder kept (`releases/2025-xx-xx`), DB migrations written **expand/contract** (additive first, destructive later release) so app rollback never needs DB rollback.

## 5. Go-live checklist

- [ ] Staging rehearsal passed on real Windows/IIS + real SQL version (auth, print, bilingual labels)
- [ ] HIS views reachable from app server; latency within NFR-3
- [ ] Criteria v1.0 × 3 drugs published and locked (committee sign-off, doc 03 §5)
- [ ] Users + roles provisioned (physicians: cardiology, endocrine; reviewers: pharmacy)
- [ ] Offline audit: zero external network calls from a workstation session (browser dev-tools + server logs)
- [ ] Audit-log write test + append-only denial test (attempt UPDATE on `audit_log` → must fail)
- [ ] Backup: full backup + restore drill on staging
- [ ] Training: 30-min session for physicians & reviewers; quick-reference card at OPD workstations
- [ ] Support channel & downtime procedure defined (manual-entry fallback, FR-1.3)

## 6. Operations

| Task | Frequency | Owner |
|---|---|---|
| Backup verification (restore test) | Quarterly | DBA |
| Criteria version review (payer circulars) | On announcement + annual | Pharmacy/DUE committee |
| Audit-log spot review | Monthly | Pharmacy + IT security |
| Price table update | On procurement change | Pharmacy admin |
| DR restore exercise (full) | Yearly | IT |
