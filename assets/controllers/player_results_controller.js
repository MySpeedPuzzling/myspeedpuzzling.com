/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { renderStreamMessage, visit } from '@hotwired/turbo';
import layers from '../layer_history.js';

const FRAME_ID = 'player-results';
const FOOTER_ID = 'player-results-more';
const STREAM_TYPE = 'text/vnd.turbo-stream.html';
// The stream's actions render after the next repaint (Turbo): the new footer is the sign they are done
const RENDER_TIMEOUT_MS = 5000;

/**
 * A player's Results page (docs/features/navigation README §4.7). Everything works without it: every control is a
 * link to the list's URL. This adds, for PROF-05b:
 *
 * - Show more (`a[data-results-more]`, `?…&page=N+1`): the same URL with `fragment=more` answers a Turbo Stream - the
 *   next rows appended to the list, its footer replaced (PlayerResultsController) - instead of loading the page again
 * - Show all (`a[data-results-all]`, `?…&page=<last>`): only the list (the `player-results` frame) is reloaded
 *
 * The address then says what the page shows (`?page=N`), so a reload or a Back from a puzzle page renders the same rows:
 * the entry is replaced through assets/layer_history.js (the one owner of history entries next to Turbo), keeping
 * Turbo's identity of the entry. Keyboard and screen reader users land on the first new row.
 *
 * PROF-05c makes this page the owner of its own history entries (pause for as long as the page is shown, popstate
 * handled here); until then the page hands the entry back to Turbo right after replacing it.
 */
export default class extends Controller {
    connect() {
        this.loading = false;
    }

    async more(event) {
        const link = event.currentTarget;

        if (!this.takesOver(event)) {
            return;
        }

        event.preventDefault();

        if (this.loading) {
            return;
        }

        const url = new URL(link.href, window.location.href);
        const streamUrl = new URL(url.href);
        streamUrl.searchParams.set('fragment', 'more');

        const before = this.rowsCount();
        const footer = document.getElementById(FOOTER_ID);
        this.busy(link, footer);

        try {
            const response = await fetch(streamUrl.href, {
                headers: { Accept: STREAM_TYPE },
                credentials: 'same-origin',
            });
            const type = response.headers.get('Content-Type') || '';

            if (!response.ok || !type.includes(STREAM_TYPE)) {
                throw new Error('Results: no stream (' + response.status + ')');
            }

            renderStreamMessage(await response.text());
            await this.replaced(footer);
        } catch (error) {
            // The link's own way: load the page with the rows
            this.loading = false;
            visit(url.href);

            return;
        }

        this.loading = false;
        this.replaceUrl(url);
        this.focusRow(before);
    }

    all(event) {
        const link = event.currentTarget;
        const frame = document.getElementById(FRAME_ID);

        if (!this.takesOver(event) || !frame) {
            return;
        }

        event.preventDefault();

        if (this.loading) {
            return;
        }

        const url = new URL(link.href, window.location.href);
        const before = this.rowsCount();
        this.busy(link, document.getElementById(FOOTER_ID));

        const done = () => {
            frame.removeEventListener('turbo:frame-load', loaded);
            frame.removeEventListener('turbo:fetch-request-error', failed);
            this.loading = false;
        };
        const loaded = () => {
            done();
            this.replaceUrl(url);
            this.focusRow(before);
        };
        const failed = () => {
            done();
            visit(url.href);
        };

        frame.addEventListener('turbo:frame-load', loaded);
        frame.addEventListener('turbo:fetch-request-error', failed);
        frame.src = url.href;
    }

    /** A plain left click this page handles - anything else (a new tab, a download) is the browser's */
    takesOver(event) {
        return !(event.defaultPrevented || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey);
    }

    busy(link, footer) {
        this.loading = true;
        link.classList.add('disabled');
        link.setAttribute('aria-disabled', 'true');

        if (footer) {
            footer.setAttribute('aria-busy', 'true');
        }
    }

    rows() {
        const body = document.getElementById('player-results-rows');

        return body ? Array.from(body.querySelectorAll(':scope > tr[id]')) : [];
    }

    rowsCount() {
        return this.rows().length;
    }

    /** Resolves once the stream replaced the footer (its last action), or after a timeout */
    replaced(footer) {
        return new Promise((resolve) => {
            const isDone = () => !footer || !footer.isConnected;

            if (isDone()) {
                resolve();

                return;
            }

            const observer = new MutationObserver(() => {
                if (isDone()) {
                    observer.disconnect();
                    clearTimeout(timer);
                    resolve();
                }
            });
            const timer = setTimeout(() => {
                observer.disconnect();
                resolve();
            }, RENDER_TIMEOUT_MS);

            observer.observe(document.getElementById(FRAME_ID) || document.body, { childList: true, subtree: true });
        });
    }

    replaceUrl(url) {
        layers.replacePageState('results', url.href);
        // Until PROF-05c the page owns no entries: Turbo takes this one back (with its identity) at once
        layers.resumeTurboHistory('results');
    }

    /** The first new row's puzzle link takes the focus, without scrolling the page */
    focusRow(index) {
        const row = this.rows()[index];
        const target = row ? row.querySelector('a.ps-puzzle-name') : null;

        if (target) {
            target.focus({ preventScroll: true });
        }
    }
}
