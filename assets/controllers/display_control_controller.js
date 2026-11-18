/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import {
    cleanMessage, entryLabel, filterEntries, justFinished, messageProblem, recentChips, sortEntries, SpotlightChoice,
} from '../displays/control_picker.js';

const REFRESH_MS = 30 * 1000;

/**
 * The live control of an event's displays (templates/competition_toolkit/display_control.html.twig, competition toolkit
 * v2 L1/L2, displays-standings.md §5.3). The page is a data-free shell: the state comes from `stateUrl` (again every
 * 30 s while the page is visible, and when the round changes); writes are JSON POSTs with the `X-CSRF-Token` header.
 * DOM by createElement + textContent only.
 *
 * Config: {stateUrl, messageUrl, spotlightUrl (with `__ROUND__` for the round), csrfToken, roundId, texts: {…}}. The
 * stopwatch page's card uses the spotlight part only (its round fixed, no message targets).
 *
 * Spotlight (L2): one tap on a chip sets it at once - highlighted before the answer (SpotlightChoice), back with a message
 * when refused; no confirmation, no display choice.
 */
export default class extends Controller {
    static targets = [
        'round', 'currentMessage', 'messageForm', 'messageInput', 'clearMessage', 'messageError', 'messageHint', 'recent',
        'recentList', 'previewSelect', 'previewFrame', 'previewWrap',
        'spotlightCurrent', 'spotlightClear', 'spotlightFilter', 'justFinishedWrap', 'justFinished', 'chips', 'spotlightError',
        'spotlightHint', 'spotlightEmpty', 'spotlightTexts',
    ];

    static values = { config: Object };

    connect() {
        this.state = null;
        this.loading = null;
        this.roundId = this.configValue.roundId ?? null;
        this.choice = null;
        this.choiceRound = null;
        this.onVisible = () => {
            if (document.visibilityState === 'visible') {
                this.load();
            }
        };
        document.addEventListener('visibilitychange', this.onVisible);
        this.timer = setInterval(() => {
            if (document.visibilityState === 'visible') {
                this.load();
            }
        }, REFRESH_MS);
        this.load();
    }

    disconnect() {
        clearInterval(this.timer);
        document.removeEventListener('visibilitychange', this.onVisible);
    }

    t(key) {
        if (this.pickerTexts === undefined) {
            try {
                this.pickerTexts = this.hasSpotlightTextsTarget ? JSON.parse(this.spotlightTextsTarget.dataset.texts ?? '{}') : {};
            } catch {
                this.pickerTexts = {};
            }
        }

        return this.configValue.texts?.[key] ?? this.pickerTexts[key] ?? key;
    }

    // ---- state

    async load() {
        if (this.loading !== null) {
            return this.loading;
        }

        const url = new URL(this.configValue.stateUrl, window.location.href);
        const requestedRound = this.roundId;

        if (requestedRound !== null) {
            url.searchParams.set('round', requestedRound);
        }

        this.loading = fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : null))
            .catch(() => null)
            .then((state) => {
                this.loading = null;

                // Another round was chosen meanwhile - its state, not this one
                if (requestedRound !== this.roundId) {
                    return this.load();
                }

                if (state !== null && typeof state === 'object') {
                    this.state = state;
                    this.stateLoaded(state);
                }
            });

        return this.loading;
    }

    stateLoaded(state) {
        if (this.roundId === null && typeof state.roundId === 'string') {
            this.roundId = state.roundId;
        }

        const current = typeof state.spotlight?.entryRef === 'string' ? state.spotlight.entryRef : null;

        if (this.choice === null || this.choiceRound !== state.roundId) {
            this.choice = new SpotlightChoice(current);
            this.choiceRound = state.roundId ?? null;
        } else {
            this.choice.sync(current);
        }

        this.renderMessage(state);
        this.renderSpotlight();
    }

    roundChanged() {
        this.roundId = this.hasRoundTarget && this.roundTarget.value !== '' ? this.roundTarget.value : null;
        this.choice = null;

        if (this.hasSpotlightFilterTarget) {
            this.spotlightFilterTarget.value = '';
        }

        this.load();
    }

    async post(url, payload) {
        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-Token': this.configValue.csrfToken,
                },
                body: JSON.stringify(payload),
            });
            const answer = await response.json().catch(() => ({}));

            return { ok: response.ok, answer };
        } catch {
            return { ok: false, answer: { error: 'offline' } };
        }
    }

    // ---- message (L1)

    renderMessage(state) {
        if (!this.hasCurrentMessageTarget) {
            return;
        }

        const message = typeof state.message === 'string' && state.message !== '' ? state.message : null;
        this.currentMessageTarget.textContent = message ?? this.t('messageNone');
        this.currentMessageTarget.classList.toggle('text-muted', message === null);

        if (this.hasClearMessageTarget) {
            this.clearMessageTarget.disabled = message === null;
        }

        if (this.hasMessageHintTarget) {
            this.messageHintTarget.hidden = (state.elements?.message ?? 0) > 0;
        }

        if (this.hasRecentListTarget) {
            const chips = recentChips(state.recentMessages, message);
            this.recentListTarget.replaceChildren();

            for (const text of chips) {
                const chip = document.createElement('button');
                chip.type = 'button';
                chip.className = 'btn btn-sm btn-outline-secondary text-start';
                chip.textContent = text;
                chip.addEventListener('click', () => this.sendMessage(text));
                this.recentListTarget.appendChild(chip);
            }

            if (this.hasRecentTarget) {
                this.recentTarget.hidden = chips.length === 0;
            }
        }
    }

    setMessage(event) {
        event.preventDefault();
        const text = cleanMessage(this.messageInputTarget.value);

        if (text === '') {
            return;
        }

        this.sendMessage(text);
    }

    clearMessage() {
        this.sendMessage(null);
    }

    async sendMessage(text) {
        const problem = text === null ? null : messageProblem(text);

        if (problem !== null) {
            this.showMessageError(problem);

            return;
        }

        this.showMessageError(null);
        this.messageFormTarget.setAttribute('aria-busy', 'true');
        const { ok, answer } = await this.post(this.configValue.messageUrl, { text });
        this.messageFormTarget.removeAttribute('aria-busy');

        if (!ok) {
            this.showMessageError(typeof answer?.error === 'string' ? answer.error : 'failed');

            return;
        }

        if (text !== null) {
            this.messageInputTarget.value = '';
        }

        this.state = { ...(this.state ?? {}), message: answer.message ?? null, recentMessages: answer.recentMessages ?? [] };
        this.renderMessage(this.state);
    }

    showMessageError(code) {
        if (!this.hasMessageErrorTarget) {
            return;
        }

        const known = ['too_long', 'no_displays', 'offline'];
        this.messageErrorTarget.textContent = code === null ? '' : this.t(known.includes(code) ? `message_${code}` : 'messageFailed');
        this.messageErrorTarget.hidden = code === null;
    }

    // ---- spotlight (L2)

    renderSpotlight() {
        if (!this.hasChipsTarget || this.state === null || this.choice === null) {
            return;
        }

        const entries = sortEntries(this.state.entries);
        const current = this.choice.shown;
        const currentEntry = entries.find((entry) => entry.ref === current) ?? null;

        if (this.hasSpotlightCurrentTarget) {
            let label = this.t('spotlightNone');

            if (current !== null) {
                label = currentEntry !== null ? entryLabel(currentEntry) : (this.state.spotlight?.name ?? this.t('spotlightGone'));
            }

            this.spotlightCurrentTarget.textContent = label;
            this.spotlightCurrentTarget.classList.toggle('text-muted', current === null);
        }

        if (this.hasSpotlightClearTarget) {
            this.spotlightClearTarget.disabled = current === null;
        }

        if (this.hasSpotlightHintTarget) {
            this.spotlightHintTarget.hidden = (this.state.elements?.spotlight ?? 0) > 0;
        }

        if (this.hasSpotlightEmptyTarget) {
            this.spotlightEmptyTarget.hidden = entries.length > 0;
        }

        if (this.hasJustFinishedTarget) {
            const latest = justFinished(entries);
            this.fillChips(this.justFinishedTarget, latest, current);

            if (this.hasJustFinishedWrapTarget) {
                this.justFinishedWrapTarget.hidden = latest.length === 0;
            }
        }

        const query = this.hasSpotlightFilterTarget ? this.spotlightFilterTarget.value : '';
        this.fillChips(this.chipsTarget, filterEntries(entries, query), current);
    }

    fillChips(container, entries, current) {
        container.replaceChildren();

        for (const entry of entries) {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = `btn btn-sm ${entry.ref === current ? 'btn-primary' : 'btn-outline-primary'}`;
            chip.setAttribute('aria-pressed', entry.ref === current ? 'true' : 'false');
            chip.textContent = entryLabel(entry);
            chip.addEventListener('click', () => this.tapSpotlight(entry.ref));
            container.appendChild(chip);
        }
    }

    filterChanged() {
        this.renderSpotlight();
    }

    clearSpotlight() {
        this.tapSpotlight(null);
    }

    tapSpotlight(ref) {
        if (this.choice === null) {
            return;
        }

        const send = this.choice.tap(ref);
        this.showSpotlightError(null);
        this.renderSpotlight();

        if (send !== undefined) {
            this.sendSpotlight(send);
        }
    }

    async sendSpotlight(ref) {
        const roundId = this.roundId;

        if (roundId === null) {
            return;
        }

        const url = String(this.configValue.spotlightUrl).replace('__ROUND__', encodeURIComponent(roundId));
        const { ok, answer } = await this.post(url, { entryRef: ref });

        if (this.choice === null || roundId !== this.roundId) {
            return;
        }

        if (ok) {
            this.state = { ...this.state, spotlight: answer.spotlight ?? null };
        } else {
            this.showSpotlightError(typeof answer?.error === 'string' ? answer.error : 'failed');
        }

        const next = this.choice.settle(ok);
        this.renderSpotlight();

        if (next !== undefined) {
            this.sendSpotlight(next);
        }
    }

    showSpotlightError(code) {
        if (!this.hasSpotlightErrorTarget) {
            return;
        }

        const known = ['unknown_entry', 'no_displays', 'offline'];
        this.spotlightErrorTarget.textContent = code === null ? '' : this.t(known.includes(code) ? `spotlight_${code}` : 'spotlightFailed');
        this.spotlightErrorTarget.hidden = code === null;
    }

    // ---- preview

    showPreview() {
        if (!this.hasPreviewSelectTarget || !this.hasPreviewFrameTarget) {
            return;
        }

        const url = this.previewSelectTarget.value;

        // Only the event's own display links (rendered by the server into the options)
        if (!/^https?:\/\//.test(url)) {
            return;
        }

        this.previewFrameTarget.setAttribute('src', url);
        this.previewWrapTarget.hidden = false;
    }
}
