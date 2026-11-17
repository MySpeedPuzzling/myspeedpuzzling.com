// Analytics Studio (docs/features/competitions-management/analytics-studio.md §7, §0a) - the page's DOM, built with
// createElement / textContent / setAttribute only (never an HTML sink): every name, team name, puzzle and brand is
// text. Links only to same-origin `/…` paths (a player's public profile), `rel="nofollow"`; images only http(s) or
// same-origin paths. All visible words come from `labels` (translation keys `analytics_studio.<name>`, LABEL_NAMES).
//
// One renderer per page: the skeleton (top bar, three columns, phone tab bar, help overlay) is built once - the search
// box keeps its focus - and render() fills the panels again from the model. The layout decides what a width shows
// (S3's stylesheet): phones show one panel by `data-as-tab` on the root and the compare columns past the second carry
// `as-compare__col--extra` (hidden there). The page adapter (S3) wires clicks by the `data-as-*` attributes:
// data-as-entry (select), data-as-compare (toggle), data-as-slot, data-as-sort, data-as-filter, data-as-tab,
// data-as-action (clearCompare, toggleHelp, closeHelp, close), data-as-search.
// Pinned by tests/analytics-studio-harness.mjs (incl. the XSS corpus and a source scan for HTML sinks).

import { stopwatchView } from '../round_stopwatch_clock.js';
import {
    activeSlot,
    availableFilters,
    availableSorts,
    compareTable,
    entrantRows,
    officialRanking,
    predictionOf,
    resultsCount,
    slotsOf,
    visibleRows,
} from './model.js';
import {
    flagOf,
    formatClock,
    formatCount,
    formatDate,
    formatDecimal,
    formatDelta,
    formatDuration,
    formatRange,
    interpolate,
} from './format.js';
import { KEYMAP } from './keys.js';

// Every label the renderer reads - S3 adds `analytics_studio.<name>` for each to the catalogue
export const LABEL_NAMES = [
    'search_placeholder', 'sort', 'sort.name', 'sort.table', 'sort.solves', 'sort.best', 'sort.rating', 'sort.rank',
    'filter.all', 'filter.solved', 'filter.never', 'filter.nohistory',
    'tab.entrants', 'tab.puzzle', 'tab.live',
    'clock.idle', 'clock.scheduled', 'clock.running', 'clock.times_up', 'clock.stopped', 'clock.left',
    'live.count', 'official.count', 'slot.hidden', 'slot.reveals_in', 'slot.image_later',
    'compare', 'compare.clear', 'compare.full', 'compare.add', 'compare.remove', 'compare.pick_more',
    'column.table', 'column.solves', 'column.best', 'column.rating', 'column.result',
    'empty.no_entrants', 'empty.no_match', 'empty.no_puzzle', 'empty.no_history', 'empty.first_time',
    'empty.nobody_logged', 'empty.not_rated', 'empty.select', 'empty.no_results', 'loading', 'opted_out',
    'private_puzzler', 'profile', 'table', 'close',
    'result.official', 'result.live', 'result.live_note', 'result.qualified', 'result.unfinished',
    'now', 'before_round', 'rating', 'rating_place', 'skill', 'solved_total', 'best500',
    'skill.enthusiast', 'skill.apprentice', 'skill.proficient', 'skill.advanced', 'skill.expert', 'skill.master', 'skill.legend',
    'history.solves', 'history.first', 'history.best', 'history.last', 'history.first_try', 'history.place',
    'history.unfinished', 'history.as_group', 'history.lineup', 'history.members_solved',
    'prediction', 'prediction.solo', 'prediction.range', 'prediction.method.personal', 'prediction.method.general',
    'similar.brand', 'similar.size', 'similar.count', 'similar.median', 'similar.best',
    'attempts', 'attempt.first', 'attempt.unboxed', 'attempt.solo', 'attempt.pair', 'attempt.team', 'attempt.unfinished',
    'recent_similar', 'recent_form', 'recent_form.median', 'earlier_rounds',
    'puzzle.pieces', 'puzzle.brand', 'difficulty', 'difficulty.very_easy', 'difficulty.easy', 'difficulty.average',
    'difficulty.challenging', 'difficulty.hard', 'difficulty.very_hard', 'difficulty.confidence',
    'stats.solvers', 'stats.times_logged', 'stats.median', 'stats.percentiles', 'stats.histogram', 'stats.first_tries',
    'stats.first_try_median', 'stats.fastest_first_try', 'stats.top', 'stats.in_round', 'stats.group', 'stats.group_median',
    'stats.fastest', 'pick_format',
    'help', 'help.title', 'keys.focus_search', 'keys.escape', 'keys.move', 'keys.open', 'keys.compare',
    'keys.clear_compare', 'keys.slot', 'keys.help',
];

const ORIGIN = 'https://analytics-studio.invalid';

/**
 * A same-origin path for a link ("/en/player-profile/…"), else null: never `javascript:`, `//host`, `/\host`, an
 * absolute URL, whitespace or control characters.
 */
export function safePath(value) {
    if (typeof value !== 'string' || value.length > 500 || !/^\/[^/\\]/.test(value) || /[\s\u0000-\u001f\u007f\\]/.test(value)) {
        return null;
    }

    try {
        const url = new URL(value, ORIGIN);

        return url.origin === ORIGIN ? `${url.pathname}${url.search}${url.hash}` : null;
    } catch {
        return null;
    }
}

/**
 * An image address: http(s) or a same-origin path, else null.
 */
export function safeImageUrl(value) {
    if (typeof value !== 'string' || value.length > 2000) {
        return null;
    }

    if (value.startsWith('/')) {
        return safePath(value);
    }

    if (!/^https?:\/\//i.test(value)) {
        return null;
    }

    try {
        const url = new URL(value);

        return url.protocol === 'https:' || url.protocol === 'http:' ? url.href : null;
    } catch {
        return null;
    }
}

function isNumber(value) {
    return typeof value === 'number' && Number.isFinite(value);
}

function isObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

export function createAnalyticsStudioRenderer({ document, root, labels = {}, locale = 'en' }) {
    const t = (name, params = {}) => interpolate(typeof labels[name] === 'string' ? labels[name] : name, params);
    const duration = (seconds) => formatDuration(seconds);
    const count = (value) => formatCount(value, locale);

    function el(tag, className = '', parent = null, text = null) {
        const node = document.createElement(tag);

        if (className !== '') {
            node.className = className;
        }

        if (text !== null) {
            node.textContent = String(text);
        }

        if (parent !== null) {
            parent.appendChild(node);
        }

        return node;
    }

    function button(className, parent, text, attributes = {}) {
        const node = el('button', className, parent, text);
        node.setAttribute('type', 'button');

        for (const [name, value] of Object.entries(attributes)) {
            node.setAttribute(name, String(value));
        }

        return node;
    }

    function flag(country, parent) {
        const emoji = flagOf(country);

        if (emoji !== '') {
            const node = el('span', 'as-flag', parent, emoji);
            node.setAttribute('title', country.toUpperCase());
            node.setAttribute('aria-label', country.toUpperCase());
        }
    }

    function spinner(parent) {
        const node = el('span', 'as-spinner', parent);
        node.setAttribute('role', 'status');
        node.setAttribute('aria-label', t('loading'));

        return node;
    }

    function optedOut(parent) {
        const node = el('span', 'as-muted as-opted-out', parent, '—');
        node.setAttribute('title', t('opted_out'));

        return node;
    }

    function fact(parent, name, value, modifier = '') {
        const row = el('div', `as-fact${modifier === '' ? '' : ` as-fact--${modifier}`}`, parent);
        el('dt', 'as-fact__name', row, name);
        const cell = el('dd', 'as-fact__value', row);

        if (value instanceof Object && 'nodeType' in value) {
            cell.appendChild(value);
        } else {
            cell.textContent = String(value);
        }

        return cell;
    }

    function personName(member, parent, { link = true } = {}) {
        const wrap = el('span', 'as-person', parent);
        const path = link ? safePath(member.profile) : null;

        if (path !== null) {
            const anchor = el('a', 'as-person__name', wrap, member.name);
            anchor.setAttribute('href', path);
            anchor.setAttribute('rel', 'nofollow noopener');
            anchor.setAttribute('target', '_blank');
            anchor.setAttribute('title', t('profile'));
        } else {
            el('span', 'as-person__name', wrap, member.name);
        }

        flag(member.country, wrap);

        return wrap;
    }

    // ---- skeleton (built once)

    root.replaceChildren();
    root.classList.add('as');

    const top = el('header', 'as-top', root);
    const roundName = el('div', 'as-top__round', top);
    const format = el('span', 'as-top__format', top);
    const slotsNav = el('nav', 'as-slots', top);
    const clock = el('div', 'as-clock', top);
    const clockStatus = el('span', 'as-clock__status', clock);
    const clockTime = el('span', 'as-clock__time', clock);
    const clockLeft = el('span', 'as-clock__left', clock);
    const liveCount = el('div', 'as-top__live', top);
    const helpToggle = button('as-top__help', top, '?', { 'data-as-action': 'toggleHelp', 'aria-label': t('help') });
    helpToggle.setAttribute('title', t('help'));

    const columns = el('div', 'as-columns', root);
    const entrantsPanel = el('section', 'as-panel as-entrants', columns);
    entrantsPanel.setAttribute('data-as-panel', 'entrants');
    const search = el('input', 'as-search', entrantsPanel);
    search.setAttribute('type', 'search');
    search.setAttribute('data-as-search', '');
    search.setAttribute('autocomplete', 'off');
    search.setAttribute('placeholder', t('search_placeholder'));
    search.setAttribute('aria-label', t('search_placeholder'));
    const controls = el('div', 'as-controls', entrantsPanel);
    const sortSelect = el('select', 'as-sort', controls);
    sortSelect.setAttribute('data-as-sort', '');
    sortSelect.setAttribute('aria-label', t('sort'));
    const filters = el('div', 'as-filters', controls);
    const compareBar = el('div', 'as-compare-bar', entrantsPanel);
    const list = el('ol', 'as-list', entrantsPanel);

    const detailPanel = el('section', 'as-panel as-detail', columns);
    detailPanel.setAttribute('data-as-panel', 'detail');

    const side = el('aside', 'as-side', columns);
    const puzzlePanel = el('section', 'as-panel as-puzzle', side);
    puzzlePanel.setAttribute('data-as-panel', 'puzzle');
    const livePanel = el('section', 'as-panel as-live', side);
    livePanel.setAttribute('data-as-panel', 'live');

    const tabs = el('nav', 'as-tabs', root);
    const tabButtons = ['entrants', 'puzzle', 'live'].map((tab) => button('as-tabs__tab', tabs, t(`tab.${tab}`), { 'data-as-tab': tab }));

    const notice = el('div', 'as-notice', root);
    notice.setAttribute('role', 'status');
    notice.setAttribute('aria-live', 'polite');

    const help = el('div', 'as-help', root);
    help.setAttribute('role', 'dialog');
    help.setAttribute('aria-modal', 'true');
    help.hidden = true;
    const helpCard = el('div', 'as-help__card', help);
    el('h2', 'as-help__title', helpCard, t('help.title'));
    const helpList = el('dl', 'as-help__keys', helpCard);

    for (const entry of KEYMAP) {
        const row = el('div', 'as-help__row', helpList);
        const keys = el('dt', 'as-help__key', row);
        entry.keys.forEach((key) => el('kbd', 'as-kbd', keys, key));
        el('dd', 'as-help__action', row, t(`keys.${entry.action}`));
    }

    button('as-help__close', helpCard, t('close'), { 'data-as-action': 'closeHelp' });

    // Countdowns drawn by renderClock() between renders
    let current = null;
    let countdowns = [];

    // ---- top bar

    function renderTop(state, slot, view) {
        roundName.textContent = typeof state?.round?.name === 'string' ? state.round.name : '';

        const pick = state?.round?.puzzleFormat;
        format.textContent = isObject(pick) && pick.kind === 'pick' && isNumber(pick.toSolve)
            ? t('pick_format', { count: pick.toSolve, total: slotsOf(state).length }) : '';

        slotsNav.replaceChildren();

        for (const candidate of slotsOf(state)) {
            const node = button(`as-slot${candidate.label === slot?.label ? ' as-slot--active' : ''}${candidate.visible ? '' : ' as-slot--hidden'}`, slotsNav, candidate.label, { 'data-as-slot': candidate.label });
            node.setAttribute('aria-pressed', candidate.label === slot?.label ? 'true' : 'false');
        }

        const results = resultsCount(state);
        liveCount.textContent = results === 0 ? '' : t(state?.round?.published === true ? 'official.count' : 'live.count', { count: count(results) });
        helpToggle.setAttribute('aria-expanded', view.help ? 'true' : 'false');
    }

    function drawClock(nowMs) {
        const stopwatch = current?.state?.round?.stopwatch ?? null;
        const view = stopwatchView(stopwatch, nowMs);
        clock.className = `as-clock as-clock--${view.phase}`;

        if (view.phase === 'scheduled') {
            clockStatus.textContent = t('clock.scheduled', { time: formatClock(view.countdownMs + 999) });
            clockTime.textContent = '';
            clockLeft.textContent = '';
        } else {
            clockStatus.textContent = t(`clock.${view.phase}`);
            clockTime.textContent = view.phase === 'idle' ? '' : formatClock(view.elapsedMs);
            clockLeft.textContent = view.limitMs > 0 && (view.phase === 'running')
                ? t('clock.left', { time: formatClock(view.limitMs - view.elapsedMs) }) : '';
        }

        for (const { node, atMs } of countdowns) {
            node.textContent = t('slot.reveals_in', { time: formatClock(Math.max(0, atMs - nowMs) + 999) });
        }
    }

    // ---- entrant list

    function resultCell(result, parent) {
        const cell = el('span', 'as-result', parent);

        if (result === null) {
            return cell;
        }

        cell.classList.add(`as-result--${result.state}`);

        if (result.state === 'official' && isNumber(result.rank)) {
            el('span', 'as-result__rank', cell, `#${result.rank}`);
        }

        if (isNumber(result.seconds)) {
            el('span', 'as-result__time', cell, duration(result.seconds));
        } else if (isNumber(result.piecesPlaced)) {
            el('span', 'as-result__pieces', cell, isNumber(result.piecesCount)
                ? t('result.unfinished', { placed: count(result.piecesPlaced), total: count(result.piecesCount) })
                : count(result.piecesPlaced));
        }

        if (result.state === 'official' && result.qualified === true) {
            el('span', 'as-result__qualified', cell, t('result.qualified'));
        }

        cell.setAttribute('title', t(result.state === 'official' ? 'result.official' : 'result.live_note'));

        return cell;
    }

    function renderList(state, rows, shown, slot, view) {
        if (search.value !== view.query && document.activeElement !== search) {
            search.value = view.query;
        }

        const sorts = availableSorts(state, slot);
        sortSelect.replaceChildren();

        for (const sort of sorts) {
            const option = el('option', '', sortSelect, t(`sort.${sort}`));
            option.setAttribute('value', sort);

            if (sort === (sorts.includes(view.sort) ? view.sort : 'name')) {
                option.setAttribute('selected', '');
                option.selected = true;
            }
        }

        filters.replaceChildren();
        const allowed = availableFilters(slot);

        for (const filter of allowed) {
            const active = filter === (allowed.includes(view.filter) ? view.filter : 'all');
            const chip = button(`as-chip${active ? ' as-chip--active' : ''}`, filters, t(`filter.${filter}`), { 'data-as-filter': filter });
            chip.setAttribute('aria-pressed', active ? 'true' : 'false');
        }

        compareBar.replaceChildren();

        if (view.compare.length > 0) {
            el('span', 'as-compare-bar__count', compareBar, t('compare', { count: view.compare.length, max: 4 }));
            button('as-compare-bar__clear', compareBar, t('compare.clear'), { 'data-as-action': 'clearCompare' });
        }

        list.replaceChildren();

        if (rows.length === 0) {
            el('li', 'as-empty', list, t('empty.no_entrants'));

            return;
        }

        if (shown.length === 0) {
            el('li', 'as-empty', list, t('empty.no_match'));

            return;
        }

        for (const row of shown) {
            const compared = view.compare.includes(row.key);
            const item = el('li', `as-row${row.key === view.selected ? ' as-row--selected' : ''}${compared ? ' as-row--compared' : ''}${row.hasHistory ? '' : ' as-row--nohistory'}`, list);
            item.setAttribute('data-as-entry', row.key);

            if (row.key === view.selected) {
                item.setAttribute('aria-current', 'true');
            }

            const name = el('div', 'as-row__name', item);

            if (row.isTeam) {
                el('span', 'as-row__team', name, row.name);
                el('span', 'as-row__members', name, row.subtitle);
            } else if (row.members[0] !== undefined) {
                el('span', 'as-row__player', name, row.members[0].name);
                flag(row.members[0].country, name);
            }

            el('span', 'as-row__table', item, row.table === null ? '' : t('table', { number: row.table }));

            const solves = el('span', 'as-row__solves', item);

            if (slot?.visible && row.analyticsLoaded && row.hasHistory) {
                if (row.isTeam) {
                    solves.textContent = row.solves === null ? '—' : t('history.lineup', { count: row.solves });

                    if (row.membersSolved !== null) {
                        el('span', 'as-row__members-solved', solves, t('history.members_solved', { solved: row.membersSolved, total: row.members.length }));
                    }
                } else {
                    solves.textContent = row.solves ? `${row.solves}×` : '—';
                }
            }

            el('span', 'as-row__best', item, slot?.visible && row.bestSeconds !== null ? duration(row.bestSeconds) : '');

            const rating = el('span', 'as-row__rating', item);

            if (row.rankingOptedOut) {
                optedOut(rating);
            } else if (row.rating !== null) {
                rating.textContent = count(row.rating);
            }

            resultCell(row.result, item);
            button(`as-row__compare${compared ? ' as-row__compare--on' : ''}`, item, compared ? '✓' : '+', {
                'data-as-compare': row.key,
                'aria-pressed': compared ? 'true' : 'false',
                'aria-label': t(compared ? 'compare.remove' : 'compare.add'),
            });
        }
    }

    // ---- entrant detail

    function predictionBlock(parent, sources, row, index, slot, { solo = false } = {}) {
        if (slot === null || !slot.visible) {
            return;
        }

        const prediction = predictionOf(sources, row.key, index, slot.key);
        const cell = fact(parent, `${t(solo ? 'prediction.solo' : 'prediction')} · ${t('now')}`, '', 'prediction');

        switch (prediction.status) {
            case 'pending':
                spinner(cell);
                break;
            case 'optedOut':
                optedOut(cell);
                break;
            case 'value': {
                const { value } = prediction;
                el('span', 'as-prediction__time', cell, `≈ ${duration(value.seconds)}`);
                const range = formatRange(value.low50, value.high50);

                if (range !== null) {
                    el('span', 'as-prediction__range', cell, t('prediction.range', { range }));
                }

                if (value.method === 'personal' || value.method === 'general') {
                    el('span', 'as-prediction__method', cell, t(`prediction.method.${value.method}`));
                }

                break;
            }
            default:
                cell.textContent = '—';
        }
    }

    function historyBlock(parent, member, slot, puzzleStats) {
        const history = isObject(member.stats?.perPuzzle) ? member.stats.perPuzzle[slot.key] ?? null : null;
        const block = el('dl', 'as-history', parent);
        el('div', 'as-history__scope as-muted', block, t('before_round'));

        if (history === null || !(history.solves > 0)) {
            el('div', 'as-history__first-time', block, t('empty.first_time'));

            if (isObject(history) && history.unfinished > 0) {
                fact(block, t('history.unfinished'), count(history.unfinished));
            }

            return;
        }

        fact(block, t('history.solves'), `${history.solves}×`);
        fact(block, t('history.first'), duration(history.firstSeconds));
        const best = fact(block, t('history.best'), duration(history.bestSeconds), 'best');
        const delta = formatDelta(history.lastSeconds, history.bestSeconds);
        const last = fact(block, t('history.last'), duration(history.lastSeconds));

        if (delta !== null && delta !== '±0:00') {
            el('span', 'as-delta', last, delta);
        }

        if (isNumber(history.lastSolvedAtMs)) {
            el('span', 'as-date', last, formatDate(history.lastSolvedAtMs, history.lastSolvedAtPrecision, locale));
        }

        if (isNumber(history.firstTrySeconds)) {
            fact(block, t('history.first_try'), duration(history.firstTrySeconds));
        }

        if (isNumber(history.placeOnPuzzle)) {
            el('span', 'as-place', best, t('history.place', { place: count(history.placeOnPuzzle), count: count(puzzleStats?.solvers) }));
        }

        if (history.unfinished > 0) {
            fact(block, t('history.unfinished'), count(history.unfinished));
        }

        if (isObject(history.asGroup) && history.asGroup.solves > 0) {
            fact(block, t('history.as_group'), `${history.asGroup.solves}× · ${duration(history.asGroup.bestSeconds)}`);
        }
    }

    function similarBlock(parent, member, slot) {
        const similar = isObject(member.stats?.similar) ? member.stats.similar[slot.key] ?? null : null;

        if (!isObject(similar)) {
            return;
        }

        const pieces = isNumber(slot.puzzle?.pieces) ? slot.puzzle.pieces : null;
        const block = el('div', 'as-similar', parent);
        const line = (name, number, median, best) => {
            const row = el('div', 'as-similar__row', block);
            el('span', 'as-similar__name', row, name);
            el('span', 'as-similar__count', row, `${count(number ?? 0)}×`);

            if (isNumber(median)) {
                el('span', 'as-similar__median', row, t('similar.median', { time: duration(median) }));
            }

            if (isNumber(best)) {
                el('span', 'as-similar__best', row, t('similar.best', { time: duration(best) }));
            }
        };

        line(t('similar.brand', { brand: slot.puzzle?.brand ?? '', pieces: pieces ?? '' }), similar.brandCount, similar.brandMedian, similar.brandBest);
        line(t('similar.size', { pieces: pieces ?? '' }), similar.sizeCount, similar.sizeMedian, similar.sizeBest);
    }

    function detailLists(parent, entrantMember, slot) {
        if (!isObject(entrantMember)) {
            return;
        }

        const attempts = Array.isArray(entrantMember.attempts?.[slot.key]) ? entrantMember.attempts[slot.key] : [];

        if (attempts.length > 0) {
            const box = el('details', 'as-attempts', parent);
            box.open = true;
            el('summary', 'as-attempts__title', box, t('attempts'));
            const items = el('ol', 'as-attempts__list', box);

            for (const attempt of attempts.filter(isObject)) {
                const item = el('li', 'as-attempt', items);
                el('span', 'as-date', item, formatDate(attempt.solvedAtMs, attempt.solvedAtPrecision, locale));

                if (isNumber(attempt.seconds)) {
                    el('span', 'as-attempt__time', item, duration(attempt.seconds));
                } else {
                    el('span', 'as-attempt__unfinished', item, t('attempt.unfinished', { placed: count(attempt.piecesPlaced) }));
                }

                if (['solo', 'pair', 'team'].includes(attempt.kind)) {
                    el('span', `as-tag as-tag--${attempt.kind}`, item, t(`attempt.${attempt.kind}`));
                }

                if (attempt.firstAttempt === true) {
                    el('span', 'as-tag as-tag--first', item, t('attempt.first'));
                }

                if (attempt.unboxed === true) {
                    el('span', 'as-tag as-tag--unboxed', item, t('attempt.unboxed'));
                }
            }
        }

        const recent = Array.isArray(entrantMember.recentSimilar?.[slot.key]) ? entrantMember.recentSimilar[slot.key] : [];

        if (recent.length > 0) {
            const box = el('details', 'as-recent', parent);
            el('summary', 'as-recent__title', box, t('recent_similar'));
            const items = el('ol', 'as-recent__list', box);

            for (const solve of recent.filter(isObject)) {
                const item = el('li', 'as-recent__item', items);
                el('span', 'as-recent__name', item, solve.puzzleName ?? '');
                el('span', 'as-recent__brand as-muted', item, `${solve.brand ?? ''} · ${t('puzzle.pieces', { count: count(solve.pieces) })}`);
                el('span', 'as-recent__time', item, duration(solve.seconds));
                el('span', 'as-date', item, formatDate(solve.solvedAtMs, solve.solvedAtPrecision, locale));
            }
        }

        const pieces = slot.puzzle?.pieces;
        const form = isNumber(pieces) ? entrantMember.recentForm?.[String(pieces)] : null;

        if (isObject(form) && Array.isArray(form.seconds) && form.seconds.length > 0) {
            const box = el('div', 'as-form', parent);
            el('div', 'as-form__title', box, t('recent_form', { pieces: count(pieces) }));
            el('div', 'as-form__median', box, t('recent_form.median', { time: duration(form.medianSeconds) }));
            const strip = el('ol', 'as-form__list', box);
            form.seconds.filter(isNumber).forEach((seconds) => el('li', 'as-form__item', strip, duration(seconds)));
        }
    }

    function memberBlock(parent, row, member, index, slot, model) {
        const block = el('section', `as-member${member.analysable ? '' : ' as-member--nohistory'}`, parent);

        if (row.isTeam) {
            personName(member, el('h3', 'as-member__name', block));
        }

        if (!member.analysable) {
            el('p', 'as-empty', block, t('empty.no_history'));

            return;
        }

        if (member.stats === null) {
            spinner(block);

            return;
        }

        const stats = member.stats;
        const facts = el('dl', 'as-facts', block);
        const rating = fact(facts, `${t('rating')} · ${t('now')}`, '');

        if (stats.rankingOptedOut === true) {
            optedOut(rating);
        } else if (isNumber(stats.rating)) {
            rating.textContent = count(stats.rating);

            if (isNumber(stats.ratingPlace)) {
                el('span', 'as-place', rating, t('rating_place', { place: count(stats.ratingPlace) }));
            }
        } else {
            rating.textContent = '—';
        }

        const skillCell = fact(facts, `${t('skill')} · ${t('now')}`, '');
        const tier = isObject(stats.skill) ? (slot?.visible ? stats.skill[slot.key] : undefined) ?? stats.skill['500'] ?? null : null;

        if (stats.rankingOptedOut === true) {
            optedOut(skillCell);
        } else if (typeof tier === 'string' && /^[a-z]+$/.test(tier)) {
            el('span', `as-skill as-skill--${tier}`, skillCell, t(`skill.${tier}`));
        } else {
            skillCell.textContent = '—';
        }

        fact(facts, t('solved_total'), count(stats.solvedTotal));
        fact(facts, t('best500'), duration(stats.best500));

        // A hidden slot: nothing about the puzzle (§5)
        if (slot === null || !slot.visible) {
            return;
        }

        const entrantMember = model.entrant?.key === row.key && Array.isArray(model.entrant.members) ? model.entrant.members[index] : null;
        historyBlock(block, member, slot, model.analytics?.puzzles?.[slot.key] ?? null);
        predictionBlock(facts, { entrant: model.entrant, predictions: model.predictions }, row, index, slot, { solo: row.isTeam });
        similarBlock(block, member, slot);

        if (model.entrant?.key === row.key) {
            detailLists(block, entrantMember, slot);
        } else {
            spinner(block);
        }
    }

    function renderDetail(row, slot, model) {
        const detail = el('article', 'as-entrant');
        const head = el('header', 'as-entrant__head', detail);
        button('as-entrant__close', head, '×', { 'data-as-action': 'close', 'aria-label': t('close') });

        if (row.isTeam) {
            el('h2', 'as-entrant__name', head, row.name);
        } else if (row.members[0] !== undefined) {
            personName(row.members[0], el('h2', 'as-entrant__name', head));
        }

        if (row.table !== null) {
            el('span', 'as-entrant__table', head, t('table', { number: row.table }));
        }

        const compared = model.view.compare.includes(row.key);
        button(`as-entrant__compare${compared ? ' as-entrant__compare--on' : ''}`, head, t(compared ? 'compare.remove' : 'compare.add'), { 'data-as-compare': row.key });

        if (row.result !== null) {
            const line = el('div', 'as-entrant__result', detail);
            resultCell(row.result, line);

            if (row.result.state === 'live') {
                el('span', 'as-muted', line, t('result.live_note'));
            }
        }

        if (row.isTeam && slot?.visible && isObject(row.lineup)) {
            const lineup = row.lineup.perPuzzle?.[slot.key] ?? null;
            const box = el('dl', 'as-lineup', detail);
            fact(box, t('history.lineup', { count: lineup?.solves ?? 0 }), lineup?.solves > 0 ? duration(lineup.bestSeconds) : t('empty.first_time'));
        }

        row.members.forEach((member, index) => memberBlock(detail, row, member, index, slot, model));

        const earlier = model.entrant?.key === row.key && Array.isArray(model.entrant.earlierRounds) ? model.entrant.earlierRounds.filter(isObject) : [];

        if (earlier.length > 0) {
            const box = el('section', 'as-earlier', detail);
            el('h3', 'as-earlier__title', box, t('earlier_rounds'));
            const items = el('ol', 'as-earlier__list', box);

            for (const round of earlier) {
                const item = el('li', 'as-earlier__item', items);
                el('span', 'as-earlier__name', item, round.roundName ?? '');
                resultCell({ ...round, state: 'official' }, item);
            }
        }

        return detail;
    }

    function compareValue(metric, value, cell) {
        switch (metric.kind) {
            case 'count':
                cell.textContent = value === null ? '—' : (metric.id === 'solves' ? `${value}×` : count(value));
                break;
            case 'duration':
                cell.textContent = value === null ? '—' : duration(value);
                break;
            case 'skill':
                cell.textContent = typeof value === 'string' && /^[a-z]+$/.test(value) ? t(`skill.${value}`) : '—';
                break;
            case 'result':
                if (value === null) {
                    cell.textContent = '—';
                } else {
                    resultCell(value, cell);
                }
                break;
            default:
                cell.textContent = '—';
        }
    }

    const METRIC_LABELS = {
        solves: 'history.solves',
        best: 'history.best',
        first: 'history.first',
        last: 'history.last',
        first_try: 'history.first_try',
        prediction: 'prediction',
        brand_median: 'similar.brand',
        size_median: 'similar.size',
        rating: 'rating',
        skill: 'skill',
        result: 'column.result',
    };

    function renderCompare(rows, slot, model) {
        const table = el('table', 'as-compare');
        const head = el('tr', 'as-compare__head', el('thead', '', table));
        el('th', 'as-compare__corner', head);
        rows.forEach((row, index) => {
            const th = el('th', `as-compare__col${index >= 2 ? ' as-compare__col--extra' : ''}`, head);
            el('span', 'as-compare__name', th, row.name);

            if (!row.isTeam) {
                flag(row.members[0]?.country, th);
            }

            button('as-compare__remove', th, '×', { 'data-as-compare': row.key, 'aria-label': t('compare.remove') });
        });

        const body = el('tbody', '', table);
        const pieces = slot?.visible && isNumber(slot.puzzle?.pieces) ? slot.puzzle.pieces : '';

        for (const metric of compareTable(rows, slot?.visible ? slot.key : null, { entrant: model.entrant, predictions: model.predictions })) {
            // Nothing about a hidden puzzle: its rows are left out
            if (!slot?.visible && !['rating', 'skill', 'result'].includes(metric.id)) {
                continue;
            }

            const tr = el('tr', `as-compare__row as-compare__row--${metric.id}`, body);
            el('th', 'as-compare__metric', tr, t(METRIC_LABELS[metric.id], { brand: slot?.puzzle?.brand ?? '', pieces, count: '' }));
            metric.values.forEach((value, index) => {
                const cell = el('td', `as-compare__cell${index >= 2 ? ' as-compare__col--extra' : ''}${metric.best.includes(index) ? ' as-compare__cell--best' : ''}`, tr);

                if (metric.id === 'prediction' && value === null && !rows[index].isTeam && rows[index].members[0]?.analysable) {
                    const prediction = predictionOf({ entrant: model.entrant, predictions: model.predictions }, rows[index].key, 0, slot?.key ?? null);

                    if (prediction.status === 'pending') {
                        spinner(cell);

                        return;
                    }

                    if (prediction.status === 'optedOut') {
                        optedOut(cell);

                        return;
                    }
                }

                if ((metric.id === 'rating' || metric.id === 'skill') && rows[index].rankingOptedOut) {
                    optedOut(cell);

                    return;
                }

                compareValue(metric, value, cell);
            });
        }

        return table;
    }

    function renderDetailPanel(rows, slot, model) {
        detailPanel.replaceChildren();
        const { view } = model;
        const compared = view.compare.map((key) => rows.find((row) => row.key === key)).filter((row) => row !== undefined);

        if (compared.length >= 2) {
            // The compare view's own head: a phone shows it as a full-screen sheet, which needs a way out (×)
            const head = el('header', 'as-compare-head', detailPanel);
            el('h2', 'as-compare-head__title', head, t('compare', { count: compared.length, max: 4 }));
            button('as-compare-head__clear', head, t('compare.clear'), { 'data-as-action': 'clearCompare' });
            button('as-entrant__close', head, '×', { 'data-as-action': 'close', 'aria-label': t('close') });
            detailPanel.appendChild(renderCompare(compared, slot, model));

            return;
        }

        const selected = rows.find((row) => row.key === view.selected);

        if (selected === undefined) {
            el('p', 'as-empty', detailPanel, rows.length === 0 ? t('empty.no_entrants') : t('empty.select'));

            return;
        }

        detailPanel.appendChild(renderDetail(selected, slot, model));

        if (compared.length === 1) {
            el('p', 'as-muted as-compare-hint', detailPanel, t('compare.pick_more'));
        }
    }

    // ---- puzzle panel

    function renderPuzzle(slot, model, rows) {
        puzzlePanel.replaceChildren();

        if (slot === null) {
            el('p', 'as-empty', puzzlePanel, t('empty.no_puzzle'));

            return;
        }

        const head = el('header', 'as-puzzle__head', puzzlePanel);
        el('span', 'as-puzzle__label', head, slot.label);

        // A hidden slot: the label and when it comes - nothing else (§5)
        if (!slot.visible) {
            el('p', 'as-puzzle__hidden', puzzlePanel, t('slot.hidden'));

            if (slot.revealsAtMs !== null) {
                countdowns.push({ node: el('p', 'as-countdown', puzzlePanel), atMs: slot.revealsAtMs });
            }

            return;
        }

        const puzzle = slot.puzzle;
        const image = safeImageUrl(puzzle.imageUrl);

        if (image !== null) {
            const img = el('img', 'as-puzzle__image', puzzlePanel);
            img.setAttribute('src', image);
            img.setAttribute('alt', typeof puzzle.name === 'string' ? puzzle.name : '');
            img.setAttribute('loading', 'lazy');
            img.setAttribute('referrerpolicy', 'no-referrer');
        } else {
            el('div', 'as-puzzle__image as-puzzle__image--none', puzzlePanel, t('slot.image_later'));
        }

        el('h2', 'as-puzzle__name', head, puzzle.name ?? '');
        el('div', 'as-puzzle__meta', puzzlePanel, [puzzle.brand ?? '', isNumber(puzzle.pieces) ? t('puzzle.pieces', { count: count(puzzle.pieces) }) : '']
            .filter((part) => part !== '').join(' · '));

        if (!isObject(model.analytics)) {
            spinner(puzzlePanel);

            return;
        }

        const stats = model.analytics.puzzles?.[slot.key];
        const facts = el('dl', 'as-facts', puzzlePanel);
        const difficulty = fact(facts, t('difficulty'), '');
        const tier = stats?.difficulty?.tier;

        if (isObject(stats?.difficulty) && typeof tier === 'string' && /^[a-z_]+$/.test(tier)) {
            el('span', `as-difficulty as-difficulty--${tier}`, difficulty, t(`difficulty.${tier}`));
            el('span', 'as-difficulty__score', difficulty, formatDecimal(stats.difficulty.score, locale));

            if (typeof stats.difficulty.confidence === 'string') {
                el('span', 'as-muted', difficulty, t('difficulty.confidence', { confidence: stats.difficulty.confidence }));
            }
        } else {
            difficulty.textContent = t('empty.not_rated');
        }

        if (!isObject(stats) || !(stats.solvers > 0)) {
            el('p', 'as-empty', puzzlePanel, t('empty.nobody_logged'));

            return;
        }

        fact(facts, t('stats.solvers'), count(stats.solvers));
        fact(facts, t('stats.times_logged'), count(stats.timesLogged));
        fact(facts, t('stats.median'), duration(stats.medianSeconds));

        if (isObject(stats.percentiles)) {
            fact(facts, t('stats.percentiles'), ['p10', 'p25', 'p50', 'p75', 'p90']
                .map((name) => `${name.toUpperCase()} ${duration(stats.percentiles[name])}`).join(' · '));
        }

        const histogram = Array.isArray(stats.histogram) ? stats.histogram.filter((bucket) => isObject(bucket) && isNumber(bucket.count)) : [];

        if (histogram.length > 0) {
            const chart = el('div', 'as-histogram', puzzlePanel);
            chart.setAttribute('role', 'img');
            chart.setAttribute('aria-label', t('stats.histogram'));
            const max = Math.max(1, ...histogram.map((bucket) => bucket.count));

            for (const bucket of histogram) {
                const bar = el('span', 'as-histogram__bar', chart);
                bar.style.height = `${Math.round((bucket.count / max) * 100)}%`;
                bar.setAttribute('title', `${duration(bucket.fromSeconds)}: ${count(bucket.count)}`);
            }
        }

        if (isObject(stats.firstTries)) {
            fact(facts, t('stats.first_tries'), count(stats.firstTries.count));
            fact(facts, t('stats.first_try_median'), duration(stats.firstTries.medianSeconds));
            fact(facts, t('stats.fastest_first_try'), duration(stats.firstTries.fastestSeconds));
        }

        if (isObject(stats.group)) {
            fact(facts, t('stats.group'), count(stats.group.solvers));
            fact(facts, t('stats.group_median'), duration(stats.group.medianSeconds));
        }

        const top = Array.isArray(stats.top) ? stats.top.filter(isObject) : [];

        if (top.length > 0) {
            const box = el('section', 'as-top10', puzzlePanel);
            el('h3', 'as-top10__title', box, t('stats.top'));
            const items = el('ol', 'as-top10__list', box);
            const keys = new Set(rows.map((row) => row.key));

            for (const entry of top) {
                const inRound = typeof entry.inRound === 'string' && keys.has(entry.inRound);
                const item = el('li', `as-top10__item${inRound ? ' as-top10__item--in-round' : ''}`, items);
                el('span', 'as-top10__place', item, isNumber(entry.place) ? String(entry.place) : '');

                if (typeof entry.name === 'string' && entry.name !== '') {
                    personName({ name: entry.name, country: entry.country, profile: entry.profile }, item);
                } else {
                    el('span', 'as-person__name as-muted', item, t('private_puzzler'));
                }

                el('span', 'as-top10__time', item, duration(entry.seconds));

                if (inRound) {
                    const star = el('span', 'as-top10__in-round', item, '★');
                    star.setAttribute('title', t('stats.in_round'));
                    star.setAttribute('data-as-entry', entry.inRound);
                }
            }
        }
    }

    // ---- live results

    function renderLive(state, rows) {
        livePanel.replaceChildren();
        el('h2', 'as-live__title', livePanel, t('tab.live'));

        // R8: an ordered list only for a published round (official ranks)
        if (state?.round?.published === true) {
            const ranking = officialRanking(state, rows);

            if (ranking.length === 0) {
                el('p', 'as-empty', livePanel, t('empty.no_results'));

                return;
            }

            const items = el('ol', 'as-live__list', livePanel);

            for (const row of ranking) {
                const item = el('li', 'as-live__item', items);
                item.setAttribute('data-as-entry', row.key);
                el('span', 'as-live__name', item, row.name);
                resultCell(row.result, item);
            }

            return;
        }

        const confirmed = resultsCount(state);

        if (state?.round?.liveResults === true && confirmed > 0) {
            el('p', 'as-live__count', livePanel, t('live.count', { count: count(confirmed) }));
            el('p', 'as-muted as-live__note', livePanel, t('result.live_note'));

            return;
        }

        el('p', 'as-empty', livePanel, t('empty.no_results'));
    }

    // ---- public

    return {
        search,

        /**
         * @param {{state: object|null, analytics: object|null, entrant: object|null, predictions: object|null, view: object, nowMs: number}} model
         */
        render(model) {
            current = model;
            countdowns = [];
            const { state, view } = model;
            const slot = activeSlot(state, view.puzzle);
            const rows = entrantRows(state, model.analytics, slot?.visible ? slot.key : null);
            const shown = visibleRows(rows, { query: view.query, sort: view.sort, filter: view.filter, state, slot });

            root.setAttribute('data-as-tab', ['entrants', 'puzzle', 'live'].includes(view.tab) ? view.tab : 'entrants');
            root.classList.toggle('as--sheet-open', view.open === true && view.selected !== null);
            root.classList.toggle('as--comparing', view.compare.length >= 2);
            tabButtons.forEach((node) => node.setAttribute('aria-pressed', node.getAttribute('data-as-tab') === view.tab ? 'true' : 'false'));

            renderTop(state, slot, view);
            renderList(state, rows, shown, slot, view);
            renderDetailPanel(rows, slot, model);
            renderPuzzle(slot, model, rows);
            renderLive(state, rows);

            help.hidden = view.help !== true;
            notice.textContent = view.notice === 'compare_full' ? t('compare.full', { max: 4 }) : '';
            drawClock(model.nowMs);

            return { rows, shown, slot };
        },

        /**
         * The clock and the reveal countdowns only - every second between renders.
         */
        renderClock(nowMs) {
            drawClock(nowMs);
        },
    };
}
