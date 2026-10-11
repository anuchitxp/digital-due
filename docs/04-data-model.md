# 04 — Data Model (MS SQL Server)

> Status: draft v0.1 · Target: MS SQL Server 2019/2022 (TBC — [Q1](02-requirements.md#6-open-questions-track-with-hospital)) · Two schemas: the **DUE application database** (owned by this system) and a **read-only HIS view contract** (owned by the HIS team).

## 1. Databases

```
┌──────────────────────────────┐        ┌─────────────────────────────────┐
│  DUE_DB  (app database)      │        │  HIS (hospital database)        │
│  - evaluations, criteria,    │ reads  │  - provided to us as READ-ONLY  │
│    decisions, audit, users   │◄───────│    VIEWS: vw_DUE_Patient,       │
│  - price & drug tables       │ views  │    vw_DUE_LabLipid, ...         │
└──────────────────────────────┘        └─────────────────────────────────┘
```

- `DUE_DB` is created and owned by this project. His views may live in the HIS DB or as synonyms forwarded by the DBA — **never write to HIS**.
- Use `CREATE SYNONYM` in `DUE_DB` pointing at the actual HIS views so connection details stay in one place.

## 2. DUE application database

Naming: `snake_case`, `id` = `bigint IDENTITY` PK where not otherwise stated, `created_at/updated_at datetime2 DEFAULT sysdatetime()`, soft-delete via `is_deleted` where useful. Collation: follow hospital server default (Thai CI recommended for name columns).

```sql
CREATE DATABASE DUE_DB;
GO
USE DUE_DB;
GO

-- ---------- Users & access ----------
CREATE TABLE dbo.users (
  id             bigint IDENTITY PRIMARY KEY,
  login_name     nvarchar(64)  NOT NULL UNIQUE,      -- AD account or local login
  full_name      nvarchar(128) NOT NULL,
  email          nvarchar(128) NULL,
  license_no     nvarchar(32)  NULL,                 -- council ID for physicians (TBC)
  is_active      bit NOT NULL DEFAULT 1,
  created_at     datetime2 NOT NULL DEFAULT sysdatetime()
);

CREATE TABLE dbo.roles (
  id    int IDENTITY PRIMARY KEY,
  code  varchar(20) NOT NULL UNIQUE   -- physician | clinic_staff | due_reviewer | admin
);
CREATE TABLE dbo.user_roles (
  user_id bigint NOT NULL REFERENCES dbo.users(id),
  role_id int    NOT NULL REFERENCES dbo.roles(id),
  PRIMARY KEY (user_id, role_id)
);

-- ---------- Drugs & criteria (criteria as data) ----------
CREATE TABLE dbo.drugs (
  id             int IDENTITY PRIMARY KEY,
  code           varchar(24) NOT NULL UNIQUE,       -- PITAVA-ORIG | EVOLOCUMAB | INCLISIRAN
  generic_name   nvarchar(128) NOT NULL,
  brand_name     nvarchar(128) NULL,
  atc_code       varchar(12) NULL,
  route          varchar(16) NULL,                  -- oral | sc
  is_active      bit NOT NULL DEFAULT 1
);

CREATE TABLE dbo.drug_prices (                      -- for cost reporting (FR-5.1)
  id           int IDENTITY PRIMARY KEY,
  drug_id      int NOT NULL REFERENCES dbo.drugs(id),
  pack_desc    nvarchar(64)  NOT NULL,               -- e.g. '1 mg tablet', '140 mg syringe'
  unit_price   decimal(12,2) NOT NULL,
  currency     char(3) NOT NULL DEFAULT 'THB',
  effective_from date NOT NULL,
  effective_to   date NULL
);

CREATE TABLE dbo.criteria_versions (                -- versioned criteria sets (FR-4)
  id            int IDENTITY PRIMARY KEY,
  drug_id       int NOT NULL REFERENCES dbo.drugs(id),
  version_no    varchar(12) NOT NULL,               -- '1.0', '1.1'
  title_th      nvarchar(256) NOT NULL,
  title_en      nvarchar(256) NULL,
  source_ref    nvarchar(512) NULL,                 -- circular/minute reference (doc 03 §5)
  effective_from date NOT NULL,
  effective_to   date NULL,
  is_published  bit NOT NULL DEFAULT 0,
  published_at  datetime2 NULL,
  published_by  bigint NULL REFERENCES dbo.users(id),
  CONSTRAINT uq_criteria_version UNIQUE (drug_id, version_no)
);

CREATE TABLE dbo.criteria_items (
  id                int IDENTITY PRIMARY KEY,
  criteria_version_id int NOT NULL REFERENCES dbo.criteria_versions(id),
  item_code         varchar(8)  NOT NULL,           -- P1..P8 / E1..E9 / I1..I7 (doc 03)
  display_order     int NOT NULL DEFAULT 0,
  label_th          nvarchar(512) NOT NULL,
  label_en          nvarchar(512) NULL,
  item_type         varchar(16) NOT NULL,           -- boolean|numeric|single_choice|multi_choice|text|date|dose
  is_mandatory      bit NOT NULL DEFAULT 1,
  unit              varchar(16) NULL,               -- mg/dL, months...
  options_json      nvarchar(max) NULL,             -- choice lists
  auto_binding_json nvarchar(max) NULL,             -- {"his_view":"vw_DUE_LabLipid","field":"ldl_c","op":"gte","value":70,"lookback_days":180}
  CONSTRAINT uq_item_code UNIQUE (criteria_version_id, item_code)
);

-- ---------- Patients cache (snapshot from HIS at evaluation time) ----------
CREATE TABLE dbo.patient_snapshots (
  id           bigint IDENTITY PRIMARY KEY,
  hn            varchar(16) NOT NULL,
  full_name     nvarchar(192) NOT NULL,
  birth_date    date NULL,
  sex           char(1) NULL,
  weight_kg     decimal(5,2) NULL,
  privilege     varchar(24) NULL,                   -- CSMBS | UCS | SS | SELFPAY ...
  is_csmbs      bit NOT NULL DEFAULT 0,
  snapshot_json nvarchar(max) NULL,                 -- raw view payload for provenance
  fetched_at    datetime2 NOT NULL DEFAULT sysdatetime(),
  fetched_by    bigint NOT NULL REFERENCES dbo.users(id)
);

-- ---------- Evaluations ----------
CREATE TABLE dbo.evaluations (
  id                 bigint IDENTITY PRIMARY KEY,
  patient_snapshot_id bigint NOT NULL REFERENCES dbo.patient_snapshots(id),
  drug_id            int NOT NULL REFERENCES dbo.drugs(id),
  criteria_version_id int NOT NULL REFERENCES dbo.criteria_versions(id),
  unit_code          varchar(24) NOT NULL,          -- CARDIO | ENDO (clinic/OPD of the prescriber)
  evaluation_type    varchar(12) NOT NULL DEFAULT 'new',   -- new | renewal
  renews_evaluation_id bigint NULL REFERENCES dbo.evaluations(id),
  status             varchar(16) NOT NULL DEFAULT 'draft',
      -- draft|submitted|under_review|approved|approved_with_conditions|info_requested|rejected|withdrawn|expired
  planned_regimen    nvarchar(256) NULL,            -- free text; structured dose lives in answers
  summary_recommendation varchar(8) NULL,           -- PASS|FAIL|PENDING (computed)
  physician_justification nvarchar(max) NULL,       -- required when submitting with FAIL
  created_by         bigint NOT NULL REFERENCES dbo.users(id),
  created_at         datetime2 NOT NULL DEFAULT sysdatetime(),
  submitted_at       datetime2 NULL,
  updated_at         datetime2 NOT NULL DEFAULT sysdatetime(),
  row_version        rowversion                     -- optimistic concurrency (draft autosave)
);
CREATE INDEX ix_eval_open ON dbo.evaluations(hn_lookup) INCLUDE(drug_id,status); -- see note
-- (hn_lookup: persist patient hn on evaluations for fast duplicate check — add column
--  hn varchar(16) NOT NULL, or join via snapshot; decided at implementation)

CREATE TABLE dbo.evaluation_answers (
  id           bigint IDENTITY PRIMARY KEY,
  evaluation_id bigint NOT NULL REFERENCES dbo.evaluations(id),
  criteria_item_id int NOT NULL REFERENCES dbo.criteria_items(id),
  value_json   nvarchar(max) NULL,                  -- typed payload per item_type
  outcome      varchar(8) NOT NULL,                 -- pass|fail|na|pending
  source       varchar(8)  NOT NULL,                -- auto|physician|reviewer
  override_justification nvarchar(512) NULL,        -- required when source=physician overrides auto
  updated_at   datetime2 NOT NULL DEFAULT sysdatetime(),
  CONSTRAINT uq_answer UNIQUE (evaluation_id, criteria_item_id)
);

CREATE TABLE dbo.decisions (                        -- append-only (FR-3.2)
  id            bigint IDENTITY PRIMARY KEY,
  evaluation_id bigint NOT NULL REFERENCES dbo.evaluations(id),
  decision      varchar(24) NOT NULL,  -- approved|approved_with_conditions|info_requested|rejected
  comments      nvarchar(max) NULL,
  decided_by    bigint NOT NULL REFERENCES dbo.users(id),
  decided_at    datetime2 NOT NULL DEFAULT sysdatetime()
);

CREATE TABLE dbo.audit_log (                        -- append-only (NFR-5)
  id           bigint IDENTITY PRIMARY KEY,
  occurred_at  datetime2 NOT NULL DEFAULT sysdatetime(),
  user_id      bigint NULL,
  login_name   nvarchar(64) NULL,                   -- denormalized: survives user deletion
  action       varchar(32) NOT NULL,                -- login|lookup|create|update|submit|decision|export|criteria_publish...
  entity       varchar(32) NULL,
  entity_id    bigint NULL,
  details_json nvarchar(max) NULL                   -- before/after (PHI minimized per doc 08)
);

CREATE TABLE dbo.price_reports (                    -- cache for report runs (optional, phase 1.5)
  id bigint IDENTITY PRIMARY KEY,
  evaluation_id bigint NOT NULL,
  estimated_cost decimal(12,2) NOT NULL,
  computed_at datetime2 NOT NULL DEFAULT sysdatetime()
);
```

Implementation notes:

- **Rowversion** on `evaluations` supports the autosave concurrency rule (FR-2.4).
- **Decisions and audit_log are append-only**: enforce via `INSTEAD OF UPDATE/DELETE` triggers that `THROW`, or by denying UPDATE/DELETE to the app role.
- Retention/archival policy per [08-security §5](08-security-compliance.md).
- All DDL must run on SQL Server 2019 **and** 2022 (no 2022-only syntax).

## 3. Read-only HIS view contract (to agree with HIS team)

The DUE app depends **only** on these views. Exact source tables and item codes to be mapped by the HIS team (Q4). Fields marked ⚠ are nice-to-have; the form degrades to manual entry when absent ([FR-1.3](02-requirements.md#fr-1-patient-lookup-his-read-only)).

```sql
-- 1) Demographics / privilege (one row per HN)
CREATE VIEW his_dup.vw_DUE_Patient AS
SELECT hn, thai_name, en_name, birth_date, sex, weight_kg,
       privilege_code, privilege_name, is_active
-- ⚠ privilege_code must distinguish CSMBS (strict mode, FR-1.2)

-- 2) Diagnoses (problem list + visit diagnoses, lookback configurable)
CREATE VIEW his_dup.vw_DUE_Diagnoses AS
SELECT hn, icd10, icd10_name_th, dx_date, dx_type   -- dx_type: chronic|visit

-- 3) Lipid laboratory results (item-coded)
CREATE VIEW his_dup.vw_DUE_LabLipid AS
SELECT hn, lab_date, item_code, item_name, value_num, unit, is_abnormal
-- expected item_code set (mapping TBC): LDL_C, TC, HDL_C, TG, ALT, AST, CK

-- 4) Prescriptions / dispensing history for lipid-lowering agents
CREATE VIEW his_dup.vw_DUE_Dispense AS
SELECT hn, date_dispensed, drug_code, generic_name, dose, dose_unit,
       quantity, days_supply, prescriber_unit
-- drug_code mapping to ATC C10A* preferred; needed for statin/ezetimibe
-- duration auto-checks (P2, E2, E4, I2) and % LDL reduction trends

-- 5) Current visit context (optional)
CREATE VIEW his_dup.vw_DUE_Visits AS
SELECT hn, visit_id, visit_datetime, clinic_code, provider_id  -- ⚠
```

Contract rules:

1. Views are **read-only**; the DUE app account gets `SELECT` only.
2. Field names/types are **frozen**; changes go through a versioned contract (e.g., `vw_DUE_LabLipid_v2`), old views kept ≥ 1 release.
3. Performance target: each view returns for one HN in ≤ 2 s with an index on `hn` (HIS team to confirm).
4. If HIS cannot expose views on the same instance, alternatives: **linked server** or **nightly sync into `DUE_DB.his_mirror`** tables — decision at Q4 (sync = always "manual-flag" data freshness warning).

## 4. ER overview

```
users ──< user_roles >── roles
drugs ──< drug_prices
drugs ──< criteria_versions ──< criteria_items
patient_snapshots ──< evaluations ──< evaluation_answers >── criteria_items
                          │  └─< decisions
                          └─ self-ref (renewals)
audit_log (references by entity/id)
```

## 5. Seed data checklist (go-live)

- [ ] `drugs`: pitavastatin (original), evolocumab, inclisiran (ATC codes: C10AA08 pitavastatin — verify; evolocumab C10AX13? — verify with pharmacy).
- [ ] `drug_prices`: current procurement prices (per tablet / per syringe).
- [ ] `criteria_versions` 1.0 × 3 drugs from committee-verified source docs (doc 03 §5).
- [ ] `roles` + initial users (reviewers first).
- [ ] HIS synonyms + connectivity test script.
