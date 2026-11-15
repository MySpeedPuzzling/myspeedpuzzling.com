import { Controller } from '@hotwired/stimulus';

/*
 * "Rows we could not read" of the data import (templates/data_import/to_fix.html.twig): an answer given in place
 * replaces a tall card by a short confirmation. Without care everything below would jump up - the next group's
 * button under the finger that just tapped - and the confirmation could end up under the sticky header.
 *
 * So: what replaces a group (or a row not imported) keeps the height of what it replaced **until the player moves
 * on by themselves** - the first scroll, a pointer moved more than a few pixels, a touch outside the confirmation,
 * a key - and only then gives it up, slowly. Never on a timer alone: a pointer that rests where it tapped must not
 * find another button under it (after 15 seconds the height is given up all the same). The confirmation is
 * scrolled into view only when it is not in view.
 */
const FALLBACK_MS = 15000;
const POINTER_PX = 12;
const SHRINK_MS = 700;

export default class extends Controller {
    connect() {
        this.beforeRender = this.beforeRender.bind(this);
        this.pointerAt = this.pointerAt.bind(this);
        this.pointer = null;
        document.addEventListener('turbo:before-stream-render', this.beforeRender);
        document.addEventListener('pointerdown', this.pointerAt, { passive: true });
        document.addEventListener('pointermove', this.pointerAt, { passive: true });
    }

    disconnect() {
        document.removeEventListener('turbo:before-stream-render', this.beforeRender);
        document.removeEventListener('pointerdown', this.pointerAt);
        document.removeEventListener('pointermove', this.pointerAt);
    }

    pointerAt(event) {
        this.pointer = { x: event.clientX, y: event.clientY };
    }

    beforeRender(event) {
        const stream = event.target;
        const targetId = stream.getAttribute('target') || '';

        if (stream.getAttribute('action') !== 'replace' || !/^(group-|left-out-row-)/.test(targetId)) {
            return;
        }

        const old = document.getElementById(targetId);

        if (!old || !this.element.contains(old)) {
            return;
        }

        const height = old.getBoundingClientRect().height;
        const parent = old.parentElement;
        const next = old.nextElementSibling;
        const render = event.detail.render;

        event.detail.render = async (streamElement) => {
            await render(streamElement);

            // What stands there now: the element with the same id, or whatever took the old one's place
            const now = document.getElementById(targetId) || (next ? next.previousElementSibling : parent.lastElementChild);

            if (now) {
                this.settle(now, height);
            }
        };
    }

    settle(element, height) {
        if (element.getBoundingClientRect().height < height) {
            element.style.minHeight = `${height}px`;
            element.classList.add('import-settling');
            this.releaseOnNextMove(element);
        }

        const header = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--header-height')) || 101;
        const box = element.getBoundingClientRect();

        if (box.top < header + 8 || box.top > window.innerHeight - 60) {
            element.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    }

    releaseOnNextMove(element) {
        const from = this.pointer;
        const options = { passive: true, capture: true };
        let timer = null;

        const release = () => {
            window.removeEventListener('scroll', release, options);
            window.removeEventListener('keydown', release, options);
            window.removeEventListener('pointermove', moved, options);
            window.removeEventListener('touchstart', touched, options);
            window.clearTimeout(timer);

            element.style.minHeight = '0px';
            window.setTimeout(() => {
                element.style.minHeight = '';
                element.classList.remove('import-settling');
            }, SHRINK_MS);
        };
        const moved = (event) => {
            if (!from || Math.hypot(event.clientX - from.x, event.clientY - from.y) > POINTER_PX) {
                release();
            }
        };
        const touched = (event) => {
            if (!element.contains(event.target)) {
                release();
            }
        };

        // Not in the same turn: the scroll into view above, and the tap that answered, are not "moving on"
        window.setTimeout(() => {
            window.addEventListener('scroll', release, options);
            window.addEventListener('keydown', release, options);
            window.addEventListener('pointermove', moved, options);
            window.addEventListener('touchstart', touched, options);
        }, 400);
        timer = window.setTimeout(release, FALLBACK_MS);
    }
}
