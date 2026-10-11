# Digital DUE — Drug Use Evaluation Digital Form

A browser-based Drug Use Evaluation (DUE) system for **expensive, strictly-reimbursed lipid-lowering agents** prescribed in **Cardiology and Endocrine units/staff OPD**.

## Purpose

Hospital pharmacy and prescribing physicians need a structured, criteria-driven digital form to:

1. Evaluate whether each prescription of a high-cost lipid-lowering drug meets the clinical criteria required for reimbursement (especially for **CSMBS** patients).
2. Record baseline data, prior therapy, laboratory results, and the approval decision in one auditable place.
3. Support periodic DUE reporting to the hospital Pharmacy & Therapeutics (P&T) / DUE committee.

## Target drugs (phase 1)

| Drug | Class | Brand (original) | Typical route/frequency |
|---|---|---|---|
| Pitavastatin (original) | Statin | Livalo® | Oral, once daily |
| Evolocumab | PCSK9 inhibitor (mAb) | Repatha® | SC, q2wk or q4wk |
| Inclisiran | siRNA PCSK9 synthesis inhibitor | Sybrava® | SC, baseline → 3 mo → q6mo |

> The form architecture is drug-agnostic so additional medicines (other specialties, other classes) can be added later by defining new criteria versions — no code change required.

## Constraints (why the design looks the way it does)

- Hospital server: **MS SQL Server** (2019/2022 — exact version to be confirmed) + **IIS** on Windows Server.
- OPD PC workstations: **browser only**, on the **hospital intranet**; **no internet access is permitted** → all assets (JS/CSS/fonts) must be self-hosted; no CDN, no external API calls.
- Integration with the hospital HIS is **read-only** (SQL views): HN lookup, demographics, lipid panels, diagnoses, prior prescriptions/dispensing.
- Developer machines run **Linux** (openSUSE Tumbleweed / Fedora atomic) with **Podman** containers emulating MS SQL Server locally.

## Documentation index

| # | Document | Contents |
|---|---|---|
| 01 | [Project context](docs/01-project-context.md) | Background, problem statement, stakeholders, scope, glossary |
| 02 | [Requirements](docs/02-requirements.md) | Functional & non-functional requirements, user stories |
| 03 | [Clinical criteria](docs/03-clinical-criteria.md) | Per-drug DUE data elements, evaluation checklist framework |
| 04 | [Data model](docs/04-data-model.md) | DUE database schema (T-SQL), read-only HIS view contract |
| 05 | [Architecture](docs/05-architecture.md) | Logical & deployment architecture, tech-stack options & recommendation |
| 06 | [Development environment](docs/06-dev-environment.md) | Linux + Podman + SQL Server container setup |
| 07 | [Deployment](docs/07-deployment.md) | Hospital Windows Server / IIS deployment outline |
| 08 | [Security & compliance](docs/08-security-compliance.md) | Roles, audit trail, patient data protection, session policy |
| 09 | [Implementation plan](docs/09-implementation-plan.md) | PHP 8 on IIS FastCGI (IT-approved): decision record, phases P0–P5, coding standards |
| — | [App skeleton](app/) | PHP dev skeleton (runs via [dev/compose.yaml](dev/compose.yaml)): DUE form, review queue, reports, criteria engine |
| — | [Prototypes](prototypes/README.md) | Offline browser demos: [DUE form](prototypes/due-form.html) (3-drug criteria checklist, verdict, print) + [committee dashboard](prototypes/due-dashboard.html) — synthetic data only |

## Status

- [x] Project context & requirements drafted
- [ ] Clinical criteria validated with hospital Pharmacy / DUE committee
- [ ] HIS read-only view contract agreed with HIS team
- [ ] Web technology stack selected (comparison in doc 05)
- [ ] Implementation

## Terminology

**DUE** Drug Use Evaluation · **CSMBS** Civil Servant Medical Benefit Scheme (Thailand) · **HIS** Hospital Information System · **HN** Hospital Number · **OPD** Out-Patient Department · **P&T** Pharmacy and Therapeutics committee. Full glossary: [doc 01](docs/01-project-context.md#glossary).
