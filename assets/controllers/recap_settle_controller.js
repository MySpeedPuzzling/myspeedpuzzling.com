/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

/**
 * Sits on XpRecapCelebration's root while a fresh recap's XP is not counted yet and asks the component to settle it,
 * exactly once, a moment after the paint (docs/features/xp-levels/README.md §Surfaces). Not a `data-poll`: that is a
 * bare setInterval, cleared only after the response re-rendered - every tick during the request (the wait for the
 * consumer's lock included) would queue one more `settle`, sent as another request after the render that shows the XP.
 *
 * A settle that fails (an error answer, a dropped connection) hands over to the component's growing poll.
 */
export default class extends Controller {
    static values = {
        delay: Number,
        fallback: Number,
    };

    initialize() {
        this.started = false;
        this.timer = null;
        this.request = null;
    }

    connect() {
        if (!this.started) {
            this.timer = setTimeout(() => this.settle(), this.delayValue);
        }
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    async settle() {
        this.started = true;

        const component = await getComponent(this.element);

        component.on('loading.state:started', (element, request) => {
            if (this.request !== null) {
                return;
            }

            this.request = request;
            request.promise.catch(() => {
                // Live Component never clears a request whose fetch rejected: it would ignore every later action
                if (component.backendRequest === request) {
                    component.backendRequest = null;
                }

                this.pollInstead(component);
            });
        });

        component.on('response:error', (backendResponse, controls) => {
            if (component.backendRequest === this.request) {
                controls.displayError = false;
                this.pollInstead(component);
            }
        });

        component.action('settle', {}, 0);
    }

    pollInstead(component) {
        this.timer = setTimeout(() => component.action('checkProgress', {}, 0), this.fallbackValue);
    }
}
