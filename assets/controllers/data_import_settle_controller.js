import { Controller } from '@hotwired/stimulus';

/*
 * "Rows we could not read" of the data import (templates/data_import/to_fix.html.twig): an answer given in place
 * replaces a tall card by a short confirmation. Without care everything below would jump up - the next group's
 * button under the finger that just tapped - and the confirmation could end up under the sticky header.
 *
 * So: what replaces a group (or a row not imported) keeps the height of what it replaced for a moment and then
 * gives it up slowly, and is scrolled into view only when it is not in view.
 */
const HOLD_MS = 1600;
const HEADER_PX = 124;

export default class extends Controller {
    connect() {
        this.beforeRender = this.beforeRender.bind(this);
        document.addEventListener('turbo:before-stream-render', this.beforeRender);
    }

    disconnect() {
        document.removeEventListener('turbo:before-stream-render', this.beforeRender);
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

            window.setTimeout(() => {
                element.style.minHeight = '0px';
                window.setTimeout(() => {
                    element.style.minHeight = '';
                    element.classList.remove('import-settling');
                }, 700);
            }, HOLD_MS);
        }

        const box = element.getBoundingClientRect();

        if (box.top < HEADER_PX || box.top > window.innerHeight - 60) {
            element.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    }
}
