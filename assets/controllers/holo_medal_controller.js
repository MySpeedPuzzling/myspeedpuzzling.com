/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

const TILT = { none: 0, rim: 8, masterpiece: 12 };
const HAPTIC_MS = 10;

/**
 * The medal of the achievement detail sheet (templates/_achievement_info_modal.html.twig,
 * docs/features/xp-levels/ux-polish/design-spec.md §2). Lazy: it loads when a sheet opens.
 *
 * - A tier row (its button) shows that tier on the big medal: the art (greyscale when not earned), the facts line, the
 *   back's engraving, and the medal data of the row (`data-holo`: back plate, tones, the foil masks of Platinum and
 *   Diamond) as custom properties. Switching always shows the front.
 * - The medal is a <button aria-pressed>: a tap, Enter or Space turns it over to the back (CSS transition; a 0.2 s
 *   cross-fade under prefers-reduced-motion). `navigator.vibrate(10)` where there is one, never under reduced motion.
 * - A tier row first brings the medal into view (scrollIntoView, nearest), then switches.
 * - Platinum (8°) and Diamond (12°) tilt with a mouse or pen (never touch) and get one light sweep: the engine
 *   (assets/holo_medal/engine.js) and the Diamond textures are imported only when such a medal is shown and motion is
 *   welcome - a sheet showing Bronze to Gold never loads them.
 * - disconnect() releases the engine (its listeners and frame), the media query listener and any pending import:
 *   Turbo clears the modal frame when the sheet closes.
 */
export default class extends Controller {
    static targets = ['medal', 'card', 'cardLabel', 'art', 'hint', 'row', 'facts'];

    static values = {
        tier: Number,
        turnLabel: String,
        frontLabel: String,
    };

    connect() {
        this.engine = null;
        this.generation = 0;
        this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        this.reducedMotion.addEventListener('change', this.onMotionPreference);
        this.applyFinish(this.rowFor(this.tierValue), true);
    }

    disconnect() {
        this.generation++;
        this.reducedMotion.removeEventListener('change', this.onMotionPreference);
        this.engine?.destroy();
        this.engine = null;
    }

    show(event) {
        const tier = Number(event.params.tier);

        if (!tier || tier === this.tierValue) {
            return;
        }

        const row = this.rowFor(tier);

        if (!row) {
            return;
        }

        // The change must happen where the eye is: bring the medal into view first (design-spec §8.3)
        this.medalTarget.scrollIntoView({ block: 'nearest', behavior: this.reducedMotion.matches ? 'auto' : 'smooth' });
        this.tierValue = tier;
        this.turnTo(false, false);

        for (const each of this.rowTargets) {
            const shown = each === row;
            each.classList.toggle('is-shown', shown);
            each.querySelector('[aria-pressed]')?.setAttribute('aria-pressed', shown ? 'true' : 'false');
        }

        for (const facts of this.factsTargets) {
            facts.hidden = Number(facts.dataset.tier) !== tier;
        }

        for (const engraving of this.medalTarget.querySelectorAll('.medal-engraving')) {
            engraving.hidden = Number(engraving.dataset.tier) !== tier;
        }

        const hasBack = row.dataset.back === '1';

        if (this.hasCardTarget) {
            this.cardTarget.disabled = !hasBack;

            if (hasBack) {
                this.cardTarget.setAttribute('aria-describedby', `medal-back-${tier}`);
            } else {
                this.cardTarget.removeAttribute('aria-describedby');
            }
        }

        if (this.hasHintTarget) {
            this.hintTarget.hidden = !hasBack;
        }

        this.medalTarget.classList.toggle('has-back', hasBack);
        this.swapArt(row);
    }

    turn() {
        if (!this.hasCardTarget || this.cardTarget.disabled) {
            return;
        }

        this.turnTo(this.cardTarget.getAttribute('aria-pressed') !== 'true', true);
    }

    turnTo(toBack, haptic) {
        if (!this.hasCardTarget) {
            return;
        }

        this.medalTarget.classList.toggle('is-turned', toBack);
        this.cardTarget.setAttribute('aria-pressed', toBack ? 'true' : 'false');

        if (this.hasCardLabelTarget) {
            this.cardLabelTarget.textContent = toBack ? this.frontLabelValue : this.turnLabelValue;
        }

        if (haptic && !this.reducedMotion.matches && typeof navigator.vibrate === 'function') {
            navigator.vibrate(HAPTIC_MS);
        }
    }

    /**
     * The new art is decoded before anything changes, then art, masks and finish change in one go - never a frame of
     * the new art with the old foil.
     */
    swapArt(row) {
        const generation = ++this.generation;
        const image = new Image();
        image.srcset = row.dataset.artSrcset || '';
        image.src = row.dataset.artSrc || '';
        const decoded = typeof image.decode === 'function' ? image.decode().catch(() => {}) : Promise.resolve();

        decoded.then(() => {
            if (generation !== this.generation || !this.hasArtTarget) {
                return;
            }

            this.artTarget.srcset = row.dataset.artSrcset || '';
            this.artTarget.src = row.dataset.artSrc || '';
            this.artTarget.classList.toggle('badge-art-locked', row.dataset.locked === '1');
            this.applyFinish(row, true);
        });
    }

    applyFinish(row, intro) {
        const holo = this.holoOf(row);
        const medal = this.medalTarget;
        const style = medal.style;
        const finish = holo?.finish ?? 'none';

        medal.dataset.finish = finish;

        if (holo) {
            style.setProperty('--medal-back', `url("${holo.back}")`);
            style.setProperty('--medal-rim-tone', holo.rimTone);
            style.setProperty('--medal-edge-tone', holo.edgeTone);
            style.setProperty('--medal-field-x', `${holo.field.x}%`);
            style.setProperty('--medal-field-y', `${holo.field.y}%`);
            style.setProperty('--medal-field-r', `${holo.field.r}%`);
        }

        if (holo && finish !== 'none') {
            style.setProperty('--medal-foil', `url("${finish === 'rim' ? holo.rim : holo.foil}")`);
            style.setProperty('--medal-art', `url("${row.dataset.artSrc}")`);
        } else {
            style.removeProperty('--medal-foil');
            style.removeProperty('--medal-art');
        }

        const tilt = TILT[finish] ?? 0;

        if (tilt === 0 || this.reducedMotion.matches) {
            this.engine?.setMaxTilt(0);
            return;
        }

        const generation = this.generation;

        this.loadEngine().then((engine) => {
            if (generation !== this.generation || engine === null) {
                return;
            }

            engine.setMaxTilt(tilt);

            if (intro) {
                engine.playIntro();
            }
        });
    }

    async loadEngine() {
        if (this.engine) {
            return this.engine;
        }

        const [{ HoloMedalEngine }, { holoTextures }] = await Promise.all([
            import('../holo_medal/engine.js'),
            import('../holo_medal/textures.js'),
        ]);

        // The sheet may have closed while the module loaded
        if (!this.element.isConnected || !this.hasCardTarget) {
            return null;
        }

        if (!this.engine) {
            const textures = holoTextures();
            this.medalTarget.style.setProperty('--medal-glitter', textures.glitter);
            this.medalTarget.style.setProperty('--medal-etch', textures.etch);
            this.medalTarget.classList.add('has-textures');
            this.engine = new HoloMedalEngine(this.medalTarget, this.cardTarget);
        }

        return this.engine;
    }

    onMotionPreference = () => {
        if (this.reducedMotion.matches) {
            this.engine?.setMaxTilt(0);
        } else {
            this.applyFinish(this.rowFor(this.tierValue), false);
        }
    };

    rowFor(tier) {
        return this.rowTargets.find((row) => Number(row.dataset.tier) === tier) ?? null;
    }

    holoOf(row) {
        if (!row?.dataset.holo) {
            return null;
        }

        try {
            return JSON.parse(row.dataset.holo);
        } catch {
            return null;
        }
    }
}
