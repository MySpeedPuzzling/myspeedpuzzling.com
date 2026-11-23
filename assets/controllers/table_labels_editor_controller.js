/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { officialResultsRequest } from '../official_results_api.js';
import { generate, labelChanges, parseList, validate } from '../table_labels.js';

/**
 * "Table names" on the seating page (docs/features/competitions-management/table-labels-and-plates.md): a hall plan
 * generator ("A-H × 1-22, 2 places") or the organiser's own list, saved for this round and the rounds ticked under
 * "Use for" as one SetRoundTableLabels write (`official_results_table_labels`). Entries keep their numbers - the editor
 * warns which seated entries would read another name first. After saving the page loads again (every list, input and
 * text of the page follows the new names). The pure half is assets/table_labels.js.
 */
export default class extends Controller {
    static targets = ['letters', 'numbers', 'seats', 'seatStyle', 'order', 'skip', 'list', 'preview', 'problems', 'warning', 'round', 'save', 'status'];

    static values = {
        url: String,
        csrfToken: String,
        roundId: String,
        rounds: Array,
        entries: Array,
        texts: Object,
    };

    connect() {
        const current = this.currentOf(this.roundIdValue);

        if (current !== null) {
            this.listTarget.value = current.join('\n');
        }

        this.update();
    }

    t(key, parameters = {}) {
        let text = this.textsValue[key] ?? key;

        for (const [name, value] of Object.entries(parameters)) {
            text = text.replaceAll(name, String(value));
        }

        return text;
    }

    currentOf(roundId) {
        const round = this.roundsValue.find((candidate) => candidate.id === roundId);

        return Array.isArray(round?.tableLabels) && round.tableLabels.length > 0 ? round.tableLabels : null;
    }

    fill(event) {
        event?.preventDefault();
        const result = generate({
            letters: this.lettersTarget.value,
            numbers: this.numbersTarget.value,
            seats: Number(this.seatsTarget.value),
            seatStyle: this.seatStyleTarget.value,
            order: this.orderTarget.value,
            skip: this.skipTarget.value,
        });

        if (result.problems) {
            this.showProblems(result.problems.map((problem) => this.t(`reason_${problem.reason}`, { '%label%': problem.label, '%first%': problem.first ?? '' })));

            return;
        }

        this.listTarget.value = result.labels.join('\n');
        this.update();
    }

    showProblems(messages) {
        this.problemsTarget.replaceChildren(...messages.map((message) => {
            const item = document.createElement('li');
            item.textContent = message;

            return item;
        }));
        this.problemsTarget.hidden = messages.length === 0;
    }

    // The list as typed: a preview ("350 tables: A1 L, A1 R … H21 R"), its problems, and what it does to seated entries
    update() {
        const typed = parseList(this.listTarget.value);
        this.labels = null;

        if (typed.length === 0) {
            this.previewTarget.textContent = this.t('preview_none');
            this.showProblems([]);
            this.warningTarget.hidden = true;
            this.saveTarget.disabled = true;

            return;
        }

        const checked = validate(typed);

        if (checked.problems) {
            this.previewTarget.textContent = '';
            this.showProblems(checked.problems.map((problem) => this.t(`reason_${problem.reason}`, { '%label%': problem.label, '%first%': problem.first ?? '' })));
            this.warningTarget.hidden = true;
            this.saveTarget.disabled = true;

            return;
        }

        this.labels = checked.labels;
        const sample = this.labels.length <= 6
            ? this.labels.join(', ')
            : `${this.labels.slice(0, 3).join(', ')} … ${this.labels.slice(-2).join(', ')}`;
        this.previewTarget.textContent = this.t('preview', { '%count%': this.labels.length, '%sample%': sample });
        this.showProblems([]);

        const { renamed, highest } = labelChanges(this.entriesValue, this.currentOf(this.roundIdValue), this.labels);
        const warnings = [];

        if (highest > this.labels.length) {
            warnings.push(this.t('too_few', { '%highest%': highest, '%count%': this.labels.length }));
        }

        if (renamed.length > 0) {
            const first = renamed[0];
            warnings.push(this.t('renamed', { '%count%': renamed.length, '%name%': first.name, '%from%': first.from, '%to%': first.to }));
        }

        this.warningTarget.textContent = warnings.join(' ');
        this.warningTarget.hidden = warnings.length === 0;
        this.saveTarget.disabled = highest > this.labels.length;
    }

    async save(event) {
        event.preventDefault();

        if (this.labels === null) {
            return;
        }

        await this.send(this.labels);
    }

    async removeLabels(event) {
        event.preventDefault();

        if (!window.confirm(this.t('remove_confirm'))) {
            return;
        }

        await this.send(null);
    }

    async send(labels) {
        const rounds = [this.roundIdValue, ...this.roundTargets.filter((input) => input.checked).map((input) => input.value)];
        const expected = Object.fromEntries(rounds.map((roundId) => [roundId, this.currentOf(roundId)]));

        this.saveTarget.disabled = true;
        this.statusTarget.textContent = this.t('saving');

        const result = await officialResultsRequest(this.urlValue, {
            method: 'POST',
            body: { rounds, labels, expected },
            csrfToken: this.csrfTokenValue,
        });

        if (result.kind === 'ok') {
            this.statusTarget.textContent = this.t('saved');
            // Every list, input and text of the page follows the new names
            window.location.reload();

            return;
        }

        this.saveTarget.disabled = false;
        this.statusTarget.textContent = '';
        const problems = Array.isArray(result.data?.problems) ? result.data.problems : [];
        this.showProblems(problems.length > 0
            ? problems.map((problem) => problem.message ?? problem.reason)
            : [this.t(result.kind === 'auth' ? 'sign_in' : (result.kind === 'offline' ? 'offline' : 'failed'))]);
    }
}
