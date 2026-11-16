// Runs the real guard of an import page's own reload (assets/data_import_reload_guard.js - what
// data_import_setup_controller.js asks before it loads "Check and add" again) for
// tests/ImportReloadGuardScriptTest.php. No browser is needed: the guard only listens, so the document and the
// window are two small event hubs. One object of observed facts per scenario is printed as JSON.
//
// By hand: node tests/import-reload-guard-harness.mjs

import { createReloadGuard } from '../assets/data_import_reload_guard.js';

function hub(extra = {}) {
    const handlers = {};

    return {
        ...extra,
        addEventListener(name, handler) { (handlers[name] ||= []).push(handler); },
        removeEventListener(name, handler) { handlers[name] = (handlers[name] || []).filter((known) => known !== handler); },
        emit(name, event = {}) { (handlers[name] || []).slice().forEach((handler) => handler(event)); },
        listening() { return Object.values(handlers).reduce((sum, list) => sum + list.length, 0); },
    };
}

function world() {
    const doc = hub({ documentElement: { name: 'html' } });
    const win = hub();

    return { doc, win, guard: createReloadGuard({ doc, win }) };
}

const inForm = { closest: (selector) => (selector === 'form' ? {} : null) };
const outsideForm = { closest: () => null };
const field = { matches: () => true };
const link = { matches: () => false };
const facts = {};

// Nothing happened: the page may load itself again
{
    const { guard } = world();
    facts.idle = [guard.verdict(), guard.verdict({ activeElement: link })];
    facts.gone = [guard.verdict({ connected: false }), guard.verdict({ stopped: true })];
}

// The player leaves - by whatever way: never a reload after it, and the poll on its way is aborted at once, once
for (const [name, where] of [['turbo:click', 'doc'], ['turbo:before-visit', 'doc'], ['turbo:visit', 'doc'], ['turbo:submit-start', 'doc'], ['beforeunload', 'win'], ['pagehide', 'win']]) {
    const { doc, win, guard } = world();
    let aborted = 0;
    guard.whenLeaving(() => { aborted += 1; });
    const before = guard.verdict();
    (where === 'doc' ? doc : win).emit(name, {});
    (where === 'doc' ? doc : win).emit(name, {});
    facts[`leaves by ${name}`] = [before, guard.leaving, aborted, guard.verdict(), guard.verdict({ activeElement: field })];
}

// The answer of a poll that was on its way when the player clicked: the click comes first, the answer finds "no"
{
    const { doc, guard } = world();
    const controller = new AbortController();
    guard.whenLeaving(() => controller.abort());
    doc.emit('turbo:click', {});
    facts.race = [controller.signal.aborted, guard.verdict()];
}

// A fetch Turbo makes for a visit is leaving; one for a frame of the page is not
{
    const { doc, guard } = world();
    doc.emit('turbo:before-fetch-request', { target: { name: 'turbo-frame' } });
    const afterFrame = [guard.leaving, guard.verdict()];
    doc.emit('turbo:before-fetch-request', { target: doc.documentElement });
    facts.fetches = [afterFrame, [guard.leaving, guard.verdict()]];
}

// In the middle of something on the page: the "Refresh" link instead of a reload
{
    const { doc, guard } = world();
    doc.emit('input', { target: outsideForm });
    const outside = [guard.touched, guard.verdict()];
    doc.emit('change', { target: inForm });
    facts.touched = [outside, [guard.touched, guard.verdict()]];

    const standing = world();
    facts.inAField = [standing.guard.verdict({ activeElement: field }), standing.guard.verdict({ activeElement: link })];
}

// Disposed (the controller disconnected): it listens to nothing any more
{
    const { doc, win, guard } = world();
    const before = doc.listening() + win.listening();
    guard.dispose();
    doc.emit('turbo:click', {});
    facts.disposed = [before > 0, doc.listening() + win.listening(), guard.leaving];
}

process.stdout.write(JSON.stringify(facts));
