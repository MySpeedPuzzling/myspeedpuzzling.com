// Runs the "Get ready" checklist's remembered collapse (assets/get_ready_collapse.js) for
// tests/GetReadyCollapseScriptTest.php: a map as localStorage, one that throws, and none. Prints the facts as JSON.
//
// By hand: node tests/get-ready-collapse-harness.mjs

import { isCollapsed, rememberCollapsed, startsOpen, STORAGE_PREFIX } from '../assets/get_ready_collapse.js';

function memory() {
    const items = new Map();

    return {
        items,
        getItem: (key) => (items.has(key) ? items.get(key) : null),
        setItem: (key, value) => { items.set(key, String(value)); },
        removeItem: (key) => { items.delete(key); },
    };
}

const throwing = {
    getItem() { throw new Error('SecurityError'); },
    setItem() { throw new Error('QuotaExceededError'); },
    removeItem() { throw new Error('SecurityError'); },
};

const facts = {};

{
    const storage = memory();
    facts.fresh = [isCollapsed(storage, 'a'), startsOpen(storage, 'a', false)];
    rememberCollapsed(storage, 'a', true);
    facts.collapsed = [isCollapsed(storage, 'a'), startsOpen(storage, 'a', false), isCollapsed(storage, 'b'), [...storage.items.keys()]];
    facts.forced = startsOpen(storage, 'a', true);
    rememberCollapsed(storage, 'a', false);
    facts.reopened = [isCollapsed(storage, 'a'), storage.items.size];
}

facts.throwing = (() => {
    try {
        rememberCollapsed(throwing, 'a', true);

        return [isCollapsed(throwing, 'a'), startsOpen(throwing, 'a', false)];
    } catch {
        return 'threw';
    }
})();

facts.missing = [isCollapsed(null, 'a'), startsOpen(undefined, 'a', false)];
facts.prefix = STORAGE_PREFIX;

process.stdout.write(JSON.stringify(facts));
