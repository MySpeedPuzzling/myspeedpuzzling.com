/**
 * The player card's measurement (docs/features/navigation README §4.8 "Measurement and revert rule", CARD-07) - a pure
 * state machine, so tests/player-card-harness.mjs runs it under node (tests/PlayerCardScriptTest.php).
 *
 * Per open of the card (assets/player_card_layer.js; a prefetch is no open):
 * - one `card_open` {context: the context's kind or 'none', input: mouse | touch | pen | keyboard};
 * - exactly one `card_outcome` {context, input, outcome, ms} - the first one wins:
 *   continue (the name, Overview, View profile), deep (Results, Statistics), puzzle (one of the latest puzzles),
 *   action (anything else the card offers: Favorite, Compare, Message, Share, the locked tier, the owner's actions),
 *   bounce (closed within BOUNCE_MS with nothing clicked) or close (closed otherwise - ×, Esc, Back, a swipe, a click
 *   outside, another name, the page left another way). `ms` = from the open to the outcome.
 * The revert rule reads these lines per context kind × input (NavigationRegistry::PLAYER_CARD_REVERT_RULE).
 *
 * Nothing is sent from here: `emit(name, detail)` dispatches the document events CARD_OPEN_EVENT / CARD_OUTCOME_EVENT,
 * assets/nav_instrumentation.js turns them into beacons (P0-03). A trigger clicked with a modifier or the middle button
 * (a new tab or window - no card) is CARD_BYPASS_EVENT, dispatched by assets/player_card.js.
 */

import { CARD_OPEN_EVENT, CARD_OUTCOME_EVENT } from './nav_instrumentation.js';

/** Closed this soon with nothing clicked: a bounce */
export const BOUNCE_MS = 2000;

// data-nav items of the card ("card/<kind>/<item>", templates/players/_card.html.twig) and what using them means
const CONTINUE = ['profile', 'overview', 'view_profile'];
const DEEP = ['results', 'statistics'];
const PUZZLE = ['puzzle'];
// Closing is decided when the card closes (bounce or close), never by the × itself
const CLOSING = ['close'];

/**
 * The outcome of a click on something the card offers: its data-nav item ('' = a control without one, e.g. the ⋯
 * toggle); null = not an outcome (closing, or the ⋯ toggle - it only opens the menu)
 */
export function outcomeOf(item) {
    if (typeof item !== 'string' || item === '' || CLOSING.includes(item)) {
        return null;
    }

    if (CONTINUE.includes(item)) {
        return 'continue';
    }

    if (DEEP.includes(item)) {
        return 'deep';
    }

    if (PUZZLE.includes(item)) {
        return 'puzzle';
    }

    return 'action';
}

/**
 * {now(): ms, emit(name, detail)} → the measure of one card layer: opened(context, input), clicked(item), closed()
 */
export function createCardMeasure({ now, emit }) {
    // The open being measured: {context, input, at, touched (something was used that is not an outcome)}
    let open = null;

    const finish = (outcome) => {
        const current = open;
        open = null;
        emit(CARD_OUTCOME_EVENT, {
            context: current.context,
            input: current.input,
            outcome,
            ms: Math.max(0, Math.round(now() - current.at)),
        });
    };

    const measure = {
        /** A card opened (another name while one is open: that one ends first) */
        opened(context, input) {
            measure.closed();
            open = { context, input, at: now(), touched: false };
            emit(CARD_OPEN_EVENT, { context, input });
        },

        /** Something inside the card was clicked: its data-nav item, '' for a control without one */
        clicked(item) {
            if (open === null || (typeof item === 'string' && CLOSING.includes(item))) {
                return;
            }

            const outcome = outcomeOf(item);

            if (outcome === null) {
                open.touched = true;

                return;
            }

            finish(outcome);
        },

        /** The card closed - nothing chosen before: a bounce or a close */
        closed() {
            if (open === null) {
                return;
            }

            finish(!open.touched && now() - open.at < BOUNCE_MS ? 'bounce' : 'close');
        },

        isMeasuring() {
            return open !== null;
        },
    };

    return measure;
}
