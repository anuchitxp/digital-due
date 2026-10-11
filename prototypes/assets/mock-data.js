/**
 * mock-data.js — synthetic demo data for DUE offline prototypes
 * -------------------------------------------------------------
 * ALL patients, names, HNs, labs and prices are FABRICATED for
 * prototype/demo purposes. No real patient data may be placed here.
 * Prices are PLACEHOLDERS pending the pharmacy price table (docs/04 §5).
 *
 * Used by: due-form.html (simulated HIS lookup) and due-dashboard.html.
 * Exposed as: window.DUE_MOCK
 */
(function () {
  'use strict';

  // Placeholder drug prices (THB) — replace with hospital procurement prices
  var PRICES = {
    PITAVA: { label: 'Pitavastatin (original) 2 mg', unitPrice: 22.0, unit: 'เม็ด', daily: 1 },   // ต่อเม็ด
    EVO:    { label: 'Evolocumab 140 mg',            unitPrice: 2300.0, unit: 'แอมพูล', daily: null }, // ต่อโดส (q2w)
    INCL:   { label: 'Inclisiran 284 mg',            unitPrice: 8500.0, unit: 'แอมพูล', daily: null }  // ต่อโดส (q6mo)
  };

  // Synthetic patient master (simulated HIS extract)
  var PATIENTS = [
    {
      hn: '6500012', name: 'สมชาย ใจดี*', age: 62, sex: 'ช', weight: 74,
      privilege: 'CSMBS', csmbs: true, unit: 'CARDIO',
      diagnoses: [{ icd: 'I21.9', th: 'MI เฉียบพลัน (post-STEMI)' }, { icd: 'I25.1', th: 'CAD เอกสารแล้ว' }, { icd: 'E11.9', th: 'DM type 2' }],
      labs: { ldl: { value: 118, date: '2568-08-10' }, hdl: 42, tg: 165, alt: 28, ck: 110 },
      statinHistory: [
        { drug: 'Atorvastatin', dose: '40→80 mg', months: 6, onTreatmentLdl: 128, note: 'ขนาดสูงสุดแล้ว' },
        { drug: 'Ezetimibe', dose: '10 mg', months: 3, onTreatmentLdl: 118, note: '' }
      ],
      adr: null
    },
    {
      hn: '6500033', name: 'สมหญิง รักงาน*', age: 58, sex: 'หญิง', weight: 66,
      privilege: 'CSMBS', csmbs: true, unit: 'ENDO',
      diagnoses: [{ icd: 'E78.01', th: 'FH (Familial Hypercholesterolemia)' }, { icd: 'E11.9', th: 'DM type 2' }],
      labs: { ldl: { value: 156, date: '2568-07-22' }, hdl: 48, tg: 140, alt: 24, ck: 95 },
      statinHistory: [],
      adr: null
    },
    {
      hn: '6600045', name: 'ประเสริฐ มั่นคง*', age: 67, sex: 'ช', weight: 71,
      privilege: 'CSMBS', csmbs: true, unit: 'CARDIO',
      diagnoses: [{ icd: 'I63.3', th: 'Ischemic stroke' }, { icd: 'I25.1', th: 'CAD' }],
      labs: { ldl: { value: 61, date: '2568-08-30' }, hdl: 40, tg: 150, alt: 30, ck: 88 },
      statinHistory: [
        { drug: 'Rosuvastatin', dose: '20 mg', months: 12, onTreatmentLdl: 142, note: '' },
        { drug: 'Ezetimibe', dose: '10 mg', months: 6, onTreatmentLdl: 138, note: '' },
        { drug: 'Evolocumab 140 mg q2w', dose: '—', months: 6, onTreatmentLdl: 61, note: 'ลด LDL จาก 142 → 61 (57%) — ผ่าน response rule' }
      ],
      adr: null,
      renewalOf: 'EVO'
    },
    {
      hn: '6600078', name: 'มาลี สุขใส*', age: 54, sex: 'หญิง', weight: 68,
      privilege: 'UCS', csmbs: false, unit: 'ENDO',
      diagnoses: [{ icd: 'E11.9', th: 'DM type 2' }, { icd: 'E78.5', th: 'Dyslipidemia' }],
      labs: { ldl: { value: 132, date: '2568-08-05' }, hdl: 52, tg: 210, alt: 26, ck: 74 },
      statinHistory: [{ drug: 'Atorvastatin', dose: '40 mg', months: 4, onTreatmentLdl: 132, note: '' }],
      adr: null
    },
    {
      hn: '6500090', name: 'วิชัย เพียรงาน*', age: 71, sex: 'ช', weight: 63,
      privilege: 'CSMBS', csmbs: true, unit: 'CARDIO',
      diagnoses: [{ icd: 'I25.1', th: 'CAD' }, { icd: 'N18.3', th: 'CKD stage 3' }],
      labs: { ldl: { value: 96, date: '2568-08-18' }, hdl: 38, tg: 175, alt: 22, ck: 145 },
      statinHistory: [
        { drug: 'Simvastatin', dose: '20 mg', months: 2, onTreatmentLdl: 104, note: 'myalgia' },
        { drug: 'Atorvastatin', dose: '20 mg', months: 1, onTreatmentLdl: 96, note: 'myalgia ซ้ำ' }
      ],
      adr: { types: ['myalgia'], drug: 'Simvastatin + Atorvastatin', dose: '20 mg ทั้งตัว' }
    }
  ];

  // ---- Deterministic pseudo-random (seeded LCG) so charts look the same every run
  var _s = 20250901;
  function rnd() { _s = (_s * 1664525 + 1013904223) % 4294967296; return _s / 4294967296; }
  function pick(a) { return a[Math.floor(rnd() * a.length)]; }

  // Seed evaluation records: 48 fabricated submissions over the last 12 months
  var UNITS = ['CARDIO', 'ENDO'];
  var DRUGS = ['PITAVA', 'EVO', 'INCL'];
  var PREVENTIONS = ['secondary', 'primary'];
  var REASONS = ['A', 'B', 'C', 'D', 'E'];
  var FIRST = ['สมชาย', 'สมหญิง', 'ประเสริฐ', 'มาลี', 'วิชัย', 'อรุณ', 'จันทร์', 'มะลิ', 'อนงค์', 'ปรีชา'];
  var LAST = ['ใจดี*', 'รักงาน*', 'มั่นคง*', 'สุขใส*', 'เพียรงาน*', 'แสงทอง*', 'มีสุข*', 'ร่มเย็น*'];

  var SEED = [];
  var base = new Date('2025-09-01T00:00:00');
  var id = 1000;
  for (var m = 11; m >= 0; m--) {
    // volume grows toward recent months
    var count = 2 + Math.round((11 - m) * 0.35 + rnd());
    for (var i = 0; i < count; i++) {
      var day = 1 + Math.floor(rnd() * 27);
      var d = new Date(base.getFullYear(), base.getMonth() - m, day);
      var drug = pick(DRUGS);
      var pass = rnd() < 0.72;
      var reasons = [];
      if (!pass || rnd() < 0.55) {
        reasons.push(pick(REASONS));
        if (rnd() < 0.25) { var r2 = pick(REASONS); if (reasons.indexOf(r2) < 0) reasons.push(r2); }
      }
      var cost = 0;
      if (drug === 'PITAVA') cost = PRICES.PITAVA.unitPrice * 30;      // 1 เดือน
      if (drug === 'EVO') cost = PRICES.EVO.unitPrice * (pass ? 2 : 0);  // โดสละ 2 สัปดาห์ ~ 2 แอมพูล/เดือน
      if (drug === 'INCL') cost = PRICES.INCL.unitPrice * (pass ? 1 : 0);
      SEED.push({
        id: 'SEED-' + (id++),
        date: d.toISOString().slice(0, 10),
        hn: '65' + String(10000 + Math.floor(rnd() * 89999)),
        initials: pick(FIRST) + ' ' + pick(LAST),
        age: 45 + Math.floor(rnd() * 30),
        sex: pick(['ช', 'หญิง']),
        unit: pick(UNITS),
        drug: drug,
        evalType: drug !== 'PITAVA' && rnd() < 0.3 ? 'renewal' : 'new',
        prevention: drug === 'PITAVA' ? pick(PREVENTIONS) : null,
        verdict: pass ? 'pass' : 'fail',
        reasons: reasons,
        costThb: cost,
        criteriaVersion: 'v1.0-draft',
        source: 'seed'
      });
    }
  }

  window.DUE_MOCK = {
    meta: {
      note: 'ข้อมูลสังเคราะห์เพื่อสาธิตระบบ (synthetic) — ห้ามใส่ข้อมูลผู้ป่วยจริง',
      criteriaVersion: 'v1.0-draft',
      prices: PRICES
    },
    patients: PATIENTS,
    seedEvaluations: SEED,

    // localStorage key shared with due-form.html
    SUBMISSIONS_KEY: 'due_demo_submissions',

    getSubmissions: function () {
      try { return JSON.parse(localStorage.getItem(this.SUBMISSIONS_KEY) || '[]'); }
      catch (e) { return []; }
    },
    addSubmission: function (rec) {
      var list = this.getSubmissions();
      rec.id = 'LOCAL-' + (Date.now());
      rec.source = 'local';
      list.push(rec);
      localStorage.setItem(this.SUBMISSIONS_KEY, JSON.stringify(list));
      return rec;
    },
    allEvaluations: function () {
      return this.seedEvaluations.concat(this.getSubmissions());
    }
  };
})();
