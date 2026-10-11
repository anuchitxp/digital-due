-- db/10_schema.sql — DUE_DB schema per docs/04 (repeatable dev reset; production uses migrations)
USE DUE_DB;
GO
-- ---------- drop (dev reset), dependency order ----------
DROP TRIGGER IF EXISTS dbo.trg_audit_log_append;
DROP TRIGGER IF EXISTS dbo.trg_decisions_append;
DROP TABLE IF EXISTS dbo.evaluation_answers;
DROP TABLE IF EXISTS dbo.decisions;
DROP TABLE IF EXISTS dbo.evaluations;
DROP TABLE IF EXISTS dbo.patient_snapshots;
DROP TABLE IF EXISTS dbo.criteria_items;
DROP TABLE IF EXISTS dbo.criteria_versions;
DROP TABLE IF EXISTS dbo.drug_prices;
DROP TABLE IF EXISTS dbo.drugs;
DROP TABLE IF EXISTS dbo.user_roles;
DROP TABLE IF EXISTS dbo.roles;
DROP TABLE IF EXISTS dbo.users;
GO
-- ---------- users & access ----------
CREATE TABLE dbo.users (
  id          bigint IDENTITY PRIMARY KEY,
  login_name  nvarchar(64)  NOT NULL UNIQUE,      -- AD account (REMOTE_USER) e.g. HOSP\due_doc1
  full_name   nvarchar(128) NOT NULL,
  email       nvarchar(128) NULL,
  license_no  nvarchar(32)  NULL,                 -- council ID for physicians (TBC)
  is_active   bit NOT NULL DEFAULT 1,
  created_at  datetime2 NOT NULL DEFAULT sysdatetime()
);
CREATE TABLE dbo.roles (
  id   int IDENTITY PRIMARY KEY,
  code varchar(20) NOT NULL UNIQUE   -- physician | clinic_staff | due_reviewer | admin
);
CREATE TABLE dbo.user_roles (
  user_id bigint NOT NULL REFERENCES dbo.users(id),
  role_id int    NOT NULL REFERENCES dbo.roles(id),
  PRIMARY KEY (user_id, role_id)
);
GO
-- ---------- drugs & criteria (criteria as data) ----------
CREATE TABLE dbo.drugs (
  id           int IDENTITY PRIMARY KEY,
  code         varchar(24) NOT NULL UNIQUE,       -- PITAVA | EVO | INCL
  generic_name nvarchar(128) NOT NULL,
  brand_name   nvarchar(128) NULL,                -- Livalo® / Repatha® / Sybrava®
  atc_code     varchar(12) NULL,
  route        varchar(16) NULL,                  -- oral | sc
  is_active    bit NOT NULL DEFAULT 1
);
CREATE TABLE dbo.drug_prices (
  id             int IDENTITY PRIMARY KEY,
  drug_id        int NOT NULL REFERENCES dbo.drugs(id),
  pack_desc      nvarchar(64)  NOT NULL,
  unit_price     decimal(12,2) NOT NULL,
  currency       char(3) NOT NULL DEFAULT 'THB',
  effective_from date NOT NULL,
  effective_to   date NULL
);
CREATE TABLE dbo.criteria_versions (
  id             int IDENTITY PRIMARY KEY,
  drug_id        int NOT NULL REFERENCES dbo.drugs(id),
  version_no     varchar(12) NOT NULL,
  title_th       nvarchar(256) NOT NULL,
  title_en       nvarchar(256) NULL,
  source_ref     nvarchar(512) NULL,
  effective_from date NOT NULL,
  effective_to   date NULL,
  is_published   bit NOT NULL DEFAULT 0,
  published_at   datetime2 NULL,
  published_by   bigint NULL REFERENCES dbo.users(id),
  CONSTRAINT uq_criteria_version UNIQUE (drug_id, version_no)
);
CREATE TABLE dbo.criteria_items (
  id                  int IDENTITY PRIMARY KEY,
  criteria_version_id int NOT NULL REFERENCES dbo.criteria_versions(id),
  item_code           varchar(8)  NOT NULL,      -- P1..P8 / E1..E9 / I1..I7 (docs/03)
  display_order       int NOT NULL DEFAULT 0,
  label_th            nvarchar(512) NOT NULL,
  label_en            nvarchar(512) NULL,
  item_type           varchar(16) NOT NULL,      -- boolean|numeric|single_choice|multi_choice|text|date|dose
  is_mandatory        bit NOT NULL DEFAULT 1,
  unit                varchar(16) NULL,
  options_json        nvarchar(max) NULL,
  auto_binding_json   nvarchar(max) NULL,
  CONSTRAINT uq_item_code UNIQUE (criteria_version_id, item_code)
);
GO
-- ---------- patients cache ----------
CREATE TABLE dbo.patient_snapshots (
  id            bigint IDENTITY PRIMARY KEY,
  hn            varchar(16) NOT NULL,
  full_name     nvarchar(192) NOT NULL,
  birth_date    date NULL,
  sex           nvarchar(8) NULL,
  weight_kg     decimal(5,2) NULL,
  privilege     varchar(24) NULL,                 -- CSMBS | UCS | SS | SELFPAY
  is_csmbs      bit NOT NULL DEFAULT 0,
  snapshot_json nvarchar(max) NULL,
  fetched_at    datetime2 NOT NULL DEFAULT sysdatetime(),
  fetched_by    bigint NOT NULL REFERENCES dbo.users(id)
);
GO
-- ---------- evaluations ----------
CREATE TABLE dbo.evaluations (
  id                     bigint IDENTITY PRIMARY KEY,
  patient_snapshot_id    bigint NOT NULL REFERENCES dbo.patient_snapshots(id),
  hn                     varchar(16) NOT NULL,    -- denormalized for duplicate checks
  drug_id                int NOT NULL REFERENCES dbo.drugs(id),
  criteria_version_id    int NOT NULL REFERENCES dbo.criteria_versions(id),
  unit_code              varchar(24) NOT NULL,    -- CARDIO | ENDO
  evaluation_type        varchar(12) NOT NULL DEFAULT 'new',  -- new | renewal
  renews_evaluation_id   bigint NULL REFERENCES dbo.evaluations(id),
  status                 varchar(24) NOT NULL DEFAULT 'draft',
      -- draft|submitted|under_review|approved|approved_with_conditions|info_requested|rejected|withdrawn|expired
  planned_regimen        nvarchar(256) NULL,
  summary_recommendation varchar(8) NULL,         -- PASS|FAIL|PENDING
  physician_justification nvarchar(max) NULL,
  created_by             bigint NOT NULL REFERENCES dbo.users(id),
  created_at             datetime2 NOT NULL DEFAULT sysdatetime(),
  submitted_at           datetime2 NULL,
  updated_at             datetime2 NOT NULL DEFAULT sysdatetime(),
  row_version            rowversion
);
CREATE INDEX ix_eval_open ON dbo.evaluations (hn, drug_id) INCLUDE (status);
CREATE TABLE dbo.evaluation_answers (
  id                     bigint IDENTITY PRIMARY KEY,
  evaluation_id          bigint NOT NULL REFERENCES dbo.evaluations(id),
  criteria_item_id       int NOT NULL REFERENCES dbo.criteria_items(id),
  value_json             nvarchar(max) NULL,
  outcome                varchar(8) NOT NULL,     -- pass|fail|na|pending
  source                 varchar(12) NOT NULL,    -- auto|physician|reviewer
  override_justification nvarchar(512) NULL,
  updated_at             datetime2 NOT NULL DEFAULT sysdatetime(),
  CONSTRAINT uq_answer UNIQUE (evaluation_id, criteria_item_id)
);
CREATE TABLE dbo.decisions (
  id            bigint IDENTITY PRIMARY KEY,
  evaluation_id bigint NOT NULL REFERENCES dbo.evaluations(id),
  decision      varchar(24) NOT NULL,  -- approved|approved_with_conditions|info_requested|rejected
  comments      nvarchar(max) NULL,
  decided_by    bigint NOT NULL REFERENCES dbo.users(id),
  decided_at    datetime2 NOT NULL DEFAULT sysdatetime()
);
GO
-- ---------- audit (append-only) ----------
CREATE TABLE dbo.audit_log (
  id           bigint IDENTITY PRIMARY KEY,
  occurred_at  datetime2 NOT NULL DEFAULT sysdatetime(),
  user_id      bigint NULL,
  login_name   nvarchar(64) NULL,
  action       varchar(32) NOT NULL,
  entity       varchar(32) NULL,
  entity_id    bigint NULL,
  details_json nvarchar(max) NULL
);
GO
-- ---------- append-only enforcement (docs/04 §2, docs/08 §3) ----------
CREATE TRIGGER dbo.trg_decisions_append ON dbo.decisions
AFTER UPDATE, DELETE AS
BEGIN
  SET NOCOUNT ON;
  ROLLBACK TRANSACTION;
  THROW 50001, 'decisions is append-only: UPDATE/DELETE denied', 1;
END;
GO
CREATE TRIGGER dbo.trg_audit_log_append ON dbo.audit_log
AFTER UPDATE, DELETE AS
BEGIN
  SET NOCOUNT ON;
  ROLLBACK TRANSACTION;
  THROW 50002, 'audit_log is append-only: UPDATE/DELETE denied', 1;
END;
GO
PRINT 'db/10 done';
