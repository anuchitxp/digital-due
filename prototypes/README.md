# DUE Prototypes — offline demo pages

> **Prototype only.** No backend, no IIS, no MS SQL — everything runs in the browser with `localStorage`.
> All patient data, names, HNs, labs, and drug prices in these pages are **synthetic**. Never enter real patient data into a prototype.

## Files

| File | Purpose |
|---|---|
| `due-form.html` | DUE evaluation form (Thai-first): simulated HIS lookup, 3-drug criteria checklist (pitavastatin original / evolocumab / inclisiran), reason codes A–F, live verdict, auto Thai summary + copy, draft autosave, submit → localStorage bridge, print approval sheet |
| `due-dashboard.html` | Committee dashboard: KPI grid, monthly trend, A–E reason distribution, drug/type/unit charts, unit + period + drug filters, recent submissions table, print report |
| `assets/mock-data.js` | Synthetic patients + ~50 seeded evaluation records + localStorage bridge (`window.DUE_MOCK`) |
| `assets/vendor/chart.umd.min.js` | Chart.js **4.4.1** umd build, vendored for offline use |

## How to open

Option A — local web server (recommended, matches production-ish origin):

```bash
cd /home/aswp/projects/digital-due
python3 -m http.server 8080
# open http://localhost:8080/prototypes/due-form.html
# open http://localhost:8080/prototypes/due-dashboard.html
```

Option B — double-click `due-form.html` (`file://` also works; localStorage persists per origin, so form→dashboard bridging works when both are opened the same way).

Demo flow: open the form → HN `6500012` → ดึงข้อมูล HIS → pick a drug → fill/see verdict → ส่งพิจารณา → open the dashboard → the new record appears (marked `ฟอร์ม (ทดลอง)`).

## Vendored asset provenance

| Asset | Version | Source | License |
|---|---|---|---|
| `chart.umd.min.js` | 4.4.1 | Chart.js official CDN build (cdnjs, umd) | MIT (Chart.js © Chart.js contributors) |

SHA-256: `81ffafe13c37e1b25793b020d446f4d9739b949dadb7f9f79d709a0cad781c2f`

## Offline constraint

Per `docs/05-architecture.md` (NFR-1) and `docs/01-project-context.md` (C2): OPD workstations have **no internet access**. These prototypes therefore use:

- **System font stack** (no Google Fonts `@import` like the reference pages),
- **Vendored Chart.js** (no cdnjs script tag),
- **Zero external URLs** — verified by grep audit during build.

## Prototype vs. production gaps

| Prototype | Production (per docs) |
|---|---|
| `localStorage` only, per-browser | MS SQL Server `DUE_DB` + audit trail (`docs/04`) |
| Simulated HIS lookup from `mock-data.js` | Read-only SQL views `vw_DUE_*` (`docs/04 §3`) |
| Verdict logic hard-coded in page JS | Criteria as versioned **data** served from `criteria_versions`/`criteria_items` (`docs/03 §1`, `docs/04 §2`) |
| "ส่งพิจารณา" appends to localStorage | Review workflow with roles, immutable decisions (`docs/02 FR-3`, `docs/08`) |
| Prices/thresholds are placeholders | Committee-validated criteria v1.0 + procurement prices (`docs/03 §5`) |
| Thai-only labels | Bilingual resource files, user-level toggle (`docs/02 NFR-8`) |
