/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * The keys of the moderators' picture review (templates/admin/puzzle_change_request_pictures.html.twig):
 * J / → next card, K / ← previous card, A approve, R reject with the default reason, O open the request.
 * A and R act on the focused card and move on to the next pending one at once - the decision answers with a Turbo
 * Stream that replaces only that card. A held key does not repeat, and a card decides once while its answer is on the
 * way. Nothing happens while typing (a reason, a filter) or with a modifier key, and
 * the up / down arrows keep scrolling the page.
 */
export default class extends Controller {
    static targets = ['card'];

    key(event) {
        // A held key repeats: holding A or R would decide a run of cards nobody looked at
        if (event.repeat || event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey || this.isTyping(event.target)) {
            return;
        }

        switch (event.key) {
            case 'j':
            case 'J':
            case 'ArrowRight':
                event.preventDefault();
                this.move(1);
                break;
            case 'k':
            case 'K':
            case 'ArrowLeft':
                event.preventDefault();
                this.move(-1);
                break;
            case 'a':
            case 'A':
                this.decide(event, 'approve');
                break;
            case 'r':
            case 'R':
                this.decide(event, 'reject');
                break;
            case 'o':
            case 'O':
                this.open(event);
                break;
        }
    }

    // A card decided by mouse or touch: the keyboard's place moves on as well. The card is marked as deciding until
    // the answer replaces it (a refused decision comes back as a fresh card without the mark)
    submitted(event) {
        const card = event.target.closest('[data-picture-review-target="card"]');

        if (card) {
            card.dataset.deciding = 'true';
        }

        if (card && card.contains(document.activeElement)) {
            this.focusNextPending(card);
        }
    }

    // No answer replaced the card (the network failed) - it can be decided again
    settled(event) {
        const card = event.target.closest('[data-picture-review-target="card"]');

        if (card) {
            setTimeout(() => {
                if (card.isConnected) {
                    delete card.dataset.deciding;
                }
            }, 0);
        }
    }

    decide(event, action) {
        const card = this.currentCard();

        if (!card) {
            return;
        }

        // One decision per card - a second key press while the first is still on its way does nothing
        if (card.dataset.deciding === 'true') {
            event.preventDefault();
            return;
        }

        const button = card.querySelector(`[data-picture-review-action="${action}"]`);

        if (!button || button.disabled) {
            return;
        }

        event.preventDefault();
        card.dataset.deciding = 'true';
        this.focusNextPending(card);
        button.form.requestSubmit(button);
    }

    open(event) {
        const card = this.currentCard();
        const link = card ? card.querySelector('a[href*="/admin/puzzle-change-requests/"]') : null;

        if (link) {
            event.preventDefault();
            window.open(link.href, '_blank', 'noopener');
        }
    }

    move(step) {
        const cards = this.cardTargets;

        if (cards.length === 0) {
            return;
        }

        const current = this.currentCard();
        const index = current ? cards.indexOf(current) : -1;
        const next = index === -1 ? (step > 0 ? 0 : cards.length - 1) : Math.min(Math.max(index + step, 0), cards.length - 1);

        this.focus(cards[next]);
    }

    focusNextPending(card) {
        const cards = this.cardTargets;
        const index = cards.indexOf(card);
        const pending = (candidate) => candidate !== card && candidate.dataset.status === 'pending' && candidate.dataset.deciding !== 'true';
        const next = cards.slice(index + 1).find(pending) || cards.slice(0, index).find(pending);

        if (next) {
            this.focus(next);
        }
    }

    focus(card) {
        card.focus({ preventScroll: true });
        card.scrollIntoView({ block: 'nearest', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    }

    currentCard() {
        const active = document.activeElement;

        return active ? this.cardTargets.find((card) => card.contains(active)) || null : null;
    }

    isTyping(target) {
        return target instanceof Element && target.closest('input, textarea, select, [contenteditable=""], [contenteditable="true"]') !== null;
    }
}
