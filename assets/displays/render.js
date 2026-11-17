// The display renderer (docs/features/competitions-management/toolkit/D-displays-overlays.md §5, §7; competition
// toolkit D4): a design + a state + the server's "now" → DOM. Used by the display page (assets/display.js through
// runtime.js) and, later, the editor's preview - the same picture by construction.
//
// Safety rules (D §9.2) - pinned by tests/display-render-harness.mjs:
// - text only through textContent, never innerHTML / insertAdjacentHTML / outerHTML;
// - the design is normalised by schema.js first, and every style value is re-checked here (colours by the pattern,
//   numbers by type) - no organiser string ever lands in a CSS declaration, a class or an attribute;
// - picture sources only from the state's URLs (images map, logos, revealed puzzles) and only http(s);
// - class names are fixed strings, the one exception a country code checked against [a-z]{2} (flag-icons).
//
// No timers and no listeners in here: the runtime asks nextChangeMs() when the picture changes next by itself and
// calls render() then. Old engines (OBS's CEF, TVs): absolute positions, transform: scale, flexbox without `gap`.

import { normaliseDesign } from './schema.js';
import { countdownSeconds, formatCountdown, stopwatchView } from '../round_stopwatch_clock.js';

export const CANVAS_WIDTH = 1920;
export const CANVAS_HEIGHT = 1080;

const COLOR_PATTERN = /^#[0-9a-fA-F]{6}(?:[0-9a-fA-F]{2})?$/;
const COUNTRY_PATTERN = /^[a-z]{2}$/;
const QR_ROW_PATTERN = /^[01]+$/;
const QR_MAX_SIZE = 177;
const SVG_NS = 'http://www.w3.org/2000/svg';

export const FONT_STACKS = {
    rubik: '"MSP Display Rubik", Rubik, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
    bebas: '"MSP Display Bebas Neue", "Bebas Neue", Impact, "Arial Narrow", sans-serif',
    roboto_mono: '"MSP Display Roboto Mono", "Roboto Mono", Menlo, Consolas, "Liberation Mono", monospace',
    courier_prime: '"MSP Display Courier Prime", "Courier Prime", "Courier New", monospace',
};

const DEFAULT_FONT_SIZES = {
    timer: 160, status: 48, progress: 32, text: 48, field: 48, round_badge: 40, image: 32, rotator: 32, clock: 64,
    puzzle: 32, finished_count: 40, leaderboard: 32, ticker: 28, qr: 32, box: 32, standings: 30,
};

// A standings row's background when the design gives none - readable over a stream's video and on a hall screen
const STANDINGS_ROW_BACKGROUND = '#0d1017cc';
const STANDINGS_ROW_ALT_BACKGROUND = '#161b26cc';

const SHADOWS = {
    soft: '0 2px 8px rgba(0,0,0,0.5)',
    strong: '0 4px 18px rgba(0,0,0,0.85), 0 1px 3px rgba(0,0,0,0.9)',
    outline: '-2px -2px 0 #000, 2px -2px 0 #000, -2px 2px 0 #000, 2px 2px 0 #000, 0 -2px 0 #000, 0 2px 0 #000, -2px 0 0 #000, 2px 0 0 #000',
};

const ALIGN = { left: 'flex-start', center: 'center', right: 'flex-end' };
const VALIGN = { top: 'flex-start', middle: 'center', bottom: 'flex-end' };

// The phases the clock moves through by itself - the picture changes every second
const MOVING_PHASES = ['scheduled', 'running'];

// ---- pure helpers (exported for the harness)

export function safeColor(value) {
    return typeof value === 'string' && COLOR_PATTERN.test(value) ? value.toLowerCase() : null;
}

export function safeImageUrl(value) {
    if (typeof value !== 'string' || value.length > 2000 || !/^https?:\/\//i.test(value)) {
        return null;
    }

    try {
        const url = new URL(value);

        return url.protocol === 'https:' || url.protocol === 'http:' ? url.href : null;
    } catch {
        return null;
    }
}

function number(value, fallback) {
    return typeof value === 'number' && Number.isFinite(value) ? value : fallback;
}

function pad(value) {
    return value < 10 ? `0${value}` : String(value);
}

/**
 * A duration as the timer element shows it - `auto` (H:MM:SS, the hours dropped while 0), `hh:mm:ss`, `mm:ss` (minutes
 * past 59 kept: 75:12). Elapsed time rounds down, a countdown and the remaining time up (0:00 exactly at the end).
 */
export function formatDuration(ms, format, rounding = 'floor') {
    const value = Math.max(0, number(ms, 0)) / 1000;
    const total = rounding === 'ceil' ? Math.ceil(value) : Math.floor(value);
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const seconds = total % 60;

    if (format === 'hh:mm:ss') {
        return `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
    }

    if (format === 'mm:ss') {
        return `${pad(Math.floor(total / 60))}:${pad(seconds)}`;
    }

    return hours > 0 ? `${hours}:${pad(minutes)}:${pad(seconds)}` : `${pad(minutes)}:${pad(seconds)}`;
}

function idleTimerText(props) {
    if (props.idleText === 'blank') {
        return '';
    }

    if (props.idleText === 'zero') {
        return formatDuration(0, props.format);
    }

    return props.format === 'hh:mm:ss' ? '--:--:--' : '--:--';
}

/**
 * What a timer element shows for a stopwatchView() - never negative. `auto`: the countdown before a scheduled start,
 * then the elapsed time; `elapsed`: the elapsed time only (before the start = idle text); `remaining`: limit − elapsed
 * (the whole limit before the start, 0 at time's up), the elapsed time for a round without a limit.
 */
export function timerText(props, view) {
    const remainingMode = props.mode === 'remaining' && view.limitMs > 0;

    if (view.phase === 'idle') {
        return idleTimerText(props);
    }

    if (view.phase === 'scheduled') {
        if (props.mode === 'auto') {
            return formatDuration(view.countdownMs, props.format, 'ceil');
        }

        return remainingMode ? formatDuration(view.limitMs, props.format, 'ceil') : idleTimerText(props);
    }

    if (remainingMode) {
        return formatDuration(view.limitMs - view.elapsedMs, props.format, 'ceil');
    }

    return formatDuration(view.elapsedMs, props.format, 'floor');
}

/**
 * The last seconds of a countdown shown as one big number (the timer's `bigCountdown`), else null.
 */
export function bigCountdownNumber(props, view) {
    if (view.phase !== 'scheduled' || !(props.bigCountdown > 0)) {
        return null;
    }

    const seconds = countdownSeconds(view.countdownMs);

    return seconds > 0 && seconds <= props.bigCountdown ? seconds : null;
}

/**
 * In the warning time: running, the round has a limit, and less than `warningSeconds` are left.
 */
export function inWarning(warningSeconds, view) {
    return view.phase === 'running' && view.limitMs > 0 && warningSeconds > 0
        && view.limitMs - view.elapsedMs <= warningSeconds * 1000;
}

export function statusText(props, view, texts) {
    const override = props?.texts?.[view.phase];
    const time = formatCountdown(view.countdownMs);

    if (typeof override === 'string' && override !== '') {
        return override.split('%time%').join(time);
    }

    switch (view.phase) {
        case 'idle':
            return texts.notStarted ?? '';
        case 'scheduled':
            return (texts.startsIn ?? '%time%').split('%time%').join(time);
        case 'times_up':
            return texts.timesUp ?? '';
        case 'stopped':
            return texts.stopped ?? '';
        default:
            return '';
    }
}

// English dates as in the rest of the site (EventsPageDates): en-GB
function intlLocale(locale) {
    return locale === 'en' ? 'en-GB' : locale;
}

function formatDate(isoDay, locale) {
    if (typeof isoDay !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(isoDay)) {
        return '';
    }

    try {
        return new Intl.DateTimeFormat(intlLocale(locale), { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' })
            .format(new Date(`${isoDay}T12:00:00Z`));
    } catch {
        return isoDay;
    }
}

function timeFormatter(locale, timeZone, twelveHours, seconds) {
    const options = { hour: '2-digit', minute: '2-digit', hour12: twelveHours };

    if (seconds) {
        options.second = '2-digit';
    }

    try {
        return new Intl.DateTimeFormat(intlLocale(locale), timeZone ? { ...options, timeZone } : options);
    } catch {
        return new Intl.DateTimeFormat(undefined, options);
    }
}

export function fieldText(props, state) {
    const round = state.round ?? null;
    const competition = state.competition ?? {};
    const locale = state.locale ?? 'en';
    let value = '';

    switch (props.field) {
        case 'competition_name':
            value = competition.name ?? '';
            break;
        case 'round_name':
            value = round?.name ?? '';
            break;
        case 'round_category':
            value = round !== null ? (state.texts?.category ?? '') : '';
            break;
        case 'round_limit':
            value = round !== null && number(round.minutesLimit, 0) > 0 ? `${round.minutesLimit} min` : '';
            break;
        case 'round_start':
            value = round !== null && typeof round.startsAtMs === 'number'
                ? timeFormatter(locale, round.timezone, false, false).format(new Date(round.startsAtMs))
                : '';
            break;
        case 'location':
            value = competition.location ?? '';
            break;
        case 'event_date': {
            const from = formatDate(competition.dateFrom, locale);
            const to = formatDate(competition.dateTo, locale);
            value = from !== '' && to !== '' && to !== from ? `${from} – ${to}` : from;
            break;
        }
        default:
            value = '';
    }

    return typeof value === 'string' && value !== '' ? `${props.prefix ?? ''}${value}${props.suffix ?? ''}` : '';
}

/**
 * Whether a results element may show its data: an element left at `source: published` shows nothing before the
 * round's results are published - even when the state carries rows for another, live element (the server gates per
 * element type, the renderer per element). Returns the data or {unpublished text}.
 */
export function resultsFor(props, state) {
    const results = state.results ?? null;

    if (results === null || typeof results !== 'object') {
        return { results: null, text: '' };
    }

    if (props.source !== 'live' && results.published !== true) {
        return { results: null, text: props.whenUnpublished === 'text' ? (props.unpublishedText ?? '') : '' };
    }

    return { results, text: '' };
}

export function resultText(row, texts) {
    if (row.didNotFinish) {
        return (texts.piecesPlaced ?? '%count%').split('%count%').join(String(number(row.piecesPlaced, 0)));
    }

    return typeof row.resultSeconds === 'number' ? formatDuration(row.resultSeconds * 1000, 'auto') : '';
}

export function finishedText(props, results, texts) {
    const finished = results?.finished;
    const total = results?.total;

    if (typeof finished !== 'number') {
        return '';
    }

    if (props.format === 'count') {
        return `${finished} ${texts.finished ?? ''}`.trim();
    }

    if (props.format === 'percent') {
        return typeof total === 'number' && total > 0 ? `${Math.round((finished * 100) / total)} %` : '';
    }

    return typeof total === 'number' ? `${finished} / ${total} ${texts.finished ?? ''}`.trim() : `${finished}`;
}

export function tickerText(row, texts) {
    const parts = [];

    if (typeof row.tableNumber === 'number') {
        parts.push((texts.table ?? '%number%').split('%number%').join(String(row.tableNumber)));
    }

    parts.push(row.name ?? '');
    const result = resultText(row, texts);

    if (result !== '') {
        parts.push(result);
    }

    return parts.join(' · ');
}

// ---- standings (competition toolkit v2 S2)

function template(text, values) {
    let result = typeof text === 'string' ? text : '';

    for (const [key, value] of Object.entries(values)) {
        result = result.split(key).join(String(value));
    }

    return result;
}

/**
 * The standing the element shows - null = nothing (the element's `whenEmpty`).
 */
export function standingFor(element, state) {
    const standing = state.standings?.[element.id] ?? null;

    return standing !== null && typeof standing === 'object' && Array.isArray(standing.rows) ? standing : null;
}

/**
 * The line under the standing's name: "Provisional · 2 of 6 rounds" / "Final - all 6 rounds".
 */
export function standingSubtitle(standing, texts) {
    const total = number(standing.roundsTotal, 0);

    if (standing.final === true) {
        const final = texts.standingFinal?.[String(total)];

        return typeof final === 'string' ? final : '';
    }

    const rounds = template(texts.standingRoundsOf ?? '%scored% / %count%', { '%scored%': number(standing.roundsCounted, 0), '%count%': total });

    return [texts.standingProvisional ?? '', rounds].filter((part) => part !== '').join(' · ');
}

/**
 * A standing row's total - the penalised total (time + the penalty for missing pieces, T6), as on the public page.
 */
export function standingTotalText(row) {
    return typeof row.totalSeconds === 'number' ? formatDuration(row.totalSeconds * 1000, 'auto') : '';
}

/**
 * "3 pieces missing" (in the display's language, per count), '' when nothing is missing.
 */
export function standingMissingText(row, texts) {
    const count = number(row.totalMissingPieces, 0);

    if (count <= 0) {
        return '';
    }

    const text = texts.standingMissing?.[String(count)];

    return typeof text === 'string' ? text : `−${count}`;
}

/**
 * One round's score of a row: the time (an unfinished one with *), – without a score. A status this renderer does not
 * know yet still shows its score (statuses are the calculator's strings - new ones come without a new renderer).
 */
export function standingScoreText(score) {
    if (score === null || typeof score !== 'object') {
        return '–';
    }

    if (typeof score.scoreSeconds !== 'number') {
        return '–';
    }

    const time = formatDuration(score.scoreSeconds * 1000, 'auto');

    return score.status === 'unfinished' ? `${time}*` : time;
}

/**
 * "5 of 6 rounds" under an incomplete row's name (listed after everybody, without a rank).
 */
export function standingIncompleteText(row, standing, texts) {
    if (row.complete !== false) {
        return '';
    }

    return template(texts.standingRoundsOf ?? '%scored% / %count%', { '%scored%': number(row.roundsScored, 0), '%count%': number(standing.roundsCounted, 0) });
}

/**
 * The visibility of an element: not hidden, shown in the phase, and its results / puzzle conditions.
 */
export function isShown(element, context) {
    if (element.hidden) {
        return false;
    }

    if (!element.when.phases.includes(context.phase)) {
        return false;
    }

    if (element.when.results === 'published' && !context.resultsPublished) {
        return false;
    }

    if (element.when.results === 'not_published' && context.resultsPublished) {
        return false;
    }

    if (element.when.puzzle === 'revealed' && !context.puzzleRevealed) {
        return false;
    }

    return !(element.when.puzzle === 'not_revealed' && context.puzzleRevealed);
}

/**
 * The QR code's modules as one SVG path ("M x y h n v1 h-n z" per run of dark modules) - digits only.
 */
export function qrPath(code, quiet) {
    if (code === null || typeof code !== 'object' || !Array.isArray(code.rows)) {
        return null;
    }

    const size = code.rows.length;

    if (size === 0 || size > QR_MAX_SIZE || !code.rows.every((row) => typeof row === 'string' && row.length === size && QR_ROW_PATTERN.test(row))) {
        return null;
    }

    let path = '';

    code.rows.forEach((row, y) => {
        let x = 0;

        while (x < size) {
            if (row[x] === '1') {
                let end = x;

                while (end < size && row[end] === '1') {
                    end += 1;
                }

                path += `M${x + quiet} ${y + quiet}h${end - x}v1h-${end - x}z`;
                x = end;
            } else {
                x += 1;
            }
        }
    });

    return { path, size: size + quiet * 2 };
}

// ---- the renderer

const EMPTY_DESIGN = normaliseDesign({ schema: 1 }, { competitionId: '' }).design;

export function createRenderer({ document, root, design, competitionId, kind }) {
    const stage = document.createElement('div');
    stage.className = 'msp-display__stage';
    const background = document.createElement('div');
    background.className = 'msp-display__background';
    const backgroundImage = document.createElement('img');
    backgroundImage.className = 'msp-display__background-image';
    backgroundImage.alt = '';
    backgroundImage.style.display = 'none';
    background.appendChild(backgroundImage);
    stage.appendChild(background);
    const invalid = document.createElement('div');
    invalid.className = 'msp-display__invalid';
    invalid.style.display = 'none';
    root.appendChild(stage);
    root.appendChild(invalid);

    let current = EMPTY_DESIGN;
    let views = [];
    let lastState = null;
    let lastNow = 0;

    function setText(view, key, node, text) {
        if (view.texts[key] !== text) {
            view.texts[key] = text;
            node.textContent = text;
            view.fitDirty = true;
        }
    }

    function setStyle(node, property, value) {
        if (node.style[property] !== value) {
            node.style[property] = value;
        }
    }

    function el(tag, className, parent) {
        const node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        if (parent) {
            parent.appendChild(node);
        }

        return node;
    }

    function baseColor(element) {
        return safeColor(element.style.color) ?? safeColor(current.theme.color) ?? '#ffffff';
    }

    function accent() {
        return safeColor(current.theme.accent) ?? '#fe696a';
    }

    function fontSize(element) {
        return number(element.style.fontSize, DEFAULT_FONT_SIZES[element.type] ?? 40);
    }

    function frameHeightPx(element) {
        return (element.frame.h / 100) * CANVAS_HEIGHT;
    }

    function applyFrame(node, body, element) {
        const { frame, style } = element;
        node.style.left = `${number(frame.x, 0)}%`;
        node.style.top = `${number(frame.y, 0)}%`;
        node.style.width = `${number(frame.w, 10)}%`;
        node.style.height = `${number(frame.h, 10)}%`;
        node.style.zIndex = String(number(element.z, 0));

        if (number(frame.rotate, 0) !== 0) {
            node.style.transform = `rotate(${number(frame.rotate, 0)}deg)`;
        }

        if (typeof style.opacity === 'number') {
            node.style.opacity = String(number(style.opacity, 1));
        }

        body.style.fontFamily = FONT_STACKS[style.font] ?? FONT_STACKS[current.theme.font] ?? FONT_STACKS.rubik;
        body.style.fontSize = `${fontSize(element)}px`;
        body.style.fontWeight = String(number(style.fontWeight, 500));
        body.style.color = baseColor(element);
        body.style.justifyContent = ALIGN[style.align] ?? 'center';
        body.style.alignItems = VALIGN[style.valign] ?? 'center';
        body.style.textAlign = style.align === 'left' || style.align === 'right' ? style.align : 'center';
        body.style.lineHeight = String(number(style.lineHeight, 1.15));

        const backgroundColor = safeColor(style.background);
        const gradient = style.gradient;

        if (gradient && safeColor(gradient.from) && safeColor(gradient.to)) {
            body.style.backgroundImage = `linear-gradient(${number(gradient.angle, 180)}deg, ${safeColor(gradient.from)}, ${safeColor(gradient.to)})`;
        }

        if (backgroundColor !== null) {
            body.style.backgroundColor = backgroundColor;
        }

        if (style.border && safeColor(style.border.color)) {
            body.style.border = `${number(style.border.width, 1)}px solid ${safeColor(style.border.color)}`;
        }

        if (typeof style.radius === 'number') {
            body.style.borderRadius = `${number(style.radius, 0)}px`;
        }

        if (typeof style.padding === 'number') {
            body.style.padding = `${number(style.padding, 0)}px`;
        }

        if (SHADOWS[style.shadow]) {
            body.style.textShadow = SHADOWS[style.shadow];
        }

        if (style.uppercase === true) {
            body.style.textTransform = 'uppercase';
        }

        if (typeof style.letterSpacing === 'number') {
            body.style.letterSpacing = `${number(style.letterSpacing, 0)}px`;
        }
    }

    function build(element) {
        const node = el('div', `msp-el msp-el--${element.type}`);
        const body = el('div', 'msp-el__body', node);
        const view = { element, node, body, texts: {}, shown: null, fitDirty: false, refs: {} };
        applyFrame(node, body, element);

        switch (element.type) {
            case 'timer':
                body.style.fontVariantNumeric = 'tabular-nums';
                view.refs.text = el('span', 'msp-el__line', body);
                break;
            case 'status':
            case 'field':
            case 'finished_count':
            case 'clock':
                if (element.type === 'clock') {
                    body.style.fontVariantNumeric = 'tabular-nums';
                }
                view.refs.text = el('span', 'msp-el__line', body);
                break;
            case 'text':
                view.refs.text = el('span', 'msp-el__text', body);
                view.refs.text.textContent = element.props.text ?? '';
                break;
            case 'round_badge':
                view.refs.text = el('span', 'msp-el__badge', body);
                break;
            case 'progress':
                view.refs.track = el('div', 'msp-el__track', body);
                view.refs.bar = el('div', 'msp-el__bar', view.refs.track);
                view.refs.track.style.backgroundColor = safeColor(element.props.trackColor) ?? '#ffffff33';
                if (typeof element.style.radius === 'number') {
                    view.refs.track.style.borderRadius = `${element.style.radius}px`;
                    view.refs.bar.style.borderRadius = `${element.style.radius}px`;
                }
                if (element.props.orientation === 'vertical') {
                    view.refs.bar.style.width = '100%';
                    view.refs.bar.style.bottom = '0';
                } else {
                    view.refs.bar.style.height = '100%';
                    view.refs.bar.style.top = '0';
                }
                if (element.props.direction === 'drain' && element.props.orientation !== 'vertical') {
                    view.refs.bar.style.left = '0';
                }
                break;
            case 'image':
                view.refs.img = el('img', 'msp-el__image', body);
                view.refs.img.alt = '';
                view.refs.img.style.objectFit = element.props.fit === 'cover' ? 'cover' : 'contain';
                view.refs.img.style.display = 'none';
                break;
            case 'rotator':
                view.refs.slides = [];
                view.refs.signature = null;
                break;
            case 'puzzle':
                view.refs.list = el('div', `msp-el__puzzles msp-el__puzzles--${element.props.layout === 'column' ? 'column' : element.props.layout === 'grid' ? 'grid' : 'row'}`, body);
                view.refs.hidden = el('span', 'msp-el__line', body);
                view.refs.signature = null;
                break;
            case 'leaderboard':
            case 'ticker':
                body.style.alignItems = 'flex-start';
                view.refs.list = el('div', 'msp-el__rows', body);
                view.refs.empty = el('span', 'msp-el__line', body);
                view.refs.rows = new Map();
                break;
            case 'qr':
                view.refs.signature = null;
                break;
            case 'standings':
                body.style.flexDirection = 'column';
                body.style.justifyContent = 'flex-start';
                body.style.alignItems = 'stretch';
                view.refs.title = el('div', 'msp-standings__title', body);
                view.refs.name = el('div', 'msp-standings__name', view.refs.title);
                view.refs.subtitle = el('div', 'msp-standings__subtitle', view.refs.title);
                view.refs.badge = el('span', 'msp-standings__badge', view.refs.subtitle);
                view.refs.subtitleText = el('span', 'msp-standings__subtitle-text', view.refs.subtitle);
                view.refs.head = el('div', 'msp-standings__row msp-standings__row--head', body);
                view.refs.list = el('div', 'msp-standings__rows', body);
                view.refs.empty = el('span', 'msp-el__line msp-standings__empty', body);
                view.refs.rows = new Map();
                view.refs.layout = null;
                break;
            default:
                break;
        }

        return view;
    }

    function setDesign(input) {
        const { design: normalised } = normaliseDesign(input, { competitionId });
        current = normalised ?? EMPTY_DESIGN;

        for (const view of views) {
            view.node.remove();
        }

        // Ties by array order: the DOM order of equal z
        views = current.elements.map((element) => {
            const view = build(element);
            stage.appendChild(view.node);

            return view;
        });

        const canvas = current.canvas.background;
        const type = canvas.type;
        stage.style.backgroundColor = type === 'transparent'
            ? 'transparent'
            : (type === 'chroma' ? safeColor(canvas.chroma) : safeColor(canvas.color)) ?? 'transparent';
        backgroundImage.style.display = 'none';
        backgroundImage.removeAttribute('src');
        backgroundImage.style.objectFit = canvas.image?.fit === 'contain' ? 'contain' : 'cover';
        backgroundImage.style.opacity = String(number(canvas.image?.opacity, 1));

        if (lastState !== null) {
            render(lastState, lastNow);
        }
    }

    function updateBackground(state) {
        const canvas = current.canvas.background;
        const url = canvas.type === 'image' && canvas.image ? safeImageUrl(state.images?.[canvas.image.path]) : null;

        if (url !== null) {
            if (backgroundImage.getAttribute('src') !== url) {
                backgroundImage.setAttribute('src', url);
            }
            backgroundImage.style.display = '';
        } else if (backgroundImage.style.display !== 'none') {
            backgroundImage.style.display = 'none';
            backgroundImage.removeAttribute('src');
        }
    }

    function imageUrl(props, state) {
        switch (props.source) {
            case 'competition_logo':
                return safeImageUrl(state.competition?.logoUrl);
            case 'series_logo':
                return safeImageUrl(state.competition?.seriesLogoUrl);
            case 'organization_logo':
                return safeImageUrl(state.competition?.organizationLogoUrl);
            default:
                return props.path ? safeImageUrl(state.images?.[props.path]) : null;
        }
    }

    function colorFor(element, view, phaseColors, warning) {
        const key = warning ? 'warning' : view.phase;
        const own = safeColor(phaseColors?.[key]);

        if (own !== null) {
            return own;
        }

        return warning ? accent() : baseColor(element);
    }

    function updateTimer(view, element, clock) {
        const props = element.props;
        const big = bigCountdownNumber(props, clock);
        const warning = inWarning(props.warningSeconds, clock);
        setStyle(view.body, 'color', colorFor(element, clock, props.phaseColors, warning));

        if (big !== null) {
            setText(view, 'text', view.refs.text, String(big));
            setStyle(view.body, 'fontSize', `${Math.round(Math.min(fontSize(element) * 2.2, frameHeightPx(element) * 0.95))}px`);
        } else {
            setText(view, 'text', view.refs.text, timerText(props, clock));
            setStyle(view.body, 'fontSize', `${fontSize(element)}px`);
        }
    }

    function updateProgress(view, element, clock) {
        const props = element.props;

        if (!(clock.limitMs > 0)) {
            setStyle(view.refs.track, 'visibility', 'hidden');

            return;
        }

        setStyle(view.refs.track, 'visibility', 'visible');
        const elapsed = ['running', 'times_up', 'stopped'].includes(clock.phase) ? clock.elapsedMs : 0;
        let fraction = Math.max(0, Math.min(1, elapsed / clock.limitMs));

        if (props.direction === 'drain') {
            fraction = 1 - fraction;
        }

        const percent = `${Math.round(fraction * 10000) / 100}%`;
        setStyle(view.refs.bar, props.orientation === 'vertical' ? 'height' : 'width', percent);
        const warning = inWarning(props.warningSeconds, clock);
        const barColor = warning
            ? safeColor(props.warningColor) ?? accent()
            : safeColor(props.barColor) ?? accent();
        setStyle(view.refs.bar, 'backgroundColor', barColor);
    }

    function updateBadge(view, element, state) {
        const round = state.round ?? null;
        setText(view, 'text', view.refs.text, round?.name ?? '');
        view.refs.text.style.display = round ? '' : 'none';

        if (element.props.useRoundColors && round) {
            setStyle(view.refs.text, 'backgroundColor', safeColor(round.badge?.bg) ?? accent());
            setStyle(view.refs.text, 'color', safeColor(round.badge?.fg) ?? '#ffffff');
        } else {
            setStyle(view.refs.text, 'backgroundColor', safeColor(element.style.background) ?? accent());
        }

        if (typeof element.style.radius === 'number') {
            setStyle(view.refs.text, 'borderRadius', `${element.style.radius}px`);
        }
    }

    function updateImage(view, element, state) {
        const url = imageUrl(element.props, state);
        const img = view.refs.img;

        if (url === null) {
            img.style.display = 'none';
            img.removeAttribute('src');

            return;
        }

        if (img.getAttribute('src') !== url) {
            img.setAttribute('src', url);
        }

        img.style.display = '';
    }

    function rotatorUrls(element, state) {
        return element.props.images
            .map((image) => safeImageUrl(state.images?.[image.path]))
            .filter((url) => url !== null);
    }

    function updateRotator(view, element, state, nowMs) {
        const urls = rotatorUrls(element, state);
        const signature = urls.join('\n');

        if (signature !== view.refs.signature) {
            view.refs.signature = signature;

            for (const slide of view.refs.slides) {
                slide.remove();
            }

            // Every picture is in the page at once (≤ 30) - the next one is loaded long before its turn
            view.refs.slides = urls.map((url) => {
                const img = el('img', 'msp-el__slide', view.body);
                img.alt = '';
                img.setAttribute('src', url);
                img.style.objectFit = element.props.fit === 'cover' ? 'cover' : 'contain';
                img.style.opacity = '0';

                if (element.props.transition === 'fade') {
                    img.style.transition = 'opacity 0.8s ease';
                }

                return img;
            });
        }

        const count = view.refs.slides.length;

        if (count === 0) {
            return;
        }

        const slot = element.props.seconds * 1000;
        const active = Math.floor(Math.max(0, nowMs) / slot) % count;
        view.refs.slides.forEach((slide, index) => setStyle(slide, 'opacity', index === active ? '1' : '0'));
    }

    function updateClock(view, element, state, nowMs) {
        const zone = element.props.zone === 'round' ? state.round?.timezone ?? null : null;
        const key = `${state.locale}|${zone}|${element.props.format}|${element.props.seconds}`;

        if (view.refs.formatterKey !== key) {
            view.refs.formatterKey = key;
            view.refs.formatter = timeFormatter(state.locale ?? 'en', zone, element.props.format === '12h', element.props.seconds);
        }

        setText(view, 'text', view.refs.text, view.refs.formatter.format(new Date(nowMs)));
    }

    function updatePuzzles(view, element, state) {
        const props = element.props;
        const puzzles = (Array.isArray(state.puzzles) ? state.puzzles : []).slice(0, props.max);
        const shown = puzzles.map((puzzle) => ({
            image: props.show.includes('image') ? safeImageUrl(puzzle.imageUrl) : null,
            name: props.show.includes('name') && typeof puzzle.name === 'string' ? puzzle.name : null,
            brand: props.show.includes('brand') && typeof puzzle.brand === 'string' ? puzzle.brand : null,
            pieces: props.show.includes('pieces') && typeof puzzle.pieces === 'number' ? (state.texts?.pieces ?? '%count%').split('%count%').join(String(puzzle.pieces)) : null,
        }));
        const signature = JSON.stringify(shown);

        setText(view, 'hidden', view.refs.hidden, shown.length === 0 && props.whenHidden === 'text' ? props.hiddenText ?? '' : '');
        view.refs.hidden.style.display = shown.length === 0 ? '' : 'none';

        if (signature === view.refs.signature) {
            return;
        }

        view.refs.signature = signature;

        while (view.refs.list.firstChild) {
            view.refs.list.firstChild.remove();
        }

        const share = shown.length > 0 ? 100 / (props.layout === 'grid' ? Math.min(shown.length, 2) : shown.length) : 100;

        for (const puzzle of shown) {
            const item = el('div', 'msp-el__puzzle', view.refs.list);

            if (props.layout === 'column') {
                item.style.height = `${share}%`;
                item.style.width = '100%';
            } else {
                item.style.width = `${share}%`;
                item.style.height = props.layout === 'grid' ? `${100 / Math.ceil(shown.length / 2)}%` : '100%';
            }

            if (puzzle.image !== null) {
                const img = el('img', 'msp-el__puzzle-image', item);
                img.alt = '';
                img.setAttribute('src', puzzle.image);
                img.style.objectFit = props.imageFit === 'cover' ? 'cover' : 'contain';
            }

            for (const key of ['name', 'brand', 'pieces']) {
                if (puzzle[key] !== null) {
                    el('div', `msp-el__puzzle-${key}`, item).textContent = puzzle[key];
                }
            }
        }
    }

    function syncRows(view, rows, buildRow, updateRow) {
        const seen = new Set();

        rows.forEach((row, index) => {
            if (typeof row?.key !== 'string' || seen.has(row.key)) {
                return;
            }

            seen.add(row.key);
            let entry = view.refs.rows.get(row.key);

            if (!entry) {
                entry = buildRow();
                view.refs.rows.set(row.key, entry);
            }

            updateRow(entry, row, index);

            // Moves an existing node into place (no rebuild), appends a new one
            if (view.refs.list.children[index] !== entry.node) {
                view.refs.list.insertBefore(entry.node, view.refs.list.children[index] ?? null);
            }
        });

        for (const [key, entry] of view.refs.rows) {
            if (!seen.has(key)) {
                entry.node.remove();
                view.refs.rows.delete(key);
            }
        }
    }

    function updateLeaderboard(view, element, state, nowMs) {
        const props = element.props;
        const { results, text } = resultsFor(props, state);
        const rows = results !== null && Array.isArray(results.top) ? results.top.slice(0, props.rows) : [];
        setText(view, 'empty', view.refs.empty, results === null ? text : '');
        view.refs.empty.style.display = results === null && text !== '' ? '' : 'none';
        view.refs.list.style.display = results === null ? 'none' : '';
        const columns = props.columns;
        const rowStyle = props.rowStyle ?? {};

        syncRows(view, rows, () => {
            const node = el('div', 'msp-el__row');
            const cells = {};

            for (const column of columns) {
                if (column === 'members') {
                    continue;
                }

                cells[column] = el('span', `msp-el__cell msp-el__cell--${column}`, node);

                if (column === 'name') {
                    cells.nameText = el('span', 'msp-el__name', cells.name);

                    if (columns.includes('members')) {
                        cells.members = el('span', 'msp-el__members', cells.name);
                    }
                }
            }

            return { node, cells, texts: {} };
        }, (entry, row, index) => {
            const set = (key, node, value) => {
                if (entry.texts[key] !== value) {
                    entry.texts[key] = value;
                    node.textContent = value;
                }
            };

            if (entry.cells.rank) {
                set('rank', entry.cells.rank, typeof row.rank === 'number' ? `${row.rank}.` : '');
            }

            if (entry.cells.flag) {
                const countries = (Array.isArray(row.countries) ? row.countries : []).filter((code) => typeof code === 'string' && COUNTRY_PATTERN.test(code)).slice(0, 4);
                const signature = countries.join(',');

                if (entry.texts.flag !== signature) {
                    entry.texts.flag = signature;

                    while (entry.cells.flag.firstChild) {
                        entry.cells.flag.firstChild.remove();
                    }

                    for (const code of countries) {
                        el('span', `fi fi-${code}`, entry.cells.flag);
                    }
                }
            }

            if (entry.cells.nameText) {
                set('name', entry.cells.nameText, typeof row.name === 'string' ? row.name : '');
            }

            if (entry.cells.members) {
                const members = Array.isArray(row.members) ? row.members.filter((name) => typeof name === 'string').join(', ') : '';
                set('members', entry.cells.members, members);
                entry.cells.members.style.display = members === '' ? 'none' : '';
            }

            if (entry.cells.result) {
                set('result', entry.cells.result, resultText(row, state.texts ?? {}));
            }

            if (entry.cells.table) {
                set('table', entry.cells.table, typeof row.tableNumber === 'number' ? String(row.tableNumber) : '');
            }

            const background = index % 2 === 1 ? safeColor(rowStyle.altBackground) ?? safeColor(rowStyle.background) : safeColor(rowStyle.background);
            setStyle(entry.node, 'backgroundColor', background ?? '');
            setStyle(entry.node, 'marginBottom', `${number(rowStyle.gap, 4)}px`);
            const fresh = props.highlightLatestSeconds > 0 && typeof row.enteredAtMs === 'number'
                && nowMs - row.enteredAtMs >= 0 && nowMs - row.enteredAtMs < props.highlightLatestSeconds * 1000;
            setStyle(entry.node, 'boxShadow', fresh ? `inset 0 0 0 3px ${accent()}` : '');
        });
    }

    function updateTicker(view, element, state) {
        const props = element.props;
        const { results, text } = resultsFor(props, state);
        const rows = results !== null && Array.isArray(results.latest) ? results.latest.slice(0, props.rows) : [];
        setText(view, 'empty', view.refs.empty, results === null ? text : '');
        view.refs.empty.style.display = results === null && text !== '' ? '' : 'none';
        view.refs.list.style.display = results === null ? 'none' : '';

        syncRows(view, rows, () => ({ node: el('div', 'msp-el__row msp-el__row--ticker'), text: null }), (entry, row) => {
            const value = tickerText(row, state.texts ?? {});

            if (entry.text !== value) {
                entry.text = value;
                entry.node.textContent = value;
            }
        });
    }

    // The cells of a standings row (and of its head) in the element's column order: rank, flag, name (members under
    // it), stage, the rounds, total (the missing pieces under it)
    function standingCells(node, columns, roundCount, size) {
        const cells = {};
        // Fixed widths in px of the element's font size: the head (smaller text) lines up with the rows
        const fixed = (cell, ems) => {
            cell.style.flex = `0 0 ${Math.round(size * ems)}px`;
            cell.style.width = `${Math.round(size * ems)}px`;

            return cell;
        };

        if (columns.includes('rank')) {
            cells.rank = fixed(el('span', 'msp-standings__cell msp-standings__cell--rank', node), 1.9);
            cells.rankPill = el('span', 'msp-standings__rank', cells.rank);
        }

        if (columns.includes('flag')) {
            cells.flag = el('span', 'msp-standings__cell msp-standings__cell--flag', node);
        }

        cells.name = el('span', 'msp-standings__cell msp-standings__cell--name', node);
        cells.nameText = el('span', 'msp-standings__entrant', cells.name);
        cells.sub = el('span', 'msp-standings__sub', cells.name);

        if (columns.includes('stage')) {
            cells.stage = fixed(el('span', 'msp-standings__cell msp-standings__cell--stage', node), 4.5);
        }

        cells.rounds = [];

        if (columns.includes('rounds')) {
            for (let index = 0; index < roundCount; index += 1) {
                cells.rounds.push(fixed(el('span', 'msp-standings__cell msp-standings__cell--round', node), 3.3));
            }
        }

        if (columns.includes('total')) {
            cells.total = fixed(el('span', 'msp-standings__cell msp-standings__cell--total', node), 3.9);
            cells.totalText = el('span', 'msp-standings__total', cells.total);
            cells.missing = el('span', 'msp-standings__missing', cells.total);
        }

        return cells;
    }

    function clearRows(view) {
        for (const entry of view.refs.rows.values()) {
            entry.node.remove();
        }

        view.refs.rows.clear();
    }

    function updateStandings(view, element, state) {
        const props = element.props;
        const texts = state.texts ?? {};
        const standing = standingFor(element, state);
        const rows = standing !== null ? standing.rows.slice(0, props.rows) : [];
        const rounds = standing !== null && Array.isArray(standing.rounds) ? standing.rounds : [];
        const columns = props.columns;
        const emptyText = props.whenEmpty === 'text' ? (props.emptyText ?? '') : '';
        const showEmpty = (standing === null || rows.length === 0) && emptyText !== '';

        setText(view, 'empty', view.refs.empty, showEmpty ? emptyText : '');
        view.refs.empty.style.display = showEmpty ? '' : 'none';

        const showTitle = standing !== null && props.title === true;
        view.refs.title.style.display = showTitle ? '' : 'none';

        if (showTitle) {
            setText(view, 'name', view.refs.name, typeof standing.name === 'string' ? standing.name : '');
            const provisional = standing.final !== true;
            // "Provisional" as a badge, then "2 of 6 rounds"; a final standing says "Final - all 6 rounds"
            const badge = provisional ? (texts.standingProvisional ?? '') : '';
            const rest = provisional
                ? template(texts.standingRoundsOf ?? '%scored% / %count%', { '%scored%': number(standing.roundsCounted, 0), '%count%': number(standing.roundsTotal, 0) })
                : standingSubtitle(standing, texts);
            setText(view, 'badge', view.refs.badge, badge);
            view.refs.badge.style.display = badge !== '' ? '' : 'none';
            setStyle(view.refs.badge, 'backgroundColor', accent());
            setStyle(view.refs.title, 'borderLeftColor', accent());
            setText(view, 'subtitle', view.refs.subtitleText, rest);
        }

        // Another number of rounds (or other columns) - every row's cells are built again
        const layout = `${columns.join(',')}|${columns.includes('rounds') ? rounds.length : 0}`;

        if (view.refs.layout !== layout) {
            view.refs.layout = layout;
            clearRows(view);

            while (view.refs.head.firstChild) {
                view.refs.head.firstChild.remove();
            }

            view.refs.headCells = standingCells(view.refs.head, columns, columns.includes('rounds') ? rounds.length : 0, fontSize(element));
            view.refs.headTexts = {};
        }

        const showHead = standing !== null && rows.length > 0 && (columns.includes('rounds') || columns.includes('stage'));
        view.refs.head.style.display = showHead ? '' : 'none';
        view.refs.list.style.display = standing === null ? 'none' : '';

        if (showHead) {
            const head = view.refs.headCells;
            const headSet = (key, node, value) => {
                if (view.refs.headTexts[key] !== value) {
                    view.refs.headTexts[key] = value;
                    node.textContent = value;
                }
            };

            head.rounds.forEach((cell, index) => {
                const round = rounds[index] ?? {};
                headSet(`round${index}`, cell, template(texts.standingRoundShort ?? 'R%number%', { '%number%': index + 1 }));
                setStyle(cell, 'opacity', round.counted === false ? '0.45' : '');
            });

            if (head.total) {
                headSet('total', head.totalText, texts.standingTotal ?? '');
            }
        }

        const rowStyle = props.rowStyle ?? {};
        const ownBackground = safeColor(rowStyle.background);

        syncRows(view, rows, () => {
            const node = el('div', 'msp-standings__row');

            return { node, cells: standingCells(node, columns, columns.includes('rounds') ? rounds.length : 0, fontSize(element)), texts: {} };
        }, (entry, row, index) => {
            const { cells } = entry;
            const set = (key, node, value) => {
                if (entry.texts[key] !== value) {
                    entry.texts[key] = value;
                    node.textContent = value;
                }
            };
            const rank = typeof row.rank === 'number' ? row.rank : null;

            if (cells.rank) {
                set('rank', cells.rankPill, rank !== null ? String(rank) : '–');
                const podium = rank !== null && rank <= 3 ? `msp-standings__rank msp-standings__rank--${rank}` : 'msp-standings__rank';

                if (cells.rankPill.className !== podium) {
                    cells.rankPill.className = podium;
                }
            }

            if (cells.flag) {
                const countries = (Array.isArray(row.countries) ? row.countries : []).filter((code) => typeof code === 'string' && COUNTRY_PATTERN.test(code)).slice(0, 4);
                const signature = countries.join(',');

                if (entry.texts.flag !== signature) {
                    entry.texts.flag = signature;

                    while (cells.flag.firstChild) {
                        cells.flag.firstChild.remove();
                    }

                    for (const code of countries) {
                        el('span', `fi fi-${code}`, cells.flag);
                    }
                }
            }

            set('name', cells.nameText, typeof row.name === 'string' ? row.name : '');
            const members = columns.includes('members') && Array.isArray(row.members) ? row.members.filter((name) => typeof name === 'string').join(', ') : '';
            const sub = [members, standingIncompleteText(row, standing, texts)].filter((part) => part !== '').join(' · ');
            set('sub', cells.sub, sub);
            cells.sub.style.display = sub === '' ? 'none' : '';

            if (cells.stage) {
                set('stage', cells.stage, typeof row.stageName === 'string' ? row.stageName : '');
            }

            const perRound = Array.isArray(row.perRound) ? row.perRound : [];
            cells.rounds.forEach((cell, at) => {
                set(`round${at}`, cell, standingScoreText(perRound[at] ?? null));
                setStyle(cell, 'opacity', rounds[at]?.counted === false ? '0.45' : '');
            });

            if (cells.total) {
                set('total', cells.totalText, standingTotalText(row));
                const missing = columns.includes('missing') ? standingMissingText(row, texts) : '';
                set('missing', cells.missing, missing);
                cells.missing.style.display = missing === '' ? 'none' : '';
            }

            const alt = index % 2 === 1;
            const background = ownBackground !== null
                ? (alt ? safeColor(rowStyle.altBackground) ?? ownBackground : ownBackground)
                : (alt ? safeColor(rowStyle.altBackground) ?? STANDINGS_ROW_ALT_BACKGROUND : STANDINGS_ROW_BACKGROUND);
            setStyle(entry.node, 'backgroundColor', background);
            setStyle(entry.node, 'marginBottom', `${number(rowStyle.gap, 4)}px`);
            setStyle(entry.node, 'opacity', row.complete === false ? '0.8' : '');
        });
    }

    function updateQr(view, element, state) {
        const props = element.props;
        const code = state.qr?.[element.id] ?? null;
        const drawn = qrPath(code, props.quietZone ? 4 : 0);
        const fg = safeColor(props.fg) ?? '#000000';
        const bg = safeColor(props.bg) ?? '#ffffff';
        const signature = drawn === null ? '' : `${drawn.path}|${fg}|${bg}`;

        if (signature === view.refs.signature) {
            return;
        }

        view.refs.signature = signature;

        while (view.body.firstChild) {
            view.body.firstChild.remove();
        }

        if (drawn === null) {
            return;
        }

        const svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('viewBox', `0 0 ${drawn.size} ${drawn.size}`);
        svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
        svg.setAttribute('shape-rendering', 'crispEdges');
        svg.setAttribute('class', 'msp-el__qr');
        const rect = document.createElementNS(SVG_NS, 'rect');
        rect.setAttribute('width', String(drawn.size));
        rect.setAttribute('height', String(drawn.size));
        rect.setAttribute('fill', bg);
        const path = document.createElementNS(SVG_NS, 'path');
        path.setAttribute('d', drawn.path);
        path.setAttribute('fill', fg);
        svg.appendChild(rect);
        svg.appendChild(path);
        view.body.appendChild(svg);
    }

    // "shrink": a name too long for its frame gets a smaller font (layout engines only - jsdom has no layout)
    function fitText(view) {
        if (!view.fitDirty || view.element.style.fit !== 'shrink') {
            view.fitDirty = false;

            return;
        }

        view.fitDirty = false;
        const body = view.body;
        const base = fontSize(view.element);
        body.style.fontSize = `${base}px`;

        if (body.clientWidth > 0 && body.scrollWidth > body.clientWidth) {
            body.style.fontSize = `${Math.max(8, Math.floor((base * body.clientWidth) / body.scrollWidth))}px`;
        }
    }

    function context(state, nowMs) {
        const clock = stopwatchView(state.stopwatch ?? null, nowMs);
        const resultsPublished = state.round ? state.round.resultsPublished === true : state.results?.published === true;

        return { clock, phase: clock.phase, resultsPublished, puzzleRevealed: state.anyPuzzleRevealed === true };
    }

    function render(state, nowMs) {
        lastState = state;
        lastNow = nowMs;
        const ctx = context(state, nowMs);
        updateBackground(state);

        for (const view of views) {
            const { element } = view;
            const shown = isShown(element, ctx);

            if (shown !== view.shown) {
                view.shown = shown;
                view.node.style.display = shown ? '' : 'none';
                view.fitDirty = true;
            }

            if (!shown) {
                continue;
            }

            switch (element.type) {
                case 'timer':
                    updateTimer(view, element, ctx.clock);
                    break;
                case 'status':
                    setText(view, 'text', view.refs.text, statusText(element.props, ctx.clock, state.texts ?? {}));
                    break;
                case 'progress':
                    updateProgress(view, element, ctx.clock);
                    break;
                case 'field':
                    setText(view, 'text', view.refs.text, fieldText(element.props, state));
                    break;
                case 'round_badge':
                    updateBadge(view, element, state);
                    break;
                case 'image':
                    updateImage(view, element, state);
                    break;
                case 'rotator':
                    updateRotator(view, element, state, nowMs);
                    break;
                case 'clock':
                    updateClock(view, element, state, nowMs);
                    break;
                case 'puzzle':
                    updatePuzzles(view, element, state);
                    break;
                case 'finished_count': {
                    const { results, text } = resultsFor(element.props, state);
                    setText(view, 'text', view.refs.text, results === null ? text : finishedText(element.props, results, state.texts ?? {}));
                    break;
                }
                case 'leaderboard':
                    updateLeaderboard(view, element, state, nowMs);
                    break;
                case 'ticker':
                    updateTicker(view, element, state);
                    break;
                case 'qr':
                    updateQr(view, element, state);
                    break;
                case 'standings':
                    updateStandings(view, element, state);
                    break;
                default:
                    break;
            }

            fitText(view);
        }
    }

    /**
     * When the picture changes next by itself (ms from nowMs), null = not before the next state: every second while
     * the clock counts down or runs, a clock's next second/minute, a rotator's next picture, a highlight's end.
     */
    function nextChangeMs(state, nowMs) {
        const ctx = context(state, nowMs);
        const candidates = [];

        if (MOVING_PHASES.includes(ctx.phase)) {
            const remainder = ctx.phase === 'scheduled' ? ctx.clock.countdownMs % 1000 : 1000 - (ctx.clock.elapsedMs % 1000);
            candidates.push(remainder > 0 ? remainder : 1000);
        }

        for (const view of views) {
            const { element } = view;

            if (!isShown(element, ctx)) {
                continue;
            }

            if (element.type === 'clock') {
                const unit = element.props.seconds ? 1000 : 60000;
                candidates.push(unit - (nowMs % unit));
            }

            if (element.type === 'rotator' && view.refs.slides.length > 1) {
                const slot = element.props.seconds * 1000;
                candidates.push(slot - (nowMs % slot));
            }

            if (element.type === 'leaderboard' && element.props.highlightLatestSeconds > 0) {
                const { results } = resultsFor(element.props, state);

                for (const row of results?.top ?? []) {
                    const ends = number(row?.enteredAtMs, -Infinity) + element.props.highlightLatestSeconds * 1000;

                    if (ends > nowMs) {
                        candidates.push(ends - nowMs);
                    }
                }
            }
        }

        return candidates.length === 0 ? null : Math.max(1, Math.ceil(Math.min(...candidates))) + 5;
    }

    function fit(width, height) {
        const scale = Math.max(0.01, Math.min(width / CANVAS_WIDTH, height / CANVAS_HEIGHT));
        const left = Math.round((width - CANVAS_WIDTH * scale) / 2);
        const top = Math.round((height - CANVAS_HEIGHT * scale) / 2);
        stage.style.transform = `translate(${left}px, ${top}px) scale(${scale})`;
    }

    /**
     * The link is no longer valid: a screen says so, an overlay shows nothing (a stream must never carry it). null =
     * valid again.
     */
    function showInvalid(text) {
        if (text === null) {
            invalid.style.display = 'none';
            stage.style.display = '';

            return;
        }

        stage.style.display = 'none';
        invalid.textContent = kind === 'overlay' ? '' : text;
        invalid.style.display = kind === 'overlay' ? 'none' : '';
    }

    setDesign(design);

    return {
        setDesign,
        render,
        nextChangeMs,
        fit,
        showInvalid,
        stage,
        get design() {
            return current;
        },
        get elementCount() {
            return views.length;
        },
    };
}
