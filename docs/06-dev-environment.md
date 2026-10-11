# 06 — Development Environment (Linux + Podman)

> Status: v2.0 · Target hosts: **openSUSE Tumbleweed** (primary, podman 5.x verified) and **Fedora atomic/immutable** (Silverblue/CoreOS-style, toolbox or host podman). PHP + SQL Server both run in **Podman containers** — full parity with the IIS FastCGI + MS SQL deployment ([09-implementation-plan](09-implementation-plan.md)).

## 1. Topology

```
Developer Linux machine — everything in Podman
┌────────────────────────────────────────────────────────────┐
│  podman compose (dev/compose.yaml)                         │
│  ├─ app: php:8.3-cli + sqlsrv/pdo_sqlsrv  → localhost:8080 │
│  │       docroot = repo app/ (bind mount)                  │
│  └─ db:  mssql 2022 (internal)  ./db mounted at /db (ro)   │
│  host: editors, git, browser                               │
└────────────────────────────────────────────────────────────┘
   Same PHP + drivers stack ships to IIS FastCGI (docs/07)
```

## 2. One-time setup

### 2.1 SQL Server container

SQL Server official images are **x86_64 only** — fine on typical Linux dev boxes (ARM: see §6).

```bash
# openSUSE Tumbleweed: sudo zypper install podman   (Fedora atomic: preinstalled)
podman pull mcr.microsoft.com/mssql/server:2022-latest

podman run -d --name mssql-due \
  -p 1433:1433 \
  -e ACCEPT_EULA=Y \
  -e MSSQL_SA_PASSWORD='Due#Dev-2024!' \
  -e MSSQL_PID=Developer \
  -e MSSQL_MEMORY_LIMIT_MB=2048 \
  -v mssql-due-data:/var/opt/mssql \
  --health-cmd "/opt/mssql-tools18/bin/sqlcmd -C -S localhost -U sa -P \"\$MSSQL_SA_PASSWORD\" -Q 'SELECT 1' -b -o /dev/null" \
  --health-interval 15s --health-retries 10 \
  mcr.microsoft.com/mssql/server:2022-latest

podman logs -f mssql-due          # wait for 'SQL Server is now ready for client connections'
```

Notes:

- SA password must meet complexity rules or the container exits immediately.
- `MSSQL_MEMORY_LIMIT_MB=2048` keeps the container light (SQL Server min is 2 GB); raise for report testing.
- If a named volume complains on SELinux systems, add `:Z` to the volume mount (`-v mssql-due-data:/var/opt/mssql:Z` is not valid for named volumes in older podman — prefer `--security-opt label=disable` only if needed; podman 5.x handles named volumes with labels automatically).
- systemd integration (recommended, replaces `podman run` after first create):

```bash
podman generate systemd --new --name mssql-due > ~/.config/systemd/user/mssql-due.service  # deprecated path
# preferred on podman 5.x: quadlet
mkdir -p ~/.config/containers/systemd
cat > ~/.config/containers/systemd/mssql-due.container <<'EOF'
[Container]
Image=mcr.microsoft.com/mssql/server:2022-latest
ContainerName=mssql-due
PublishPort=1433:1433
Environment=ACCEPT_EULA=Y
Environment=MSSQL_SA_PASSWORD=Due#Dev-2024!
Environment=MSSQL_PID=Developer
Environment=MSSQL_MEMORY_LIMIT_MB=2048
Volume=mssql-due-data.volume:/var/opt/mssql
HealthCmd=/opt/mssql-tools18/bin/sqlcmd -C -S localhost -U sa -P "Due#Dev-2024!" -Q "SELECT 1" -b -o /dev/null

[Service]
Restart=always

[Install]
WantedBy=default.target
EOF
systemctl --user daemon-reload && systemctl --user start mssql-due
```

### 2.2 Client tooling (choose what fits)

| Tool | Install (Tumbleweed) | Use |
|---|---|---|
| `sqlcmd` in container | bundled (`/opt/mssql-tools18/bin/sqlcmd`, flag `-C` trusts self-signed cert) | DDL/seed scripts |
| `podman exec -it mssql-due /opt/mssql-tools18/bin/sqlcmd -C -S localhost -U sa -p` | — | quick ad-hoc |
| DBeaver / Azure Data Studio | zypper / flatpak | GUI browsing |
| PHP 8 CLI (optional on host) | zypper `php8 php8-cli` | quick `php -l` without container |

## 3. Database bootstrap (repeatable)

```bash
# schema
podman exec -i mssql-due /opt/mssql-tools18/bin/sqlcmd -C -S localhost -U sa -P 'Due#Dev-2024!' -i /dev/stdin < db/01_create_database.sql
# execute all numbered scripts in order
for f in db/0*.sql; do
  podman exec -i mssql-due /opt/mssql-tools18/bin/sqlcmd -C -S localhost -U sa -P 'Due#Dev-2024!' -i /dev/stdin < "$f"
done
```

- `db/` scripts per [04-data-model](04-data-model.md): `01_create_database.sql`, `10_schema.sql`, `20_seed_drugs_prices.sql`, `30_seed_criteria_v1.sql`, `40_seed_users.sql`, `50_seed_synonyms_his.sql`.
- In dev, **HIS views are faked**: create a `his_dup` schema with the same view names populated with synthetic patients (include: CSMBS patient with ASCVD + LDL 118 on max statin → should PASS evolocumab v1; UCS patient → strict-mode off; patient missing labs → manual-entry path). Synthetic-data script: `db/90_fake_his_data.sql`. **Never copy real patient data into the dev environment.**

## 4. Connection strings

| Target | Example |
|---|---|
| Dev (container) | `Server=localhost,1433;Database=DUE_DB;User Id=due_app;Password=...;TrustServerCertificate=True;Encrypt=True` |
| Staging/prod (Windows) | `Server=HOSP-SQL01;Database=DUE_DB;Integrated Security=SSPI;TrustServerCertificate=True` (app-pool identity) — final form with DBA |

Keep credentials out of source: user-secrets / environment variables; config file only in deployment.

## 5. Daily workflow

```bash
systemctl --user start mssql-due       # quadlet unit
# ... develop, run app against localhost:1433 ...
systemctl --user stop mssql-due        # or leave running
```

- Reset DB: `podman exec -i mssql-due ... sqlcmd -Q "DROP DATABASE DUE_DB"` then re-run bootstrap (§3).
- Volume inspection/backup: `podman volume ls`, `podman run --rm -v mssql-due-data:/d:Z alpine ls /d` (or use `BACKUP DATABASE` to `/var/opt/mssql/backup` then `podman cp`).

## 6. Gotchas

| Issue | Note |
|---|---|
| ARM machines (Asahi/aarch64) | No official SQL Server images; use x86 box, or emulation (slow), or Remote SQL container on another host |
| immutable Fedora (Silverblue) | Keep toolchains in `toolbox`/`distrobox` or use flatpak IDEs; podman runs on the host image by default |
| Port 1433 occupied | Check `ss -tlnp | grep 1433`; change `-p 11433:1433` and connection string accordingly |
| `sqlcmd` cert error | mssql-tools18 requires `-C` (trust server cert) or proper cert provisioning |
| SQL Server + SELinux | If container fails to start with AVC denials, `sudo ausearch -m avc -ts recent`; prefer named volumes over bind mounts |
| Hospital-parity | Container = SQL Server engine only. **IIS behaviors (Windows auth, app-pool identity, ASP.NET Core module) must be validated on a real Windows staging host** (doc 07) — not in the container |


## 7. PHP dev stack (Podman compose)

`dev/compose.yaml` runs the **same stack as production** (PHP 8.3 + sqlsrv drivers + MS SQL 2022), so Linux checks transfer to IIS.

### 7.1 Files

- `dev/Containerfile.php` — `php:8.3-cli` base, installs `msodbcsql18` (Microsoft repo) + `sqlsrv`/`pdo_sqlsrv` (pecl, pinned), docroot `/app`.
- `dev/compose.yaml` — two services:
  - `app`: builds from `Containerfile.php`, bind-mounts `../app` at `/app`, publishes `8080:8080`, command `php -S 0.0.0.0:8080 -t /app`.
  - `db`: `mcr.microsoft.com/mssql/server:2022-latest`, EULA + SA password env, named volume, `../db` mounted read-only at `/db`, **no host port published** (internal only).

### 7.2 Daily workflow

```bash
cd /home/aswp/projects/digital-due
podman compose -f dev/compose.yaml up -d --build
cp app/inc/config.php.example app/inc/config.php   # one-time (git-ignored; fills from compose env)
# web app:      http://localhost:8080/          (dev impersonation: ?devu=HOSP%5Cdue_doc1)
# run db script:
podman compose -f dev/compose.yaml exec -T db \
  /opt/mssql-tools18/bin/sqlcmd -C -S localhost -U sa -P 'Due#Dev-2024!' -i /db/10_schema.sql
podman compose -f dev/compose.yaml down          # keep volume: add -v only to wipe
```

- The earlier standalone `mssql-due` quadlet (`§2.1`) is an **alternative** to the compose `db` service — use one or the other (port 1433 is not published by compose, so both can coexist if desired).
- PHP lint without starting anything: `podman compose -f dev/compose.yaml run --rm app php -l /app/index.php`.

### 7.3 Gotchas

| Issue | Note |
|---|---|
| First build slow | pecl compile of sqlsrv takes a few minutes; layers are cached afterwards |
| SELinux bind mount | If `app/` mount reads as empty/denied, add `:Z` to the volume in compose |
| Driver mismatch with staging | Container pins sqlsrv 5.11 + ODBC 18 — keep `docs/07` staging install on the same versions |
| Thai text | Files UTF-8; `default_charset=UTF-8` is set in the Containerfile php.ini snippet and mirrored in IIS php.ini |
