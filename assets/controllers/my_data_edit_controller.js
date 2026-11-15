import { Controller } from '@hotwired/stimulus';

/**
 * Editing one cell in place on the wide table of "My data" (docs/features/my-data.md "Editing in place").
 *
 * A double-click or Enter (or F2) on a cell with `data-edit` turns it into an input; Enter saves that ONE field,
 * Escape cancels. The save is a POST to the row's `data-cell-url` with the column, what the cell showed when the
 * editor opened (`from` = its `data-value` then - never what it holds at commit) and the new value; the server
 * checks it like the row sheet does and answers with the row's cells as they are stored now.
 *
 *  - saved: the cells of the row are written from the answer;
 *  - 409 (the value was changed meanwhile): nothing saved, the cell shows the current value and says so;
 *  - a refusal: the editor stays open with what was typed and the reason under it; a refusal that is a question
 *    (first try, the same time saved already, a time far off the player's own) offers "Open the row" - it is
 *    answered in the row sheet, never here.
 *
 * Nothing is ever written as HTML: texts go through textContent, values through input values. One editor at a time;
 * an editor with a changed value is never closed by a click elsewhere - only by Enter or Escape.
 */
export default class extends Controller {
    static values = {
        token: String,
        openRow: String,
        failed: String,
        hint: String,
    };

    connect() {
        this.editing = null;
    }

    disconnect() {
        if (this.editing !== null) {
            this.close(this.editing);
        }
    }

    open(event) {
        this.start(event.currentTarget);
    }

    key(event) {
        // Keys typed into the editor itself are the editor's
        if (event.target !== event.currentTarget) {
            return;
        }

        if (event.key === 'Enter' || event.key === 'F2') {
            event.preventDefault();
            this.start(event.currentTarget);
        }
    }

    start(cell) {
        if (this.editing !== null) {
            if (this.editing.cell === cell) {
                return;
            }

            // Typed and not saved: never thrown away by opening another cell
            if (this.isDirty(this.editing)) {
                this.note(this.editing, this.hintValue);
                this.editing.input.focus();

                return;
            }

            this.close(this.editing);
        }

        if (!cell.dataset.edit || cell.closest('tr')?.dataset.cellUrl === undefined) {
            return;
        }

        // A remark left by an earlier answer goes with the next thing done to the cell
        cell.querySelectorAll('.my-data-cell-note').forEach((remark) => remark.remove());

        const from = cell.dataset.value ?? '';
        const input = this.input(cell, from);
        const shown = Array.from(cell.childNodes);
        const holder = document.createElement('div');

        holder.className = 'my-data-editor';
        holder.append(input);
        cell.replaceChildren(holder);
        cell.classList.add('my-data-editing');

        const editing = { cell, from, input, shown, holder, saving: false };

        input.addEventListener('keydown', (keyEvent) => {
            if (keyEvent.key === 'Enter') {
                keyEvent.preventDefault();
                this.save(editing);
            } else if (keyEvent.key === 'Escape') {
                keyEvent.preventDefault();
                this.close(editing);
                cell.focus();
            }
        });

        // Left without a change: nothing to keep open
        input.addEventListener('blur', () => {
            window.setTimeout(() => {
                if (this.editing === editing && !editing.saving && !this.isDirty(editing) && !holder.contains(document.activeElement)) {
                    this.close(editing);
                }
            }, 150);
        });

        this.editing = editing;
        input.focus();

        if (typeof input.select === 'function' && input.type !== 'checkbox' && input.type !== 'date') {
            input.select();
        }
    }

    input(cell, from) {
        const type = cell.dataset.edit;

        if (type === 'select') {
            const select = document.createElement('select');
            const options = this.options(cell);

            select.className = 'form-select form-select-sm';

            // A value from before the list changed stays selectable - it is what the row holds
            if (from !== '' && !options.some(([value]) => value === from)) {
                options.push([from, from]);
            }

            options.forEach(([value, label]) => {
                const option = document.createElement('option');

                option.value = value;
                option.textContent = label;
                option.selected = value === from;
                select.append(option);
            });

            return select;
        }

        const input = document.createElement('input');

        if (type === 'check') {
            input.type = 'checkbox';
            input.className = 'form-check-input';
            input.checked = from === '1';

            return input;
        }

        input.type = type === 'date' ? 'date' : 'text';
        input.className = 'form-control form-control-sm';
        input.autocomplete = 'off';
        input.value = from;

        if (type === 'time') {
            input.inputMode = 'numeric';
            input.placeholder = 'h:mm:ss';
        } else if (type === 'number') {
            input.inputMode = 'decimal';
        }

        return input;
    }

    // The options of a select column travel once, on its header cell
    options(cell) {
        const header = cell.closest('table')?.querySelector(`thead th[data-column="${CSS.escape(cell.dataset.column)}"]`);

        try {
            const options = JSON.parse(header?.dataset.editOptions ?? '[]');

            return Array.isArray(options) ? options.filter((option) => Array.isArray(option) && option.length === 2).map(([value, label]) => [String(value), String(label)]) : [];
        } catch (error) {
            return [];
        }
    }

    value(editing) {
        return editing.input.type === 'checkbox' ? (editing.input.checked ? '1' : '0') : editing.input.value;
    }

    isDirty(editing) {
        return this.value(editing).trim() !== editing.from.trim();
    }

    close(editing) {
        editing.cell.replaceChildren(...editing.shown);
        editing.cell.classList.remove('my-data-editing');

        if (this.editing === editing) {
            this.editing = null;
        }
    }

    async save(editing) {
        if (editing.saving) {
            return;
        }

        if (!this.isDirty(editing)) {
            this.close(editing);
            editing.cell.focus();

            return;
        }

        const row = editing.cell.closest('tr');
        const column = editing.cell.dataset.column;
        const body = new URLSearchParams();

        body.set('column', column);

        // A value of an own field travels under the name nothing of it is ever logged with
        if (column.startsWith('f:')) {
            body.set('my_fields[from]', editing.from);
            body.set('my_fields[value]', this.value(editing));
        } else {
            body.set('from', editing.from);
            body.set('value', this.value(editing));
        }

        editing.saving = true;
        editing.input.disabled = true;
        this.note(editing, null);

        let response = null;
        let answer = null;

        try {
            response = await fetch(row.dataset.cellUrl, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-Token': this.tokenValue },
                credentials: 'same-origin',
                body,
            });
            answer = await response.json();
        } catch (error) {
            answer = null;
        }

        editing.saving = false;

        // The table was replaced meanwhile (a refresh): nothing to write into
        if (this.editing !== editing || !editing.cell.isConnected) {
            return;
        }

        if (response !== null && response.ok && answer !== null && typeof answer.cells === 'object' && answer.cells !== null) {
            this.editing = null;
            editing.cell.classList.remove('my-data-editing');
            // Whatever the answer leaves out of this cell, the editor goes
            editing.cell.replaceChildren(...editing.shown);

            Object.entries(answer.cells).forEach(([name, cell]) => {
                const target = Array.from(row.children).find((candidate) => candidate.dataset.column === name);

                if (target) {
                    this.write(target, cell);
                }
            });

            editing.cell.focus();

            return;
        }

        if (response !== null && response.status === 409 && answer !== null && typeof answer.cell === 'object' && answer.cell !== null) {
            // Changed meanwhile: the cell shows what is stored now, the editor's value is gone on purpose
            this.editing = null;
            editing.cell.classList.remove('my-data-editing');
            this.write(editing.cell, answer.cell);
            this.remark(editing.cell, typeof answer.message === 'string' ? answer.message : this.failedValue);
            editing.cell.focus();

            return;
        }

        editing.input.disabled = false;
        this.note(editing, answer !== null && typeof answer.message === 'string' && answer.message !== '' ? answer.message : this.failedValue, answer !== null && answer.row === true ? row.dataset.rowUrl : null);
        editing.input.focus();
    }

    // A cell as the server words it: { value, text, badge? }
    write(cell, content) {
        const text = typeof content.text === 'string' ? content.text : '';
        const nodes = [];

        if (cell.dataset.edit === 'check' || cell.dataset.column === 'first_try') {
            if (content.value === '1') {
                const icon = document.createElement('i');
                const label = document.createElement('span');

                icon.className = 'bi bi-check-lg text-success';
                icon.setAttribute('aria-hidden', 'true');
                label.className = 'visually-hidden';
                label.textContent = text;
                nodes.push(icon, label);
            }
        } else {
            nodes.push(document.createTextNode(text));

            if (typeof content.badge === 'string' && content.badge !== '') {
                const badge = document.createElement('span');

                badge.className = 'badge bg-warning text-dark fw-normal';
                badge.textContent = content.badge;
                nodes.push(document.createTextNode(' '), badge);
            }
        }

        cell.replaceChildren(...nodes);

        // Only a cell that is edited in place keeps a value - and one that became several lines is no such cell any more
        if (cell.dataset.edit && typeof content.value === 'string') {
            cell.dataset.value = content.value;
        }
    }

    // Under the open editor: why it was not saved, and - for a question - the way to the row sheet
    note(editing, message, rowUrl = null) {
        editing.holder.querySelector('.my-data-cell-note')?.remove();

        if (message === null || message === '') {
            return;
        }

        const note = document.createElement('div');

        note.className = this.noteClass(editing.cell);
        note.setAttribute('role', 'alert');
        note.textContent = message;

        if (rowUrl) {
            const link = document.createElement('a');

            link.href = rowUrl;
            link.dataset.turboFrame = 'modal-frame';
            link.className = 'd-block fw-medium';
            link.textContent = this.openRowValue;
            note.append(link);
        }

        editing.holder.append(note);
    }

    // On a closed cell: a remark that goes with the next thing done to it
    remark(cell, message) {
        const note = document.createElement('div');

        note.className = this.noteClass(cell);
        note.setAttribute('role', 'alert');
        note.textContent = message;
        cell.append(note);
    }

    // The note hangs under the cell and is as wide as its text needs, never as narrow as the cell: it opens to the
    // left from a cell in the right half of the table, so it stays inside the table's box
    noteClass(cell) {
        const table = cell.closest('table');
        const atTheEnd = table !== null && cell.offsetLeft + cell.offsetWidth / 2 > table.offsetWidth / 2;

        return atTheEnd ? 'my-data-cell-note my-data-cell-note-end' : 'my-data-cell-note';
    }
}
