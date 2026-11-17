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
    'finished_count', 'leaderboard', 'ticker', 'qr', 'box',
];
export const RESULTS_TYPES = ['finished_count', 'leaderboard', 'ticker'];
export const FONTS = ['rubik', 'bebas', 'roboto_mono', 'courier_prime'];
export const PHASES = ['idle', 'scheduled', 'running', 'times_up', 'stopped'];

const FONT_WEIGHTS = [300, 400, 500, 700, 900];
const FIELDS = ['competition_name', 'round_name', 'round_category', 'round_limit', 'round_start', 'location', 'event_date'];
const PHASE_COLORS = ['idle', 'scheduled', 'running', 'warning', 'times_up', 'stopped'];

const DEFAULT_BACKGROUND_COLOR = '#0d1017';
const DEFAULT_CHROMA_COLOR = '#00b140';
const DEFAULT_FONT = 'rubik';
const DEFAULT_TEXT_COLOR = '#ffffff';
const DEFAULT_ACCENT_COLOR = '#fe696a';

const COLOR_PATTERN = /^#[0-9a-fA-F]{6}(?:[0-9a-fA-F]{2})?$/;
const ID_PATTERN = /^[a-z0-9]{8}$/;
const URL_PATTERN = /^https:\/\/[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+(?::[0-9]{1,5})?(?:[/?#][A-Za-z0-9\-._~:/?#[\]@!$&()*+,;=%]*)?$/;
const CONTROL_CHARACTERS = /[\u0000-\u0009\u000B-\u001F\u007F-\u009F]/g;
const URL_MAX_LENGTH = 300;
const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

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

export function usesResults(design) {
    return shownResultsElements(design).length > 0;
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
                fit: oneOf(get(imageInput, 'fit'), ['cover', 'contain'], 'cover'),
                opacity: decimal(get(imageInput, 'opacity'), 0, 1, 1),
            };
        }
    }

    return {
        aspect: '16:9',
        background: {
            type: oneOf(get(background, 'type'), ['color', 'transparent', 'image', 'chroma'], 'color'),
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
        name: plain(get(input, 'name'), 60, false, true),
        frame: {
            x: decimal(get(frame, 'x'), 0, 100, 0),
            y: decimal(get(frame, 'y'), 0, 100, 0),
            w: decimal(get(frame, 'w'), 0.5, 100, 10),
            h: decimal(get(frame, 'h'), 0.5, 100, 10),
            rotate: decimal(get(frame, 'rotate'), -180, 180, 0),
        },
        z: integer(get(input, 'z'), 0, 999, 0),
        hidden: boolean(get(input, 'hidden'), false),
        locked: boolean(get(input, 'locked'), false),
        when: {
            phases: subset(get(when, 'phases'), PHASES, PHASES),
            results: oneOf(get(when, 'results'), ['any', 'published', 'not_published'], 'any'),
            puzzle: oneOf(get(when, 'puzzle'), ['any', 'revealed', 'not_revealed'], 'any'),
        },
        style: normaliseStyle(get(input, 'style')),
        props,
    };
}

// Only the keys given with a valid value - a missing key means "the theme's / the type's default" (§4.1)
function normaliseStyle(input) {
    const style = {};

    const font = get(input, 'font');
    if (typeof font === 'string' && FONTS.includes(font)) {
        style.font = font;
    }

    put(style, 'fontSize', optionalInteger(get(input, 'fontSize'), 8, 900));

    const weight = get(input, 'fontWeight');
    if (isNumber(weight)) {
        let nearest = FONT_WEIGHTS[0];
        for (const candidate of FONT_WEIGHTS) {
            if (Math.abs(weight - candidate) < Math.abs(weight - nearest)) {
                nearest = candidate;
            }
        }
        style.fontWeight = nearest;
    }

    put(style, 'color', color(get(input, 'color')));
    put(style, 'background', color(get(input, 'background')));

    const gradient = get(input, 'gradient');
    const from = color(get(gradient, 'from'));
    const to = color(get(gradient, 'to'));
    if (from !== null && to !== null) {
        style.gradient = { from, to, angle: integer(get(gradient, 'angle'), 0, 359, 180) };
    }

    const border = get(input, 'border');
    const borderColor = color(get(border, 'color'));
    if (borderColor !== null) {
        style.border = { width: integer(get(border, 'width'), 0, 40, 1), color: borderColor };
    }

    put(style, 'radius', optionalInteger(get(input, 'radius'), 0, 540));
    put(style, 'padding', optionalInteger(get(input, 'padding'), 0, 200));
    put(style, 'opacity', optionalDecimal(get(input, 'opacity'), 0, 1));
    put(style, 'align', optionalOneOf(get(input, 'align'), ['left', 'center', 'right']));
    put(style, 'valign', optionalOneOf(get(input, 'valign'), ['top', 'middle', 'bottom']));
    put(style, 'shadow', optionalOneOf(get(input, 'shadow'), ['none', 'soft', 'strong', 'outline']));

    const uppercase = get(input, 'uppercase');
    if (typeof uppercase === 'boolean') {
        style.uppercase = uppercase;
    }

    put(style, 'letterSpacing', optionalDecimal(get(input, 'letterSpacing'), -10, 40));
    put(style, 'lineHeight', optionalDecimal(get(input, 'lineHeight'), 0.8, 2));
    put(style, 'fit', optionalOneOf(get(input, 'fit'), ['none', 'shrink', 'fill']));

    return style;
}

function normaliseProps(type, input, path, competitionId, errors) {
    switch (type) {
        case 'timer':
            return {
                mode: oneOf(get(input, 'mode'), ['auto', 'elapsed', 'remaining'], 'auto'),
                format: oneOf(get(input, 'format'), ['auto', 'hh:mm:ss', 'mm:ss'], 'auto'),
                bigCountdown: integer(get(input, 'bigCountdown'), 0, 30, 0),
                phaseColors: colorMap(get(input, 'phaseColors'), PHASE_COLORS),
                warningSeconds: integer(get(input, 'warningSeconds'), 0, 3600, 0),
                idleText: oneOf(get(input, 'idleText'), ['dashes', 'zero', 'blank'], 'dashes'),
            };
        case 'status':
            return { texts: textMap(get(input, 'texts'), PHASES, 80) };
        case 'progress':
            return {
                direction: oneOf(get(input, 'direction'), ['fill', 'drain'], 'fill'),
                orientation: oneOf(get(input, 'orientation'), ['horizontal', 'vertical'], 'horizontal'),
                trackColor: color(get(input, 'trackColor')),
                barColor: color(get(input, 'barColor')),
                warningColor: color(get(input, 'warningColor')),
                warningSeconds: integer(get(input, 'warningSeconds'), 0, 3600, 0),
            };
        case 'text':
            return { text: plain(get(input, 'text'), 300, true, true) };
        case 'field':
            return {
                field: oneOf(get(input, 'field'), FIELDS, 'round_name'),
                prefix: plain(get(input, 'prefix'), 60, false, false),
                suffix: plain(get(input, 'suffix'), 60, false, false),
            };
        case 'round_badge':
            return { useRoundColors: boolean(get(input, 'useRoundColors'), true) };
        case 'image':
            return imageProps(input, path, competitionId, errors);
        case 'rotator':
            return rotatorProps(input, path, competitionId, errors);
        case 'clock':
            return {
                zone: oneOf(get(input, 'zone'), ['round', 'device'], 'round'),
                format: oneOf(get(input, 'format'), ['24h', '12h'], '24h'),
                seconds: boolean(get(input, 'seconds'), false),
            };
        case 'puzzle':
            return {
                layout: oneOf(get(input, 'layout'), ['row', 'column', 'grid'], 'row'),
                show: subset(get(input, 'show'), ['image', 'name', 'brand', 'pieces'], ['image', 'name']),
                max: integer(get(input, 'max'), 1, 6, 3),
                whenHidden: oneOf(get(input, 'whenHidden'), ['nothing', 'text'], 'nothing'),
                hiddenText: plain(get(input, 'hiddenText'), 80, false, true),
                imageFit: oneOf(get(input, 'imageFit'), ['contain', 'cover'], 'contain'),
            };
        case 'finished_count':
            return {
                format: oneOf(get(input, 'format'), ['fraction', 'count', 'percent'], 'fraction'),
                ...resultsSource(input),
            };
        case 'leaderboard': {
            const rowStyle = get(input, 'rowStyle');

            return {
                rows: integer(get(input, 'rows'), 1, 30, 10),
                columns: subset(get(input, 'columns'), ['rank', 'flag', 'name', 'members', 'result', 'table'], ['rank', 'flag', 'name', 'result']),
                highlightLatestSeconds: integer(get(input, 'highlightLatestSeconds'), 0, 60, 10),
                rowStyle: {
                    background: color(get(rowStyle, 'background')),
                    altBackground: color(get(rowStyle, 'altBackground')),
                    gap: integer(get(rowStyle, 'gap'), 0, 40, 4),
                },
                ...resultsSource(input),
            };
        }
        case 'ticker':
            return {
                rows: integer(get(input, 'rows'), 1, 10, 5),
                ...resultsSource(input),
            };
        case 'qr':
            return qrProps(input);
        default:
            return {}; // box
    }
}

function resultsSource(input) {
    return {
        source: oneOf(get(input, 'source'), ['published', 'live'], 'published'),
        whenUnpublished: oneOf(get(input, 'whenUnpublished'), ['nothing', 'text'], 'nothing'),
        unpublishedText: plain(get(input, 'unpublishedText'), 80, false, true),
    };
}

function imageProps(input, path, competitionId, errors) {
    const source = oneOf(get(input, 'source'), ['upload', 'competition_logo', 'series_logo', 'organization_logo'], 'upload');
    const picture = imagePath(get(input, 'path'), `${path}/path`, competitionId, errors);

    return {
        source,
        path: source === 'upload' ? picture : null,
        fit: oneOf(get(input, 'fit'), ['contain', 'cover'], 'contain'),
    };
}

function rotatorProps(input, path, competitionId, errors) {
    const images = [];
    const imagesInput = get(input, 'images');

    if (isList(imagesInput) && Array.isArray(imagesInput)) {
        if (imagesInput.length > MAX_IMAGES) {
            errors.push({ path: `${path}/images`, code: 'too_many_images' });
        } else {
            imagesInput.forEach((image, index) => {
                const picture = imagePath(get(image, 'path'), `${path}/images/${index}/path`, competitionId, errors);

                if (picture !== null) {
                    images.push({ path: picture });
                }
            });
        }
    }

    return {
        images,
        seconds: integer(get(input, 'seconds'), 3, 120, 8),
        transition: oneOf(get(input, 'transition'), ['none', 'fade'], 'fade'),
        fit: oneOf(get(input, 'fit'), ['contain', 'cover'], 'contain'),
    };
}

function qrProps(input) {
    const target = oneOf(get(input, 'target'), ['event_page', 'round_results', 'custom'], 'event_page');
    const url = get(input, 'url');

    return {
        target,
        url: target === 'custom' && typeof url === 'string' && Array.from(url).length <= URL_MAX_LENGTH && URL_PATTERN.test(url) ? url : null,
        fg: color(get(input, 'fg')) ?? '#000000',
        bg: color(get(input, 'bg')) ?? '#ffffff',
        quietZone: boolean(get(input, 'quietZone'), true),
    };
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
