# 03 — Clinical Criteria (DUE Framework)

> Status: draft v0.1 — **all thresholds and payer criteria below are PLACEHOLDERS to be validated** with the hospital Pharmacy/DUE committee against current CSMBS/NHSO circulars and the hospital formulary policy. Do not implement as fixed constants; every value must be part of a versioned criteria set ([FR-4](02-requirements.md#fr-4-criteria-management)).

## 1. Design principle: criteria as data, not code

For each drug, the DUE committee maintains a **criteria version** containing:

- **Data elements** to capture (indication, prior therapy, labs, contraindications…)
- **Checklist items** with types, mandatory flags, and (optionally) auto-check bindings
- **Result logic** (all mandatory items pass → "meets criteria" recommendation)

The web form renders whatever the current version defines. When a payer (CSMBS/NHSO) or the hospital updates criteria, the committee publishes a new **effective-dated version**; historical evaluations keep the version under which they were judged. See the generic schema in [04-data-model §2](04-data-model.md#2-due-application-database).

## 2. Common data elements (all three drugs)

| Element | Source | Notes |
|---|---|---|
| Patient HN, name, age, sex | HIS view | Auto-filled |
| Privilege scheme (CSMBS / UCS / SS / self-pay) | HIS view | **CSMBS triggers strict-mode criteria** |
| Indication / diagnosis (ICD-10) | HIS + physician | e.g., ASCVD event codes, FH, high-risk primary prevention |
| Cardiovascular risk category | Physician | e.g., recurrent ASCVD, ASCVD + DM, post-ACS, stroke |
| Weight | HIS/clinic | Dosing & anti-obesity interactions |
| Pregnancy status / contraception (where applicable) | Physician | Contraindication check |
| Baseline LDL-C (date + value) | HIS lab view | With manual-entry fallback |
| Baseline lipid panel: TC, HDL-C, TG | HIS lab view | Optional context |
| Hepatic/transaminase status (statins) | HIS lab / physician | ALT/AST, CK if symptomatic |
| Prior lipid-lowering therapy ≥ lookback window | HIS dispensing view | Drug, dose, duration, adherence proxy |
| Statin intolerance details (if claimed) | Physician | Which statin(s), dose, reaction (myalgia/CK rise), dechallenge/rechallenge |
| Ezetimibe trial & duration (where required) | HIS + physician | Typically ≥ 3 months before PCSK9-agent approval |
| Concomitant interacting drugs | Physician | e.g., cyclosporine for pitavastatin (dose cap) |
| Planned regimen: drug, dose, frequency, duration | Physician | Drives estimated cost |
| Monitoring plan | Physician | LDL-C recheck interval, safety labs |

## 3. Drug-specific criteria frameworks (PLACEHOLDERS)

### 3.1 Pitavastatin (original brand, e.g., Livalo®)

**Rationale for original-brand use** is what the form must evidence. Typical item set:

| # | Item (placeholder wording) | Type | Mandatory | Auto-check |
|---|---|---|---|---|
| P1 | Diagnosis justifies statin therapy (ASCVD / high-risk primary prevention / FH) | single-choice (ICD list) | ✔ | — |
| P2 | Documented inadequate LDL-C control on generic/formulary statin at maximally tolerated dose for ≥ 3 months | boolean + text | ✔ (CSMBS) | dispensing history + LDL trend |
| P3 | **or** intolerance to ≥ 2 formulary statins (details) | boolean + intolerance detail sub-form | alt. to P2 | — |
| P4 | Baseline LDL-C above goal for risk category (threshold per version) | numeric mg/dL | ✔ | lab auto-check |
| P5 | No contraindication (active liver disease, pregnancy…) | boolean | ✔ | — |
| P6 | If on cyclosporine: dose ≤ 1 mg/day (interaction cap) | boolean | cond. | — |
| P7 | Planned dose & duration | dose/date | ✔ | — |
| P8 | Monitoring plan: lipid panel at 4–12 weeks after start | boolean | ✔ | — |

### 3.2 Evolocumab (Repatha® — PCSK9 mAb)

Typical structure per Thai payer criteria (validate with committee):

| # | Item | Type | Mandatory | Auto-check |
|---|---|---|---|---|
| E1 | Clinical ASCVD (MI, PAD, stroke) **or** severe/high-risk hypercholesterolemia (incl. FH) | multi-select diagnosis | ✔ | ICD list |
| E2 | Maximally tolerated statin therapy ≥ 3 months (document dose & % LDL reduction) | boolean + detail | ✔ | dispensing view |
| E3 | Statin intolerance documented (if statin not used) | intolerance sub-form | alt. to E2 | — |
| E4 | Ezetimibe trial ≥ 3 months (or contraindication) | boolean + detail | ✔ | dispensing view |
| E5 | LDL-C ≥ threshold for risk category (placeholder: ≥ 70 mg/dL secondary prevention / ≥ 100 mg/dL primary severe) — **version value** | numeric mg/dL | ✔ | lab auto-check |
| E6 | No prior same-class therapy duplication (active evolocumab/inclisiran check) | system check | ✔ | DUE DB + HIS |
| E7 | Pregnancy/contraception status where applicable | single-choice | cond. | — |
| E8 | Injection administration plan (SC q2wk 140 mg / q4wk 420 mg), self-injection training | single-choice + boolean | ✔ | — |
| E9 | Response review at 3 months: LDL-C reduction ≥ X% else discontinuation (continuation rule) | rule | ✔ | lab auto-check at renewal |

### 3.3 Inclisiran (Sybrava® — siRNA)

| # | Item | Type | Mandatory | Auto-check |
|---|---|---|---|---|
| I1 | Same indication gate as E1 (ASCVD/high-risk; payer wording TBC) | multi-select | ✔ | ICD list |
| I2 | Maximally tolerated statin ± ezetimibe ≥ duration per version | boolean + detail | ✔ | dispensing view |
| I3 | **Prior PCSK9 mAb failure/intolerance** OR payer allows first-line siRNA (version-dependent — verify) | single-choice | ✔ | DUE DB history |
| I4 | LDL-C ≥ threshold (version value) | numeric | ✔ | lab auto-check |
| I5 | Dosing schedule acknowledged: day 0 → 3 months → every 6 months | schedule display + boolean | ✔ | — |
| I6 | Renewal requires interval LDL-C lab + response rule (continuation criteria) | rule | ✔ (renewal) | lab auto-check |
| I7 | No contraindication / pregnancy check | boolean | ✔ | — |

> **Continuation (renewal) criteria** matter as much as initiation criteria for evolocumab/inclisiran: the form should implement the "response rule" (e.g., LDL-C reduction ≥ 30–50% vs. baseline, or absolute target attainment) as auto-checked items at each renewal (US-12).

## 4. Result & decision model

- Each item outcome: `pass / fail / na / pending-manual` with `source = auto | physician | reviewer`.
- Mandatory item `fail` ⇒ recommendation **"does not meet criteria"**; physician may still submit with written justification → goes to committee review (`under_review`).
- Reviewer decision: `approved / approved_with_conditions / info_requested / rejected`, with comments. ([FR-3](02-requirements.md#fr-3-review--decision-workflow))

## 5. Sources to verify before go-live (committee action list)

1. Current **CSMBS** circular/announcement for each drug (pre-authorization conditions, covered indications, renewal rules) — obtain latest version + Thai original.
2. Hospital formulary policy & P&T minutes for the three drugs.
3. Thai lipid guideline targets (risk-stratified LDL-C goals) used by the hospital.
4. HIS lab item codes for lipid panel & CK/ALT; dispensing data fields available for auto-checks.
5. Price table (hospital procurement price) for cost reporting.

Each verified criteria set becomes **criteria version 1.0** per drug, with the source document referenced in the version metadata (`source_ref` field, [04-data-model](04-data-model.md)).
