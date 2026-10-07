/**
 * The match-then-confirm dialog of the participants spreadsheet (§6 "Bigger actions get a preview"; start.gg's bulk add)
 * - generic: the caller (a paste planner, a bulk action, "Make a pair/team") hands in what it will do, the dialog shows
 * it and returns what the organiser confirmed. Never applies anything itself.
 *
 *   const dialog = new PreviewDialog({host, texts, title, ...});
 *   dialog.open();                       // a native modal <dialog>, focus inside, Esc = cancel
 *   dialog.update({counts, lines, ...})  // e.g. when the server's dry run answered (`loading: true` until then)
 *   const selection = await dialog.result;   // null = cancelled, else {choices: {lineId: value}, ticks: {lineId: bool}}
 *
 * - `counts`: [{text, tone?}] - "12 new pairs · 3 moves · 1 new person" (the caller words and pluralises them);
 * - `lines`: [{id, text, status, note?, choices?, tick?}] - status `new` | `change` | `same` | `warning` | `error` |
 *   `skip`, shown as an icon **and** its word (never colour alone); `choices` = {label, options: [{value, label}],
 *   value} for an ambiguous match ("2 pairs are called Corners - which one, or a new pair?"); `tick` = {label, checked}
 *   for "add as a new participant" (D9, ticked per name);
 * - `onConfirm(selection)` may return a Promise; `{error: '...'}` keeps the dialog open with the message.
 *
 * Texts come from the caller (`texts.t()` of the core texts: preview_status_*, preview_confirm, preview_cancel...).
 */

import { escapeHtml } from './sheet_grid.js';

const STATUS_ICONS = {
    new: 'bi-plus-circle',
    change: 'bi-pencil',
    same: 'bi-check2',
    warning: 'bi-exclamation-triangle',
    error: 'bi-x-octagon',
    skip: 'bi-dash-circle',
};

let dialogCounter = 0;

export class PreviewDialog {
    /**
     * @param {object} options
     * @param {HTMLElement} options.host
     * @param {{t: function(string, object=): string}} options.texts
     * @param {string} options.title
     * @param {string} [options.intro]
     * @param {Array<{text: string, tone?: string}>} [options.counts]
     * @param {Array<object>} [options.lines]
     * @param {boolean} [options.loading]
     * @param {string} [options.confirmLabel]
     * @param {string} [options.message]          a note above the buttons (e.g. "Could not check with the server")
     * @param {function(object): (Promise<object|void>|object|void)} [options.onConfirm]
     * @param {function(): void} [options.returnFocus]   where the focus goes when the dialog closes (default: where it was)
     */
    constructor(options) {
        this.options = { counts: [], lines: [], loading: false, ...options };
        this.id = `sheet-preview-${++dialogCounter}`;
        this.choices = {};
        this.ticks = {};
        this.result = new Promise((resolve) => {
            this.resolve = resolve;
        });
        this.settled = false;
    }

    t(key, params) {
        return this.options.texts.t(key, params);
    }

    open() {
        this.returnFocus = document.activeElement;
        const dialog = document.createElement('dialog');
        dialog.className = 'sheet-preview';
        dialog.setAttribute('aria-labelledby', `${this.id}-title`);
        dialog.innerHTML = `
            <form method="dialog" class="sheet-preview-form" novalidate>
                <div class="sheet-preview-head">
                    <h2 class="h5 mb-0" id="${this.id}-title" tabindex="-1"></h2>
                    <button type="button" class="btn-close" data-preview-cancel aria-label="${escapeHtml(this.t('preview_close'))}"></button>
                </div>
                <div class="sheet-preview-body">
                    <p class="sheet-preview-intro" data-preview-intro></p>
                    <p class="sheet-preview-counts fw-semibold" data-preview-counts></p>
                    <div class="sheet-preview-loading" data-preview-loading role="status"></div>
                    <ul class="sheet-preview-lines list-unstyled" data-preview-lines></ul>
                </div>
                <div class="sheet-preview-foot">
                    <div class="sheet-preview-message small" data-preview-message role="alert"></div>
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-outline-secondary" data-preview-cancel>${escapeHtml(this.t('preview_cancel'))}</button>
                        <button type="submit" class="btn btn-primary" data-preview-confirm></button>
                    </div>
                </div>
            </form>`;
        this.dialog = dialog;
        this.options.host.append(dialog);

        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            this.finish(null);
        });
        dialog.querySelectorAll('[data-preview-cancel]').forEach((button) => button.addEventListener('click', () => this.finish(null)));
        dialog.querySelector('form').addEventListener('submit', (event) => {
            event.preventDefault();
            this.confirm();
        });
        dialog.addEventListener('change', (event) => this.onChange(event));

        this.render();
        dialog.showModal();
        // Confirm when there is something to confirm; while the server checks, the title (Enter must never cancel)
        (dialog.querySelector('[data-preview-confirm]:not([disabled])') ?? dialog.querySelector(`#${this.id}-title`))?.focus();

        return this;
    }

    update(options) {
        const wasLoading = this.options.loading;
        this.options = { ...this.options, ...options };

        if (this.dialog) {
            this.render();

            // The check answered: the focus moves on to Confirm unless the organiser already went elsewhere
            const title = this.dialog.querySelector(`#${this.id}-title`);
            const confirm = this.dialog.querySelector('[data-preview-confirm]');

            if (wasLoading && !this.options.loading && (document.activeElement === title || !this.dialog.contains(document.activeElement)) && !confirm.disabled) {
                confirm.focus();
            }
        }
    }

    render() {
        const { title, intro, counts, lines, loading, confirmLabel, message } = this.options;
        const dialog = this.dialog;
        dialog.querySelector(`#${this.id}-title`).textContent = title ?? '';

        const introElement = dialog.querySelector('[data-preview-intro]');
        introElement.textContent = intro ?? '';
        introElement.hidden = !intro;

        const countsElement = dialog.querySelector('[data-preview-counts]');
        countsElement.innerHTML = (counts ?? []).map((count) => `<span class="sheet-preview-count${count.tone ? ` text-${escapeHtml(count.tone)}-emphasis` : ''}">${escapeHtml(count.text)}</span>`).join('<span class="text-body-secondary"> · </span>');
        countsElement.hidden = loading || (counts ?? []).length === 0;

        const loadingElement = dialog.querySelector('[data-preview-loading]');
        loadingElement.hidden = !loading;
        loadingElement.innerHTML = loading ? `<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>${escapeHtml(this.t('preview_checking'))}` : '';

        const list = dialog.querySelector('[data-preview-lines]');
        list.innerHTML = loading ? '' : (lines ?? []).map((line) => this.lineHtml(line)).join('');

        for (const line of lines ?? []) {
            if (line.choices && !(line.id in this.choices)) {
                this.choices[line.id] = line.choices.value ?? line.choices.options[0]?.value ?? null;
            }

            if (line.tick && !(line.id in this.ticks)) {
                this.ticks[line.id] = line.tick.checked === true;
            }
        }

        const messageElement = dialog.querySelector('[data-preview-message]');
        messageElement.textContent = message ?? '';
        messageElement.hidden = !message;

        const confirm = dialog.querySelector('[data-preview-confirm]');
        confirm.textContent = confirmLabel ?? this.t('preview_confirm');
        confirm.disabled = loading || this.confirming === true;
    }

    lineHtml(line) {
        const status = STATUS_ICONS[line.status] ? line.status : 'same';
        const statusText = this.t(`preview_status_${status}`);
        let controls = '';

        if (line.choices) {
            const value = this.choices[line.id] ?? line.choices.value;
            controls += `<label class="sheet-preview-choice small">${escapeHtml(line.choices.label ?? '')}
                <select class="form-select form-select-sm" data-choice="${escapeHtml(line.id)}">
                    ${line.choices.options.map((option) => `<option value="${escapeHtml(option.value)}"${option.value === value ? ' selected' : ''}>${escapeHtml(option.label)}</option>`).join('')}
                </select></label>`;
        }

        if (line.tick) {
            const checked = this.ticks[line.id] ?? line.tick.checked === true;
            controls += `<label class="form-check sheet-preview-tick small mb-0"><input type="checkbox" class="form-check-input" data-tick="${escapeHtml(line.id)}"${checked ? ' checked' : ''}> <span class="form-check-label">${escapeHtml(line.tick.label)}</span></label>`;
        }

        return `<li class="sheet-preview-line sheet-preview-${status}">
            <span class="sheet-preview-status"><i class="bi ${STATUS_ICONS[status]}" aria-hidden="true"></i> ${escapeHtml(statusText)}</span>
            <span class="sheet-preview-text">${escapeHtml(line.text)}${line.note ? `<span class="d-block small text-body-secondary">${escapeHtml(line.note)}</span>` : ''}</span>
            ${controls ? `<span class="sheet-preview-controls">${controls}</span>` : ''}
        </li>`;
    }

    onChange(event) {
        const choice = event.target.closest('[data-choice]');

        if (choice) {
            this.choices[choice.dataset.choice] = choice.value;
        }

        const tick = event.target.closest('[data-tick]');

        if (tick) {
            this.ticks[tick.dataset.tick] = tick.checked;
        }
    }

    selection() {
        return { choices: { ...this.choices }, ticks: { ...this.ticks } };
    }

    async confirm() {
        if (this.options.loading || this.confirming) {
            return;
        }

        const selection = this.selection();

        if (this.options.onConfirm) {
            this.confirming = true;
            this.render();
            let answer;

            try {
                answer = await this.options.onConfirm(selection);
            } finally {
                this.confirming = false;
            }

            if (answer && typeof answer.error === 'string') {
                this.update({ message: answer.error });

                return;
            }
        }

        this.finish(selection);
    }

    finish(selection) {
        if (this.settled) {
            return;
        }

        this.settled = true;
        this.dialog?.close();
        this.dialog?.remove();
        this.resolve(selection);

        if (typeof this.options.returnFocus === 'function') {
            this.options.returnFocus();
        } else if (this.returnFocus && this.returnFocus.isConnected && !this.returnFocus.hidden) {
            this.returnFocus.focus({ preventScroll: true });
        }
    }

    close() {
        this.finish(null);
    }
}
