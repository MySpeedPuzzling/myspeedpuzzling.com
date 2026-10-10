/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * The XP launch reveal of a member (templates/xp_launch_reveal.html.twig): every achievement, one card at a time in
 * crescendo order. Each card assembles by itself (the last-piece reveal, driven through the outlet), then its name,
 * tier, the requirement it met and the Achievement Points it brought appear. The XP in the bar stays the player's XP
 * from puzzles - achievements grant none. A tap anywhere (or the big button) goes on - while a card still assembles,
 * the first tap completes it. The level medallion is the finale, a summary of everything ends it; "Skip to summary"
 * is there all the time. Reduced motion: every card is complete at once (the reveal controller decides).
 */
export default class extends Controller {
    static targets = ['intro', 'deck', 'card', 'progress', 'next', 'summary', 'summaryHeading'];

    static outlets = ['last-piece-reveal'];

    static values = {
        progress: String,                                  // "%current% / %total%"
        nextLabel: String,
        levelLabel: String,
        summaryLabel: String,
    };

    connect() {
        this.index = -1;
        this.queued = false;
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
}

function stageOf(card) {
    return card ? card.querySelector('.lpr-stage') : null;
}

function prefersReducedMotion() {
    return window.matchMedia !== undefined && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}
