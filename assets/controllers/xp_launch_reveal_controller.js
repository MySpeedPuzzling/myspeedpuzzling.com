/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

const COUNT_MS = 650;

/**
 * The XP launch reveal of a member (templates/xp_launch_reveal.html.twig): every achievement, one card at a time in
 * crescendo order. Each card assembles by itself (the last-piece reveal, driven through the outlet), then its name,
 * tier, the requirement it met and the XP it brought appear and the XP counter at the top grows by that much. A tap
 * anywhere (or the big button) goes on - while a card still assembles, the first tap completes it. The level
 * medallion is the finale (the counter lands on the player's XP), a summary of everything ends it; "Skip to summary"
 * is there all the time. Reduced motion: every card is complete at once (the reveal controller decides).
 */
export default class extends Controller {
    static targets = ['intro', 'deck', 'card', 'counter', 'progress', 'next', 'summary', 'summaryHeading'];

    static outlets = ['last-piece-reveal'];

    static values = {
        start: Number,                                     // the counter's first value: what the puzzles alone brought
        total: Number,                                     // the player's XP: where the counter lands with the level
        totalText: String,                                 // the same, as the server printed it ("3 500")
        progress: String,                                  // "%current% / %total%"
        nextLabel: String,
        levelLabel: String,
        summaryLabel: String,
    };

    connect() {
        this.index = -1;
        this.queued = false;
        this.shown = this.startValue;
        this.aim = this.startValue;
        this.frame = 0;
        // thousands grouped exactly like the server groups them (number_format in the template)
        this.separator = this.totalTextValue.match(/\d(\D+)\d{3}$/)?.[1] ?? '';
    }

    disconnect() {
        cancelAnimationFrame(this.frame);
    }

    lastPieceRevealOutletConnected() {
        if (this.queued) {
            this.queued = false;
            this.show(0);
        }
    }

    /** action: the intro's button */
    start() {
        this.introTarget.hidden = true;
        this.deckTarget.hidden = false;
        this.deckTarget.scrollIntoView({ block: 'start' });
        this.nextTarget.focus({ preventScroll: true });

        if (!this.hasLastPieceRevealOutlet) {
            this.queued = true;                            // the lazy reveal controller is still on its way
            return;
        }

        this.lastPieceRevealOutlet.unlockSound();          // inside the tap: the only moment audio may start
        this.show(0);
    }

    /** action: the big button */
    next() {
        if (this.index < 0 || !this.hasLastPieceRevealOutlet) {
            return;
        }

        const reveal = this.lastPieceRevealOutlet;
        const card = this.cardTargets[this.index];

        // Before its texts are there, a tap completes the card; once they are, it goes on (a glint or the shimmer
        // still sweeping ends with the next card's start)
        if (!card.classList.contains('is-revealed')) {
            const stage = stageOf(card);

            if (reveal.stateOf(stage) === 'running') {
                reveal.start(stage);
            }

            return;
        }

        reveal.unlockSound();

        if (this.index + 1 < this.cardTargets.length) {
            this.show(this.index + 1);
        } else {
            this.finish();
        }
    }

    /** action: a tap anywhere on the card */
    tap(event) {
        if (event.target.closest('a, button, input, select, textarea')) {
            return;
        }

        this.next();
        this.holdFocus();
    }

    /** action: "Skip to summary" - every achievement the summary shows counts as revealed */
    skip() {
        if (this.hasLastPieceRevealOutlet) {
            const reveal = this.lastPieceRevealOutlet;
            reveal.stopAll();

            for (const card of this.cardTargets) {
                reveal.postReveal(stageOf(card));
            }
        }

        this.finish();
    }

    /** action: `last-piece-reveal:revealed` - a picture is complete */
    cardRevealed(event) {
        const card = event.detail.stage.closest('.xpl-card');

        if (!card || !this.cardTargets.includes(card) || card.classList.contains('is-revealed')) {
            return;
        }

        card.classList.add('is-revealed');
        const isLevel = card.classList.contains('xpl-card-level');
        this.countTo(isLevel ? this.totalValue : this.aim + Number(card.dataset.xp || 0), event.detail.instant);

        if (!event.detail.instant && !prefersReducedMotion()) {
            card.querySelector('.xpl-card-text')?.animate(
                [{ opacity: 0, transform: 'translateY(8px)' }, { opacity: 1, transform: 'none' }],
                { duration: 300, easing: 'ease-out' },
            );
        }
    }

    show(index) {
        this.index = index;
        this.cardTargets.forEach((card, i) => {
            card.hidden = i !== index;
        });
        const card = this.cardTargets[index];
        const badges = this.cardTargets.length - 1;        // the last card is the level
        const isLevel = card.classList.contains('xpl-card-level');
        this.progressTarget.textContent = isLevel
            ? ''
            : this.progressValue.replace('%current%', String(index + 1)).replace('%total%', String(badges));
        this.nextTarget.textContent = isLevel
            ? this.summaryLabelValue
            : (index + 1 === badges ? this.levelLabelValue : this.nextLabelValue);

        this.holdFocus();
        const reveal = this.lastPieceRevealOutlet;
        reveal.start(stageOf(card));
        reveal.preloadStage(stageOf(this.cardTargets[index + 1]));
    }

    finish() {
        cancelAnimationFrame(this.frame);
        this.introTarget.hidden = true;
        this.deckTarget.hidden = true;
        this.summaryTarget.hidden = false;
        this.summaryTarget.classList.add('is-shown');
        this.summaryTarget.scrollIntoView({ block: 'start' });
        (this.hasSummaryHeadingTarget ? this.summaryHeadingTarget : this.summaryTarget).focus({ preventScroll: true });
    }

    /** keyboard flow: a tap on the card takes the focus off the big button - it goes back, Enter keeps working */
    holdFocus() {
        if (!this.deckTarget.hidden && !this.deckTarget.contains(document.activeElement)) {
            this.nextTarget.focus({ preventScroll: true });
        }
    }

    countTo(value, instant) {
        this.aim = value;
        cancelAnimationFrame(this.frame);

        if (instant || prefersReducedMotion()) {
            this.shown = value;
            this.counterTarget.textContent = this.format(value);
            return;
        }

        const from = this.shown;
        const started = performance.now();
        const tick = (now) => {
            const progress = Math.min((now - started) / COUNT_MS, 1);
            this.shown = Math.round(from + (value - from) * (1 - Math.pow(1 - progress, 3)));
            this.counterTarget.textContent = this.format(this.shown);

            if (progress < 1) {
                this.frame = requestAnimationFrame(tick);
            }
        };
        this.frame = requestAnimationFrame(tick);
        this.counterTarget.parentElement?.animate(
            [{ transform: 'scale(1)' }, { transform: 'scale(1.08)' }, { transform: 'scale(1)' }],
            { duration: 420, easing: 'ease-out' },
        );
    }

    format(value) {
        return String(value).replace(/\B(?=(\d{3})+(?!\d))/g, this.separator);
    }
}

function stageOf(card) {
    return card ? card.querySelector('.lpr-stage') : null;
}

function prefersReducedMotion() {
    return window.matchMedia !== undefined && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}
