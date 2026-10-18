/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * The keys of the moderators' picture review (templates/admin/puzzle_change_request_pictures.html.twig):
 * J / → next card, K / ← previous card, A approve, R reject with the default reason, O open the request.
 * A and R act on the focused card and move on to the next pending one at once - the decision answers with a Turbo
 * Stream that replaces only that card. Nothing happens while typing (a reason, a filter) or with a modifier key, and
 * the up / down arrows keep scrolling the page.
 */
export default class extends Controller {
    static targets = ['card'];

    key(event) {
        if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey || this.isTyping(event.target)) {
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

    // A card decided by mouse or touch: the keyboard's place moves on as well
    submitted(event) {
        const card = event.target.closest('[data-picture-review-target="card"]');

        if (card && card.contains(document.activeElement)) {
            this.focusNextPending(card);
        }
    }

    decide(event, action) {
        const card = this.currentCard();

        if (!card) {
            return;
        }

        const button = card.querySelector(`[data-picture-review-action="${action}"]`);

        if (!button || button.disabled) {
            return;
        }

        event.preventDefault();
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
        const pending = (candidate) => candidate !== card && candidate.dataset.status === 'pending';
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
