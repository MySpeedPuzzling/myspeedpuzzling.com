// The visible feedback of the sheet (assets/participants_sheet/sheet_toasts.js - BR1) in jsdom: newest first, at most
// three, how long they stay (errors ~8 s, information ~4 s, the time standing still under the pointer / the focus),
// the same message replacing itself, close / Esc / actions, pointing at a cell and going back to the stack when the
// organiser moves on, phones never pointing, a modal dialog holding them while it is open.
import assert from 'node:assert/strict';
import { setupDom, key, TEXTS } from './dom.mjs';
import { clock } from './fixture.mjs';

let SheetToasts;
let TOAST_DURATIONS;

async function setup({ phone = false, anchorElement = null } = {}) {
    setupDom();
    ({ SheetToasts, TOAST_DURATIONS } = await import('../../assets/participants_sheet/sheet_toasts.js'));
    const time = clock();
    document.body.innerHTML = '<div id="bar"><div id="status"></div><div id="region"></div></div><table><tr><td id="cell">Kim</td><td id="other">Pat</td></tr></table><button id="elsewhere">x</button>';
    const region = document.getElementById('region');
    let returned = 0;
    const toasts = new SheetToasts({
        region,
        texts: TEXTS,
        resolveAnchor: (anchor) => (anchor?.nodeType === 1 ? anchor : (anchor?.row === 'kim' ? (anchorElement ?? document.getElementById('cell')) : null)),
        isPhone: () => phone,
        returnFocus: () => returned++,
        setTimer: (fn, ms) => time.schedule(fn, ms),
        clearTimer: (id) => time.cancel(id),
        now: time.now,
    });

    return { toasts, region, time, returned: () => returned };
}

/** jsdom lays nothing out: a cell somewhere in the window. */
function placeAt(element, rect) {
    element.getBoundingClientRect = () => ({ top: rect.top, bottom: rect.top + rect.height, left: rect.left, right: rect.left + rect.width, width: rect.width, height: rect.height });
}

export default function (test) {
    test('newest first, at most three; the same message again replaces the one shown', async () => {
        const { toasts, region } = await setup();
        toasts.show('One', { kind: 'info' });
        toasts.show('Two', { kind: 'error' });
        toasts.show('Three', { kind: 'warning' });
        toasts.show('Four');
        assert.deepEqual(toasts.shown().map((toast) => toast.text), ['Four', 'Three', 'Two']);
        assert.deepEqual([...region.querySelectorAll('.sheet-toast-text')].map((element) => element.textContent), ['Four', 'Three', 'Two'], 'newest first in the page too');
        assert.ok(region.querySelector('.sheet-toast-error .bi-x-octagon'), 'an icon per kind (never colour alone - the text says it)');
        assert.equal(region.querySelector('[aria-live]'), null, 'no live region of its own - the page says it once');

        toasts.show('Two', { kind: 'error' });
        assert.deepEqual(toasts.shown().map((toast) => toast.text), ['Two', 'Four', 'Three']);
        toasts.destroy();
    });

    test('errors stay ~8 s, information ~4 s; the time stands still under the pointer and while the focus is inside', async () => {
        const { toasts, region, time } = await setup();
        assert.equal(TOAST_DURATIONS.error, 8000);
        assert.equal(TOAST_DURATIONS.info, 4000);
        toasts.show('Saved elsewhere', { kind: 'info' });
        toasts.show('Not saved', { kind: 'error' });
        await time.advance(4000);
        assert.deepEqual(toasts.shown().map((toast) => toast.text), ['Not saved']);

        const error = region.querySelector('.sheet-toast');
        error.dispatchEvent(new window.MouseEvent('mouseover', { bubbles: true }));
        await time.advance(10000);
        assert.equal(toasts.shown().length, 1, 'kept while the pointer is on it');
        error.dispatchEvent(new window.MouseEvent('mouseout', { bubbles: true, relatedTarget: document.body }));
        await time.advance(1500);
        assert.equal(toasts.shown().length, 1, 'a moment to finish reading');
        await time.advance(4000);
        assert.equal(toasts.shown().length, 0);
        toasts.destroy();
    });

    test('close button, Esc in a toast (the focus goes back where it came from), an action runs and closes', async () => {
        const { toasts, region, returned } = await setup();
        let shown = 0;
        toasts.show('Problem on Pairs', { kind: 'error', actions: [{ label: 'Show', run: () => shown++ }] });
        region.querySelector('[data-toast-action="0"]').click();
        assert.equal(shown, 1);
        assert.equal(toasts.shown().length, 0);

        toasts.show('Nothing to undo.');
        region.querySelector('[data-toast-close]').click();
        assert.equal(toasts.shown().length, 0);
        assert.equal(region.querySelector('[data-toast-close]'), null);

        const before = document.getElementById('elsewhere');
        before.focus();
        toasts.show('Not saved', { kind: 'error' });
        const close = region.querySelector('[data-toast-close]');
        assert.equal(close.getAttribute('aria-label'), 'notify_close');
        close.dispatchEvent(new window.FocusEvent('focusin', { bubbles: true, relatedTarget: before }));
        close.focus();
        const escape = key(close, 'Escape');
        assert.equal(escape.defaultPrevented, true, 'not the dialog\'s or the grid\'s Esc');
        assert.equal(toasts.shown().length, 0);
        assert.equal(document.activeElement, before, 'back where it came from');

        // Came from nowhere known: the page's focus (the view's active cell)
        toasts.show('Not saved', { kind: 'error' });
        region.querySelector('[data-toast-close]').focus();
        toasts.focusBefore = null;
        key(region.querySelector('[data-toast-close]'), 'Escape');
        assert.equal(returned(), 1);
        toasts.destroy();
    });

    test('desktop: the newest toast points at its cell; it goes back to the stack when the organiser moves on', async () => {
        const { toasts, region, time } = await setup();
        placeAt(document.getElementById('cell'), { top: 200, left: 300, width: 120, height: 32 });
        const handle = toasts.show('Kim Example\'s result in Solo is recorded', { kind: 'error', anchor: { row: 'kim', col: 'solo' } });
        const element = region.querySelector('.sheet-toast');
        assert.equal(element.classList.contains('is-anchored'), true);
        assert.equal(element.style.top, '238px', 'right under the cell');
        assert.equal(element.style.left, '300px');
        assert.equal(toasts.shown()[0].anchored, true);

        // The focus moves of the action that caused it do not count; what comes after does
        document.getElementById('elsewhere').dispatchEvent(new window.FocusEvent('focusin', { bubbles: true }));
        assert.equal(element.classList.contains('is-anchored'), true);
        await time.advance(0);
        document.getElementById('other').dispatchEvent(new window.FocusEvent('focusin', { bubbles: true }));
        assert.equal(element.classList.contains('is-anchored'), false, 'docked in the stack');
        assert.equal(element.style.top, '');
        assert.equal(toasts.shown()[0].text, 'Kim Example\'s result in Solo is recorded', 'still shown');
        handle.dismiss();

        // Only the newest one points; a cell out of view or gone: in the stack
        toasts.show('First', { anchor: { row: 'kim' } });
        toasts.show('Second', { anchor: { row: 'kim' } });
        assert.deepEqual(toasts.shown().map((toast) => toast.anchored), [true, false]);
        toasts.show('Nowhere', { anchor: { row: 'gone' } });
        assert.equal(toasts.shown()[0].anchored, false);
        placeAt(document.getElementById('cell'), { top: -400, left: 300, width: 120, height: 32 });
        toasts.show('Scrolled away', { anchor: { row: 'kim' } });
        assert.equal(toasts.shown()[0].anchored, false);
        toasts.destroy();
    });

    test('phones never point at a cell (a bottom toast)', async () => {
        const { toasts } = await setup({ phone: true });
        placeAt(document.getElementById('cell'), { top: 200, left: 300, width: 120, height: 32 });
        toasts.show('Not saved', { kind: 'error', anchor: { row: 'kim' } });
        assert.equal(toasts.shown()[0].anchored, false);
        toasts.destroy();
    });

    test('while a modal dialog is open the toasts show inside it, and come back to the stack when it closes', async () => {
        const { toasts, region } = await setup();
        const dialog = document.createElement('dialog');
        dialog.innerHTML = '<button>Confirm</button>';
        document.body.append(dialog);
        dialog.showModal();
        toasts.show('Kim can\'t be moved');
        assert.ok(dialog.querySelector('.sheet-toasts-dialog .sheet-toast'), 'inside the dialog (the page behind it is inert)');
        assert.equal(region.querySelector('.sheet-toast'), null);
        dialog.close();
        assert.equal(dialog.querySelector('.sheet-toast'), null);
        assert.equal(region.querySelector('.sheet-toast-text').textContent, 'Kim can\'t be moved');
        toasts.destroy();
    });
}
