// Display design - schema v1 (docs/features/competitions-management/toolkit/D-displays-overlays.md §4, §4.1, §5 with
// the v1 cuts of R-review-and-plan.md §3). The browser twin of src/Services/CompetitionToolkit/DisplayDesignSanitizer.php:
// the editor normalises what it saves and the renderer what it draws (defence in depth). Both implementations are run
// over tests/fixtures/display-design-cases.json (tests/display-schema-harness.mjs + DisplayDesignSanitizerTest), so
// they cannot drift - change both together, in the same order (errors are reported in document order).
//
// Pure module: no DOM, no randomness. normaliseDesign(input, {competitionId}) → {design, errors}: design is null when
// errors is not empty; errors are [{path (JSON pointer, '' = the whole design), code}].

export const SCHEMA_VERSION = 1;
export const MAX_ELEMENTS = 60;
export const MAX_IMAGES = 30;
export const MAX_BYTES = 65536;

export const ELEMENT_TYPES = [
    'timer', 'status', 'progress', 'text', 'field', 'round_badge', 'image', 'rotator', 'clock', 'puzzle',
    'finished_count', 'leaderboard', 'ticker', 'qr', 'box', 'standings',
];
// The standings element's columns (competition toolkit v2 S2) - `stage` shows only once a standing has stages
export const STANDINGS_COLUMNS = ['rank', 'flag', 'name', 'members', 'total', 'rounds', 'missing', 'stage'];
export const RESULTS_TYPES = ['finished_count', 'leaderboard', 'ticker'];
export const FONTS = ['rubik', 'bebas', 'roboto_mono', 'courier_prime'];
export const PHASES = ['idle', 'scheduled', 'running', 'times_up', 'stopped'];

export const FONT_WEIGHTS = [300, 400, 500, 700, 900];
export const FIELDS = ['competition_name', 'round_name', 'round_category', 'round_limit', 'round_start', 'location', 'event_date'];
export const PHASE_COLORS = ['idle', 'scheduled', 'running', 'warning', 'times_up', 'stopped'];
export const BACKGROUND_TYPES = ['color', 'transparent', 'image', 'chroma'];
export const BACKGROUND_FITS = ['cover', 'contain'];
export const WHEN_RESULTS = ['any', 'published', 'not_published'];
export const WHEN_PUZZLE = ['any', 'revealed', 'not_revealed'];

// [min, max, default] - percent of the canvas (2 decimals), rotate in degrees
export const FRAME_LIMITS = { x: [0, 100, 0], y: [0, 100, 0], w: [0.5, 100, 10], h: [0.5, 100, 10], rotate: [-180, 180, 0] };
export const Z_LIMITS = [0, 999, 0];
export const NAME_MAX_LENGTH = 60;
const URL_MAX_LENGTH = 300;

// The one list of what an element's style may hold (§4.1) - the normaliser below and the editor's property panel both
// read it. Every key is optional: missing = the theme's / the type's default.
export const STYLE_SPEC = [
    { key: 'font', kind: 'enum', options: FONTS },
    { key: 'fontSize', kind: 'int', min: 8, max: 900 },
    { key: 'fontWeight', kind: 'weight', options: FONT_WEIGHTS },
    { key: 'color', kind: 'color' },
    { key: 'background', kind: 'color' },
    { key: 'gradient', kind: 'gradient', angle: [0, 359, 180] },
    { key: 'border', kind: 'border', width: [0, 40, 1] },
    { key: 'radius', kind: 'int', min: 0, max: 540 },
    { key: 'padding', kind: 'int', min: 0, max: 200 },
    { key: 'opacity', kind: 'decimal', min: 0, max: 1 },
    { key: 'align', kind: 'enum', options: ['left', 'center', 'right'] },
    { key: 'valign', kind: 'enum', options: ['top', 'middle', 'bottom'] },
    { key: 'shadow', kind: 'enum', options: ['none', 'soft', 'strong', 'outline'] },
    { key: 'uppercase', kind: 'bool' },
    { key: 'letterSpacing', kind: 'decimal', min: -10, max: 40 },
    { key: 'lineHeight', kind: 'decimal', min: 0.8, max: 2 },
    { key: 'fit', kind: 'enum', options: ['none', 'shrink', 'fill'] },
];

const RESULTS_SOURCE_SPEC = [
    { key: 'source', kind: 'enum', options: ['published', 'live'], default: 'published' },
    { key: 'whenUnpublished', kind: 'enum', options: ['nothing', 'text'], default: 'nothing' },
    { key: 'unpublishedText', kind: 'text', max: 80, multiline: false, trim: true },
];

// The props of every element type (§5), in the order they are stored - the normaliser and the property panel read it
export const PROP_SPECS = {
    timer: [
        { key: 'mode', kind: 'enum', options: ['auto', 'elapsed', 'remaining'], default: 'auto' },
        { key: 'format', kind: 'enum', options: ['auto', 'hh:mm:ss', 'mm:ss'], default: 'auto' },
        { key: 'bigCountdown', kind: 'int', min: 0, max: 30, default: 0 },
        { key: 'phaseColors', kind: 'colorMap', keys: PHASE_COLORS },
        { key: 'warningSeconds', kind: 'int', min: 0, max: 3600, default: 0 },
        { key: 'idleText', kind: 'enum', options: ['dashes', 'zero', 'blank'], default: 'dashes' },
    ],
    status: [
        { key: 'texts', kind: 'textMap', keys: PHASES, max: 80 },
    ],
    progress: [
        { key: 'direction', kind: 'enum', options: ['fill', 'drain'], default: 'fill' },
        { key: 'orientation', kind: 'enum', options: ['horizontal', 'vertical'], default: 'horizontal' },
        { key: 'trackColor', kind: 'color', default: null },
        { key: 'barColor', kind: 'color', default: null },
        { key: 'warningColor', kind: 'color', default: null },
        { key: 'warningSeconds', kind: 'int', min: 0, max: 3600, default: 0 },
    ],
    text: [
        { key: 'text', kind: 'text', max: 300, multiline: true, trim: true },
    ],
    field: [
        { key: 'field', kind: 'enum', options: FIELDS, default: 'round_name' },
        { key: 'prefix', kind: 'text', max: 60, multiline: false, trim: false },
        { key: 'suffix', kind: 'text', max: 60, multiline: false, trim: false },
    ],
    round_badge: [
        { key: 'useRoundColors', kind: 'bool', default: true },
    ],
    image: [
        { key: 'source', kind: 'enum', options: ['upload', 'competition_logo', 'series_logo', 'organization_logo'], default: 'upload' },
        { key: 'path', kind: 'path', onlyWhen: { key: 'source', value: 'upload' } },
        { key: 'fit', kind: 'enum', options: ['contain', 'cover'], default: 'contain' },
    ],
    rotator: [
        { key: 'images', kind: 'images', max: MAX_IMAGES },
        { key: 'seconds', kind: 'int', min: 3, max: 120, default: 8 },
        { key: 'transition', kind: 'enum', options: ['none', 'fade'], default: 'fade' },
        { key: 'fit', kind: 'enum', options: ['contain', 'cover'], default: 'contain' },
    ],
    clock: [
        { key: 'zone', kind: 'enum', options: ['round', 'device'], default: 'round' },
        { key: 'format', kind: 'enum', options: ['24h', '12h'], default: '24h' },
        { key: 'seconds', kind: 'bool', default: false },
    ],
    puzzle: [
        { key: 'layout', kind: 'enum', options: ['row', 'column', 'grid'], default: 'row' },
        { key: 'show', kind: 'subset', options: ['image', 'name', 'brand', 'pieces'], default: ['image', 'name'] },
        { key: 'max', kind: 'int', min: 1, max: 6, default: 3 },
        { key: 'whenHidden', kind: 'enum', options: ['nothing', 'text'], default: 'nothing' },
        { key: 'hiddenText', kind: 'text', max: 80, multiline: false, trim: true },
        { key: 'imageFit', kind: 'enum', options: ['contain', 'cover'], default: 'contain' },
    ],
    finished_count: [
        { key: 'format', kind: 'enum', options: ['fraction', 'count', 'percent'], default: 'fraction' },
        ...RESULTS_SOURCE_SPEC,
    ],
    leaderboard: [
        { key: 'rows', kind: 'int', min: 1, max: 30, default: 10 },
        { key: 'columns', kind: 'subset', options: ['rank', 'flag', 'name', 'members', 'result', 'table'], default: ['rank', 'flag', 'name', 'result'] },
        { key: 'highlightLatestSeconds', kind: 'int', min: 0, max: 60, default: 10 },
        {
            key: 'rowStyle',
            kind: 'object',
            fields: [
                { key: 'background', kind: 'color', default: null },
                { key: 'altBackground', kind: 'color', default: null },
                { key: 'gap', kind: 'int', min: 0, max: 40, default: 4 },
            ],
        },
        ...RESULTS_SOURCE_SPEC,
    ],
    ticker: [
        { key: 'rows', kind: 'int', min: 1, max: 10, default: 5 },
        ...RESULTS_SOURCE_SPEC,
    ],
    qr: [
        { key: 'target', kind: 'enum', options: ['event_page', 'round_results', 'custom'], default: 'event_page' },
        { key: 'url', kind: 'url', max: URL_MAX_LENGTH, onlyWhen: { key: 'target', value: 'custom' } },
        { key: 'fg', kind: 'color', default: '#000000' },
        { key: 'bg', kind: 'color', default: '#ffffff' },
        { key: 'quietZone', kind: 'bool', default: true },
    ],
    box: [],
    // A published standing (competition toolkit v2 S2, displays-standings.md §3.2) - public data, so no `source`
    standings: [
        { key: 'standingId', kind: 'standing', default: 'auto' },
        { key: 'rows', kind: 'int', min: 1, max: 30, default: 10 },
        { key: 'columns', kind: 'subset', options: STANDINGS_COLUMNS, default: ['rank', 'flag', 'name', 'total'] },
        { key: 'title', kind: 'bool', default: true },
        {
            key: 'rowStyle',
            kind: 'object',
            fields: [
                { key: 'background', kind: 'color', default: null },
                { key: 'altBackground', kind: 'color', default: null },
                { key: 'gap', kind: 'int', min: 0, max: 40, default: 4 },
            ],
        },
        { key: 'whenEmpty', kind: 'enum', options: ['nothing', 'text'], default: 'nothing' },
        { key: 'emptyText', kind: 'text', max: 80, multiline: false, trim: true },
    ],
};

const DEFAULT_BACKGROUND_COLOR = '#0d1017';
const DEFAULT_CHROMA_COLOR = '#00b140';
const DEFAULT_FONT = 'rubik';
const DEFAULT_TEXT_COLOR = '#ffffff';
const DEFAULT_ACCENT_COLOR = '#fe696a';

const COLOR_PATTERN = /^#[0-9a-fA-F]{6}(?:[0-9a-fA-F]{2})?$/;
const ID_PATTERN = /^[a-z0-9]{8}$/;
const URL_PATTERN = /^https:\/\/[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+(?::[0-9]{1,5})?(?:[/?#][A-Za-z0-9\-._~:/?#[\]@!$&()*+,;=%]*)?$/;
const CONTROL_CHARACTERS = /[\u0000-\u0009\u000B-\u001F\u007F-\u009F]/g;
const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
const STANDING_ID_PATTERN = /^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/;

export function normaliseDesign(input, { competitionId }) {
    const errors = [];
    const design = run(input, String(competitionId).toLowerCase(), errors);

    if (errors.length > 0 || design === null) {
        return { design: null, errors: errors.length > 0 ? errors : [{ path: '', code: 'not_object' }] };
    }

    return { design, errors: [] };
}

// Hidden elements and elements shown in no phase never render - they ask for no results data
function shownResultsElements(design) {
    return (Array.isArray(design?.elements) ? design.elements : []).filter((element) => element !== null
        && typeof element === 'object'
        && RESULTS_TYPES.includes(element.type)
        && element.hidden !== true
        && Array.isArray(element.when?.phases)
        && element.when.phases.length > 0);
}

function isLive(element) {
    return RESULTS_TYPES.includes(element?.type) && element.props?.source === 'live';
}

// A result edit in a published round changes a published standing - its `results` signals must reach the display
export function usesResults(design) {
    return shownResultsElements(design).length > 0 || (Array.isArray(design?.elements) ? design.elements : []).some((element) => element !== null
        && typeof element === 'object'
        && element.type === 'standings'
        && element.hidden !== true
        && Array.isArray(element.when?.phases)
        && element.when.phases.length > 0);
}

export function usesLiveResults(design) {
    return shownResultsElements(design).some(isLive);
}

export function imagePaths(design) {
    const paths = [];
    const image = design?.canvas?.background?.image;

    if (image && typeof image === 'object') {
        paths.push(image.path);
    }

    for (const element of Array.isArray(design?.elements) ? design.elements : []) {
        const props = element?.props;

        if (!props || typeof props !== 'object') {
            continue;
        }

        if (element.type === 'image') {
            paths.push(props.path);
        }

        if (element.type === 'rotator' && Array.isArray(props.images)) {
            for (const item of props.images) {
                paths.push(item && typeof item === 'object' ? item.path : null);
            }
        }
    }

    return [...new Set(paths.filter((path) => typeof path === 'string'))];
}

function run(input, competitionId, errors) {
    if (!isObject(input)) {
        errors.push({ path: '', code: 'not_object' });

        return null;
    }

    const schema = get(input, 'schema');

    if (!isNumber(schema) || schema !== SCHEMA_VERSION) {
        errors.push({ path: '/schema', code: 'unknown_schema' });

        return null;
    }

    const canvas = normaliseCanvas(get(input, 'canvas'), competitionId, errors);
    const theme = normaliseTheme(get(input, 'theme'));

    const elementsInput = get(input, 'elements');
    const elements = [];

    if (elementsInput !== null) {
        if (!isList(elementsInput)) {
            errors.push({ path: '/elements', code: 'not_list' });

            return null;
        }

        const list = Array.isArray(elementsInput) ? elementsInput : [];

        if (list.length > MAX_ELEMENTS) {
            errors.push({ path: '/elements', code: 'too_many_elements' });

            return null;
        }

        const seenIds = new Set();

        list.forEach((elementInput, index) => {
            const element = normaliseElement(elementInput, `/elements/${index}`, competitionId, errors, seenIds);

            if (element !== null) {
                elements.push(element);
            }
        });
    }

    if (errors.length > 0) {
        return null;
    }

    const design = { schema: SCHEMA_VERSION, canvas, theme, elements };

    if (imagePaths(design).length > MAX_IMAGES) {
        errors.push({ path: '/elements', code: 'too_many_images' });

        return null;
    }

    if (new TextEncoder().encode(JSON.stringify(design)).length > MAX_BYTES) {
        errors.push({ path: '', code: 'too_large' });

        return null;
    }

    return design;
}

function normaliseCanvas(input, competitionId, errors) {
    const background = get(input, 'background');
    const imageInput = get(background, 'image');
    let image = null;

    if (isObject(imageInput)) {
        const path = imagePath(get(imageInput, 'path'), '/canvas/background/image/path', competitionId, errors);

        if (path !== null) {
            image = {
                path,
                fit: oneOf(get(imageInput, 'fit'), BACKGROUND_FITS, 'cover'),
                opacity: decimal(get(imageInput, 'opacity'), 0, 1, 1),
            };
        }
    }

    return {
        aspect: '16:9',
        background: {
            type: oneOf(get(background, 'type'), BACKGROUND_TYPES, 'color'),
            color: color(get(background, 'color')) ?? DEFAULT_BACKGROUND_COLOR,
            chroma: color(get(background, 'chroma')) ?? DEFAULT_CHROMA_COLOR,
            image,
        },
    };
}

function normaliseTheme(input) {
    return {
        font: oneOf(get(input, 'font'), FONTS, DEFAULT_FONT),
        color: color(get(input, 'color')) ?? DEFAULT_TEXT_COLOR,
        accent: color(get(input, 'accent')) ?? DEFAULT_ACCENT_COLOR,
    };
}

function normaliseElement(input, path, competitionId, errors, seenIds) {
    if (!isObject(input)) {
        errors.push({ path, code: 'element_not_object' });

        return null;
    }

    let refused = false;
    const id = get(input, 'id');

    if (typeof id !== 'string' || !ID_PATTERN.test(id)) {
        errors.push({ path: `${path}/id`, code: 'invalid_id' });
        refused = true;
    } else if (seenIds.has(id)) {
        errors.push({ path: `${path}/id`, code: 'duplicate_id' });
        refused = true;
    } else {
        seenIds.add(id);
    }

    const type = get(input, 'type');

    if (typeof type !== 'string' || !ELEMENT_TYPES.includes(type)) {
        errors.push({ path: `${path}/type`, code: 'unknown_type' });

        return null;
    }

    const frame = get(input, 'frame');

    if (!isObject(frame)) {
        errors.push({ path: `${path}/frame`, code: 'missing_frame' });

        return null;
    }

    const props = normaliseProps(type, get(input, 'props'), `${path}/props`, competitionId, errors);

    if (refused) {
        return null;
    }

    const when = get(input, 'when');

    return {
        id,
        type,
        name: plain(get(input, 'name'), NAME_MAX_LENGTH, false, true),
        frame: {
            x: decimal(get(frame, 'x'), ...FRAME_LIMITS.x),
            y: decimal(get(frame, 'y'), ...FRAME_LIMITS.y),
            w: decimal(get(frame, 'w'), ...FRAME_LIMITS.w),
            h: decimal(get(frame, 'h'), ...FRAME_LIMITS.h),
            rotate: decimal(get(frame, 'rotate'), ...FRAME_LIMITS.rotate),
        },
        z: integer(get(input, 'z'), ...Z_LIMITS),
        hidden: boolean(get(input, 'hidden'), false),
        locked: boolean(get(input, 'locked'), false),
        when: {
            phases: subset(get(when, 'phases'), PHASES, PHASES),
            results: oneOf(get(when, 'results'), WHEN_RESULTS, 'any'),
            puzzle: oneOf(get(when, 'puzzle'), WHEN_PUZZLE, 'any'),
        },
        style: normaliseStyle(get(input, 'style')),
        props,
    };
}

// Only the keys given with a valid value - a missing key means "the theme's / the type's default" (§4.1)
function normaliseStyle(input) {
    const style = {};

    for (const spec of STYLE_SPEC) {
        put(style, spec.key, styleValue(spec, get(input, spec.key)));
    }

    return style;
}

function styleValue(spec, value) {
    switch (spec.kind) {
        case 'enum':
            return optionalOneOf(value, spec.options);
        case 'int':
            return optionalInteger(value, spec.min, spec.max);
        case 'decimal':
            return optionalDecimal(value, spec.min, spec.max);
        case 'color':
            return color(value);
        case 'bool':
            return typeof value === 'boolean' ? value : null;
        case 'weight': {
            if (!isNumber(value)) {
                return null;
            }

            let nearest = spec.options[0];
            for (const candidate of spec.options) {
                if (Math.abs(value - candidate) < Math.abs(value - nearest)) {
                    nearest = candidate;
                }
            }

            return nearest;
        }
        case 'gradient': {
            const from = color(get(value, 'from'));
            const to = color(get(value, 'to'));

            return from !== null && to !== null ? { from, to, angle: integer(get(value, 'angle'), ...spec.angle) } : null;
        }
        case 'border': {
            const borderColor = color(get(value, 'color'));

            return borderColor !== null ? { width: integer(get(value, 'width'), ...spec.width), color: borderColor } : null;
        }
        default:
            return null;
    }
}

function normaliseProps(type, input, path, competitionId, errors) {
    return propsOf(PROP_SPECS[type] ?? [], input, path, competitionId, errors);
}

function propsOf(specs, input, path, competitionId, errors) {
    const props = {};

    for (const spec of specs) {
        props[spec.key] = propValue(spec, get(input, spec.key), path, competitionId, errors);
    }

    // A value that only counts with a certain choice of another prop (an uploaded picture, a custom QR link)
    for (const spec of specs) {
        if (spec.onlyWhen && props[spec.onlyWhen.key] !== spec.onlyWhen.value) {
            props[spec.key] = null;
        }
    }

    return props;
}

function propValue(spec, value, path, competitionId, errors) {
    switch (spec.kind) {
        case 'enum':
            return oneOf(value, spec.options, spec.default);
        case 'int':
            return integer(value, spec.min, spec.max, spec.default);
        case 'bool':
            return boolean(value, spec.default);
        case 'color':
            return color(value) ?? spec.default;
        case 'text':
            return plain(value, spec.max, spec.multiline, spec.trim);
        case 'subset':
            return subset(value, spec.options, spec.default);
        case 'colorMap':
            return colorMap(value, spec.keys);
        case 'textMap':
            return textMap(value, spec.keys, spec.max);
        case 'object':
            return propsOf(spec.fields, value, path, competitionId, errors);
        case 'path':
            return imagePath(value, `${path}/${spec.key}`, competitionId, errors);
        case 'url':
            return typeof value === 'string' && Array.from(value).length <= spec.max && URL_PATTERN.test(value) ? value : null;
        case 'images':
            return imageList(value, `${path}/${spec.key}`, competitionId, errors);
        case 'standing':
            // `auto` or a standing's id (lowercased) - whether it is a published standing of the event is the state's question
            return typeof value === 'string' && STANDING_ID_PATTERN.test(value) ? value.toLowerCase() : spec.default;
        default:
            return null;
    }
}

function imageList(input, path, competitionId, errors) {
    const images = [];

    if (isList(input) && Array.isArray(input)) {
        if (input.length > MAX_IMAGES) {
            errors.push({ path, code: 'too_many_images' });
        } else {
            input.forEach((image, index) => {
                const picture = imagePath(get(image, 'path'), `${path}/${index}/path`, competitionId, errors);

                if (picture !== null) {
                    images.push({ path: picture });
                }
            });
        }
    }

    return images;
}

// A picture uploaded for this event's displays - never an external URL, never another event's file
function imagePath(value, path, competitionId, errors) {
    if (typeof value !== 'string' || value === '') {
        return null;
    }

    const escaped = competitionId.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const pattern = new RegExp(`^competition-displays/${escaped}/${UUID}\\.(?:jpe?g|png|gif|webp)$`);

    if (!pattern.test(value)) {
        errors.push({ path, code: 'foreign_image' });

        return null;
    }

    return value;
}

function colorMap(input, keys) {
    const map = {};

    for (const key of keys) {
        map[key] = color(get(input, key));
    }

    return map;
}

function textMap(input, keys, maxLength) {
    const map = {};

    for (const key of keys) {
        const text = plain(get(input, key), maxLength, false, true);
        map[key] = text !== '' ? text : null;
    }

    return map;
}

// A JSON object - or an empty list, which PHP cannot tell from an empty object ([] in the stored design)
function isObject(value) {
    return value !== null && typeof value === 'object' && (!Array.isArray(value) || value.length === 0);
}

// A JSON list - or an empty object, for the same reason
function isList(value) {
    return Array.isArray(value) || (value !== null && typeof value === 'object' && Object.keys(value).length === 0);
}

function get(object, key) {
    if (object !== null && typeof object === 'object' && !Array.isArray(object) && Object.prototype.hasOwnProperty.call(object, key)) {
        const value = object[key];

        return value === undefined ? null : value;
    }

    return null;
}

function isNumber(value) {
    return typeof value === 'number' && Number.isFinite(value);
}

function color(value) {
    return typeof value === 'string' && COLOR_PATTERN.test(value) ? value.toLowerCase() : null;
}

function oneOf(value, allowed, fallback) {
    return optionalOneOf(value, allowed) ?? fallback;
}

function optionalOneOf(value, allowed) {
    return typeof value === 'string' && allowed.includes(value) ? value : null;
}

function boolean(value, fallback) {
    return typeof value === 'boolean' ? value : fallback;
}

// The allowed values given, in the allowed order, each once; not a list = the default
function subset(value, allowed, fallback) {
    if (!isList(value)) {
        return [...fallback];
    }

    const given = Array.isArray(value) ? value : [];

    return allowed.filter((item) => given.includes(item));
}

function integer(value, min, max, fallback) {
    return optionalInteger(value, min, max) ?? fallback;
}

// Half away from zero by floor - the same IEEE operations as the PHP sanitizer
function optionalInteger(value, min, max) {
    if (!isNumber(value)) {
        return null;
    }

    const clamped = Math.max(min, Math.min(max, value));
    const rounded = (clamped < 0 ? -1 : 1) * Math.floor(Math.abs(clamped) + 0.5);

    return rounded === 0 ? 0 : rounded;
}

function decimal(value, min, max, fallback) {
    return optionalDecimal(value, min, max) ?? fallback;
}

function optionalDecimal(value, min, max) {
    if (!isNumber(value)) {
        return null;
    }

    const clamped = Math.max(min, Math.min(max, value));
    const rounded = (clamped < 0 ? -1 : 1) * Math.floor(Math.abs(clamped) * 100 + 0.5) / 100;

    return rounded === 0 ? 0 : rounded;
}

// Plain text: CRLF → LF, line breaks → spaces on one-line texts, control characters removed, trimmed of spaces and
// line breaks (not on prefixes/suffixes), cut to maxLength characters (code points, as PHP's mb_substr)
function plain(value, maxLength, multiline, trim) {
    if (typeof value !== 'string') {
        return '';
    }

    let text = value.replace(/\r\n/g, '\n');

    if (!multiline) {
        text = text.replace(/\n/g, ' ');
    }

    text = text.replace(CONTROL_CHARACTERS, '');

    if (trim) {
        text = text.replace(/^[ \n]+|[ \n]+$/g, '');
    }

    return Array.from(text).slice(0, maxLength).join('');
}

function put(style, key, value) {
    if (value !== null) {
        style[key] = value;
    }
}
