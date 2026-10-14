/**
 * The reviewer's gap marks in the review of an online round (docs/features/online-competitions/README.md §5.9, stream
 * RG) - pure, no DOM, the requests through `send`, pinned by tests/online-review-harness.mjs:
 *
 * - G (or "Shows a gap - next") marks the moment the review stands on - a raise of the hands or a Finished tap, its
 *   event id - as showing a gap; "Not a gap" (or confirming that very moment) takes the mark back. The page shows the
 *   mark at once and moves on at once: the request (online_review_gap, `{eventId, gap}`) follows;
 * - one request at a time, the latest wish per moment wins (a mark taken back while it was on its way is sent again);
 * - the request did not reach the server (offline, a 5xx, a rate limiter, signed out): the mark stays on the page as
 *   "not saved yet" and is sent again by retry() - the page calls it when it is back online and with every refresh of
 *   its list; said once until a request goes through again (`offline` / `auth`, then `saved`);
 * - refused (422 moment_not_found, 403): the mark is dropped - the server's state is shown - and said (`refused`);
 * - what the server answers (`gaps` - the participant's moments marked) and what the detail brings (load()) is the
 *   saved state; a wish not answered yet wins over it.
 */

const key = (participantId, eventId) => `${participantId} ${String(eventId).toLowerCase()}`;
const lower = (id) => String(id).toLowerCase();

export class GapMarks {
    /**
     * @param {{
     *   send: function({participantId: string, eventId: string, gap: boolean}): Promise<{kind: string, status?: number, data?: object}>,
     *   changed?: function(): void,
     *   report?: function({kind: 'offline'|'auth'|'saved'|'refused', answer?: object}): void,
     * }} options
     */
    constructor({ send, changed = () => {}, report = () => {} }) {
        this.send = send;
        this.changed = changed;
        this.report = report;
        // participantId => the event ids the server says are marked
        this.saved = new Map();
        // key => {participantId, eventId, gap} - wishes not answered yet, in the order they came
        this.pending = new Map();
        this.flushing = null;
        // Why the last request did not go through ('offline' | 'auth') - null after one that did
        this.failure = null;
    }

    /**
     * The participant's marks as the server has them (the review's detail) - quietly, the page renders after it
     */
    load(participantId, gaps) {
        this.saved.set(participantId, new Set((Array.isArray(gaps) ? gaps : []).filter((id) => typeof id === 'string').map(lower)));
    }

    isGap(participantId, eventId) {
        const wish = this.pending.get(key(participantId, eventId));

        if (wish !== undefined) {
            return wish.gap;
        }

        return this.saved.get(participantId)?.has(lower(eventId)) === true;
    }

    /**
     * A mark on the page the server does not have yet (sent, or waiting to be sent again)
     */
    isUnsaved(participantId, eventId) {
        return this.pending.has(key(participantId, eventId));
    }

    /**
     * The reviewer's wish for one moment - true: shows a gap. Shown at once, sent after; false when it is so already.
     */
    set(participantId, eventId, gap) {
        if (typeof participantId !== 'string' || typeof eventId !== 'string' || this.isGap(participantId, eventId) === gap) {
            return false;
        }

        const wishKey = key(participantId, eventId);
        // Back to what the server has, and nothing on its way: no request needed
        const savedGap = this.saved.get(participantId)?.has(lower(eventId)) === true;

        if (savedGap === gap && this.flushing === null) {
            this.pending.delete(wishKey);
        } else {
            this.pending.delete(wishKey);
            this.pending.set(wishKey, { participantId, eventId: lower(eventId), gap });
        }

        this.changed();
        this.flush();

        return true;
    }

    /**
     * Sends what waits, one after another - the promise of the run in progress
     */
    flush() {
        if (this.flushing === null) {
            this.flushing = this.run().finally(() => {
                this.flushing = null;
            });
        }

        return this.flushing;
    }

    /**
     * Sends again what could not be sent (back online, the list refreshed)
     */
    retry() {
        return this.pending.size > 0 ? this.flush() : Promise.resolve('done');
    }

    async run() {
        for (;;) {
            const wish = this.pending.values().next().value;

            if (wish === undefined) {
                return 'done';
            }

            const { participantId, eventId, gap } = wish;
            const wishKey = key(participantId, eventId);
            let answer;

            try {
                answer = await this.send({ participantId, eventId, gap });
            } catch (e) {
                answer = { kind: 'offline' };
            }

            const current = this.pending.get(wishKey);

            if (answer?.kind === 'ok') {
                const marked = this.saved.get(participantId) ?? new Set();

                if (Array.isArray(answer.data?.gaps)) {
                    this.load(participantId, answer.data.gaps);
                } else {
                    if (gap) {
                        marked.add(eventId);
                    } else {
                        marked.delete(eventId);
                    }

                    this.saved.set(participantId, marked);
                }

                // A wish that changed on the way is sent again
                if (current !== undefined && current.gap === gap) {
                    this.pending.delete(wishKey);
                }

                if (this.failure !== null) {
                    this.failure = null;
                    this.report({ kind: 'saved' });
                }

                this.changed();

                continue;
            }

            if (answer?.kind === 'offline' || answer?.kind === 'server' || answer?.kind === 'auth') {
                // Kept - sent again by retry(); said once until a request goes through
                const failure = answer.kind === 'auth' ? 'auth' : 'offline';

                if (this.failure !== failure) {
                    this.failure = failure;
                    this.report({ kind: failure, answer });
                }

                this.changed();

                return failure;
            }

            // Refused: the server's state stays, the mark goes
            if (current !== undefined && current.gap === gap) {
                this.pending.delete(wishKey);
            }

            this.report({ kind: 'refused', answer });
            this.changed();
        }
    }
}

/**
 * The mark a moment of the review's list carries: null when it is not marked; else its text key and its title key -
 * "not saved yet" while the server does not have it.
 *
 * @param {GapMarks} marks
 * @returns {{text: string, title: string, unsaved: boolean}|null}
 */
export function gapMark(marks, participantId, eventId) {
    if (!marks || !marks.isGap(participantId, eventId)) {
        return null;
    }

    const unsaved = marks.isUnsaved(participantId, eventId);

    return { text: 'gap_mark', title: unsaved ? 'gap_mark_unsaved' : 'gap_mark_title', unsaved };
}
