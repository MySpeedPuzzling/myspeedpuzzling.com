/**
 * The generic spreadsheet grid of the participants sheet (docs/features/competitions-management/participants-spreadsheet.md
 * §7, §9 and "Client architecture (as built)"): a native `<table role="grid">` the views fill through callbacks - the
 * grid owns focus, selection, the one floating editor, the clipboard and the keyboard model (grid_keys.js); the view
 * owns what a cell shows and what an edit means.
 *
 * - WAI-ARIA APG grid: roving tabindex (one cell - or the checkbox in a checkbox cell - has tabindex 0), aria-rowcount /
 *   aria-colcount / aria-rowindex, aria-selected on a range, aria-readonly on cells that cannot be edited, Tab leaves the
 *   grid only from the very last cell (Ctrl+End, then Tab - said in the help).
 * - Native table layout, every row in the DOM (screen readers, the browser's Ctrl+F), no content-visibility (stage 0b:
 *   it does nothing on table boxes). An edit re-renders only the cells the model says changed - never the whole grid.
 * - One floating `<input>` editor (16 px - no iOS zoom), IME-safe (nothing commits while composing), `aria-invalid` + a
 *   described error for an edit the view refused; on `list` columns it is a combobox with a listbox of suggestions
 *   (the view's `suggest`, sync or async, may end with a "create" option).
 * - Clipboard: Ctrl/Cmd+C/X/V move the focus for a moment to a hidden textarea where the browser's own command runs -
 *   WebKit fires no clipboard event on a focused non-editable cell (stage 0b). Copy writes TSV + an HTML table; paste
 *   hands the parsed rows (tsv.js) to the view.
 * - Cells show the view's state markers (saving / waiting / conflict / refused / warning) as an icon **and** text,
 *   never colour alone; sticky header and first column with scroll-padding so the focused cell is never hidden under
 *   them (WCAG 2.4.11).
 * - What an editor showed when it OPENED (`seenValue(row, col)`) comes back with its commit as `seen` - the view sends it
 *   as `from`, so a live change that arrived while the cell was being edited is a conflict, never silently reverted.
 *   The view may show that change next to the editor (`editorNotice()` - "Changed meanwhile to X · Keep mine / Use
 *   theirs"). Typed text survives a rebuild of the grid (`editState()` / `resumeEdit()`); destroying the grid commits
 *   an open edit like a blur.
 * - List columns: the highlighted option is taken by Enter (or a click); Tab, arrows and a blur never take an option
 *   flagged `action: true` nor any option of a column with `commitOnBlur: false` (a profile search hit, "Unlink").
 *
 * Views pass texts in (`texts.t(key, params)` / `texts.tc(key, count, params)` - the core texts JSON); no user-facing
 * string lives here.
 */

import { nextAction } from './grid_keys.js';
import { rowsFromClipboard, toHtmlTable, toTsv } from './tsv.js';

export const PAGE_SIZE = 20;
const SUGGEST_DELAY_MS = 150;
let gridCounter = 0;

export function escapeHtml(text) {
    return String(text ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

const MARKER_ICONS = {
    saving: 'bi-arrow-repeat',
    waiting: 'bi-cloud-slash',
    conflict: 'bi-people',
    refused: 'bi-exclamation-octagon',
    warning: 'bi-exclamation-triangle',
    info: 'bi-info-circle',
};

/**
 * A cell's state marker: an icon and a short text (visible for problems, for screen readers only while saving).
 *
 * @param {{state: string, text: string, title?: string}|null} marker
 */
export function markerHtml(marker) {
    if (!marker) {
        return '';
    }

    const quiet = marker.state === 'saving' || marker.state === 'waiting';
    const title = marker.title ?? marker.text;

    return `<span class="sheet-marker sheet-marker-${escapeHtml(marker.state)}"${title ? ` title="${escapeHtml(title)}"` : ''}>`
        + `<i class="bi ${MARKER_ICONS[marker.state] ?? 'bi-dot'}" aria-hidden="true"></i>`
        + `<span class="${quiet ? 'visually-hidden' : 'sheet-marker-text'}">${escapeHtml(marker.text)}</span></span>`;
}

/**
 * @typedef {object} GridColumn
 * @property {string} key
 * @property {string} label            header text (also the editor's accessible name)
 * @property {'text'|'list'|'checkbox'|'readonly'|'action'} kind
 * @property {number} [width]          px
 * @property {string} [headerHtml]     trusted header content instead of the label
 * @property {'panel'} [space]         Space opens the row's panel (the person editor) instead of typing a space
 * @property {boolean} [autoHighlight] list columns: the first suggestion is highlighted (Enter picks it) - default true
 * @property {boolean} [commitOnBlur] list columns: false = Tab / arrows / a blur never take an option (only Enter or
 *                                   a click) - default true; options flagged `action: true` never in any column
 * @property {string} [className]
 *
 * @typedef {object} CellContent
 * @property {string} [text]           plain text (escaped by the grid); also what is copied
 * @property {string} [html]           trusted markup instead of `text` (the view escapes)
 * @property {boolean} [checked]       checkbox cells
 * @property {string} [label]          checkbox cells: the accessible name ("Solo, Kim Example")
 * @property {boolean} [readonly]      this cell cannot be edited (the column's kind otherwise)
 * @property {{state: string, text: string, title?: string}|null} [marker]
 * @property {string} [className]
 * @property {string} [copy]           what Ctrl+C copies (default: text, TRUE/FALSE for checkboxes)
 */

export class SheetGrid {
    /**
     * @param {object} options
     * @param {HTMLElement} options.container    the grid is rendered into it (a scroller with the table)
     * @param {string} options.label             the grid's accessible name
     * @param {GridColumn[]} options.columns
     * @param {string[]} options.rows            row keys
     * @param {{t: function(string, object=): string, tc: function(string, number, object=): string}} options.texts
     * @param {function(string, string): CellContent} options.cell
     * @param {function(string): string} [options.rowLabel]                    the row's name for accessible labels
     * @param {function(string): string} [options.rowClass]
     * @param {function(string, string): string} [options.editValue]          the text an edit (Enter/F2) starts with
     * @param {function(string, string): *} [options.seenValue]                what the cell shows when an editor opens
     *        (the value its change's `from` must be) - handed back to commit() as `seen`
     * @param {function(string, string, string): (Array|Promise<Array>)} [options.suggest]   list columns
     * @param {function(string, string, {text: string, option: object|null}, {fill: boolean, cells: Array, seen: *}): ({error?: string, focus?: object|function}|void)} options.commit
 *        `error` keeps the editor open with the reason; `focus` = {row, col} keys (or a function of the planned move
 *        {row, col} indexes) where the focus goes instead of the planned move. `seen` = seenValue() when the editor
 *        opened (undefined for fills and pastes - no editor)
     * @param {function(Array<{row: string, col: string}>, boolean|null): void} [options.toggle]  checkbox cells (null = flip)
     * @param {function(Array<{row: string, col: string}>): void} [options.clear]
     * @param {function({row: string, col: string}, string[][], Array<{row: string, col: string}>): void} [options.paste]
     * @param {function('down'|'selection', {rows: string[], cols: string[]}, {row: string, col: string}): void} [options.fill]
     * @param {function(Array<{row: string, col: string}>): void} [options.cut]
     * @param {function(string, string): void} [options.activate]
     * @param {function(string): void} [options.openPanel]
     * @param {function(): void} [options.undo]
     * @param {function(): void} [options.redo]
     * @param {function(string): void} [options.announce]
     */
    constructor(options) {
        this.options = options;
        this.columns = options.columns;
        this.rows = options.rows.slice();
        this.texts = options.texts;
        this.id = `sheet-grid-${++gridCounter}`;
        this.rowElements = new Map();
        this.rowIndex = new Map();
        this.active = { row: this.rows[0] ?? null, col: this.columns[0]?.key ?? null };
        this.anchor = { ...this.active };
        this.selection = null;
        this.selectedCells = new Set();
        this.mode = 'nav';
        this.editKind = 'keep';
        this.editing = null;
        this.composing = false;
        this.list = { open: false, options: [], active: -1, request: 0, timer: null };
        this.dragging = false;
        this.destroyed = false;
        this.stats = { renderMs: null, lastCellMs: null };
        this.listeners = [];

        this.build();
    }

    // ---------------------------------------------------------------- building

    build() {
        const started = performance.now();
        const container = this.options.container;
        container.replaceChildren();

        this.scroller = document.createElement('div');
        this.scroller.className = 'sheet-scroller';
        this.table = document.createElement('table');
        this.table.className = 'sheet-grid';
        this.table.id = this.id;
        this.table.setAttribute('role', 'grid');
        this.table.setAttribute('aria-label', this.options.label);
        this.table.setAttribute('aria-multiselectable', 'true');
        this.table.setAttribute('aria-colcount', String(this.columns.length));

        this.colgroup = document.createElement('colgroup');
        this.thead = document.createElement('thead');
        this.tbody = document.createElement('tbody');
        this.table.append(this.colgroup, this.thead, this.tbody);
        this.scroller.append(this.table);

        this.editor = document.createElement('input');
        this.editor.type = 'text';
        this.editor.className = 'sheet-editor';
        this.editor.autocomplete = 'off';
        this.editor.spellcheck = false;
        this.editor.hidden = true;
        this.editor.id = `${this.id}-editor`;
        this.editorError = document.createElement('div');
        this.editorError.className = 'sheet-editor-error';
        this.editorError.id = `${this.id}-error`;
        this.editorError.setAttribute('role', 'alert');
        this.editorError.hidden = true;
        this.listbox = document.createElement('ul');
        this.listbox.className = 'sheet-listbox';
        this.listbox.id = `${this.id}-listbox`;
        this.listbox.setAttribute('role', 'listbox');
        this.listbox.hidden = true;
        this.listStatus = document.createElement('div');
        this.listStatus.className = 'sheet-listbox-status';
        this.listStatus.setAttribute('aria-live', 'polite');
        this.listStatus.hidden = true;
        this.notice = document.createElement('div');
        this.notice.className = 'sheet-editor-notice';
        this.notice.id = `${this.id}-notice`;
        this.notice.setAttribute('aria-live', 'polite');
        this.notice.hidden = true;
        this.noticeActions = [];

        this.proxy = document.createElement('textarea');
        this.proxy.className = 'sheet-clipboard';
        this.proxy.tabIndex = -1;
        this.proxy.hidden = true;
        this.proxy.setAttribute('aria-hidden', 'true');

        this.scroller.append(this.editor, this.editorError, this.notice, this.listbox, this.listStatus, this.proxy);
        container.append(this.scroller);

        this.renderHeader();
        this.renderBody();
        this.wire();
        this.setTabStop();
        this.fitHeight();
        requestAnimationFrame(() => {
            if (!this.destroyed) {
                this.fitHeight();
            }
        });
        this.stats.renderMs = performance.now() - started;
    }

    renderHeader() {
        // A fixed width (every column has one): the browser never measures the content of 7,000 cells (max-content)
        const width = this.columns.reduce((sum, column) => sum + (Number(column.width) || 0), 0);
        this.table.style.width = this.columns.every((column) => Number(column.width) > 0) ? `${width}px` : '';
        this.colgroup.innerHTML = this.columns.map((column) => `<col${column.width ? ` style="width:${Number(column.width)}px"` : ''}>`).join('');
        this.thead.innerHTML = `<tr aria-rowindex="1">${this.columns.map((column, index) => `<th scope="col" data-c="${index}" title="${escapeHtml(this.texts.t('grid_select_column', { column: column.label }))}">${column.headerHtml ?? escapeHtml(column.label)}</th>`).join('')}</tr>`;
    }

    renderBody() {
        this.rowElements.clear();
        this.rowIndex = new Map(this.rows.map((key, index) => [key, index]));
        this.tbody.innerHTML = this.rows.map((key, index) => this.rowHtml(key, index)).join('');

        Array.from(this.tbody.rows).forEach((tr, index) => {
            this.rowElements.set(this.rows[index], tr);
        });

        this.table.setAttribute('aria-rowcount', String(this.rows.length + 1));
    }

    rowHtml(key, index) {
        const rowClass = this.options.rowClass?.(key) ?? '';

        return `<tr aria-rowindex="${index + 2}" data-row="${escapeHtml(key)}"${rowClass ? ` class="${escapeHtml(rowClass)}"` : ''}>${this.columns.map((column, colIndex) => this.cellHtml(key, column, colIndex)).join('')}</tr>`;
    }

    content(rowKey, colKey) {
        return this.options.cell(rowKey, colKey) ?? { text: '' };
    }

    cellKind(rowKey, column) {
        const content = this.content(rowKey, column.key);

        if (content.readonly && column.kind !== 'action') {
            return 'readonly';
        }

        return column.kind;
    }

    cellAttributes(rowKey, column, content) {
        const kind = content.readonly && column.kind !== 'action' ? 'readonly' : column.kind;
        // Short markup: a 400 x 18 sheet is 7,000 cells (the column is data-c, its index)
        const classes = [`sheet-kind-${kind}`];

        if (column.className) {
            classes.push(column.className);
        }

        if (content.className) {
            classes.push(content.className);
        }

        if (content.marker) {
            classes.push(`has-marker-${content.marker.state}`);
        }

        const readonly = kind === 'readonly' || kind === 'action' ? ' aria-readonly="true"' : '';

        return { classes: classes.join(' '), readonly, kind };
    }

    cellInner(rowKey, column, content) {
        if (column.kind === 'checkbox' && !content.readonly) {
            return `<input type="checkbox" class="form-check-input sheet-check" tabindex="-1" aria-label="${escapeHtml(content.label ?? column.label)}"${content.checked ? ' checked' : ''}>${markerHtml(content.marker)}`;
        }

        // Plain text straight in the cell - every extra element costs layout time on a 7,000-cell sheet
        const body = content.html ?? escapeHtml(content.text ?? '');

        return `${body}${markerHtml(content.marker)}`;
    }

    cellHtml(rowKey, column, colIndex) {
        const content = this.content(rowKey, column.key);
        const { classes, readonly } = this.cellAttributes(rowKey, column, content);
        const tag = colIndex === 0 ? 'th' : 'td';
        const scope = colIndex === 0 ? ' scope="row"' : '';

        // APG: a grid supporting selection says it of every cell
        return `<${tag}${scope} class="${classes}" data-c="${colIndex}" aria-selected="false"${readonly}>${this.cellInner(rowKey, column, content)}</${tag}>`;
    }

    /** The sticky header's height and first column's width as scroll padding, the scroller's height to the viewport. */
    fitHeight() {
        const head = this.thead.rows[0];

        if (head) {
            this.scroller.style.setProperty('--sheet-head-h', `${head.offsetHeight}px`);
            this.scroller.style.setProperty('--sheet-first-w', `${head.cells[0]?.offsetWidth ?? 0}px`);
        }

        const top = this.scroller.getBoundingClientRect().top + window.scrollY;
        this.scroller.style.setProperty('--sheet-scroller-top', `${Math.max(0, Math.round(top))}px`);
    }

    // ---------------------------------------------------------------- rows from the view

    /**
     * The row list changed (people added, removed, a filter): rows are reused by key, created, removed, moved - cells of
     * reused rows are not re-rendered (updateRows() does that).
     */
    setRows(keys) {
        const wanted = new Set(keys);

        for (const [key, tr] of this.rowElements) {
            if (!wanted.has(key)) {
                tr.remove();
                this.rowElements.delete(key);
            }
        }

        let previous = null;

        keys.forEach((key, index) => {
            let tr = this.rowElements.get(key);

            if (tr === undefined) {
                const template = document.createElement('tbody');
                template.innerHTML = this.rowHtml(key, index);
                tr = template.firstElementChild;
                this.rowElements.set(key, tr);
            } else if (tr.getAttribute('aria-rowindex') !== String(index + 2)) {
                tr.setAttribute('aria-rowindex', String(index + 2));
            }

            const expected = previous === null ? this.tbody.firstElementChild : previous.nextElementSibling;

            if (expected !== tr) {
                this.tbody.insertBefore(tr, expected);
            }

            previous = tr;
        });

        const activeIndex = this.rowIndex.get(this.active.row) ?? 0;
        this.rows = keys.slice();
        this.rowIndex = new Map(this.rows.map((key, index) => [key, index]));
        this.table.setAttribute('aria-rowcount', String(this.rows.length + 1));

        if (!this.rowIndex.has(this.active.row)) {
            // The focused row went: the row now at its place takes the focus (without stealing it from elsewhere)
            const hadFocus = this.table.contains(document.activeElement);
            this.active = { row: this.rows[Math.min(activeIndex, this.rows.length - 1)] ?? null, col: this.active.col };
            this.setTabStop();

            if (hadFocus) {
                this.focusActive();
            }
        }

        if (this.selection !== null) {
            this.setSelection(null);
        }
    }

    /** Re-render the cells of these rows (only cells whose markup changed are touched). */
    updateRows(keys) {
        for (const key of keys) {
            const tr = this.rowElements.get(key);

            if (tr === undefined) {
                continue;
            }

            const rowClass = this.options.rowClass?.(key) ?? '';

            if ((tr.getAttribute('class') ?? '') !== rowClass) {
                tr.setAttribute('class', rowClass);
            }

            this.columns.forEach((column, colIndex) => this.patchCell(key, column, tr.cells[colIndex]));
        }
    }

    updateCell(rowKey, colKey) {
        const tr = this.rowElements.get(rowKey);
        const colIndex = this.columns.findIndex((column) => column.key === colKey);

        if (tr !== undefined && colIndex !== -1) {
            this.patchCell(rowKey, this.columns[colIndex], tr.cells[colIndex]);
        }
    }

    patchCell(rowKey, column, cell) {
        if (!cell || (this.editing && this.editing.row === rowKey && this.editing.col === column.key && cell.dataset.editing === 'true')) {
            return;
        }

        const started = performance.now();
        const content = this.content(rowKey, column.key);
        const { classes, readonly } = this.cellAttributes(rowKey, column, content);
        const selected = cell.getAttribute('aria-selected') === 'true';

        if (cell.getAttribute('class') !== `${classes}${selected ? ' is-selected' : ''}`) {
            cell.setAttribute('class', `${classes}${selected ? ' is-selected' : ''}`);
        }

        if (readonly) {
            cell.setAttribute('aria-readonly', 'true');
        } else if (cell.hasAttribute('aria-readonly')) {
            cell.removeAttribute('aria-readonly');
        }

        const inner = this.cellInner(rowKey, column, content);

        if (column.kind === 'checkbox' && !content.readonly) {
            // A click toggles the box at once; a change the view refused (or did differently) puts it back - always,
            // even when the markup did not change
            this.syncCheckbox(cell, content);
        }

        if (cell.__inner === inner) {
            return;
        }

        const checkbox = cell.querySelector('input.sheet-check');

        if (checkbox !== null && column.kind === 'checkbox' && !content.readonly && document.activeElement === checkbox) {
            // The focused checkbox stays the same element - only its state and the marker change
            checkbox.checked = content.checked === true;
            checkbox.setAttribute('aria-label', content.label ?? column.label);
            cell.querySelectorAll('.sheet-marker').forEach((element) => element.remove());
            checkbox.insertAdjacentHTML('afterend', markerHtml(content.marker));
        } else {
            cell.innerHTML = inner;

            if (this.isActiveCell(rowKey, column.key)) {
                this.setTabStop();
            }
        }

        cell.__inner = inner;
        this.stats.lastCellMs = performance.now() - started;
    }

    syncCheckbox(cell, content) {
        const checkbox = cell.querySelector('input.sheet-check');

        if (checkbox !== null && checkbox.checked !== (content.checked === true)) {
            checkbox.checked = content.checked === true;
        }
    }

    // ---------------------------------------------------------------- positions

    colIndex(key) {
        return this.columns.findIndex((column) => column.key === key);
    }

    cellElement(rowKey, colKey) {
        const tr = this.rowElements.get(rowKey);
        const index = this.colIndex(colKey);

        return tr === undefined || index === -1 ? null : tr.cells[index] ?? null;
    }

    /** The element focus goes to for a cell: its checkbox, else the cell. */
    focusTarget(rowKey, colKey) {
        const cell = this.cellElement(rowKey, colKey);

        if (cell === null) {
            return null;
        }

        return cell.querySelector('input.sheet-check') ?? cell;
    }

    isActiveCell(rowKey, colKey) {
        return this.active.row === rowKey && this.active.col === colKey;
    }

    positionOf(element) {
        const cell = element?.closest?.('td, th');

        if (!cell || !this.tbody.contains(cell)) {
            return null;
        }

        const row = cell.parentElement.dataset.row;
        const col = this.columns[Number(cell.dataset.c)]?.key;

        return row !== undefined && col !== undefined ? { row, col } : null;
    }

    activeIndexes() {
        return { row: this.rowIndex.get(this.active.row) ?? 0, col: Math.max(0, this.colIndex(this.active.col)) };
    }

    // ---------------------------------------------------------------- focus and selection

    setTabStop() {
        const previous = this.table.querySelectorAll('[tabindex="0"]');
        const target = this.active.row === null ? null : this.focusTarget(this.active.row, this.active.col);

        previous.forEach((element) => {
            if (element !== target) {
                element.tabIndex = -1;
            }
        });

        if (target !== null) {
            target.tabIndex = 0;
        }
    }

    focusActive({ scroll = true } = {}) {
        const target = this.active.row === null ? null : this.focusTarget(this.active.row, this.active.col);

        if (target === null) {
            return;
        }

        this.setTabStop();
        target.focus({ preventScroll: true });

        if (scroll) {
            (target.closest('td, th') ?? target).scrollIntoView({ block: 'nearest', inline: 'nearest' });
        }
    }

    /** Focus a cell by its keys (the view after a tab switch, the problems panel). */
    focusCell(rowKey, colKey, { select = true } = {}) {
        if (!this.rowIndex.has(rowKey) || this.colIndex(colKey) === -1) {
            return false;
        }

        if (this.mode === 'edit') {
            this.cancelEdit(false);
        }

        this.active = { row: rowKey, col: colKey };
        this.anchor = { ...this.active };

        if (select) {
            this.setSelection(null);
        }

        this.focusActive();

        return true;
    }

    moveTo(rowIndex, colIndex) {
        const row = this.rows[Math.max(0, Math.min(rowIndex, this.rows.length - 1))];
        const col = this.columns[Math.max(0, Math.min(colIndex, this.columns.length - 1))]?.key;

        if (row === undefined || col === undefined) {
            return;
        }

        this.active = { row, col };
        this.anchor = { ...this.active };
        this.setSelection(null);
        this.focusActive();
    }

    extendTo(rowIndex, colIndex) {
        const row = this.rows[Math.max(0, Math.min(rowIndex, this.rows.length - 1))];
        const col = this.columns[Math.max(0, Math.min(colIndex, this.columns.length - 1))]?.key;

        if (row === undefined || col === undefined) {
            return;
        }

        this.active = { row, col };
        this.focusActive();
        this.setSelection(this.rectOf(this.anchor, this.active));
    }

    rectOf(a, b) {
        const ra = this.rowIndex.get(a.row) ?? 0;
        const rb = this.rowIndex.get(b.row) ?? 0;
        const ca = Math.max(0, this.colIndex(a.col));
        const cb = Math.max(0, this.colIndex(b.col));

        return { r0: Math.min(ra, rb), r1: Math.max(ra, rb), c0: Math.min(ca, cb), c1: Math.max(ca, cb) };
    }

    currentRect() {
        if (this.selection !== null) {
            return this.selection;
        }

        const { row, col } = this.activeIndexes();

        return { r0: row, r1: row, c0: col, c1: col };
    }

    setSelection(rect) {
        const single = rect === null || (rect.r0 === rect.r1 && rect.c0 === rect.c1);
        this.selection = single ? null : rect;
        const next = new Set();

        if (!single) {
            for (let r = rect.r0; r <= rect.r1; r++) {
                const tr = this.rowElements.get(this.rows[r]);

                for (let c = rect.c0; c <= rect.c1; c++) {
                    const cell = tr?.cells[c];

                    if (cell) {
                        next.add(cell);
                    }
                }
            }
        }

        for (const cell of this.selectedCells) {
            if (!next.has(cell)) {
                cell.setAttribute('aria-selected', 'false');
                cell.classList.remove('is-selected');
            }
        }

        for (const cell of next) {
            if (!this.selectedCells.has(cell)) {
                cell.setAttribute('aria-selected', 'true');
                cell.classList.add('is-selected');
            }
        }

        this.selectedCells = next;
    }

    /** The cells of the selection (or the active cell), as keys. */
    selectedKeys() {
        const rect = this.currentRect();
        const cells = [];

        for (let r = rect.r0; r <= rect.r1; r++) {
            for (let c = rect.c0; c <= rect.c1; c++) {
                cells.push({ row: this.rows[r], col: this.columns[c].key });
            }
        }

        return cells;
    }

    selectedRange() {
        const rect = this.currentRect();

        return {
            rows: this.rows.slice(rect.r0, rect.r1 + 1),
            cols: this.columns.slice(rect.c0, rect.c1 + 1).map((column) => column.key),
        };
    }

    // ---------------------------------------------------------------- keyboard

    keyState() {
        const { row, col } = this.activeIndexes();
        const rowKey = this.active.row;

        return {
            mode: this.mode,
            editKind: this.editKind,
            row,
            col,
            rowCount: this.rows.length,
            colCount: this.columns.length,
            pageSize: PAGE_SIZE,
            columns: this.columns.map((column, index) => (index === col && rowKey !== null ? { kind: this.cellKind(rowKey, column), space: column.space } : { kind: column.kind, space: column.space })),
        };
    }

    onKeyDown(event) {
        if (this.destroyed || event.target === this.proxy) {
            return;
        }

        if (this.mode === 'edit' && this.handleListKeys(event)) {
            return;
        }

        // A notice next to the editor: Tab goes to its buttons (like a form), the edit stays open
        if (this.mode === 'edit' && event.target === this.editor && event.key === 'Tab' && !event.shiftKey && !event.isComposing && !this.composing
            && !this.notice.hidden && this.noticeActions.length > 0 && (!this.list.open || this.listbox.hidden)) {
            event.preventDefault();
            this.focusNotice(0);

            return;
        }

        if (this.mode === 'nav' && event.target === this.editor) {
            return;
        }

        const answer = nextAction(this.keyState(), { ...pickKeyEvent(event), isComposing: event.isComposing || this.composing });

        if (answer.preventDefault) {
            event.preventDefault();
        }

        if (this.mode === 'nav' && (answer.action === 'copy' || answer.action === 'cut' || answer.action === 'paste')) {
            this.armProxy(answer.action);

            return;
        }

        this.handle(answer, event);
    }

    /** Keys of an open suggestion list: arrows pick, Enter/Tab take the highlighted one, Esc closes the list. */
    handleListKeys(event) {
        if (!this.list.open || event.isComposing || this.composing) {
            return false;
        }

        const count = this.list.options.length;

        // With options shown the arrows choose among them; without any they commit and move like in any cell
        if (count > 0 && (event.key === 'ArrowDown' || event.key === 'ArrowUp') && !event.altKey && !event.ctrlKey && !event.metaKey) {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            this.highlight((this.list.active + step + count) % count);

            return true;
        }

        // The first Esc closes a visible list (or hint), the second one cancels the edit
        if (event.key === 'Escape' && (!this.listbox.hidden || !this.listStatus.hidden)) {
            event.preventDefault();
            this.closeList();

            return true;
        }

        return false;
    }

    handle(answer, event = null) {
        const { row, col } = this.activeIndexes();

        switch (answer.action) {
            case 'move':
                this.moveTo(answer.row, answer.col);
                break;
            case 'extend':
                this.extendTo(answer.row, answer.col);
                break;
            case 'collapse':
                if (this.selection !== null) {
                    this.setSelection(null);
                    this.anchor = { ...this.active };
                }
                break;
            case 'selectRow':
                this.anchor = { row: this.active.row, col: this.columns[0].key };
                this.setSelection({ r0: row, r1: row, c0: 0, c1: this.columns.length - 1 });
                this.announce(this.texts.t('grid_row_selected'));
                break;
            case 'selectColumn':
                this.selectColumn(col);
                break;
            case 'selectAll':
                this.setSelection({ r0: 0, r1: this.rows.length - 1, c0: 0, c1: this.columns.length - 1 });
                this.announce(this.texts.t('grid_all_selected'));
                break;
            case 'startEdit':
                this.startEdit(answer.replace);
                break;
            case 'commit':
                this.commitEdit(answer.move, answer.fill, undefined, event?.key === 'Enter' ? 'enter' : 'key');
                break;
            case 'cancel':
                this.cancelEdit(true);
                break;
            case 'toggleEditKind':
                this.editKind = this.editKind === 'type' ? 'keep' : 'type';
                break;
            case 'toggle':
                this.toggleCells(null);
                break;
            case 'activate':
                this.options.activate?.(this.active.row, this.active.col);
                break;
            case 'openList':
                if (this.mode === 'nav') {
                    this.startEdit(false);
                }

                if (this.mode === 'edit') {
                    this.openList(true);
                }
                break;
            case 'openPanel':
                this.options.openPanel?.(this.active.row);
                break;
            case 'readonly':
                this.announce(this.texts.t('grid_readonly', { column: this.columns[col]?.label ?? '' }));
                break;
            case 'fillDown':
                this.options.fill?.('down', this.selectedRange(), { ...this.active });
                break;
            case 'fillSelection':
                if (this.selection !== null) {
                    this.options.fill?.('selection', this.selectedRange(), { ...this.active });
                }
                break;
            case 'clear':
                this.options.clear?.(this.selectedKeys());
                break;
            case 'undo':
                this.options.undo?.();
                break;
            case 'redo':
                this.options.redo?.();
                break;
            default:
                // none, leave (Tab out of the grid from its very last cell): the browser's own
                break;
        }
    }

    selectColumn(colIndex) {
        this.anchor = { row: this.rows[0], col: this.columns[colIndex].key };
        this.setSelection({ r0: 0, r1: this.rows.length - 1, c0: colIndex, c1: colIndex });
        this.announce(this.texts.t('grid_column_selected', { column: this.columns[colIndex].label }));
    }

    toggleCells(value) {
        const cells = this.selectedKeys().filter(({ row, col }) => {
            const column = this.columns[this.colIndex(col)];

            return column.kind === 'checkbox' && this.cellKind(row, column) === 'checkbox';
        });

        if (cells.length > 0) {
            this.options.toggle?.(cells, value);
        }
    }

    // ---------------------------------------------------------------- editing

    /**
     * Opens the editor on the active cell. `preset` = {text, seen, editKind?} resumes an edit (a rebuilt grid) instead
     * of starting one: the typed text and what the cell showed when the edit first opened.
     */
    startEdit(replace, preset = null) {
        const rowKey = this.active.row;
        const column = this.columns[this.colIndex(this.active.col)];

        if (rowKey === null || column === undefined) {
            return;
        }

        const kind = this.cellKind(rowKey, column);

        if (kind !== 'text' && kind !== 'list') {
            return;
        }

        const cell = this.cellElement(rowKey, column.key);
        const value = preset !== null ? String(preset.text ?? '') : (replace ? '' : (this.options.editValue?.(rowKey, column.key) ?? this.content(rowKey, column.key).text ?? ''));
        // What the organiser saw - the `from` of the change this edit makes, whatever arrives while it is open
        const seen = preset !== null ? preset.seen : this.options.seenValue?.(rowKey, column.key);

        this.mode = 'edit';
        this.editKind = preset?.editKind ?? (replace ? 'type' : 'keep');
        this.editing = { row: rowKey, col: column.key, kind, original: value, seen };
        cell.dataset.editing = 'true';
        cell.classList.add('is-editing');

        const label = [column.label, this.options.rowLabel?.(rowKey) ?? ''].filter(Boolean).join(', ');
        const editor = this.editor;
        editor.value = value;
        editor.setAttribute('aria-label', label);
        editor.removeAttribute('aria-invalid');
        editor.removeAttribute('aria-describedby');
        this.editorError.hidden = true;
        this.clearNotice();

        if (kind === 'list') {
            editor.setAttribute('role', 'combobox');
            editor.setAttribute('aria-autocomplete', 'list');
            editor.setAttribute('aria-expanded', 'false');
            editor.setAttribute('aria-controls', this.listbox.id);
        } else {
            for (const attribute of ['role', 'aria-autocomplete', 'aria-expanded', 'aria-controls', 'aria-activedescendant']) {
                editor.removeAttribute(attribute);
            }
        }

        this.positionEditor(cell, kind === 'list');
        editor.hidden = false;
        // Focused inside the keydown: the typed character (a dead key, AltGr, an IME) lands in the editor
        editor.focus({ preventScroll: true });

        if (!replace || preset !== null) {
            const caret = preset?.caret ?? value.length;
            editor.setSelectionRange(caret, caret);
        }

        if (kind === 'list' && !replace && (preset === null || preset.listOpen)) {
            this.openList(false);
        }
    }

    positionEditor(cell, wide) {
        const cellRect = cell.getBoundingClientRect();
        const scrollerRect = this.scroller.getBoundingClientRect();
        const top = cellRect.top - scrollerRect.top + this.scroller.scrollTop - this.scroller.clientTop;
        const left = cellRect.left - scrollerRect.left + this.scroller.scrollLeft - this.scroller.clientLeft;
        const width = Math.max(cellRect.width, wide ? 240 : 140);

        Object.assign(this.editor.style, { top: `${top}px`, left: `${left}px`, width: `${width}px`, minHeight: `${cellRect.height}px` });
        Object.assign(this.editorError.style, { top: `${top + cellRect.height}px`, left: `${left}px`, maxWidth: `${Math.max(width, 260)}px` });
        Object.assign(this.listbox.style, { top: `${top + cellRect.height}px`, left: `${left}px`, minWidth: `${width}px` });
        Object.assign(this.listStatus.style, { top: `${top + cellRect.height}px`, left: `${left}px`, minWidth: `${width}px` });
        Object.assign(this.notice.style, { top: `${top + cellRect.height}px`, left: `${left}px`, maxWidth: `${Math.max(width, 320)}px` });
        this.stackBelowEditor();
    }

    /** The notice and the error under the editor, one below the other when both show. */
    stackBelowEditor() {
        if (!this.notice.hidden && !this.editorError.hidden) {
            this.editorError.style.top = `${this.notice.offsetTop + this.notice.offsetHeight}px`;
        } else if (!this.notice.hidden && this.list.open && !this.listbox.hidden) {
            this.listbox.style.top = `${this.notice.offsetTop + this.notice.offsetHeight}px`;
        }
    }

    describeEditor() {
        const ids = [];

        if (!this.editorError.hidden) {
            ids.push(this.editorError.id);
        }

        if (!this.notice.hidden) {
            ids.push(this.notice.id);
        }

        if (ids.length > 0) {
            this.editor.setAttribute('aria-describedby', ids.join(' '));
        } else {
            this.editor.removeAttribute('aria-describedby');
        }
    }

    showError(message) {
        this.editorError.textContent = message;
        this.editorError.hidden = false;
        this.editor.setAttribute('aria-invalid', 'true');
        this.describeEditor();

        if (this.list.open) {
            // The error sits where the list was
            this.closeList();
        }

        this.stackBelowEditor();
    }

    /**
     * A note next to the open editor - "Changed meanwhile to Canada · Keep mine / Use theirs" - or none (null). Read
     * out politely and named in the editor's aria-describedby; Tab from the editor goes to its buttons (Esc back).
     * Nothing happens when no editor is open.
     *
     * @param {{text: string, actions?: Array<{label: string, run: function(): void}>}|null} notice
     */
    editorNotice(notice) {
        if (notice === null || notice === undefined || this.mode !== 'edit') {
            this.clearNotice();

            return;
        }

        const actions = notice.actions ?? [];
        const keys = actions.length > 0 ? ` <span class="visually-hidden">${escapeHtml(this.texts.t('grid_notice_keys'))}</span>` : '';
        const html = `<span class="sheet-editor-notice-text">${escapeHtml(notice.text)}${keys}</span>${actions.map((action, index) => `<button type="button" class="btn btn-sm btn-link sheet-editor-notice-action" data-notice-action="${index}">${escapeHtml(action.label)}</button>`).join('')}`;
        this.noticeActions = actions;

        if (this.notice.__html !== html) {
            this.notice.innerHTML = html;
            this.notice.__html = html;
        }

        if (this.notice.hidden) {
            this.notice.hidden = false;
            const cell = this.editing ? this.cellElement(this.editing.row, this.editing.col) : null;

            if (cell !== null) {
                this.positionEditor(cell, this.editing.kind === 'list');
            }
        }

        this.describeEditor();
        this.stackBelowEditor();
    }

    clearNotice() {
        if (this.notice.hidden && this.noticeActions.length === 0) {
            return;
        }

        this.notice.hidden = true;
        this.notice.replaceChildren();
        this.notice.__html = '';
        this.noticeActions = [];
        this.describeEditor();
    }

    /** The organiser has seen what changed meanwhile: their edit's `from` is now that value (until they commit). */
    acknowledgeSeen(value) {
        if (this.editing !== null) {
            this.editing.seen = value;
        }

        this.clearNotice();
    }

    /** "Keep mine": the open edit goes over the value the organiser has now seen (`from` = it). */
    commitWithSeen(value) {
        if (this.mode !== 'edit' || this.editing === null) {
            return false;
        }

        this.acknowledgeSeen(value);

        return this.commitEdit(null, false, undefined, 'enter');
    }

    /** The open edit as it is - what a rebuilt grid resumes (resumeEdit()); null when nothing is being edited. */
    editState() {
        if (this.mode !== 'edit' || this.editing === null) {
            return null;
        }

        return {
            row: this.editing.row,
            col: this.editing.col,
            text: this.editor.value,
            seen: this.editing.seen,
            editKind: this.editKind,
            caret: this.editor.selectionStart ?? this.editor.value.length,
            listOpen: this.list.open,
        };
    }

    /** Opens the editor again with an edit of a grid this one replaced. Returns false when its cell is not here. */
    resumeEdit(state) {
        if (!state || !this.rowIndex.has(state.row) || this.colIndex(state.col) === -1) {
            return false;
        }

        if (this.mode === 'edit') {
            this.cancelEdit(false);
        }

        this.active = { row: state.row, col: state.col };
        this.anchor = { ...this.active };
        this.setSelection(null);
        this.focusActive();
        this.startEdit(false, state);

        return this.mode === 'edit';
    }

    /**
     * The open edit goes to the view. `move` = {row, col} indexes to go to afterwards (null = stay). Returns false when
     * the view refused the value (the editor stays, with the reason).
     */
    commitEdit(move = null, fill = false, option = undefined, via = 'blur') {
        if (this.mode !== 'edit' || this.editing === null) {
            return true;
        }

        const { row, col, seen } = this.editing;
        const chosen = option !== undefined ? option : this.highlightedOption(via);
        const started = performance.now();
        const answer = this.options.commit(row, col, { text: this.editor.value, option: chosen ?? null }, { fill, cells: fill ? this.selectedKeys() : [{ row, col }], seen });

        if (answer && typeof answer.error === 'string') {
            this.showError(answer.error);
            this.editor.focus({ preventScroll: true });

            return false;
        }

        this.stopEdit();
        this.stats.lastCommitMs = performance.now() - started;

        if (answer && answer.focus && move) {
            // The view knows better where the next edit is (the new-person row after a person was added)
            const target = typeof answer.focus === 'function' ? answer.focus(move) : answer.focus;

            if (target && this.focusCell(target.row, target.col)) {
                return true;
            }
        }

        if (move && !fill) {
            this.moveTo(move.row, move.col);
        } else {
            this.focusActive();
        }

        return true;
    }

    /**
     * The option an edit commits with: the highlighted one - by Enter always, by Tab / arrows / a blur only when the
     * column takes options that way (`commitOnBlur`) and the option is no action (Open the profile, Unlink).
     */
    highlightedOption(via) {
        if (!this.list.open || this.list.active < 0) {
            return null;
        }

        const option = this.list.options[this.list.active] ?? null;

        if (option === null || via === 'enter') {
            return option;
        }

        const column = this.columns[this.colIndex(this.editing?.col)];

        return option.action === true || column?.commitOnBlur === false ? null : option;
    }

    cancelEdit(refocus) {
        if (this.mode !== 'edit') {
            return;
        }

        this.stopEdit();

        if (refocus) {
            this.focusActive();
        }
    }

    stopEdit() {
        const cell = this.editing ? this.cellElement(this.editing.row, this.editing.col) : null;
        const editing = this.editing;
        this.mode = 'nav';
        this.editing = null;
        this.composing = false;
        this.closeList();
        this.clearNotice();
        this.editor.hidden = true;
        this.editorError.hidden = true;

        if (cell !== null) {
            delete cell.dataset.editing;
            cell.classList.remove('is-editing');
            // Re-render in case the model changed while the cell was being edited
            this.patchCell(editing.row, this.columns[this.colIndex(editing.col)], cell);
        }
    }

    onEditorBlur() {
        if (this.mode !== 'edit' || this.committingOnBlur || this.focusingNotice) {
            return;
        }

        this.commitOpenEdit();
    }

    /**
     * The open edit committed like a blur (a click elsewhere, the grid going away): a refused value is dropped with its
     * reason said aloud.
     */
    commitOpenEdit() {
        if (this.mode !== 'edit' || this.committingOnBlur) {
            return;
        }

        this.committingOnBlur = true;

        try {
            if (!this.commitEditWithoutFocus()) {
                const message = this.editorError.textContent;
                this.stopEdit();
                this.announce(message);
            }
        } finally {
            this.committingOnBlur = false;
        }
    }

    commitEditWithoutFocus() {
        const { row, col, seen } = this.editing;
        const chosen = this.highlightedOption('blur');
        const answer = this.options.commit(row, col, { text: this.editor.value, option: chosen }, { fill: false, cells: [{ row, col }], seen });

        if (answer && typeof answer.error === 'string') {
            this.editorError.textContent = answer.error;

            return false;
        }

        this.stopEdit();

        return true;
    }

    onEditorInput() {
        if (this.mode === 'edit' && this.editing?.kind === 'list' && !this.composing) {
            this.openList(false);
        }

        if (!this.editorError.hidden) {
            this.editorError.hidden = true;
            this.editor.removeAttribute('aria-invalid');
            this.editor.removeAttribute('aria-describedby');
        }
    }

    // ---------------------------------------------------------------- suggestions (list columns)

    openList(immediately) {
        if (this.mode !== 'edit' || this.editing?.kind !== 'list' || !this.options.suggest) {
            return;
        }

        clearTimeout(this.list.timer);
        const run = () => {
            const request = ++this.list.request;
            const { row, col } = this.editing ?? {};

            if (row === undefined) {
                return;
            }

            let answer;

            try {
                answer = this.options.suggest(row, col, this.editor.value);
            } catch (e) {
                answer = [];
            }

            if (answer && typeof answer.then === 'function') {
                this.showListStatus(this.texts.t('grid_searching'));
                answer.then((options) => {
                    if (request === this.list.request && this.mode === 'edit') {
                        this.renderList(options);
                    }
                }, () => {
                    if (request === this.list.request && this.mode === 'edit') {
                        this.renderList([]);
                    }
                });
            } else {
                this.renderList(answer);
            }
        };

        if (immediately) {
            run();
        } else {
            this.list.timer = setTimeout(run, SUGGEST_DELAY_MS);
        }
    }

    /** @param {Array|{options: Array, hint?: string}} answer the view's suggestions, with an optional hint line */
    renderList(answer) {
        const options = Array.isArray(answer) ? answer : (answer?.options ?? []);
        const hint = Array.isArray(answer) ? '' : (answer?.hint ?? '');
        const column = this.columns[this.colIndex(this.editing?.col)];
        this.list.options = options;
        this.list.open = true;
        this.listbox.innerHTML = options.map((option, index) => `<li role="option" id="${this.listbox.id}-${index}" class="sheet-option${option.create ? ' sheet-option-create' : ''}${option.className ? ` ${escapeHtml(option.className)}` : ''}" aria-selected="false" data-index="${index}">${option.html ?? escapeHtml(option.label ?? '')}${option.detail ? ` <small class="sheet-option-detail">${escapeHtml(option.detail)}</small>` : ''}</li>`).join('');
        this.listbox.hidden = options.length === 0;
        this.editor.setAttribute('aria-expanded', options.length > 0 ? 'true' : 'false');

        if (options.length === 0) {
            this.showListStatus(hint !== '' ? hint : (this.editor.value.trim() === '' ? '' : this.texts.t('grid_no_matches')));
            this.list.active = -1;
            this.editor.removeAttribute('aria-activedescendant');
        } else {
            this.showListStatus(hint);
            this.highlight(column?.autoHighlight === false ? -1 : 0);
            this.announce(this.texts.tc('grid_suggestions', options.length));
        }
    }

    showListStatus(text) {
        this.listStatus.textContent = text;
        this.listStatus.hidden = text === '';
        // Under the options when there are some
        this.listStatus.classList.toggle('is-below', !this.listbox.hidden);

        if (!this.listbox.hidden) {
            this.listStatus.style.top = `${this.listbox.offsetTop + this.listbox.offsetHeight}px`;
        }
    }

    highlight(index) {
        this.list.active = index;

        Array.from(this.listbox.children).forEach((element, position) => {
            element.setAttribute('aria-selected', position === index ? 'true' : 'false');
            element.classList.toggle('is-active', position === index);

            if (position === index) {
                element.scrollIntoView({ block: 'nearest' });
            }
        });

        if (index >= 0) {
            this.editor.setAttribute('aria-activedescendant', `${this.listbox.id}-${index}`);
        } else {
            this.editor.removeAttribute('aria-activedescendant');
        }
    }

    closeList() {
        clearTimeout(this.list.timer);
        this.list.request++;
        this.list.open = false;
        this.list.options = [];
        this.list.active = -1;
        this.listbox.hidden = true;
        this.listbox.replaceChildren();
        this.listStatus.hidden = true;
        this.editor.setAttribute('aria-expanded', 'false');
        this.editor.removeAttribute('aria-activedescendant');
    }

    // ---------------------------------------------------------------- clipboard

    selectionBlock() {
        const rect = this.currentRect();
        const block = [];

        for (let r = rect.r0; r <= rect.r1; r++) {
            const cells = [];

            for (let c = rect.c0; c <= rect.c1; c++) {
                const column = this.columns[c];
                const content = this.content(this.rows[r], column.key);
                let value = content.copy ?? content.text ?? '';

                if (content.copy === undefined && column.kind === 'checkbox') {
                    value = content.checked ? 'TRUE' : 'FALSE';
                }

                cells.push(String(value));
            }

            block.push(cells);
        }

        return block;
    }

    /**
     * WebKit fires no copy/cut/paste event for the shortcut on a non-editable element: inside the keydown the focus
     * moves to a hidden textarea, the browser's own command runs there, our handlers read/write the clipboard, and the
     * focus goes back to the cell.
     */
    armProxy(kind) {
        const proxy = this.proxy;
        this.proxyKind = kind;
        proxy.value = kind === 'paste' ? '' : toTsv(this.selectionBlock());
        proxy.hidden = false;
        proxy.focus({ preventScroll: true });
        proxy.select();
        clearTimeout(this.proxyTimer);
        // Nothing came (an empty clipboard, the command refused): back to the cell
        this.proxyTimer = setTimeout(() => this.restoreFromProxy(), 250);
    }

    restoreFromProxy() {
        clearTimeout(this.proxyTimer);
        this.proxyKind = null;
        const hadFocus = document.activeElement === this.proxy;
        this.proxy.hidden = true;

        if (hadFocus) {
            this.focusActive({ scroll: false });
        }
    }

    onCopy(event, cut = false) {
        if (this.mode === 'edit') {
            return;
        }

        const block = this.selectionBlock();
        event.clipboardData?.setData('text/plain', toTsv(block));
        event.clipboardData?.setData('text/html', toHtmlTable(block));
        event.preventDefault();
        const count = block.length * (block[0]?.length ?? 0);

        if (cut) {
            (this.options.cut ?? this.options.clear)?.(this.selectedKeys());
        }

        this.announce(this.texts.tc(cut ? 'grid_cut' : 'grid_copied', count));
    }

    onPaste(event) {
        if (this.mode === 'edit') {
            return;
        }

        const data = event.clipboardData;
        const rows = rowsFromClipboard(data?.getData('text/plain') ?? '', data?.getData('text/html') ?? '');
        event.preventDefault();

        if (rows.length === 0) {
            this.announce(this.texts.t('grid_nothing_to_paste'));

            return;
        }

        this.options.paste?.({ ...this.active }, rows, this.selectedKeys());
    }

    // ---------------------------------------------------------------- mouse

    onPointerDown(event) {
        if (event.button !== 0) {
            return;
        }

        const header = event.target.closest?.('thead th');

        if (header && this.thead.contains(header)) {
            event.preventDefault();
            const index = Number(header.dataset.c);

            if (this.columns[index] !== undefined && this.rows.length > 0) {
                if (this.mode === 'edit' && !this.commitEdit(null, false, undefined, 'blur')) {
                    return;
                }

                this.active = { row: this.rows[0], col: this.columns[index].key };
                this.focusActive({ scroll: false });
                this.selectColumn(index);
            }

            return;
        }

        const position = this.positionOf(event.target);

        if (position === null) {
            return;
        }

        if (this.mode === 'edit') {
            if (this.editing.row === position.row && this.editing.col === position.col) {
                return;
            }

            if (!this.commitEdit(null, false, undefined, 'blur')) {
                event.preventDefault();

                return;
            }
        }

        const interactive = event.target.closest('a, input');

        if (!interactive) {
            // No text selection drag, no focus jump to the body - the grid focuses the cell itself
            event.preventDefault();
        }

        if (event.shiftKey) {
            this.active = position;
            this.focusActive({ scroll: false });
            this.setSelection(this.rectOf(this.anchor, this.active));
        } else {
            this.active = position;
            this.anchor = { ...position };
            this.setSelection(null);
            this.focusActive({ scroll: false });
            this.dragging = event.pointerType === 'mouse' && !interactive;
        }
    }

    onPointerMove(event) {
        if (!this.dragging || (event.buttons & 1) === 0) {
            this.dragging = false;

            return;
        }

        const position = this.positionOf(document.elementFromPoint(event.clientX, event.clientY));

        if (position !== null && (position.row !== this.active.row || position.col !== this.active.col)) {
            this.active = position;
            this.setTabStop();
            this.setSelection(this.rectOf(this.anchor, this.active));
        }
    }

    onDoubleClick(event) {
        const position = this.positionOf(event.target);

        if (position === null || event.target.closest('a, input')) {
            return;
        }

        this.active = position;
        const column = this.columns[this.colIndex(position.col)];
        const kind = this.cellKind(position.row, column);

        if (kind === 'action') {
            this.options.activate?.(position.row, position.col);
        } else if (kind === 'text' || kind === 'list') {
            this.startEdit(false);
        }
    }

    onChange(event) {
        if (!event.target.matches?.('input.sheet-check')) {
            return;
        }

        const position = this.positionOf(event.target);

        if (position === null) {
            return;
        }

        const value = event.target.checked;
        let cells = [position];

        // A click on a checkbox inside a range changes the whole range (Sheets); otherwise only that cell
        if (this.selection !== null && this.selectedKeys().some((cell) => cell.row === position.row && cell.col === position.col)) {
            cells = this.selectedKeys();
            this.toggleCells(value);
        } else {
            this.active = position;
            this.setTabStop();
            this.options.toggle?.([position], value);
        }

        // The box shows what the view made of the click: a refused change puts the tick back
        for (const cell of cells) {
            const column = this.columns[this.colIndex(cell.col)];
            const element = this.cellElement(cell.row, cell.col);

            if (column?.kind === 'checkbox' && element !== null) {
                this.syncCheckbox(element, this.content(cell.row, cell.col));
            }
        }
    }

    onFocusIn(event) {
        if (event.target === this.editor || event.target === this.proxy) {
            return;
        }

        const position = this.positionOf(event.target);

        // Focus that arrived another way (a screen reader, a click) moves the roving tab stop with it
        if (position !== null && (position.row !== this.active.row || position.col !== this.active.col)) {
            this.active = position;
            this.anchor = { ...position };
            this.setTabStop();
        }
    }

    focusNotice(index) {
        const buttons = [...this.notice.querySelectorAll('[data-notice-action]')];
        const button = buttons[Math.max(0, Math.min(index, buttons.length - 1))];

        if (button === undefined) {
            return;
        }

        this.focusingNotice = true;

        try {
            button.focus({ preventScroll: true });
        } finally {
            this.focusingNotice = false;
        }
    }

    backToEditor() {
        this.focusingNotice = true;

        try {
            this.editor.focus({ preventScroll: true });
        } finally {
            this.focusingNotice = false;
        }
    }

    onNoticeKeyDown(event) {
        const buttons = [...this.notice.querySelectorAll('[data-notice-action]')];
        const index = buttons.indexOf(event.target.closest?.('[data-notice-action]'));

        if (event.key === 'Escape') {
            event.preventDefault();
            this.backToEditor();
        } else if (event.key === 'Tab') {
            event.preventDefault();
            const next = index + (event.shiftKey ? -1 : 1);

            if (next < 0 || next >= buttons.length) {
                this.backToEditor();
            } else {
                this.focusNotice(next);
            }
        }
    }

    runNoticeAction(index) {
        const action = this.noticeActions[index];

        if (action !== undefined) {
            action.run();
        }
    }

    /** Focus left the notice for somewhere else than the editor: like leaving the editor - the edit is committed. */
    onNoticeFocusOut() {
        setTimeout(() => {
            if (this.destroyed || this.mode !== 'edit') {
                return;
            }

            const active = document.activeElement;

            if (active !== this.editor && !this.notice.contains(active)) {
                this.commitOpenEdit();
            }
        }, 0);
    }

    // ---------------------------------------------------------------- wiring

    on(target, type, handler, options) {
        target.addEventListener(type, handler, options);
        this.listeners.push(() => target.removeEventListener(type, handler, options));
    }

    wire() {
        this.on(this.table, 'keydown', (event) => this.onKeyDown(event));
        this.on(this.editor, 'keydown', (event) => this.onKeyDown(event));
        this.on(this.editor, 'input', () => this.onEditorInput());
        this.on(this.editor, 'compositionstart', () => {
            this.composing = true;
        });
        this.on(this.editor, 'compositionend', () => {
            this.composing = false;
            this.onEditorInput();
        });
        this.on(this.editor, 'blur', () => this.onEditorBlur());
        this.on(this.listbox, 'mousedown', (event) => event.preventDefault());
        this.on(this.listbox, 'click', (event) => {
            const option = event.target.closest('[role="option"]');

            if (option !== null) {
                this.commitEdit(null, false, this.list.options[Number(option.dataset.index)] ?? null, 'click');
            }
        });
        // The notice's buttons: a mouse press keeps the focus in the editor (a blur would commit)
        this.on(this.notice, 'mousedown', (event) => event.preventDefault());
        this.on(this.notice, 'click', (event) => {
            const button = event.target.closest('[data-notice-action]');

            if (button !== null) {
                this.runNoticeAction(Number(button.dataset.noticeAction));
            }
        });
        this.on(this.notice, 'keydown', (event) => this.onNoticeKeyDown(event));
        this.on(this.notice, 'focusout', () => this.onNoticeFocusOut());
        this.on(this.table, 'pointerdown', (event) => this.onPointerDown(event));
        this.on(this.table, 'pointermove', (event) => this.onPointerMove(event));
        this.on(this.table, 'dblclick', (event) => this.onDoubleClick(event));
        this.on(this.table, 'change', (event) => this.onChange(event));
        this.on(this.table, 'focusin', (event) => this.onFocusIn(event));
        this.on(this.table, 'copy', (event) => this.onCopy(event));
        this.on(this.table, 'cut', (event) => this.onCopy(event, true));
        this.on(this.table, 'paste', (event) => this.onPaste(event));
        this.on(this.proxy, 'copy', (event) => {
            this.onCopy(event);
            setTimeout(() => this.restoreFromProxy(), 0);
        });
        this.on(this.proxy, 'cut', (event) => {
            this.onCopy(event, true);
            setTimeout(() => this.restoreFromProxy(), 0);
        });
        this.on(this.proxy, 'paste', (event) => {
            this.onPaste(event);
            setTimeout(() => this.restoreFromProxy(), 0);
        });
        this.on(this.proxy, 'keydown', (event) => {
            if (event.key === 'Escape' || event.key === 'Tab') {
                this.restoreFromProxy();
            }
        });
        this.on(this.proxy, 'blur', () => {
            this.proxy.hidden = true;
        });
        this.on(window, 'resize', () => this.fitHeight());

        // A banner or the problems panel above the grid moves it down: its height follows
        if (typeof ResizeObserver !== 'undefined') {
            let frame = null;
            const observer = new ResizeObserver(() => {
                cancelAnimationFrame(frame);
                frame = requestAnimationFrame(() => {
                    if (!this.destroyed) {
                        this.fitHeight();
                    }
                });
            });
            observer.observe(document.body);
            this.listeners.push(() => {
                cancelAnimationFrame(frame);
                observer.disconnect();
            });
        }
        this.on(this.scroller, 'scroll', () => {
            if (this.mode === 'edit' && this.editing !== null) {
                const cell = this.cellElement(this.editing.row, this.editing.col);

                if (cell !== null) {
                    this.positionEditor(cell, this.editing.kind === 'list');
                }
            }
        }, { passive: true });
    }

    announce(text) {
        if (text) {
            this.options.announce?.(text);
        }
    }

    isEditing() {
        return this.mode === 'edit';
    }

    /**
     * Removes the grid. An open edit is committed first, like a blur - typed text is never lost when a view goes (a tab
     * switch, the breakpoint, the page leaving); `keepEdit: true` when the caller resumes it in a new grid (editState()).
     */
    destroy({ keepEdit = false } = {}) {
        if (this.destroyed || this.destroying) {
            return;
        }

        this.destroying = true;

        if (!keepEdit && this.mode === 'edit') {
            try {
                this.commitOpenEdit();
            } catch (error) {
                console.error(error);
            }
        }

        this.destroyed = true;
        clearTimeout(this.proxyTimer);
        clearTimeout(this.list.timer);
        this.listeners.forEach((remove) => remove());
        this.listeners = [];
        this.options.container.replaceChildren();
    }
}

function pickKeyEvent(event) {
    return {
        key: event.key,
        code: event.code,
        shiftKey: event.shiftKey,
        ctrlKey: event.ctrlKey,
        metaKey: event.metaKey,
        altKey: event.altKey,
        isComposing: event.isComposing,
        keyCode: event.keyCode,
    };
}
