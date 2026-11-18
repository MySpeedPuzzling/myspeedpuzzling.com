/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { cleanMessage, messageProblem, recentChips } from '../displays/control_picker.js';

const REFRESH_MS = 30 * 1000;

/**
 * The live control of an event's displays (templates/competition_toolkit/display_control.html.twig, competition toolkit
 * v2 L1/L2, displays-standings.md §5.3). The page is a data-free shell: the state comes from `stateUrl` (again every
 * 30 s while the page is visible, and when the round changes); writes are JSON POSTs with the `X-CSRF-Token` header.
 * DOM by createElement + textContent only.
 *
 * Config: {stateUrl, messageUrl, csrfToken, roundId, texts: {…}}.
 */
export default class extends Controller {
    static targets = [
        'round', 'currentMessage', 'messageForm', 'messageInput', 'clearMessage', 'messageError', 'messageHint', 'recent',
        'recentList', 'previewSelect', 'previewFrame', 'previewWrap',
    ];

    static values = { config: Object };

    connect() {
        this.state = null;
        this.loading = null;
        this.roundId = this.configValue.roundId ?? null;
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
        return this.configValue.texts?.[key] ?? key;
    }

    // ---- state

    async load() {
        if (this.loading !== null) {
            return this.loading;
        }

        const url = new URL(this.configValue.stateUrl, window.location.href);

        if (this.roundId !== null) {
            url.searchParams.set('round', this.roundId);
        }

        this.loading = fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : null))
            .catch(() => null)
            .then((state) => {
                this.loading = null;

                if (state !== null && typeof state === 'object') {
                    this.state = state;
                    this.stateLoaded(state);
                }
            });

        return this.loading;
    }

    stateLoaded(state) {
        this.renderMessage(state);
    }

    roundChanged() {
        this.roundId = this.hasRoundTarget && this.roundTarget.value !== '' ? this.roundTarget.value : null;
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
