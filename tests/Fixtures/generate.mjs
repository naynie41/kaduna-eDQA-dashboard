// The ODK fixture generator (tests/Fixtures/README.md): `node tests/Fixtures/generate.mjs`.
// Writes, from one spec per case:
//   sources/odk/{valid,broken}/<name>.json     one ODK Central OData Submissions entry
//   sources/kobo/{valid,broken}/<name>.json    the same visit as one KPI API v2 data row
//   parsed/{valid,broken}/<name>.json          ParsedSubmission::toArray() a correct parser gives
//   parsed/{valid,broken}/<name>.expected.json the outcome 2A expects
// The parsed side is written from the spec, never by the parser, so the golden tests stay a
// check on it. Run from the repo root. Add a case as one line in CASES.
import fs from 'node:fs';
import path from 'node:path';

const ROOT = process.argv[2] ?? '.';
const CODES = ['nhmis_summary', 'register_present', 'tally_sheet', 'reporting_rate'];
const DIMS = [['avail', 'availability'], ['consist', 'consistency'], ['valid', 'validity']];

/** The ordinary visit every case starts from. */
function base(name) {
    return {
        name,
        version: '2026.1',
        instance: `uuid:fx-${name}`,
        date: '2026-04-14',
        start: '09:00', end: '09:45', submitted: '08:50', // local, local, UTC
        lga: 'CHK', ward: 'CHK-KUJ', facility: 'FX-0001',
        roundYear: 2026, roundQuarter: 2,
        assessor: 'Hauwa Ibrahim', device: 'collect:hauwa01', submitter: ['17', 'hauwa.ibrahim'],
        answer: () => (code) => (code === 'reporting_rate' ? 'no' : 'yes'),
        skipSlot: null, onlyTyped: null, typed: null, override: {},
        startRaw: null,
    };
}

const CASES = [
    ['valid', 'clean-visit', {}, 'accepted', []],
    ['valid', 'older-version-2025-2', { version: '2025.2', facility: 'FX-0006' }, 'accepted', []],
    ['broken', 'unknown-form-version', { version: '2021.3' }, 'quarantined', ['UNKNOWN_FORM_VERSION']],
    ['broken', 'shifted-columns', { ward: 'Quarterly', startRaw: 'FEBRUARY' }, 'quarantined', ['COLUMN_DRIFT']],
    ['broken', 'blank-lga', { lga: null }, 'quarantined', ['LGA_UNKNOWN']],
    ['broken', 'unknown-lga', { lga: 'Kaduna Central' }, 'quarantined', ['LGA_UNKNOWN']],
    ['broken', 'numeric-facility', { facility: '2019.00' }, 'quarantined', ['FACILITY_UNKNOWN']],
    ['broken', 'deactivated-facility', { facility: 'FX-0004' }, 'quarantined', ['FACILITY_UNKNOWN']],
    ['broken', 'facility-in-another-lga', { lga: 'ZAR' }, 'quarantined', ['FACILITY_LGA_MISMATCH']],
    ['broken', 'missing-slot', { skipSlot: ['valid', 3] }, 'quarantined', ['ITEMS_INCOMPLETE']],
    ['broken', 'typed-scores-only', { onlyTyped: { avail_m1_score: 92.5 } }, 'quarantined', ['ITEMS_INCOMPLETE']],
    ['broken', 'typed-score-347-66', { typed: { avail_m1_score: 347.66 } }, 'quarantined', ['SCORE_RANGE']],
    ['broken', 'ends-before-start', { end: '08:30' }, 'quarantined', ['END_BEFORE_START']],
    ['broken', 'visit-in-closed-round', { date: '2026-01-14', roundQuarter: 1 }, 'quarantined', ['ROUND_WINDOW']],
    ['broken', 'visit-outside-every-round', { date: '2026-07-30' }, 'quarantined', ['ROUND_WINDOW']],
    ['broken', 'form-names-another-round', { roundQuarter: 3 }, 'quarantined', ['ROUND_WINDOW']],
    ['broken', 'second-visit-in-round', { facility: 'FX-0002' }, 'quarantined', ['DUPLICATE_ASSESSMENT']],
    ['broken', 'live-report-row', { lga: null, ward: 'Quarterly', facility: '2019.00', typed: { avail_m1_score: 347.66 } }, 'quarantined',
        ['COLUMN_DRIFT', 'LGA_UNKNOWN', 'FACILITY_UNKNOWN', 'SCORE_RANGE']],
    ['broken', 'unexpected-choice', { override: { avail_m1_tally_sheet: 'maybe' } }, 'quarantined', ['ITEMS_INCOMPLETE']],
    ['broken', 'score-jump', { facility: 'FX-0003' }, 'flagged', ['SCORE_JUMP']],
    ['broken', 'all-perfect', { answer: () => () => 'yes' }, 'flagged', ['ALL_PERFECT']],
    ['broken', 'twelve-minute-visit', { end: '09:12' }, 'flagged', ['VISIT_TOO_SHORT']],
    ['broken', 'seventh-visit-of-the-day', { facility: 'FX-0005', assessor: 'A. Bello', device: 'collect:p1', submitter: ['23', 'a.bello'] }, 'flagged', ['ASSESSOR_VOLUME']],
];

const OUTCOME = { yes: 'pass', no: 'fail', na: 'na' };
const iso = (date, time, offset) => `${date}T${time}:00.000${offset}`;
const isoParsed = (date, time, offset) => `${date}T${time}:00${offset}`;

/** The scored questions in form order: [rawName, canonicalName, dim, slot, code, answer]. */
function questions(c) {
    const out = [];
    const answer = c.answer();
    for (const [prefix, dim] of DIMS) {
        for (const slot of [1, 2, 3]) {
            if (c.skipSlot && c.skipSlot[0] === prefix && c.skipSlot[1] === slot) continue;
            for (const code of CODES) {
                const canonical = `${prefix}_m${slot}_${code}`;
                const raw = c.version === '2025.2' && prefix === 'avail' && code === 'nhmis_summary' ? `${prefix}_m${slot}_nhmis_form` : canonical;
                out.push([raw, canonical, dim, slot, code, c.override[canonical] ?? answer(code)]);
            }
        }
    }
    return out;
}

function raw(c) {
    const start = c.startRaw ?? iso(c.date, c.start, '+01:00');
    const groups = {};
    if (!c.onlyTyped) {
        for (const [rawName, , dim, , , value] of questions(c)) {
            (groups[dim] ??= {})[rawName] = value;
        }
    }
    for (const [name, value] of Object.entries({ ...(c.typed ?? {}), ...(c.onlyTyped ?? {}) })) {
        (groups.availability ??= {})[name] = value;
    }
    const facility = c.version === '2025.2'
        ? { fac: { lga_name: c.lga, ward_name: c.ward, fac_code: c.facility } }
        : { facility: { lga: c.lga, ward: c.ward, facility_code: c.facility } };
    const assessor = c.version === '2025.2' ? { assessor: c.assessor } : { assessor_name: c.assessor };

    return {
        __id: c.instance,
        __system: {
            submissionDate: `${c.date}T${c.submitted}:00.000Z`,
            updatedAt: null,
            submitterId: c.submitter[0],
            submitterName: c.submitter[1],
            attachmentsPresent: 0,
            attachmentsExpected: 0,
            status: null,
            reviewState: null,
            deviceId: c.device,
            edits: 0,
            formVersion: c.version,
        },
        meta: { instanceID: c.instance },
        start,
        end: iso(c.date, c.end, '+01:00'),
        assessor_name: undefined,
        ...assessor,
        round_year: c.roundYear,
        round_quarter: c.roundQuarter,
        ...facility,
        // GeoJSON, (lon, lat, alt); ODK Central's OData omits the accuracy (docs, 2026-10).
        gps: { type: 'Point', coordinates: [7.4302, 10.4806, 612.3] },
        ...groups,
    };
}

function parsed(c) {
    const known = ['2026.1', '2025.2'].includes(c.version);
    const submitted = `${c.date}T${c.submitted}:00+00:00`;
    if (!known) {
        return {
            source: 'odk', external_id: c.instance,
            instance_id: c.instance, deprecated_id: null, form_version: c.version, form_version_known: false,
            submitted_at: submitted, started_at: null, ended_at: null, raw_start: '', raw_end: '',
            lga_ref: null, ward_ref: null, facility_ref: null, round_year: null, round_quarter: null,
            assessor_name: '', device_id: c.device, gps: null, items: [], raw_numeric_scores: null,
        };
    }
    const items = c.onlyTyped ? [] : questions(c).map(([, , dim, slot, code, value]) => {
        const outcome = OUTCOME[value] ?? 'invalid';
        return { dimension: dim, month_slot: slot, item_code: code, value: outcome, is_applicable: outcome === 'pass' || outcome === 'fail' };
    });
    const typed = { ...(c.typed ?? {}), ...(c.onlyTyped ?? {}) };

    return {
        source: 'odk',
        external_id: c.instance, // __id: an unedited submission's own instance ID
        instance_id: c.instance,
        deprecated_id: null,
        form_version: c.version,
        form_version_known: true,
        submitted_at: submitted,
        started_at: c.startRaw ? null : isoParsed(c.date, c.start, '+01:00'),
        ended_at: isoParsed(c.date, c.end, '+01:00'),
        raw_start: c.startRaw ?? iso(c.date, c.start, '+01:00'),
        raw_end: iso(c.date, c.end, '+01:00'),
        lga_ref: c.lga,
        ward_ref: c.ward,
        facility_ref: c.facility,
        round_year: c.roundYear,
        round_quarter: c.roundQuarter,
        assessor_name: c.assessor,
        device_id: c.device,
        gps: { lat: 10.4806, lng: 7.4302 },
        items,
        raw_numeric_scores: Object.keys(typed).length === 0 ? null : typed,
    };
}

const write = (file, data) => {
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file, JSON.stringify(data, null, 4) + '\n');
};

// The same XLSForm versions deployed on KoboToolbox, under FICTIONAL deployment uids
// (config/edqa_field_maps.php, CONTRACT-ASSUMED); any other version is an uid Kobo would give an
// unmapped deployment.
const KOBO_VERSIONS = { '2026.1': 'vKd26Q1aBcDeFgHiJkLmNo', '2025.2': 'vKd25Q4pQrStUvWxYzAbCd' };
const koboVersion = (version) => KOBO_VERSIONS[version] ?? `vKdUnmapped${version.replace(/\W/g, '')}`;
const koboTime = (date, time) => `${date}T${time}:00`; // _submission_time: UTC, no zone

/**
 * One row of the KPI API v2 data endpoint for the same visit: flat group paths, every answer a
 * string, unanswered questions absent, Kobo's own underscore fields. The geopoint is the XForms
 * text without accuracy, so the pair parses identically (ODK Central's OData omits accuracy).
 */
function kobo(c, id) {
    const row = {
        _id: id,
        'formhub/uuid': '5f1c0a9be1d24c4b8a7e3d2f6b0c9a11',
        start: c.startRaw ?? iso(c.date, c.start, '+01:00'),
        end: iso(c.date, c.end, '+01:00'),
        deviceid: c.device,
    };
    const prefix = c.version === '2025.2' ? 'fac' : 'facility';
    const facility = c.version === '2025.2'
        ? { lga_name: c.lga, ward_name: c.ward, fac_code: c.facility }
        : { lga: c.lga, ward: c.ward, facility_code: c.facility };
    for (const [field, value] of Object.entries(facility)) {
        if (value !== null) row[`${prefix}/${field}`] = String(value);
    }
    row[c.version === '2025.2' ? 'assessor' : 'assessor_name'] = c.assessor;
    row.round_year = String(c.roundYear);
    row.round_quarter = String(c.roundQuarter);
    row.gps = '10.4806 7.4302 612.3';
    if (!c.onlyTyped) {
        for (const [rawName, , dim, , , value] of questions(c)) {
            row[`${dim}/${rawName}`] = value;
        }
    }
    for (const [name, value] of Object.entries({ ...(c.typed ?? {}), ...(c.onlyTyped ?? {}) })) {
        row[`availability/${name}`] = String(value);
    }

    return {
        ...row,
        __version__: koboVersion(c.version),
        'meta/instanceID': c.instance,
        'meta/rootUuid': c.instance,
        _xform_id_string: 'aKdDqaForm2026',
        _uuid: c.instance.replace(/^uuid:/, ''),
        _attachments: [],
        _status: 'submitted_via_web',
        _geolocation: [10.4806, 7.4302],
        _submission_time: koboTime(c.date, c.submitted),
        _tags: [],
        _notes: [],
        _validation_status: {},
        _submitted_by: c.submitter[1],
    };
}

/** parsed(c) as it reads from Kobo: its source, its _id, its version label. */
function parsedKobo(c, id) {
    const known = ['2026.1', '2025.2'].includes(c.version);
    return { ...parsed(c), source: 'kobo', external_id: String(id), form_version: koboVersion(c.version), form_version_known: known };
}

// Cases only Kobo produces: parsed and expected for the Kobo row alone.
//   kobo-missing-meta-block   an older Kobo row with no meta/* keys: the instance ID comes from _uuid
//   kobo-select-multiple-answer  a scored question answered "yes no" (a select_multiple's
//                                space-separated choices): not a known choice, so ITEMS_INCOMPLETE
const KOBO_ONLY = [
    ['valid', 'kobo-missing-meta-block', {}, (row) => { delete row['meta/instanceID']; delete row['meta/rootUuid']; }, 'accepted', []],
    ['broken', 'kobo-select-multiple-answer', { override: { avail_m1_tally_sheet: 'yes no' } }, () => {}, 'quarantined', ['ITEMS_INCOMPLETE']],
];

CASES.forEach(([kind, name, changes, status, codes], i) => {
    const c = { ...base(name), ...changes };
    write(path.join(ROOT, 'tests/Fixtures/sources/odk', kind, `${name}.json`), raw(c));
    write(path.join(ROOT, 'tests/Fixtures/sources/kobo', kind, `${name}.json`), kobo(c, 1001 + i));
    write(path.join(ROOT, 'tests/Fixtures/parsed', kind, `${name}.json`), parsed(c));
    write(path.join(ROOT, 'tests/Fixtures/parsed', kind, `${name}.expected.json`), { status, codes });
});
KOBO_ONLY.forEach(([kind, name, changes, oddity, status, codes], i) => {
    const c = { ...base(name), ...changes };
    const row = kobo(c, 2001 + i);
    oddity(row);
    write(path.join(ROOT, 'tests/Fixtures/sources/kobo', kind, `${name}.json`), row);
    write(path.join(ROOT, 'tests/Fixtures/parsed', kind, `${name}.json`), parsedKobo(c, 2001 + i));
    write(path.join(ROOT, 'tests/Fixtures/parsed', kind, `${name}.expected.json`), { status, codes });
});
console.log(`${CASES.length} cases written for ODK and Kobo, ${KOBO_ONLY.length} for Kobo only`);
