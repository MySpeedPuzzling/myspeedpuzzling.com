import { isNativeApp } from './platform.js';
import { cardUrl, contextOf, inputOf, playerIdOf, shouldOpen, TRIGGER_SELECTOR } from './player_card_rules.js';

/**
 * The player card's trigger (docs/features/navigation README §4.8, CARD-01a): one delegated click listener for every
 * player name / avatar link carrying data-player-card, on every page. Small and eager (imported by app.js); the layer
 * itself (assets/player_card_layer.js) loads on the first hover rest, focus or open.
 *
 * - A plain click opens the card instead of following the link (assets/player_card_rules.js shouldOpen()): the
 *   listener runs on the document in the bubble phase, before Turbo's own on the window, so its preventDefault() wins.
 *   Ctrl/Cmd/Shift/Alt and the middle button keep opening the profile; inside the native app the link navigates.
 * - Prefetch: after a 150 ms pointer rest on a trigger (a touch has none - its tap follows at once) and on focus; only
 *   the card URL is fetched, kept 20 s (the layer's cache).
 * - The card URL: the template on <body data-player-card-url> (viewer-independent), the player's id, the closest
 *   data-player-card-context.
 * Nothing here writes an attribute on a trigger: Live Components re-apply attributes JavaScript changed.
 */

/** A pointer resting this long on a trigger prefetches its card */
export const PREFETCH_REST_MS = 150;

/**
 * Installs the listeners on the document. `load` returns the layer module (tests/player-card-harness.mjs passes a
 * fake); by default assets/player_card_layer.js, imported on first use.
 */
export function installPlayerCard({ window, document, load = () => import('./player_card_layer.js') }) {
    let layerModule = null;
    let lastPointerType = null;
    let restLink = null;
    let restTimer = null;

    const layer = () => {
        if (layerModule === null) {
            layerModule = load();
            layerModule.catch(() => {
                layerModule = null;
            });
        }

        return layerModule;
    };

    const triggerOf = (target) => (target && typeof target.closest === 'function' ? target.closest(TRIGGER_SELECTOR) : null);

    const urlOf = (link) => {
        const template = document.body ? document.body.getAttribute('data-player-card-url') : null;

        return cardUrl(template, playerIdOf(link), contextOf(link));
    };

    const prefetch = (link) => {
        const url = isNativeApp(document.documentElement) ? null : urlOf(link);

        if (url) {
            layer().then((module) => module.prefetchCard(url), () => {});
        }
    };

    document.addEventListener('pointerdown', (event) => {
        lastPointerType = event.pointerType || null;
    }, { capture: true, passive: true });

    document.addEventListener('click', (event) => {
        const link = triggerOf(event.target);

        if (!link) {
            return;
        }

        const env = {
            nativeApp: isNativeApp(document.documentElement),
            direct: document.body ? document.body.getAttribute('data-player-card-direct') : null,
            lastPointerType,
        };
        const url = shouldOpen(event, link, env) ? urlOf(link) : null;

        if (!url) {
            return;
        }

        event.preventDefault();
        const input = inputOf(event, lastPointerType);

        // The layer could not load (offline, a stale build): the link goes where it always went - the profile
        layer().then(
            (module) => module.openCard(link, url, { input }),
            () => window.location.assign(link.href),
        );
    });

    document.addEventListener('pointerover', (event) => {
        const link = event.pointerType === 'touch' ? null : triggerOf(event.target);

        if (!link || link === restLink) {
            return;
        }

        window.clearTimeout(restTimer);
        restLink = link;
        restTimer = window.setTimeout(() => {
            restTimer = null;
            prefetch(link);
        }, PREFETCH_REST_MS);
    });

    document.addEventListener('pointerout', (event) => {
        if (restLink === null || triggerOf(event.target) !== restLink || (event.relatedTarget && restLink.contains(event.relatedTarget))) {
            return;
        }

        window.clearTimeout(restTimer);
        restTimer = null;
        restLink = null;
    });

    document.addEventListener('focusin', (event) => {
        const link = triggerOf(event.target);

        if (link) {
            prefetch(link);
        }
    });
}
