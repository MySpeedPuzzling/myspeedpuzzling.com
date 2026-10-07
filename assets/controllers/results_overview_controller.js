/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { officialResultsRequest } from '../official_results_api.js';

const ROUND_PLACEHOLDER = '00000000-0000-0000-0000-000000000000';

/**
 * The results overview's counters follow the rounds' private Mercure topics (docs/features/competitions-management/
 * results-desk.md): every official results update carries the round's progress (RoundResultsOverview), a refresh
 * signal fetches it. The rows are server-rendered; this only rewrites the numbers and badges of the round that changed.
 * Coming back to the tab after a while reloads the page (the subscription may have lapsed meanwhile).
 */
export default class extends Controller {
    static values = {
        stateTemplate: String,
        texts: Object,
        isOnline: Boolean,
    };

    connect() {
        this.hiddenSince = null;
        this.onMercure = this.onMercure.bind(this);
        this.onVisibility = this.onVisibility.bind(this);
        document.addEventListener('mercure:message', this.onMercure);
        document.addEventListener('visibilitychange', this.onVisibility);
    }

    disconnect() {
        document.removeEventListener('mercure:message', this.onMercure);
        document.removeEventListener('visibilitychange', this.onVisibility);
    }

    row(roundId) {
        return this.element.querySelector(`[data-overview-round="${CSS.escape(roundId)}"]`);
    }

    async onMercure(event) {
        const detail = event.detail;

        if (!detail || typeof detail.roundId !== 'string' || this.row(detail.roundId) === null) {
            return;
        }

        if ((detail.type === 'official_results.entries' || detail.type === 'official_results.round') && detail.round) {
            this.update(detail.round);
        } else if (detail.type === 'official_results.refresh') {
            const answer = await officialResultsRequest(this.stateTemplateValue.replace(ROUND_PLACEHOLDER, detail.roundId));

            if (answer.kind === 'ok' && answer.data.round) {
                this.update(answer.data.round);
            }
        }
    }

    update(round) {
        const row = this.row(round.id);

        if (row === null) {
            return;
        }

        const entries = round.entries ?? {};
        const set = (field, text) => {
            row.querySelectorAll(`[data-overview-field="${field}"]`).forEach((element) => { element.textContent = text; });
        };

        set('entries', String(entries.total ?? 0));
        set('results', `${entries.withResult ?? 0} / ${entries.total ?? 0}`);
        set('qualified', String(entries.qualified ?? 0));

        const progress = row.querySelector('[data-overview-progress]');
        if (progress) {
            const percent = entries.total > 0 ? Math.round((entries.withResult / entries.total) * 100) : 0;
            progress.style.width = `${percent}%`;
            progress.closest('[role="progressbar"]')?.setAttribute('aria-valuenow', String(percent));
        }

        const tables = row.querySelector('[data-overview-field="tables"]');
        if (tables && !this.isOnlineValue) {
            const seated = entries.withTableNumber ?? 0;
            const total = entries.total ?? 0;

            if (round.tableNumbersOff) {
                tables.textContent = this.textsValue.tables_off;
                tables.className = 'text-body-secondary';
            } else {
                tables.textContent = `${seated} / ${total}`;
                tables.className = total > 0 && seated >= total ? 'text-success' : (seated < total ? 'text-warning-emphasis' : '');
            }
        }

        row.querySelectorAll('[data-overview-published]').forEach((element) => {
            element.hidden = (element.dataset.overviewPublished === 'true') !== (round.resultsPublished === true);
        });

        row.querySelectorAll('[data-overview-stopwatch]').forEach((element) => {
            element.hidden = element.dataset.overviewStopwatch !== (round.stopwatch?.status ?? 'none');
        });
    }

    onVisibility() {
        if (document.visibilityState === 'hidden') {
            this.hiddenSince = Date.now();

            return;
        }

        // Never while a dialog is open (an advancement plan under review)
        if (this.hiddenSince !== null && Date.now() - this.hiddenSince > 60000 && document.querySelector('.modal.show') === null) {
            window.location.reload();
        }

        this.hiddenSince = null;
    }
}
