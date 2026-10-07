/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { officialResultsRequest, newClientId, isGone } from '../official_results_api.js';
import { OfficialResultsEvents } from '../official_results_events.js';
import { chooseTranslation } from '../translation_choice.js';
import {
    clearAssignments,
    highestTable,
    holderOf,
    matchesQuery,
    mergeOrder,
    moveInLists,
    nameComparator,
    numbersOf,
    parseTableNumber,
    proposalAssignments,
    proposalCoversEntrants,
    renumberAssignments,
    renumberStart,
    seatRestAssignments,
    seenNumber,
    splitByTable,
    swapAssignments,
    takeOverAssignments,
    typedNumberWrite,
    undoAssignments,
    withFrom,
} from '../seating.js';

const RESYNC_EVERY_MS = 60000;

/**
 * The seating page of a round (templates/seating/round_seating.html.twig, docs/features/competitions-management/seating.md):
 * two lists - "No table yet" on top, then the seated entries by table number - with a table number input per entry,
 * swapping, drag and drop / Move up / Move down (an order that waits for "Renumber"), auto-assign as a proposal first,
 * and "Clear all". A typed number is one RecordRoundResults change (three-way checked against what this page saw);
 * every other action is one AssignTableNumbers write, validated as a whole - nothing is ever half applied - and can be
 * undone from its toast. Other organisers' changes arrive over Mercure - the page's own stream with the token its state
 * carries (official_results_events.js).
 *
 * The pure half (order, the writes each action sends) is assets/seating.js.
 */
export default class extends Controller {
    static targets = [
        'readiness', 'readinessBox', 'readinessProgress', 'readinessNote', 'onPanel', 'offPanel',
        'autoButton', 'renumberButton', 'renumberLabel', 'clearButton', 'status',
        'autoPanel', 'sourceInput', 'orderInput', 'orderFieldset', 'orderLegend', 'firstInput', 'mspHelp', 'proposal',
        'applyButton', 'drawAgainButton',
        'filter', 'filterNote', 'orderBar', 'orderBarRenumber', 'swapBar', 'swapText', 'empty', 'nothingFound',
        'lists', 'unseatedSection', 'unseatedHeading', 'seatRestButton', 'unseatedList', 'seatedHeading', 'seatedList',
        'toast', 'gone',
    ];

    static values = {
        roundId: String,
        locale: String,
        stateUrl: String,
        recordUrl: String,
        assignUrl: String,
        usageUrl: String,
        proposalUrl: String,
        signInUrl: String,
        csrfToken: String,
        entries: Array,
        round: Object,
        texts: Object,
        propose: String,
        // The live updates subscription ({} for an online event - no seating, no stream)
        mercure: Object,
    };

    connect() {
        this.compareNames = nameComparator(this.localeValue);
        this.round = this.roundValue;
        this.byRef = new Map();
        this.rows = new Map();
        this.problems = new Map();
        this.pendingNumbers = new Map();
        this.orderDirty = false;
        this.resortPending = false;
        this.swapRef = null;
        this.query = '';
        this.busy = false;
        this.proposal = null;
        this.proposalRequest = 0;
        this.proposalNote = null;
        this.hiddenAt = null;
        this.dragging = false;
        this.sortables = [];
        // Counts merges of newer entries (answers, Mercure) - a state fetched meanwhile may be older than them
        this.dataGeneration = 0;
        this.refreshSequence = 0;
        // The round was deleted while the page was open (goneAway)
        this.gone = false;

        this.setEntries(this.entriesValue);
        this.resetOrder();
        this.render();
        this.setupSortable();

        if (this.proposeValue !== '') {
            this.openAuto(this.proposeValue === 'auto' ? null : this.proposeValue);
        }

        this.events = new OfficialResultsEvents({
            subscription: this.mercureValue,
            onMessage: (data) => this.mercureMessage({ detail: data }),
            refresh: () => this.refresh(),
        });
        this.events.start();

        // The last safety net under the live updates - the page re-reads the round once a minute while it is shown, like
        // the results desk, so a bulk write is never built from stale numbers for long
        this.resyncInterval = setInterval(() => {
            if (document.visibilityState === 'visible' && !this.busy) {
                this.refresh();
            }
        }, RESYNC_EVERY_MS);
    }

    disconnect() {
        this.events?.close();
        this.sortables.forEach((sortable) => sortable.destroy());
        this.sortables = [];
        clearTimeout(this.toastTimer);
        clearInterval(this.resyncInterval);
    }

    // ---- texts

    t(key, params = {}) {
        const text = this.textsValue[key];
        let message;

        if (text !== null && typeof text === 'object') {
            message = chooseTranslation(text.message, Number(params['%count%'] ?? 0), text.locale) ?? text.message;
        } else {
            message = typeof text === 'string' ? text : key;
        }

        for (const [name, value] of Object.entries(params)) {
            message = message.replaceAll(name, String(value));
        }

        return message;
    }

    // ---- data

    setEntries(entries) {
        this.byRef = new Map(entries.map((entry) => [entry.ref, entry]));
    }

    mergeEntries(entries) {
        for (const entry of entries ?? []) {
            if (entry && typeof entry.ref === 'string') {
                this.byRef.set(entry.ref, entry);
            }
        }

        this.dataGeneration++;
    }

    resetOrder() {
        const { unseated, seated } = splitByTable([...this.byRef.values()], this.compareNames);
        this.unseated = unseated;
        this.seated = seated;
        this.orderDirty = false;
        this.resortPending = false;
    }

    /**
     * Data changed (an answer, another organiser): the lists follow the table numbers - unless the organiser has their
     * own order waiting for "Renumber", or is typing table numbers in the list (rows never jump under the cursor; they
     * settle when the focus leaves the list). `resort` = the organiser's own action renumbered entries: follow at once.
     */
    afterDataChange({ resort = false } = {}) {
        if (this.orderDirty || (!resort && this.focusInLists())) {
            ({ unseated: this.unseated, seated: this.seated } = mergeOrder(this.unseated, this.seated, this.byRef));
            this.resortPending = !this.orderDirty;
        } else {
            this.resetOrder();
        }

        for (const ref of [...this.problems.keys()]) {
            if (!this.byRef.has(ref)) {
                this.problems.delete(ref);
            }
        }

        if (this.swapRef !== null && !this.byRef.has(this.swapRef)) {
            this.swapRef = null;
        }

        this.render();

        if (this.proposal !== null) {
            this.renderProposal();
        }
    }

    /**
     * The organiser is typing a table number in the list.
     */
    focusInLists() {
        const focused = document.activeElement;

        return this.hasListsTarget && focused instanceof HTMLElement && focused.classList.contains('seating-number') && this.listsTarget.contains(focused);
    }

    listFocusOut(event) {
        if (event.relatedTarget instanceof Node && this.listsTarget.contains(event.relatedTarget)) {
            return;
        }

        if (this.resortPending && !this.orderDirty) {
            // After the blur's own change event has been handled
            setTimeout(() => {
                if (this.resortPending && !this.orderDirty && !this.focusInLists()) {
                    this.resetOrder();
                    this.render();
                }
            }, 0);
        }
    }

    /**
     * The round's state again. An answer overtaken by a newer refresh is dropped, and one that may be older than
     * entries merged while it was on its way (an answer of our own write, a Mercure update) is asked for again - a slow
     * GET never undoes newer numbers (review2-b nit). Every answer hands its live updates subscription on (a fresh
     * token; signed out / no rights stop the stream). Resolves to the answer's kind.
     */
    async refresh(attempt = 0) {
        // The round is gone - nothing to ask for any more
        if (this.gone) {
            return 'client';
        }

        const tries = Number.isInteger(attempt) ? attempt : 0;
        const sequence = ++this.refreshSequence;
        const generation = this.dataGeneration;
        const result = await officialResultsRequest(this.stateUrlValue);

        if (!this.element.isConnected) {
            return result.kind;
        }

        if (result.kind === 'ok') {
            this.events.update(result.data.mercure ?? null);
        } else if (result.kind === 'auth' || result.kind === 'forbidden') {
            this.events.suspend();
        } else if (isGone(result)) {
            this.goneAway();
        }

        if (sequence !== this.refreshSequence || result.kind !== 'ok' || !Array.isArray(result.data.entries)) {
            return result.kind;
        }

        if (generation !== this.dataGeneration) {
            if (tries < 3) {
                return this.refresh(tries + 1);
            }

            return result.kind;
        }

        this.setEntries(result.data.entries);

        if (result.data.round) {
            this.round = result.data.round;
        }

        this.afterDataChange();

        return result.kind;
    }

    mercureMessage(event) {
        const detail = event.detail;

        if (!detail || typeof detail.type !== 'string' || String(detail.roundId).toLowerCase() !== this.roundIdValue.toLowerCase()) {
            return;
        }

        if (detail.type === 'official_results.entries') {
            this.mergeEntries(detail.entries);

            if (detail.round) {
                this.round = detail.round;
            }

            this.afterDataChange();
        } else if (detail.type === 'official_results.refresh') {
            this.refresh();
        } else if (detail.type === 'official_results.round' && detail.round) {
            this.round = detail.round;
            this.render();
        }
    }

    visibilityChanged() {
        if (document.visibilityState === 'hidden') {
            this.hiddenAt = Date.now();

            return;
        }

        // Mercure misses what happened while a laptop slept or the Wi-Fi was gone
        if (this.hiddenAt !== null && Date.now() - this.hiddenAt > 20000) {
            this.refresh();
        }

        this.hiddenAt = null;
    }

    beforeUnload(event) {
        if (this.orderDirty) {
            event.preventDefault();
            event.returnValue = '';
        }
    }

    beforeVisit(event) {
        if (this.orderDirty && !window.confirm(this.t('leaveUnsaved'))) {
            event.preventDefault();
        }
    }

    escape() {
        if (this.swapRef !== null) {
            this.cancelSwap();
        }
    }

    /**
     * The round switch - another round's seating page (leaving asks first while an own order waits for "Renumber").
     */
    switchRound(event) {
        const url = event.currentTarget.value;

        if (url) {
            window.location.assign(url);
        }
    }

    // ---- rendering

    render() {
        // Rows moved around lose the focus - it goes back where it was
        const focused = document.activeElement;

        this.draw();

        if (focused instanceof HTMLElement && focused !== document.activeElement && focused.isConnected && this.element.contains(focused) && !focused.disabled) {
            focused.focus({ preventScroll: true });
        }
    }

    draw() {
        const entries = [...this.byRef.values()];
        const total = entries.length;
        const assigned = entries.filter((entry) => entry.tableNumber !== null).length;
        const off = this.round?.tableNumbersOff === true;

        this.offPanelTarget.hidden = !off;
        this.onPanelTarget.hidden = off;

        this.readinessProgressTarget.textContent = this.t('readinessProgress', { '%assigned%': assigned, '%total%': total });
        this.readinessNoteTarget.textContent = total > 0 && assigned >= total ? `- ${this.t('readinessDone')}` : `- ${this.t('readinessRecommended')}`;
        this.readinessBoxTarget.classList.toggle('alert-info', !(total > 0 && assigned >= total));
        this.readinessBoxTarget.classList.toggle('alert-light', total > 0 && assigned >= total);
        this.readinessBoxTarget.classList.toggle('border', total > 0 && assigned >= total);
        // The one rule (SeatingReadiness, `tablesReadiness`): a round under way or over does not nag
        this.readinessTarget.hidden = off || this.round?.tablesReadiness !== true;

        const start = renumberStart(this.seated, this.byRef);
        const renumberText = this.t('renumber', { '%first%': start, '%last%': start + Math.max(this.seated.length, 1) - 1 });
        this.renumberLabelTarget.textContent = renumberText;
        this.renumberButtonTarget.disabled = this.busy || this.seated.length === 0;
        this.orderBarRenumberTarget.textContent = renumberText;
        this.orderBarRenumberTarget.disabled = this.busy;
        this.orderBarTarget.hidden = !this.orderDirty;
        this.clearButtonTarget.disabled = this.busy || assigned === 0;
        this.applyButtonTarget.disabled = this.busy || !this.proposalApplicable();

        this.unseatedHeadingTarget.textContent = this.t('unseatedHeading', { '%count%': this.unseated.length });
        this.seatedHeadingTarget.textContent = this.t('seatedHeading', { '%count%': this.seated.length });
        this.unseatedSectionTarget.hidden = this.unseated.length === 0 && !this.orderDirty && !this.dragging;

        const showSeatRest = this.unseated.length > 0 && !this.orderDirty;
        this.seatRestButtonTarget.hidden = !showSeatRest;
        this.seatRestButtonTarget.disabled = this.busy;

        if (showSeatRest) {
            const first = highestTable(this.byRef) + 1;
            this.seatRestButtonTarget.textContent = this.t('seatRest', { '%count%': this.unseated.length, '%first%': first, '%last%': first + this.unseated.length - 1 });
        }

        this.emptyTarget.hidden = total > 0;

        const swapEntry = this.swapRef !== null ? this.byRef.get(this.swapRef) : null;
        this.swapBarTarget.hidden = !swapEntry;

        if (swapEntry) {
            this.swapTextTarget.textContent = this.t('swapPick', { '%name%': swapEntry.displayName });
        }

        this.renderRows(this.unseatedListTarget, this.unseated);
        this.renderRows(this.seatedListTarget, this.seated);

        for (const [ref, row] of this.rows) {
            if (!this.byRef.has(ref)) {
                row.remove();
                this.rows.delete(ref);
            }
        }

        let visible = 0;
        for (const [ref, row] of this.rows) {
            const matches = matchesQuery(this.byRef.get(ref), this.query);
            row.hidden = !matches;
            visible += matches ? 1 : 0;
        }

        this.nothingFoundTarget.hidden = this.query === '' || visible > 0 || total === 0;
        this.filterNoteTarget.hidden = this.query === '';
        this.updateSortable();
    }

    renderRows(list, refs) {
        refs.forEach((ref, index) => {
            const entry = this.byRef.get(ref);

            if (!entry) {
                return;
            }

            let row = this.rows.get(ref);

            if (!row) {
                row = this.createRow(ref);
                this.rows.set(ref, row);
            }

            this.updateRow(row, entry);

            const current = list.children[index] ?? null;

            if (current !== row) {
                list.insertBefore(row, current);
            }
        });
    }

    createRow(ref) {
        const row = document.createElement('li');
        row.className = 'list-group-item seating-row';
        row.dataset.ref = ref;

        const handle = document.createElement('span');
        handle.className = 'seating-handle';
        handle.dataset.dragHandle = '';
        handle.innerHTML = '<i class="bi bi-grip-vertical" aria-hidden="true"></i>';

        const input = document.createElement('input');
        input.type = 'text';
        input.inputMode = 'numeric';
        input.autocomplete = 'off';
        input.maxLength = 4;
        input.className = 'form-control seating-number';
        input.dataset.action = 'change->round-seating#numberChanged keydown->round-seating#numberKeydown';

        const entrant = document.createElement('div');
        entrant.className = 'seating-entrant';
        entrant.innerHTML = '<div class="seating-name"><span class="seating-flag"></span><span class="seating-display-name"></span> <small class="text-muted seating-code"></small></div>'
            + '<div class="seating-members small text-muted"></div>'
            + '<div class="seating-problem small text-danger" role="alert"></div>';

        const actions = document.createElement('div');
        actions.className = 'seating-actions';
        actions.append(
            this.iconButton('up', 'bi-arrow-up', 'round-seating#moveUp'),
            this.iconButton('down', 'bi-arrow-down', 'round-seating#moveDown'),
            this.iconButton('swap', 'bi-arrow-left-right', 'round-seating#swap'),
        );

        row.append(handle, input, entrant, actions);

        return row;
    }

    problemButton(text, action) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-link btn-sm p-0 ms-2 align-baseline';
        button.dataset.action = action;
        button.textContent = text;

        return button;
    }

    iconButton(name, icon, action) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-outline-secondary seating-icon-button';
        button.dataset.move = name;
        button.dataset.action = action;
        button.innerHTML = `<i class="bi ${icon}" aria-hidden="true"></i>`;

        return button;
    }

    updateRow(row, entry) {
        const name = entry.displayName;
        const problem = this.problems.get(entry.ref) ?? null;
        const input = row.querySelector('.seating-number');
        const saving = this.pendingNumbers.has(entry.ref);

        row.classList.toggle('is-swap-source', this.swapRef === entry.ref);
        row.classList.toggle('has-problem', problem !== null);
        row.classList.toggle('is-saving', saving);
        row.querySelector('.seating-handle').title = this.t('drag', { '%name%': name });

        input.setAttribute('aria-label', this.t('tableFor', { '%name%': name }));
        input.placeholder = this.t('noTable');
        input.disabled = this.busy;

        if (document.activeElement !== input && !saving) {
            if (problem?.typed !== undefined) {
                input.value = problem.typed;
            } else {
                input.value = entry.tableNumber !== null ? String(entry.tableNumber) : '';
                // What the organiser sees when they start typing - the `from` of their write (typedNumberWrite)
                input.dataset.seen = input.value;
            }
        }

        const flag = row.querySelector('.seating-flag');
        const country = entry.kind === 'person' ? entry.country : null;
        flag.className = country ? `seating-flag shadow-custom fi fi-${country} me-1` : 'seating-flag';

        row.querySelector('.seating-display-name').textContent = name;
        row.querySelector('.seating-code').textContent = entry.playerCode ? `#${String(entry.playerCode).toUpperCase()}` : '';

        const members = row.querySelector('.seating-members');
        members.textContent = entry.kind === 'team' && entry.name !== null ? (entry.members ?? []).map((member) => member.name).join(', ') : '';
        members.hidden = members.textContent === '';

        const problemElement = row.querySelector('.seating-problem');
        problemElement.replaceChildren();

        if (problem !== null) {
            problemElement.append(document.createTextNode(problem.message));

            if (problem.meanwhile) {
                problemElement.append(
                    this.problemButton(this.t('keepMine'), 'round-seating#keepMineNumber'),
                    this.problemButton(this.t('takeTheirs'), 'round-seating#takeTheirsNumber'),
                );
            }

            if (problem.takeOver) {
                const takeOver = document.createElement('button');
                takeOver.type = 'button';
                takeOver.className = 'btn btn-link btn-sm p-0 ms-2 align-baseline';
                takeOver.dataset.action = 'round-seating#takeOver';
                takeOver.textContent = this.t('swapThem');
                problemElement.append(takeOver);
            }
        }

        problemElement.hidden = problem === null;

        const isFirst = this.unseated.length > 0 ? this.unseated[0] === entry.ref : this.seated[0] === entry.ref;
        const isLast = this.seated.length > 0 ? this.seated[this.seated.length - 1] === entry.ref : this.unseated[this.unseated.length - 1] === entry.ref;
        const up = row.querySelector('[data-move="up"]');
        const down = row.querySelector('[data-move="down"]');
        const swap = row.querySelector('[data-move="swap"]');

        up.disabled = this.busy || isFirst || this.query !== '';
        down.disabled = this.busy || isLast || this.query !== '';
        this.label(up, this.t('moveUp', { '%name%': name }));
        this.label(down, this.t('moveDown', { '%name%': name }));

        const swapEntry = this.swapRef !== null ? (this.byRef.get(this.swapRef) ?? null) : null;
        const isSwapSource = swapEntry !== null && swapEntry.ref === entry.ref;
        const isSwapTarget = swapEntry !== null && !isSwapSource;

        if (isSwapSource) {
            this.label(swap, this.t('swapCancel'));
        } else {
            this.label(swap, isSwapTarget ? this.t('swapHere', { '%name%': swapEntry.displayName }) : this.t('swap', { '%name%': name }));
        }

        swap.setAttribute('aria-pressed', isSwapSource ? 'true' : 'false');
        swap.classList.toggle('active', isSwapSource);
        swap.classList.toggle('btn-primary', isSwapTarget);
        swap.classList.toggle('btn-outline-secondary', !isSwapTarget);
        // Two entries without a table have nothing to swap
        swap.disabled = this.busy || (isSwapTarget && swapEntry.tableNumber === null && entry.tableNumber === null);
    }

    label(button, text) {
        button.setAttribute('aria-label', text);
        button.title = text;
    }

    refOf(event) {
        return event.currentTarget.closest('[data-ref]')?.dataset.ref ?? null;
    }

    // ---- drag and drop, move up / down, renumber

    async setupSortable() {
        const { default: Sortable } = await import('sortablejs');

        if (!this.element.isConnected) {
            return;
        }

        const options = {
            group: `seating-${this.roundIdValue}`,
            handle: '[data-drag-handle]',
            animation: 150,
            ghostClass: 'seating-ghost',
            onStart: (event) => {
                // "No table yet" opens above the seated list as the drop target - the page scrolls by what it adds, so
                // the list stays where it was under the pointer (browser verification: the first drop landed in it)
                const before = event.item.getBoundingClientRect().top;

                this.dragging = true;
                this.listsTarget.classList.add('is-dragging');
                this.unseatedSectionTarget.hidden = false;

                const shift = event.item.getBoundingClientRect().top - before;

                if (shift !== 0) {
                    window.scrollBy({ top: shift, behavior: 'instant' });
                }
            },
            onEnd: () => {
                // ... and when it closes again, the seated list stays put as well
                const before = this.seatedListTarget.getBoundingClientRect().top;

                this.dragging = false;
                this.listsTarget.classList.remove('is-dragging');
                this.dragged();

                const shift = this.seatedListTarget.getBoundingClientRect().top - before;

                if (shift !== 0) {
                    window.scrollBy({ top: shift, behavior: 'instant' });
                }
            },
        };

        this.sortables = [
            Sortable.create(this.unseatedListTarget, options),
            Sortable.create(this.seatedListTarget, options),
        ];
        this.updateSortable();
    }

    updateSortable() {
        const disabled = this.busy || this.query !== '' || this.swapRef !== null;
        this.sortables.forEach((sortable) => sortable.option('disabled', disabled));
        this.listsTarget.classList.toggle('drag-disabled', disabled);
    }

    dragged() {
        const unseated = [...this.unseatedListTarget.children].map((row) => row.dataset.ref);
        const seated = [...this.seatedListTarget.children].map((row) => row.dataset.ref);

        if (unseated.join() !== this.unseated.join() || seated.join() !== this.seated.join()) {
            this.unseated = unseated;
            this.seated = seated;
            this.orderDirty = true;
        }

        this.render();
    }

    moveUp(event) {
        this.move(event, 'up');
    }

    moveDown(event) {
        this.move(event, 'down');
    }

    move(event, direction) {
        const ref = this.refOf(event);

        if (ref === null) {
            return;
        }

        ({ unseated: this.unseated, seated: this.seated } = moveInLists(this.unseated, this.seated, ref, direction));
        this.orderDirty = true;
        this.render();

        // The focus stays with the moved entry - on the other button once this one is disabled at the end of the list
        const row = this.rows.get(ref);
        const same = row?.querySelector(`[data-move="${direction}"]`);
        const other = row?.querySelector(`[data-move="${direction === 'up' ? 'down' : 'up'}"]`);
        (same && !same.disabled ? same : other)?.focus();
    }

    undoOrder() {
        this.resetOrder();
        this.render();
    }

    async renumber() {
        const start = renumberStart(this.seated, this.byRef);
        const assignments = renumberAssignments(this.unseated, this.seated, this.byRef, start);

        if (assignments.length === 0) {
            this.resetOrder();
            this.render();

            return;
        }

        await this.assign(assignments, this.t('toastRenumbered'));
    }

    async seatRest() {
        await this.assign(seatRestAssignments(this.unseated, this.byRef), this.t('toastSeatedRest'));
    }

    async clearAll() {
        const count = [...this.byRef.values()].filter((entry) => entry.tableNumber !== null).length;

        if (count === 0 || !window.confirm(this.t('clearAllConfirm', { '%count%': count }))) {
            return;
        }

        await this.assign(clearAssignments(this.byRef), this.t('toastCleared', { '%count%': count }));
    }

    // ---- swapping

    swap(event) {
        const ref = this.refOf(event);

        if (ref === null) {
            return;
        }

        if (this.swapRef === null) {
            this.swapRef = ref;
            this.render();

            return;
        }

        if (this.swapRef === ref) {
            this.cancelSwap();

            return;
        }

        const a = this.byRef.get(this.swapRef);
        const b = this.byRef.get(ref);
        this.swapRef = null;

        if (!a || !b || (a.tableNumber === null && b.tableNumber === null)) {
            this.render();

            return;
        }

        this.assign(swapAssignments(a, b), this.t('toastSwapped'));
    }

    cancelSwap() {
        const ref = this.swapRef;
        this.swapRef = null;
        this.render();
        this.rows.get(ref)?.querySelector('[data-move="swap"]')?.focus();
    }

    takeOver(event) {
        const ref = this.refOf(event);
        const problem = ref !== null ? this.problems.get(ref) : null;
        const entry = ref !== null ? this.byRef.get(ref) : null;
        const holder = problem?.takeOver ? this.byRef.get(problem.takeOver.holder) : null;

        if (!entry || !holder) {
            return;
        }

        this.assign(takeOverAssignments(entry, problem.takeOver.number, holder), this.t('toastSwapped'));
    }

    // ---- typing a table number

    numberKeydown(event) {
        const input = event.currentTarget;

        if (event.key === 'Enter') {
            event.preventDefault();
            this.saveNumber(input);
            this.focusNextNumber(input);
        } else if (event.key === 'Escape') {
            const ref = this.refOf(event);
            const entry = ref !== null ? this.byRef.get(ref) : null;
            this.problems.delete(ref);
            input.value = entry && entry.tableNumber !== null ? String(entry.tableNumber) : '';
            input.dataset.seen = input.value;
            this.render();
        }
    }

    numberChanged(event) {
        this.saveNumber(event.currentTarget);
    }

    focusNextNumber(input) {
        const inputs = [...this.listsTarget.querySelectorAll('.seating-row:not([hidden]) .seating-number')];
        const next = inputs[inputs.indexOf(input) + 1];

        if (next) {
            next.focus();
            next.select();
        } else {
            input.blur();
        }
    }

    saveNumber(input) {
        const ref = input.closest('[data-ref]')?.dataset.ref;
        const entry = ref ? this.byRef.get(ref) : null;

        if (!entry) {
            return;
        }

        const parsed = parseTableNumber(input.value);

        if (parsed.error) {
            this.problems.set(ref, { message: this.t('invalidNumber'), typed: input.value });
            this.render();

            return;
        }

        if (this.pendingNumbers.has(ref) && this.pendingNumbers.get(ref) === parsed.number) {
            return;
        }

        const decision = typedNumberWrite(seenNumber(input.dataset.seen, entry.tableNumber), entry.tableNumber, parsed.number);

        if (decision.action === 'nothing') {
            if (this.problems.delete(ref)) {
                this.render();
            }

            return;
        }

        if (decision.action === 'meanwhile') {
            // Another organiser changed this entry's table while this one was typing: they decide, nothing goes over it
            this.problems.set(ref, this.meanwhileProblem(decision.current, input.value, parsed.number));
            this.render();

            return;
        }

        this.writeNumber(entry, parsed.number, input.value);
    }

    /**
     * "Somebody else saved table 9 meanwhile." with Keep mine / Take theirs - the typed value stays in the input.
     */
    meanwhileProblem(current, typed, number) {
        return {
            message: current === null || current === undefined ? this.t('removedMeanwhile') : this.t('savedMeanwhile', { '%number%': current }),
            typed,
            meanwhile: { number, current: current ?? null },
        };
    }

    keepMineNumber(event) {
        const ref = this.refOf(event);
        const problem = ref !== null ? this.problems.get(ref) : null;
        const entry = ref !== null ? this.byRef.get(ref) : null;

        if (!problem?.meanwhile || !entry) {
            return;
        }

        // The organiser has seen the other number now - their own goes over it
        const input = this.rows.get(ref)?.querySelector('.seating-number');
        if (input) {
            input.dataset.seen = entry.tableNumber !== null ? String(entry.tableNumber) : '';
        }

        this.problems.delete(ref);
        this.writeNumber(entry, problem.meanwhile.number, problem.typed ?? '');
    }

    takeTheirsNumber(event) {
        const ref = this.refOf(event);
        const entry = ref !== null ? this.byRef.get(ref) : null;

        if (!entry || !this.problems.get(ref)?.meanwhile) {
            return;
        }

        this.problems.delete(ref);
        const input = this.rows.get(ref)?.querySelector('.seating-number');
        if (input) {
            input.value = entry.tableNumber !== null ? String(entry.tableNumber) : '';
            input.dataset.seen = input.value;
        }

        this.render();
    }

    /**
     * The typed number goes to the server - or, when somebody has that table, the swap is offered instead.
     */
    writeNumber(entry, number, typed) {
        const ref = entry.ref;

        if (number === entry.tableNumber) {
            this.render();

            return;
        }

        // Somebody has that table: offer the swap instead of a refusal
        const holder = number !== null ? holderOf(this.byRef, number, ref) : null;

        if (holder) {
            this.problems.set(ref, {
                message: this.t('tableTaken', { '%number%': number, '%name%': holder.displayName }),
                typed,
                takeOver: { number, holder: holder.ref },
            });
            this.render();

            return;
        }

        this.recordNumber(entry, number, entry.tableNumber ?? null);
    }

    /**
     * One typed number. `from` = the number the organiser saw - kept for a retry, so a change arriving meanwhile comes
     * back as a conflict instead of being written over.
     */
    async recordNumber(entry, number, from) {
        const ref = entry.ref;
        this.pendingNumbers.set(ref, number);
        this.problems.delete(ref);
        this.setStatus('saving');
        this.render();

        const change = {
            clientChangeId: newClientId(),
            entry: ref,
            field: 'table_number',
            from,
            to: number,
        };
        const result = await officialResultsRequest(this.recordUrlValue, {
            method: 'POST',
            body: { changes: [change] },
            csrfToken: this.csrfTokenValue,
        });

        this.pendingNumbers.delete(ref);

        if (result.kind !== 'ok') {
            this.problems.set(ref, { message: this.failureText(result), typed: number === null ? '' : String(number) });
            this.failed(result, () => this.recordNumber(this.byRef.get(ref) ?? entry, number, from));
            this.render();

            return;
        }

        this.mergeEntries(result.data.entries);
        const outcome = (result.data.outcomes ?? [])[0] ?? null;

        if (outcome && (outcome.status === 'applied' || outcome.status === 'unchanged')) {
            this.problems.delete(ref);

            // The organiser's own number is what they see now - also while the input keeps the focus
            const input = this.rows.get(ref)?.querySelector('.seating-number');
            if (input) {
                input.dataset.seen = number === null ? '' : String(number);
            }
        } else if (outcome && outcome.status === 'conflict') {
            this.problems.set(ref, this.meanwhileProblem(outcome.current, number === null ? '' : String(number), number));
        } else if (outcome && outcome.reason === 'table_number_taken') {
            const holder = holderOf(this.byRef, number, ref);
            this.problems.set(ref, holder
                ? { message: this.t('tableTaken', { '%number%': number, '%name%': holder.displayName }), typed: String(number), takeOver: { number, holder: holder.ref } }
                : { message: outcome.message ?? this.t('statusFailed'), typed: String(number) });
            // Our copy did not know the holder - fetch the round again
            if (!holder) {
                this.refresh();
            }
        } else {
            this.problems.set(ref, { message: outcome?.message ?? this.t('statusFailed'), typed: number === null ? '' : String(number) });
        }

        this.setStatus('saved');
        this.afterDataChange();
    }

    // ---- one bulk write

    /**
     * One AssignTableNumbers write - all of it or nothing. A refusal names the entries; anything else keeps the page as
     * it was with "Try again". Success offers Undo (the numbers before, as one more write).
     */
    async assign(unsent, successText, { undoable = true } = {}) {
        if (unsent.length === 0) {
            return true;
        }

        // The numbers this page shows now - kept for a retry, so a change arriving meanwhile is never written over
        const assignments = withFrom(unsent, this.byRef);

        const before = numbersOf(this.byRef);
        // The buttons are disabled while saving - the focus comes back to the one pressed
        const focusBefore = document.activeElement;
        this.busy = true;
        this.setStatus('saving');
        this.render();

        const result = await officialResultsRequest(this.assignUrlValue, {
            method: 'POST',
            body: { assignments },
            csrfToken: this.csrfTokenValue,
        });

        this.busy = false;
        this.render();

        if (focusBefore instanceof HTMLElement && focusBefore.isConnected && !focusBefore.disabled && !focusBefore.closest('[hidden]')) {
            focusBefore.focus({ preventScroll: true });
        }

        if (result.kind === 'ok') {
            this.mergeEntries(result.data.entries);

            for (const assignment of assignments) {
                this.problems.delete(assignment.entry);
            }

            this.orderDirty = false;
            this.setStatus('saved');
            this.afterDataChange({ resort: true });
            this.showToast(successText, undoable ? () => this.assign(undoAssignments(assignments, before), this.t('toastUndone'), { undoable: false }) : null);

            return true;
        }

        if (result.kind === 'client' && result.data?.error === 'invalid_table_numbers') {
            const problems = result.data.problems ?? [];

            for (const problem of problems) {
                if (typeof problem.entry === 'string') {
                    this.problems.set(problem.entry, { message: problem.reason === 'changed_meanwhile' ? this.t('changedMeanwhile') : (problem.message ?? this.t('statusFailed')) });
                }
            }

            this.setStatus(null);
            this.showToast(this.t(problems.some((problem) => problem.reason === 'changed_meanwhile') ? 'toastChangedMeanwhile' : 'toastNothingSaved'), null);
            // Somebody else may have changed the round - the next attempt starts from the server's numbers
            await this.refresh();
            this.render();

            return false;
        }

        this.failed(result, () => this.assign(assignments, successText, { undoable }));
        this.render();

        return false;
    }

    // ---- this round doesn't use table numbers

    turnOff() {
        this.setUsage(true);
    }

    turnOn() {
        this.setUsage(false);
    }

    async setUsage(off) {
        this.setStatus('saving');
        const result = await officialResultsRequest(this.usageUrlValue, {
            method: 'POST',
            body: { off },
            csrfToken: this.csrfTokenValue,
        });

        if (result.kind !== 'ok') {
            this.failed(result, () => this.setUsage(off));

            return;
        }

        this.round = result.data.round ?? { ...this.round, tableNumbersOff: off };
        this.setStatus('saved');
        this.render();
        this.showToast(this.t(off ? 'turnedOff' : 'turnedOn'), () => this.setUsage(!off));
    }

    // ---- auto-assign

    toggleAuto() {
        if (this.autoPanelTarget.hidden) {
            this.openAuto(null);
        } else {
            this.closeAuto();
        }
    }

    openAuto(source) {
        this.autoPanelTarget.hidden = false;
        this.autoButtonTarget.setAttribute('aria-expanded', 'true');

        if (source !== null) {
            this.sourceInputTargets.forEach((input) => {
                input.checked = input.value === source;
            });
        }

        this.requestProposal({ keepSeed: false });
        this.autoPanelTarget.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    closeAuto() {
        this.autoPanelTarget.hidden = true;
        this.autoButtonTarget.setAttribute('aria-expanded', 'false');
        this.proposal = null;
        this.proposalRequest++;
        this.proposalTarget.replaceChildren();
        this.render();
        this.autoButtonTarget.focus();
    }

    optionsChanged(event) {
        // Another source is a new proposal; the order or the first table keep a draw
        this.requestProposal({ keepSeed: !this.sourceInputTargets.includes(event.currentTarget) });
    }

    drawAgain() {
        this.requestProposal({ keepSeed: false });
    }

    selectedSource() {
        return this.sourceInputTargets.find((input) => input.checked)?.value ?? '';
    }

    async requestProposal({ keepSeed }) {
        const params = new URLSearchParams();
        const source = this.selectedSource();
        const first = parseTableNumber(this.firstInputTarget.value);

        if (source !== '') {
            params.set('source', source);
        }

        params.set('order', this.orderInputTargets.find((input) => input.checked)?.value ?? 'fastest_first');
        params.set('first', String(first.number ?? 1));

        if (keepSeed && this.proposal?.randomSeed) {
            params.set('seed', String(this.proposal.randomSeed));
        }

        const request = ++this.proposalRequest;
        this.proposal = null;
        this.proposalTarget.replaceChildren(this.paragraph(this.t('proposing'), 'text-muted'));
        this.render();

        const result = await officialResultsRequest(`${this.proposalUrlValue}?${params.toString()}`);

        if (request !== this.proposalRequest) {
            return;
        }

        if (result.kind !== 'ok') {
            if (isGone(result)) {
                this.goneAway();
            }

            this.proposalTarget.replaceChildren(this.paragraph(result.kind === 'auth' ? this.t('statusSignIn') : (isGone(result) ? this.t('statusGone') : this.t('proposalFailed')), 'text-danger'));

            return;
        }

        this.proposal = result.data;
        this.renderProposalOptions();
        this.renderProposal();
        this.render();
    }

    renderProposalOptions() {
        const proposal = this.proposal;
        const total = proposal.total;

        this.sourceInputTargets.forEach((input) => {
            input.checked = input.value === proposal.source;
        });

        for (const { source, withData } of proposal.sources) {
            const count = this.element.querySelector(`[data-source-count="${source}"]`);
            const best = this.element.querySelector(`[data-source-best="${source}"]`);

            if (count) {
                count.textContent = source === 'earlier_rounds' || source === 'msp_times'
                    ? `(${this.t('withData', { '%count%': withData, '%total%': total })})`
                    : '';
            }

            if (best) {
                best.hidden = source !== proposal.defaultSource;
            }
        }

        this.mspHelpTarget.textContent = this.t('mspHelp', { '%pieces%': proposal.piecesCount });
        this.orderFieldsetTarget.hidden = proposal.source === 'random' || proposal.source === 'name';
        this.orderLegendTarget.textContent = this.t('orderFirst', { '%first%': proposal.firstTable });
        this.drawAgainButtonTarget.hidden = proposal.source !== 'random';
    }

    proposalApplicable() {
        return this.proposal !== null && this.proposal.rows.length > 0 && proposalAssignments(this.proposal.rows, this.byRef).length > 0;
    }

    renderProposal() {
        const proposal = this.proposal;

        if (proposal === null) {
            return;
        }

        const parts = [];

        if (this.proposalNote !== null) {
            parts.push(this.paragraph(this.proposalNote, 'text-warning-emphasis fw-semibold mb-2'));
            this.proposalNote = null;
        }

        if (proposal.total === 0) {
            parts.push(this.paragraph(this.t('proposalEmpty'), 'text-muted'));
            this.proposalTarget.replaceChildren(...parts);

            return;
        }

        const summary = [];

        if (proposal.source === 'random') {
            summary.push(this.t('draw', { '%seed%': proposal.randomSeed }));
        }

        if (proposal.source === 'msp_times' && proposal.piecesCountAssumed) {
            summary.push(this.t('piecesAssumed', { '%pieces%': proposal.piecesCount }));
        }

        if ((proposal.source === 'earlier_rounds' || proposal.source === 'msp_times') && proposal.withoutData > 0) {
            summary.push(this.t('noData', { '%count%': proposal.withoutData }));
        }

        summary.push(this.t('changes', { '%count%': proposalAssignments(proposal.rows, this.byRef).length }));
        parts.push(this.paragraph(summary.join(' '), 'small mb-2'));

        const table = document.createElement('table');
        table.className = 'table table-sm align-middle mb-0 seating-proposal-table';
        const head = document.createElement('thead');
        const headRow = document.createElement('tr');
        const columns = [this.t('columnTable'), this.t('columnEntrant')];

        if (proposal.source === 'earlier_rounds') {
            columns.push(this.t('columnBasis'));
        }

        columns.push(this.t('columnNow'));
        columns.forEach((text, index) => {
            const th = document.createElement('th');
            th.scope = 'col';
            th.textContent = text;

            if (index === 0 || index === columns.length - 1) {
                th.className = 'text-end';
            }

            headRow.append(th);
        });
        head.append(headRow);

        const body = document.createElement('tbody');

        for (const row of proposal.rows) {
            const entry = this.byRef.get(row.entry);
            const tr = document.createElement('tr');
            tr.classList.toggle('text-muted', !row.hasData);

            const tableCell = document.createElement('td');
            tableCell.className = 'text-end fw-bold';
            tableCell.textContent = String(row.tableNumber);

            const entrantCell = document.createElement('td');
            entrantCell.textContent = entry?.displayName ?? row.displayName;

            if (entry && entry.kind === 'team' && entry.name !== null && (entry.members ?? []).length > 0) {
                const members = document.createElement('div');
                members.className = 'small text-muted';
                members.textContent = entry.members.map((member) => member.name).join(', ');
                entrantCell.append(members);
            }

            tr.append(tableCell, entrantCell);

            if (proposal.source === 'earlier_rounds') {
                const basisCell = document.createElement('td');
                basisCell.className = 'small';
                basisCell.textContent = row.basis
                    ? this.t('basis', { '%round%': row.basis.roundName, '%rank%': row.basis.rank })
                    : this.t('noBasis');
                tr.append(basisCell);
            }

            const now = entry?.tableNumber ?? null;
            const nowCell = document.createElement('td');
            nowCell.className = 'text-end';
            nowCell.textContent = now === null ? '–' : String(now);
            nowCell.classList.toggle('text-decoration-line-through', now !== null && now !== row.tableNumber);
            tr.append(nowCell);

            body.append(tr);
        }

        table.append(head, body);

        const scroller = document.createElement('div');
        scroller.className = 'seating-proposal-scroller border rounded';
        scroller.append(table);
        parts.push(scroller);

        this.proposalTarget.replaceChildren(...parts);
    }

    async applyProposal() {
        const proposal = this.proposal;

        if (proposal === null) {
            return;
        }

        // Somebody was added or removed meanwhile - never number a list that is not the round's
        if (!proposalCoversEntrants(proposal.rows, this.byRef)) {
            this.proposalNote = this.t('entrantsChanged');
            await this.requestProposal({ keepSeed: true });

            return;
        }

        const assignments = proposalAssignments(proposal.rows, this.byRef);
        const applied = await this.assign(assignments, this.t('toastApplied', { '%count%': assignments.length }));

        if (applied) {
            this.closeAuto();
        }
    }

    // ---- filter

    filterChanged(event) {
        // Swapping stays possible while filtering: the other entry is found through the filter
        this.query = event.currentTarget.value;
        this.render();
    }

    // ---- status, failures, toast

    setStatus(state) {
        this.statusTarget.replaceChildren();
        this.statusTarget.className = 'seating-status small ms-sm-auto';

        if (state === 'saving') {
            this.statusTarget.append(this.icon('bi-arrow-repeat'), document.createTextNode(` ${this.t('statusSaving')}`));
            this.statusTarget.classList.add('text-muted');
        } else if (state === 'saved') {
            this.statusTarget.append(this.icon('bi-check2'), document.createTextNode(` ${this.t('statusSaved')}`));
            this.statusTarget.classList.add('text-success');
        }
    }

    /**
     * The round was deleted (or never existed): the page says so and stops asking - no stream, no minute refresh, and
     * nothing offers to try again.
     */
    goneAway() {
        if (this.gone) {
            return;
        }

        this.gone = true;
        this.events?.close();
        clearInterval(this.resyncInterval);

        if (this.hasGoneTarget) {
            this.goneTarget.hidden = false;
        }
    }

    failureText(result) {
        if (isGone(result)) {
            return this.t('statusGone');
        }

        switch (result.kind) {
            case 'auth':
                return this.t('statusSignIn');
            case 'forbidden':
                return this.t('statusForbidden');
            case 'offline':
                return this.t('statusOffline');
            default:
                return this.t('statusFailed');
        }
    }

    failed(result, retry) {
        if (isGone(result)) {
            this.goneAway();
        }

        this.statusTarget.replaceChildren();
        this.statusTarget.className = 'seating-status small ms-sm-auto text-danger fw-semibold';
        this.statusTarget.append(this.icon('bi-exclamation-triangle'), document.createTextNode(` ${this.failureText(result)} `));

        if (result.kind === 'auth') {
            const link = document.createElement('a');
            link.href = this.signInUrlValue;
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = this.t('statusSignInLink');
            this.statusTarget.append(link, document.createTextNode(' '));
        }

        if (result.kind !== 'forbidden' && !this.gone) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm btn-outline-danger py-0';
            button.textContent = this.t('statusRetry');
            button.addEventListener('click', () => {
                this.setStatus(null);
                retry();
            }, { once: true });
            this.statusTarget.append(button);
        }
    }

    showToast(message, undo) {
        clearTimeout(this.toastTimer);

        const toast = document.createElement('div');
        toast.className = 'seating-toast shadow';

        const text = document.createElement('span');
        text.textContent = message;
        toast.append(text);

        if (undo) {
            const undoButton = document.createElement('button');
            undoButton.type = 'button';
            undoButton.className = 'btn btn-sm btn-light';
            undoButton.textContent = this.t('toastUndo');
            undoButton.addEventListener('click', () => {
                this.toastTarget.replaceChildren();
                undo();
            }, { once: true });
            toast.append(undoButton);
        }

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn-close btn-close-white';
        close.setAttribute('aria-label', this.t('toastClose'));
        close.addEventListener('click', () => this.toastTarget.replaceChildren(), { once: true });
        toast.append(close);

        this.toastTarget.replaceChildren(toast);
        this.toastTimer = setTimeout(() => {
            if (this.toastTarget.contains(toast)) {
                this.toastTarget.replaceChildren();
            }
        }, 15000);
    }

    icon(name) {
        const icon = document.createElement('i');
        icon.className = `bi ${name}`;
        icon.setAttribute('aria-hidden', 'true');

        return icon;
    }

    paragraph(text, className) {
        const paragraph = document.createElement('p');
        paragraph.className = className;
        paragraph.textContent = text;

        return paragraph;
    }
}
