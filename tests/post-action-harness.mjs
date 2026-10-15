// Runs assets/post_action.js (docs/features/navigation GEST-15, decision 99: the one-tap actions of the browse pages)
// through the cases tests/PostActionScriptTest.php asks for and prints one JSON object {case: answer}.

import { ACCEPT, STREAM_TYPE, TOKEN_FIELD, bodyEntries, createPostAction, outcomeOf, spaceActivation } from '../assets/post_action.js';

const ORIGIN = 'https://msp.test';
const results = {};

async function run(name, fn) {
    try {
        results[name] = await fn();
    } catch (error) {
        results[name] = { threw: String(error && error.stack ? error.stack : error) };
    }
}

class FakeFormData {
    constructor() {
        this.entries = [];
    }

    append(name, value) {
        this.entries.push([name, value]);
    }
}

function env(answer) {
    const calls = { fetch: [], streams: [], visits: [], clears: 0, posts: 0, haptics: [], toasts: [] };

    return {
        calls,
        env: {
            FormData: FakeFormData,
            fetch: async (url, options) => {
                calls.fetch.push({ url, method: options.method, accept: options.headers.Accept, credentials: options.credentials, body: options.body.entries });

                if (answer instanceof Error) {
                    throw answer;
                }

                return {
                    status: answer.status,
                    ok: answer.status >= 200 && answer.status < 300,
                    redirected: answer.redirected === true,
                    url: answer.url || `${ORIGIN}/en/action-target`,
                    headers: { get: (name) => (name === 'Content-Type' ? answer.type || 'text/html; charset=UTF-8' : null) },
                    text: async () => answer.body || '',
                };
            },
            renderStream: (html) => calls.streams.push(html),
            visit: (url, options) => calls.visits.push({ url, action: options.action, shouldCacheSnapshot: options.shouldCacheSnapshot, response: options.response }),
            clearSnapshots: () => { calls.clears++; },
            notePost: () => { calls.posts++; },
            haptic: (name) => calls.haptics.push(name),
            toast: (message) => calls.toasts.push(message),
            currentUrl: () => `${ORIGIN}/en/puzzle?brand=x#top`,
        },
    };
}

await run('contract', () => ({ ACCEPT, STREAM_TYPE, TOKEN_FIELD }));

await run('outcomes', () => {
    const here = `${ORIGIN}/en/puzzle?brand=x`;

    return {
        refused: outcomeOf({ status: 422, ok: false, currentUrl: here }),
        serverError: outcomeOf({ status: 500, ok: false, currentUrl: here }),
        stream: outcomeOf({ status: 200, ok: true, contentType: 'text/vnd.turbo-stream.html; charset=UTF-8', currentUrl: here }),
        backHere: outcomeOf({ status: 200, ok: true, redirected: true, url: `${here}#list`, contentType: 'text/html', currentUrl: here }),
        elsewhere: outcomeOf({ status: 200, ok: true, redirected: true, url: `${ORIGIN}/en/compare`, contentType: 'text/html', currentUrl: here }),
        pageWithoutRedirect: outcomeOf({ status: 200, ok: true, redirected: false, contentType: 'text/html', currentUrl: here }),
    };
});

await run('body', () => ({
    linkFields: bodyEntries({ context: 'detail', collectionId: '' }, [], 'tok'),
    formFields: bodyEntries({}, [['country', 'cz'], ['back', '/en/puzzlers'], ['_token', 'from-a-form'], ['', 'x']], 'tok'),
    noToken: bodyEntries({ subject: 'p-1' }, [], ''),
}));

await run('stream answer', async () => {
    const { calls, env: e } = env({ status: 200, type: 'text/vnd.turbo-stream.html; charset=UTF-8', body: '<turbo-stream action="replace"></turbo-stream>' });
    const kind = await createPostAction(e).run({ url: '/en/wishlist/1/remove', fields: { context: 'detail' }, token: '', failedText: 'Nope' });

    return { kind, ...calls };
});

await run('redirect back here', async () => {
    const { calls, env: e } = env({ status: 200, redirected: true, url: `${ORIGIN}/en/puzzle?brand=x`, body: '<html>page</html>' });
    const kind = await createPostAction(e).run({ url: '/en/compare/add', fields: { subject: 'p-1', return: '/en/puzzle?brand=x' }, token: 'csrf-token', failedText: 'Nope' });

    return { kind, ...calls };
});

await run('redirect elsewhere', async () => {
    const { calls, env: e } = env({ status: 200, redirected: true, url: `${ORIGIN}/en/puzzlers?scope=cz`, body: '<html>players</html>' });
    const kind = await createPostAction(e).run({ url: '/en/puzzlers/my-country', formEntries: [['country', 'cz']], token: 'tok', failedText: 'Nope' });

    return { kind, visits: calls.visits, clears: calls.clears, posts: calls.posts };
});

await run('refused', async () => {
    const { calls, env: e } = env({ status: 422, body: '<html>refused</html>' });
    const kind = await createPostAction(e).run({ url: '/en/puzzlers/my-country', formEntries: [['country', 'xx']], token: 'tok', failedText: 'That did not work.' });

    return { kind, ...calls, fetch: calls.fetch.length };
});

await run('network failure', async () => {
    const { calls, env: e } = env(new TypeError('Failed to fetch'));
    const kind = await createPostAction(e).run({ url: '/en/x', fields: {}, token: '', failedText: 'That did not work.' });

    return { kind, haptics: calls.haptics, toasts: calls.toasts, clears: calls.clears };
});

await run('one request at a time', async () => {
    let release;
    const { calls, env: e } = env({ status: 200, type: 'text/vnd.turbo-stream.html', body: '' });
    const slowFetch = e.fetch;
    e.fetch = (...args) => new Promise((resolve) => { release = () => resolve(slowFetch(...args)); });
    const action = createPostAction(e);
    const first = action.run({ key: 'k', url: '/en/x', fields: {} });
    const second = await action.run({ key: 'k', url: '/en/x', fields: {} });
    release();
    const firstKind = await first;
    const third = action.run({ key: 'k', url: '/en/x', fields: {} });
    release();

    return { second, firstKind, third: await third, fetches: calls.fetch.length };
});

// Instant Back review F9: the links were buttons - Space taps them as it does a button (keydown keeps the page still,
// keyup taps); Enter stays the link's own; a modified, composed or other key is never taken
await run('space activates', () => ({
    keydown: spaceActivation({ type: 'keydown', key: ' ' }),
    keyup: spaceActivation({ type: 'keyup', key: ' ' }),
    oldSpacebar: spaceActivation({ type: 'keyup', key: 'Spacebar' }),
    enter: spaceActivation({ type: 'keydown', key: 'Enter' }),
    letter: spaceActivation({ type: 'keyup', key: 'a' }),
    withShift: spaceActivation({ type: 'keyup', key: ' ', shiftKey: true }),
    withCtrl: spaceActivation({ type: 'keydown', key: ' ', ctrlKey: true }),
    composing: spaceActivation({ type: 'keyup', key: ' ', isComposing: true }),
    keypress: spaceActivation({ type: 'keypress', key: ' ' }),
    nothing: spaceActivation(null),
}));

process.stdout.write(JSON.stringify(results));
