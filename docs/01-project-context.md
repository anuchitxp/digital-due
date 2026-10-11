# 01 — Project Context

> Status: draft v0.1 · Owner: project team · Reviewers: hospital pharmacy (DUE/P&T committee), cardiology, endocrine

## 1. Background

The hospital prescribes several **high-cost lipid-lowering agents** whose reimbursement — particularly for **CSMBS** (Civil Servant Medical Benefit Scheme) patients — is subject to **rigid clinical criteria** and pre-authorization:

- **Pitavastatin (original brand)** — statin where the original product is reimbursed only under specific conditions (e.g., failure of/intolerance to formulary statins).
- **Evolocumab (Repatha®)** — PCSK9 inhibitor monoclonal antibody; reimbursement requires documented ASCVD/high risk, inadequate LDL-C control on maximally tolerated statin ± ezetimibe, etc.
- **Inclisiran (Sybrava®)** — siRNA against PCSK9 synthesis; similar or stricter criteria, often including failure of/intolerance to PCSK9 mAb therapy.

Today this evaluation is done with **paper forms or ad-hoc documentation**, which causes:

- Incomplete or inconsistent evidence captured per patient (missing lab values, prior regimens, intolerance history).
- Slow, hard-to-track approval workflow between physician → pharmacy → payer.
- No reliable aggregate data for the DUE committee (e.g., "how many patients met criteria?", "any off-label use?", "cost per responder?").
- Difficulty demonstrating compliance during audits of CSMBS claims.

## 2. Problem statement

> Prescribing physicians in **Cardiology** and **Endocrine** OPD have no standardized digital tool to capture, validate, and document Drug Use Evaluation data for expensive lipid-lowering agents, leading to reimbursement delays/rejections and weak audit evidence.

## 3. Proposed solution

A **web-based digital DUE form** that:

- Runs in the **browser** on existing OPD PC workstations (**intranet only, no internet access**).
- Is served by the hospital **IIS** web server, with data stored in **MS SQL Server**.
- Looks up patient data (HN) from the **HIS via read-only SQL views** — no manual re-typing of demographics/labs where HIS data exists.
- Walks the physician through a **criteria checklist per drug** (criteria are versioned).
- Routes the result to **pharmacy/DUE committee review** and records the final decision.
- Produces **summary reports** for the DUE committee and for reimbursement documentation.

## 4. Goals & success criteria

| # | Goal | Measure |
|---|---|---|
| G1 | Complete, criteria-structured documentation for every target-drug prescription | 100% of target-drug starts in cardiology/endocrine OPD have a DUE record |
| G2 | Faster reimbursement submission | Median time prescription → approved DUE record reduced vs. paper baseline |
| G3 | Reliable audit evidence | Every approval/change traceable to a user, timestamp, and criteria version |
| G4 | Committee reporting | DUE summary report (per drug, per unit) generated on demand, without manual aggregation |

## 5. Stakeholders & users

| Stakeholder | Role in system |
|---|---|
| **Cardiologists / endocrinologists (staff, fellows, residents)** | Primary users: create/complete DUE evaluations in OPD |
| **OPD nurses / pharmacists in clinic** | May create draft records, verify HN, attach vitals |
| **Hospital pharmacy — DUE/P&T committee** | Review submissions, record approval/rejection, manage criteria versions, run reports |
| **HIS team / DBA** | Provide and maintain read-only SQL views; manage production DB |
| **IT / network** | Host IIS site, certificates, workstation browser configuration, no-internet policy |
| **Payer liaison (CSMBS office)** | Consumes the printed/exported approval package |

## 6. Scope

### In scope (phase 1)
- DUE digital form for **pitavastatin (original), evolocumab, inclisiran**.
- Use in **cardiology and endocrine staff/unit/OPD** workflows.
- Patient lookup via HIS **read-only views**; DUE data captured/owned by this system.
- Criteria checklist + decision workflow + audit trail + basic reports.
- Thai/English bilingual UI labels; documents in English.

### Out of scope (phase 1, revisit later)
- Writing back to HIS or to the hospital billing/e-claims system.
- Other drug classes/units (the design must allow adding them later).
- Electronic signature / integration with national e-claim APIs.
- Mobile devices; the form targets PC workstation browsers.

## 7. Constraints & assumptions

| # | Constraint / assumption | Consequence for design |
|---|---|---|
| C1 | Hospital server side: **MS SQL Server** (2019/2022 — exact version TBC) and **IIS** on Windows Server | Solution must run on IIS + MS SQL; T-SQL compatible with 2019+ |
| C2 | **No internet access** from OPD workstation PCs | All JS/CSS/fonts self-hosted; no CDN; no external fonts/APIs; updates installed offline |
| C3 | Browser-only client on OPD PCs (institutional standard browser, version TBC) | Avoid bleeding-edge browser features; test on the hospital's standard browser |
| C4 | HIS integration is **read-only** via SQL views (assumed) | Patient/labs/prescriptions fetched read-only; DUE DB is authoritative for DUE data |
| C5 | Dev machines: Linux (openSUSE Tumbleweed, Fedora atomic) + **Podman** | Local SQL Server via container; stack must be developable on Linux (see doc 05/06) |
| C6 | Drug prices are high; reimbursement criteria are strict and **may change** (NHSO/CSMBS/payer updates) | Criteria **versioning** is a first-class feature (doc 03) |
| C7 | Patient data is sensitive personal data (PDPA applies) | Role-based access + full audit trail (doc 08) |

> Assumption note: "CRMBS" in the original brief is interpreted as **CSMBS** (Civil Servant Medical Benefit Scheme). "MS SQL Server 2021" does not exist; server version to be confirmed with hospital IT (2019 or 2022).

## 8. High-level workflow (phase 1)

```
Physician (OPD)                    Pharmacy / DUE committee           Reports
────────────────                   ────────────────────────           ───────
1. Enter HN ──► HIS read-only views ──► patient + labs + prior Rx
2. Select drug (pitavastatin/evolocumab/inclisiran)
3. Complete criteria checklist (auto-checks vs. labs where possible)
4. Submit ─────────────────────────► 5. Review submission
                                        6. Approve / request info / reject
                                        7. Record decision + comments
                                   8. Print/export approval package ──► CSMBS office
                                   9. Periodic DUE summary reports ──► committee
```

## 9. Glossary

| Term | Meaning |
|---|---|
| **DUE** | Drug Use Evaluation — structured review of drug use against predefined criteria |
| **CSMBS** | Civil Servant Medical Benefit Scheme (Thailand) — scheme for government employees/dependents; known for strict pre-authorization criteria on expensive drugs |
| **HIS** | Hospital Information System (patient administration, labs, prescriptions) |
| **HN** | Hospital Number — unique patient identifier |
| **AN** | Admission Number (visit/encounter identifier, if used by HIS) |
| **OPD** | Out-Patient Department |
| **P&T / DUE committee** | Pharmacy & Therapeutics / Drug Use Evaluation committee |
| **ASCVD** | Atherosclerotic cardiovascular disease |
| **LDL-C** | Low-density lipoprotein cholesterol |
| **PCSK9** | Proprotein convertase subtilisin/kexin type 9 (target of evolocumab/inclisiran) |
| **FH** | Familial hypercholesterolemia |
| **PDPA** | Personal Data Protection Act (Thailand) |
| **IIS** | Internet Information Services (Microsoft web server) |
| **NHSO** | National Health Security Office (Thailand; sets criteria for UCS scheme — relevant reference for criteria wording) |

## 10. Related documents

- Requirements → [02-requirements.md](02-requirements.md)
- Clinical criteria framework → [03-clinical-criteria.md](03-clinical-criteria.md)
- Data model → [04-data-model.md](04-data-model.md)
- Architecture & stack options → [05-architecture.md](05-architecture.md)
- Dev environment (Linux/Podman) → [06-dev-environment.md](06-dev-environment.md)
- Deployment → [07-deployment.md](07-deployment.md)
- Security & compliance → [08-security-compliance.md](08-security-compliance.md)
