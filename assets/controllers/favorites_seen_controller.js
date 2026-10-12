/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

const ACTION = 'markFavoritesSeen';

/**
 * Sits on the Hub's Favorites pane (templates/hub.html.twig) and marks the feed seen once per Hub visit, the first
 * time the pane is shown (docs/features/navigation, INB-04a): rows added after that count as "new since your last
 * visit" next time.
 *
 * - "shown" = Bootstrap's `shown.bs.tab` for the pane's tab, `feed-swipe:shown` for the pane settling in the phone
 *   scroller (GEST-16 dispatches it on the pane, bubbling; a `detail.pane` is honoured too), or the pane already shown
 *   when this connects (a #recent-favorites-activity link, Favorites as the default tab)
 * - one Live action on the pane's RecentActivity component - never from live_refresh: its re-renders happen inside
 *   the component and never reconnect this controller, which sits on the pane around it
 * - skipped while the last visit is younger than `interval` seconds - the server would change nothing (reloading the
 *   Hub a minute later sends nothing)
 * - nothing on the page changes: the component keeps the `newSince` it was rendered with
 */
export default class extends Controller {
    static values = {
        // The last visit as the page was rendered with, Unix seconds; 0 = none yet
        newSince: Number,
        // The server's clock when the page was rendered, Unix seconds - the age is measured on the server's clock
        rendered: Number,
        interval: { type: Number, default: 600 },
    };

    connect() {
        this.handled = false;
        this.offset = this.renderedValue > 0 ? this.renderedValue * 1000 - Date.now() : 0;
        this.onShown = (event) => {
            if (paneOf(event) === this.element) {
                this.shown();
            }
        };

        document.addEventListener('shown.bs.tab', this.onShown);
        document.addEventListener('feed-swipe:shown', this.onShown);

        if (isShown(this.element)) {
            this.shown();
        }
    }

    disconnect() {
        document.removeEventListener('shown.bs.tab', this.onShown);
        document.removeEventListener('feed-swipe:shown', this.onShown);
    }

    shown() {
        if (this.handled) {
            return;
        }

        this.handled = true;

        if (!isDue(this.newSinceValue, Date.now() + this.offset, this.intervalValue)) {
            return;
        }

        const element = this.element.querySelector('[data-controller~="live"]');

        if (element === null) {
            return;
        }

        getComponent(element).then((component) => mark(component), () => {
            // Not connected yet: the next time the pane is shown tries again
            this.handled = false;
        });
    }
}

function mark(component) {
    let pending = true;

    const done = () => {
        pending = false;
        component.off('response:error', onError);
    };

    // A background mark never opens Live's error page; an automatic refresh failing meanwhile is live_refresh's call
    const onError = (backendResponse, controls) => {
        if (pending) {
            controls.displayError = false;
        }
    };

    const onStarted = (requestConfig) => {
        if (!requestConfig.actions.some((action) => action.name === ACTION)) {
            return;
        }

        component.off('request:started', onStarted);

        // Live Component never clears a request whose fetch rejected (offline, a dropped connection): every later
        // render - the automatic refresh included - would wait for it forever. Assigned right after this hook.
        queueMicrotask(() => {
            const request = component.backendRequest;

            request?.promise.catch(() => {
                if (component.backendRequest === request) {
                    component.backendRequest = null;
                }

                done();
            });
        });
    };

    component.on('response:error', onError);
    component.on('request:started', onStarted);
    component.action(ACTION).then(done);
}

function isDue(newSince, serverNow, interval) {
    return newSince <= 0 || serverNow - newSince * 1000 >= interval * 1000;
}

function paneOf(event) {
    if (event.type === 'feed-swipe:shown') {
        return event.detail?.pane ?? event.target;
    }

    const selector = event.target.getAttribute?.('data-bs-target') ?? event.target.getAttribute?.('href');

    return selector?.startsWith('#') ? document.getElementById(selector.slice(1)) : null;
}

function isShown(element) {
    return typeof element.checkVisibility === 'function' ? element.checkVisibility() : element.offsetParent !== null;
}
