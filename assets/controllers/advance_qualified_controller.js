/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';
import { officialResultsRequest } from '../official_results_api.js';
import { formatResultTime } from '../official_results_time.js';
import { seatAdvanced } from '../official_results_qualification.js';
import { chooseTranslation } from '../translation_choice.js';

const ROUND_PLACEHOLDER = '00000000-0000-0000-0000-000000000000';

/**
 * "Advance the qualified" (docs/features/competitions-management/results-desk.md) - from the results desk (the
 * round preselected as the source) and from the results overview: pick the rounds the qualified come from and the
 * rounds they go into (one category only), how they are spread, then the server's dry-run plan (who goes where,
 * who is skipped and why, entries before/after per round). "Add them" applies exactly that plan through its
 * `planHash`; when anything changed meanwhile the server refuses (409 plan_changed) and the new plan is shown.
 * Afterwards "Seat them now" gives the advanced entries table numbers in the plan's seed order.
 */
export default class extends Controller {
    static targets = ['modal', 'choose', 'sources', 'targets', 'distribution', 'map', 'countryRule', 'unsaved', 'error', 'plan', 'done', 'backButton', 'planButton', 'applyButton'];

    static values = {
        rounds: Array,
        source: String,
        urls: Object,
        csrfToken: String,
        texts: Object,
        isOnline: Boolean,
    };

    connect() {
        this.rounds = this.roundsValue;
        this.chosenSources = new Set();
        this.chosenTargets = new Set();
        this.distribution = null;
        this.targetBySource = {};
        this.countryRule = null;
        this.plan = null;
        this.busy = false;
    }

    disconnect() {
        if (this.hasModalTarget) {
            Modal.getInstance(this.modalTarget)?.hide();
        }
    }

    // ---------------------------------------------------------------- texts

    t(key, params = {}) {
        let text = typeof this.textsValue[key] === 'string' ? this.textsValue[key] : key;

        for (const [name, value] of Object.entries(params)) {
            text = text.replaceAll(`%${name}%`, String(value));
        }

        return text;
    }

    tc(key, count, params = {}) {
        const message = this.textsValue[key];
        let text = message && typeof message === 'object' ? chooseTranslation(message.message, count, message.locale) : null;

        if (text === null) {
            text = typeof message === 'string' ? message : String(count);
        }

        for (const [name, value] of Object.entries({ count, ...params })) {
            text = text.replaceAll(`%${name}%`, String(value));
        }

        return text;
    }

    url(template, roundId) {
        return template.replace(ROUND_PLACEHOLDER, roundId);
    }

    round(id) {
        return this.rounds.find((round) => round.id === id) ?? null;
    }

    // ---------------------------------------------------------------- choosing

    async open() {
        this.chosenSources = new Set(this.sourceValue && this.round(this.sourceValue) ? [this.sourceValue] : []);
        this.chosenTargets = new Set();
        this.distribution = null;
        this.targetBySource = {};
        this.countryRule = null;
        this.plan = null;
        this.resetCountryRule();
        this.showStep('choose');
        this.renderChoose();
        Modal.getOrCreateInstance(this.modalTarget).show();

        // The qualified counts as they are right now
        const anyRound = this.sourceValue || this.rounds[0]?.id;
        if (anyRound) {
            const answer = await officialResultsRequest(this.url(this.urlsValue.stateTemplate, anyRound));

            if (answer.kind === 'ok' && Array.isArray(answer.data.rounds)) {
                // Fresh counts, the page's order and colours kept
                const fresh = new Map(answer.data.rounds.map((round) => [round.id, round]));
                this.rounds = [
                    ...this.rounds.filter((round) => fresh.has(round.id)).map((round) => ({ ...round, ...fresh.get(round.id) })),
                    ...answer.data.rounds.filter((round) => !this.rounds.some((known) => known.id === round.id)),
                ];

                if (this.plan === null) {
                    this.renderChoose();
                }
            }
        }
    }

    category() {
        const first = [...this.chosenSources][0];

        return first ? this.round(first)?.category ?? null : null;
    }

    roundLabel(round) {
        return `<span class="badge me-1" style="background-color: ${escapeAttribute(round.color ?? '#6c757d')}; color: ${escapeAttribute(round.textColor ?? '#fff')}">${escapeHtml(round.name)}</span>`;
    }

    renderChoose() {
        const category = this.category();

        this.sourcesTarget.innerHTML = this.rounds.map((round) => {
            const disabled = category !== null && round.category !== category;
            const id = `advance-source-${escapeHtml(round.id)}`;

            return `<div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="${id}" value="${escapeHtml(round.id)}" data-kind="source" ${this.chosenSources.has(round.id) ? 'checked' : ''} ${disabled ? 'disabled' : ''}>
                <label class="form-check-label" for="${id}">${this.roundLabel(round)}
                    <span class="small text-body-secondary">${escapeHtml(this.t(`category_${round.category}`))} · ${escapeHtml(this.tc('qualified_count', round.entries?.qualified ?? 0))}</span>
                </label>
            </div>`;
        }).join('');

        const targetRounds = this.rounds.filter((round) => category !== null && round.category === category && !this.chosenSources.has(round.id));
        for (const id of [...this.chosenTargets]) {
            if (!targetRounds.some((round) => round.id === id)) {
                this.chosenTargets.delete(id);
            }
        }

        this.targetsTarget.innerHTML = category === null
            ? `<p class="text-body-secondary small mb-0">${escapeHtml(this.t('choose_source_first'))}</p>`
            : (targetRounds.length === 0
                ? `<p class="text-body-secondary small mb-0">${escapeHtml(this.t('no_target_rounds'))}</p>`
                : targetRounds.map((round) => {
                    const id = `advance-target-${escapeHtml(round.id)}`;

                    return `<div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="${id}" value="${escapeHtml(round.id)}" data-kind="target" ${this.chosenTargets.has(round.id) ? 'checked' : ''}>
                        <label class="form-check-label" for="${id}">${this.roundLabel(round)}
                            <span class="small text-body-secondary">${escapeHtml(this.tc('entries_count', round.entries?.total ?? 0))}</span>
                        </label>
                    </div>`;
                }).join(''));

        this.renderDistribution();
    }

    renderDistribution() {
        const targetCount = this.chosenTargets.size;

        if (this.distribution === 'single' && targetCount !== 1) {
            this.distribution = null;
        }

        if (this.distribution === 'balanced' && targetCount < 2) {
            this.distribution = null;
        }

        if (this.distribution === null && targetCount === 1) {
            this.distribution = 'single';
        } else if (this.distribution === null && targetCount > 1) {
            this.distribution = 'balanced';
        }

        const option = (value, disabled) => `<div class="form-check mb-1">
            <input class="form-check-input" type="radio" name="advance-distribution" id="advance-distribution-${value}" value="${value}" data-kind="distribution" ${this.distribution === value ? 'checked' : ''} ${disabled ? 'disabled' : ''}>
            <label class="form-check-label" for="advance-distribution-${value}">${escapeHtml(this.t(`distribution_${value}`))}
                <span class="d-block small text-body-secondary">${escapeHtml(this.t(`distribution_${value}_help`))}</span>
            </label>
        </div>`;

        this.distributionTarget.hidden = targetCount === 0;
        this.distributionTarget.querySelector('[data-options]').innerHTML = [
            option('single', targetCount !== 1),
            option('balanced', targetCount < 2),
            option('by_source', targetCount === 0),
        ].join('');

        if (this.distribution === 'by_source') {
            const targetRounds = [...this.chosenTargets].map((id) => this.round(id)).filter(Boolean);
            this.mapTarget.hidden = false;
            this.mapTarget.innerHTML = [...this.chosenSources].map((sourceId) => {
                const source = this.round(sourceId);
                const chosen = this.targetBySource[sourceId] ?? '';

                return `<div class="row g-2 align-items-center mb-2">
                    <div class="col-sm-5">${source ? this.roundLabel(source) : ''}</div>
                    <div class="col-sm-7">
                        <select class="form-select form-select-sm" data-kind="map" data-source="${escapeHtml(sourceId)}" aria-label="${escapeHtml(this.t('map_label', { round: source?.name ?? '' }))}">
                            <option value="">${escapeHtml(this.t('map_choose'))}</option>
                            ${targetRounds.map((target) => `<option value="${escapeHtml(target.id)}" ${chosen === target.id ? 'selected' : ''}>${escapeHtml(target.name)}</option>`).join('')}
                        </select>
                    </div>
                </div>`;
            }).join('');
        } else {
            this.mapTarget.hidden = true;
            this.mapTarget.innerHTML = '';
        }

        this.renderCountryRule();
        this.planButtonTarget.disabled = !this.canPlan();
    }

    resetCountryRule() {
        if (!this.hasCountryRuleTarget) {
            return;
        }

        const checkbox = this.countryRuleTarget.querySelector('[data-kind="country_rule"]');
        const count = this.countryRuleTarget.querySelector('[data-kind="country_count"]');

        if (checkbox) {
            checkbox.checked = false;
        }

        if (count) {
            count.value = '1';
        }
    }

    /**
     * "Also the best of each country" - offered once the qualified come from somewhere and go somewhere.
     */
    renderCountryRule() {
        if (!this.hasCountryRuleTarget) {
            return;
        }

        this.countryRuleTarget.hidden = this.chosenSources.size === 0 || this.chosenTargets.size === 0;
        const row = this.countryRuleTarget.querySelector('[data-country-count-row]');

        if (row) {
            row.hidden = this.countryRule === null;
        }
    }

    readCountryRule() {
        const checkbox = this.countryRuleTarget.querySelector('[data-kind="country_rule"]');
        const count = Math.floor(Number(this.countryRuleTarget.querySelector('[data-kind="country_count"]')?.value) || 0);

        this.countryRule = checkbox?.checked ? Math.min(99, Math.max(1, count)) : null;
    }

    canPlan() {
        if (this.chosenSources.size === 0 || this.chosenTargets.size === 0 || this.distribution === null) {
            return false;
        }

        if (this.distribution === 'by_source') {
            return [...this.chosenSources].every((sourceId) => this.chosenTargets.has(this.targetBySource[sourceId] ?? ''));
        }

        return true;
    }

    choiceChanged(event) {
        const input = event.target;

        if (input.dataset.kind === 'source') {
            input.checked ? this.chosenSources.add(input.value) : this.chosenSources.delete(input.value);
            this.renderChoose();
        } else if (input.dataset.kind === 'target') {
            input.checked ? this.chosenTargets.add(input.value) : this.chosenTargets.delete(input.value);
            this.renderDistribution();
        } else if (input.dataset.kind === 'distribution') {
            this.distribution = input.value;
            this.renderDistribution();
        } else if (input.dataset.kind === 'map') {
            this.targetBySource[input.dataset.source] = input.value;
            this.planButtonTarget.disabled = !this.canPlan();
        } else if (input.dataset.kind === 'country_rule' || input.dataset.kind === 'country_count') {
            this.readCountryRule();
            this.renderCountryRule();
        }

        this.hideError();
    }

    // ---------------------------------------------------------------- the plan

    body(dryRun) {
        const body = {
            sourceRoundIds: [...this.chosenSources],
            targetRoundIds: [...this.chosenTargets],
            distribution: this.distribution,
            targetBySource: this.distribution === 'by_source' ? this.targetBySource : {},
            bestOfEachCountry: this.countryRule,
            dryRun,
        };

        if (!dryRun) {
            body.planHash = this.plan.planHash;
        }

        return body;
    }

    requestPlan() {
        this.showPlan(null);
    }

    async showPlan(notice) {
        if (!this.canPlan() || this.busy) {
            return;
        }

        this.busy = true;
        this.planButtonTarget.disabled = true;
        const answer = await officialResultsRequest(this.urlsValue.advance, { method: 'POST', body: this.body(true), csrfToken: this.csrfTokenValue });
        this.busy = false;
        this.planButtonTarget.disabled = !this.canPlan();

        if (answer.kind !== 'ok') {
            this.showStep('choose');
            this.showError(this.errorText(answer));

            return;
        }

        this.plan = answer.data;
        this.renderPlan(notice);
        this.showStep('plan');
    }

    renderPlan(notice) {
        const plan = this.plan;
        const added = plan.assignments.length;
        const roundName = (id) => this.round(id)?.name ?? '';

        const targets = plan.targets.map((target) => `<li>${escapeHtml(this.t('plan_target', {
            round: target.name,
            before: target.entriesBefore,
            after: target.entriesAfter,
        }))}</li>`).join('');

        const groups = plan.targets.map((target) => {
            const rows = plan.assignments.filter((assignment) => assignment.targetRoundId === target.roundId);

            if (rows.length === 0) {
                return '';
            }

            return `<h3 class="h6 mt-3">${escapeHtml(this.tc('plan_group', rows.length, { round: target.name }))}</h3>
                <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                    <thead><tr>
                        <th scope="col" class="text-end">${escapeHtml(this.t('column_seed'))}</th>
                        <th scope="col">${escapeHtml(this.t('column_entrant'))}</th>
                        <th scope="col">${escapeHtml(this.t('column_from'))}</th>
                        <th scope="col">${escapeHtml(this.t('column_result'))}</th>
                    </tr></thead>
                    <tbody>${rows.map((assignment) => `<tr>
                        <td class="text-end">${assignment.seed}</td>
                        <td>${escapeHtml(assignment.entry.displayName)}${assignment.byCountryRule ? this.countryRuleBadge() : ''}</td>
                        <td>${escapeHtml(roundName(assignment.sourceRoundId))}${assignment.entry.rank ? ` <span class="text-body-secondary">(${escapeHtml(this.t('rank_short', { rank: assignment.entry.rank }))})</span>` : ''}</td>
                        <td>${escapeHtml(this.formatResult(assignment.entry.result, assignment.sourceRoundId))}</td>
                    </tr>`).join('')}</tbody>
                </table></div>`;
        }).join('');

        const skipped = plan.skipped.length === 0 ? '' : `<h3 class="h6 mt-3">${escapeHtml(this.tc('plan_skipped', plan.skipped.length))}</h3>
            <ul class="small mb-0">${plan.skipped.map((skip) => `<li>${escapeHtml(skip.entry.displayName)}${skip.byCountryRule ? this.countryRuleBadge() : ''} <span class="text-body-secondary">(${escapeHtml(roundName(skip.sourceRoundId))})</span> - ${escapeHtml(skip.message ?? skip.reason)}</li>`).join('')}</ul>`;

        const markedByRule = plan.markedByCountryRule ?? [];
        const withoutCountry = plan.withoutCountry ?? [];
        const countryRule = plan.bestOfEachCountry === null || plan.bestOfEachCountry === undefined ? '' : `${markedByRule.length > 0 ? `<p class="small mb-2"><i class="bi bi-flag me-1" aria-hidden="true"></i>${escapeHtml(this.tc('country_rule_marked', markedByRule.length))}</p>` : ''}
            ${withoutCountry.length > 0 ? `<details class="small mb-2"><summary>${escapeHtml(this.tc('country_rule_without', withoutCountry.length))}</summary><ul class="mb-0">${withoutCountry.map((without) => `<li>${escapeHtml(without.entry.displayName)} <span class="text-body-secondary">(${escapeHtml(roundName(without.sourceRoundId))}${without.entry.rank ? `, ${escapeHtml(this.t('rank_short', { rank: without.entry.rank }))}` : ''})</span></li>`).join('')}</ul></details>` : ''}`;

        this.planTarget.innerHTML = `${notice ? `<div class="alert alert-warning" role="alert"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>${escapeHtml(notice)}</div>` : ''}
            <p class="fw-semibold mb-1">${escapeHtml(added > 0 ? this.tc('plan_summary', added) : this.t('plan_nobody'))}</p>
            <ul class="mb-2">${targets}</ul>
            ${countryRule}
            ${groups}
            ${skipped}`;

        this.applyButtonTarget.hidden = added === 0;
        this.applyButtonTarget.disabled = added === 0;
        this.applyButtonTarget.textContent = this.tc('apply', added);
    }

    countryRuleBadge() {
        return ` <span class="badge text-bg-info ms-1">${escapeHtml(this.t('country_rule_badge'))}</span>`;
    }

    /**
     * Qualified marks the results desk on this page has not saved yet (the desk answers this event) - the plan is made
     * from the saved marks only (review2-b m7).
     */
    unsavedMarks() {
        const detail = { count: 0 };
        document.dispatchEvent(new CustomEvent('official-results:unsaved-marks', { detail }));

        return detail.count;
    }

    renderUnsaved() {
        if (!this.hasUnsavedTarget) {
            return;
        }

        const count = this.unsavedMarks();
        this.unsavedTarget.hidden = count === 0;
        this.unsavedTarget.textContent = count === 0 ? '' : this.tc('unsaved_marks', count);
    }

    formatResult(result, roundId) {
        if (!result) {
            return '';
        }

        if (Number.isInteger(result.seconds)) {
            return formatResultTime(result.seconds);
        }

        if (Number.isInteger(result.piecesPlaced)) {
            const pieces = this.round(roundId)?.piecesCount;

            return Number.isInteger(pieces)
                ? this.t('pieces_placed_of', { placed: result.piecesPlaced, pieces })
                : this.t('pieces_placed', { placed: result.piecesPlaced });
        }

        return '';
    }

    async apply() {
        if (this.plan === null || this.busy) {
            return;
        }

        this.busy = true;
        this.applyButtonTarget.disabled = true;
        const answer = await officialResultsRequest(this.urlsValue.advance, { method: 'POST', body: this.body(false), csrfToken: this.csrfTokenValue });
        this.busy = false;

        if (answer.kind === 'ok' && answer.data.applied === true) {
            this.plan = answer.data;
            this.renderDone();
            this.showStep('done');
            document.dispatchEvent(new CustomEvent('official-results:advanced', { detail: { plan: answer.data } }));

            return;
        }

        if (answer.kind === 'client' && answer.status === 409) {
            // Somebody changed marks, results or the target rounds meanwhile - nothing was added; show the plan as it is now
            await this.showPlan(answer.data?.message ?? this.t('plan_changed'));

            return;
        }

        this.applyButtonTarget.disabled = false;
        this.showError(this.errorText(answer));
    }

    // ---------------------------------------------------------------- after applying: seat them now

    createdByTarget() {
        const byTarget = new Map();

        for (const assignment of this.plan.assignments) {
            if (assignment.createdEntry) {
                if (!byTarget.has(assignment.targetRoundId)) {
                    byTarget.set(assignment.targetRoundId, []);
                }

                byTarget.get(assignment.targetRoundId).push(assignment.createdEntry);
            }
        }

        return byTarget;
    }

    renderDone() {
        const byTarget = this.createdByTarget();
        const seatable = [...byTarget.keys()].filter((roundId) => !this.isOnlineValue && this.round(roundId)?.tableNumbersOff !== true);
        const links = [...byTarget.keys()].map((roundId) => {
            const round = this.round(roundId);

            return `<li class="mb-1">${round ? this.roundLabel(round) : ''} ${escapeHtml(this.tc('done_round', byTarget.get(roundId).length))}
                <a class="ms-2" href="${escapeAttribute(this.url(this.urlsValue.deskTemplate, roundId))}">${escapeHtml(this.t('open_desk'))}</a>
                ${this.isOnlineValue ? '' : `<a class="ms-2" href="${escapeAttribute(this.url(this.urlsValue.seatingTemplate, roundId))}">${escapeHtml(this.t('arrange_by_hand'))}</a>`}
                <span class="d-block small" data-seat-result="${escapeHtml(roundId)}"></span>
            </li>`;
        }).join('');

        this.doneTarget.innerHTML = `<div class="alert alert-success" role="status"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>${escapeHtml(this.tc('done', this.plan.assignments.length))}</div>
            <ul class="list-unstyled mb-3">${links}</ul>
            ${seatable.length === 0 ? '' : `<div class="card"><div class="card-body" data-seat-panel>
                <div data-seat-intro>
                    <h3 class="h6">${escapeHtml(this.t('seat_title'))}</h3>
                    <p class="small text-body-secondary mb-2">${escapeHtml(this.t('seat_help'))}</p>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <select class="form-select form-select-sm w-auto" data-seat-order aria-label="${escapeHtml(this.t('seat_order_label'))}">
                        <option value="fastest">${escapeHtml(this.t('seat_fastest_first'))}</option>
                        <option value="slowest">${escapeHtml(this.t('seat_slowest_first'))}</option>
                    </select>
                    <button type="button" class="btn btn-primary" data-action="advance-qualified#seatNow" data-seat-button>${escapeHtml(this.t('seat_now'))}</button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">${escapeHtml(this.t('seat_skip'))}</button>
                </div>
            </div></div>`}`;
    }

    async seatNow(event) {
        const button = event.currentTarget;
        const slowestFirst = this.doneTarget.querySelector('[data-seat-order]')?.value === 'slowest';
        const byTarget = this.createdByTarget();
        button.disabled = true;

        for (const [roundId, refs] of byTarget) {
            const round = this.round(roundId);
            const resultLine = this.doneTarget.querySelector(`[data-seat-result="${CSS.escape(roundId)}"]`);

            if (this.isOnlineValue || round?.tableNumbersOff === true) {
                continue;
            }

            const state = await officialResultsRequest(this.url(this.urlsValue.stateTemplate, roundId));

            if (state.kind !== 'ok') {
                this.seatLine(resultLine, 'danger', this.errorText(state));
                continue;
            }

            const assignments = seatAdvanced(state.data.entries ?? [], this.seedOrder(roundId, refs, state.data.entries ?? []), slowestFirst);

            if (assignments.length === 0) {
                this.seatLine(resultLine, 'secondary', this.t('seat_nothing'));
                continue;
            }

            const answer = await officialResultsRequest(this.url(this.urlsValue.assignTemplate, roundId), {
                method: 'POST',
                body: { assignments },
                csrfToken: this.csrfTokenValue,
            });

            if (answer.kind === 'ok') {
                const numbers = assignments.map((assignment) => assignment.number);
                this.seatLine(resultLine, 'success', this.tc('seat_done', assignments.length, { from: Math.min(...numbers), to: Math.max(...numbers) }));
            } else if (answer.kind === 'client' && Array.isArray(answer.data?.problems)) {
                this.seatLine(resultLine, 'danger', answer.data.problems.map((problem) => problem.message).filter(Boolean).join(' ') || this.t('error_generic'));
            } else {
                this.seatLine(resultLine, 'danger', this.errorText(answer));
            }
        }

        // Seated: only the way out is left
        const panel = this.doneTarget.querySelector('[data-seat-panel]');
        if (panel) {
            panel.querySelector('[data-seat-button]')?.remove();
            panel.querySelector('[data-seat-order]')?.remove();
            panel.querySelector('[data-seat-intro]')?.remove();
            const close = panel.querySelector('[data-bs-dismiss="modal"]');

            if (close) {
                close.textContent = this.t('close');
                close.className = 'btn btn-primary';
            }
        }
    }

    /**
     * The round's entries from this plan in seed order: the ones just added, and those skipped because they were in the
     * round already (found by their people) - everybody who qualified into it.
     */
    seedOrder(roundId, createdRefs, targetEntries) {
        const identity = (entry) => (entry.kind === 'team'
            ? (entry.members ?? []).map((member) => member.participantId).sort().join(',')
            : (entry.participantId ?? ''));
        const byIdentity = new Map(targetEntries.map((entry) => [identity(entry), entry.ref]));
        const created = new Set(createdRefs);

        return [
            ...this.plan.assignments
                .filter((assignment) => assignment.targetRoundId === roundId && created.has(assignment.createdEntry))
                .map((assignment) => ({ seed: assignment.seed, ref: assignment.createdEntry })),
            ...this.plan.skipped
                .filter((skip) => skip.reason === 'already_in_target' && identity(skip.entry) !== '' && byIdentity.has(identity(skip.entry)))
                .map((skip) => ({ seed: skip.seed, ref: byIdentity.get(identity(skip.entry)) })),
        ]
            .sort((a, b) => a.seed - b.seed)
            .map((item) => item.ref);
    }

    seatLine(element, tone, text) {
        if (element) {
            element.className = `d-block small text-${tone}`;
            element.textContent = text;
        }
    }

    // ---------------------------------------------------------------- steps

    back() {
        this.plan = null;
        this.showStep('choose');
        this.renderChoose();
    }

    showStep(step) {
        if (step !== 'done') {
            this.renderUnsaved();
        } else if (this.hasUnsavedTarget) {
            this.unsavedTarget.hidden = true;
        }

        this.chooseTarget.hidden = step !== 'choose';
        this.planTarget.hidden = step !== 'plan';
        this.doneTarget.hidden = step !== 'done';
        this.planButtonTarget.hidden = step !== 'choose';
        this.backButtonTarget.hidden = step !== 'plan';
        this.applyButtonTarget.hidden = step !== 'plan' || (this.plan?.assignments?.length ?? 0) === 0;
        this.hideError();
    }

    errorText(answer) {
        if (answer.kind === 'auth') {
            return this.t('error_sign_in');
        }

        if (answer.kind === 'offline' || answer.kind === 'server') {
            return this.t('error_offline');
        }

        if (answer.kind === 'forbidden') {
            return this.t('error_forbidden');
        }

        return answer.data?.message ?? this.t('error_generic');
    }

    showError(text) {
        this.errorTarget.textContent = text;
        this.errorTarget.hidden = false;
    }

    hideError() {
        this.errorTarget.hidden = true;
        this.errorTarget.textContent = '';
    }
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function escapeAttribute(value) {
    return escapeHtml(value);
}
