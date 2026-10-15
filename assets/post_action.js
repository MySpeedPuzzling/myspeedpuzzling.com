/**
 * One-tap actions of the browse pages (docs/features/navigation README §4.13 G9, requirement GEST-15, decision 99).
 *
 * A browse page Turbo may restore from its cache carries no POST form; its one-tap actions (compare add / remove, the
 * Players country nudge, the library actions of a puzzle card, the marketplace card's own actions) are links to the
 * confirmation view `post_action_confirm` (PostAction, src/Value/PostAction.php). With JavaScript the tap posts at once,
 * as the form did: the action's route with its fields and CSRF token (the same stateless double-submit as the form), then
 * - a Turbo Stream answer is applied (the library actions update their menus and badges in place);
 * - a redirect is followed as a Turbo visit rendering the answer fetch already has (a replace visit when it comes back
 *   to this very page - Turbo's own rule for a form answered with a redirect to the same location), caching no copy of
 *   the page left (as Turbo does for a POST form);
 * - any success clears Turbo's cache and the restore ledger (assets/instant_back.js: a page restored by Back must never
 *   show the state before the tap) and marks the document as having posted (its back/forward-cache copy reloads);
 * - a refusal (422) or a failure: the reject haptic (Android) and a toast with the link's failure text; nothing else
 *   changes - the link still opens the confirmation view.
 * While the request runs, the page shows it as it did for the form (QA-04 R03): progress('start') before the request -
 * Turbo's progress bar, shown at once (progressBarDelay 0) - and progress('end') once the answer is applied or refused.
 * A second tap on the same action meanwhile is ignored ('busy').
 *
 * Pure: everything comes in through `env` ({fetch, renderStream(html), visit(url, options), clearSnapshots(),
 * notePost(), haptic(name), toast(message), currentUrl(), progress(state)}) - tests/post-action-harness.mjs drives it
 * under node; the Stimulus controller assets/controllers/post_action_controller.js is the browser's wiring.
 */

export const STREAM_TYPE = 'text/vnd.turbo-stream.html';
export const ACCEPT = `${STREAM_TYPE}, text/html, application/xhtml+xml`;
export const TOKEN_FIELD = '_token';

function withoutHash(url, base) {
    try {
        const parsed = new URL(String(url), base);
        parsed.hash = '';

        return parsed.href;
    } catch (e) {
        return null;
    }
}

/**
 * What an answer means: {kind: 'stream'} | {kind: 'visit', url, action: 'replace' | 'advance'} | {kind: 'refresh'} |
 * {kind: 'refused'} (422) | {kind: 'failed'}
 */
export function outcomeOf({ status, ok, redirected, url, contentType, currentUrl }) {
    if (status === 422) {
        return { kind: 'refused' };
    }

    if (!ok) {
        return { kind: 'failed' };
    }

    if (typeof contentType === 'string' && contentType.includes(STREAM_TYPE)) {
        return { kind: 'stream' };
    }

    if (redirected && typeof url === 'string' && url !== '') {
        const target = withoutHash(url, currentUrl);
        const here = withoutHash(currentUrl, currentUrl);

        return { kind: 'visit', url, action: target !== null && target === here ? 'replace' : 'advance' };
    }

    // A page answered without a redirect: this page is shown again from the server
    return { kind: 'refresh' };
}

/**
 * The one-tap links were buttons (Instant Back review F9): they say role="button" and Space activates them as it does a
 * button - its keydown only stops the page from scrolling ('prevent'), its keyup taps ('activate'); Enter is the link's
 * own. A Space with a modifier, held down (repeat) or in an editable field is left alone (null).
 */
export function spaceActivation(event) {
    if (!event || (event.key !== ' ' && event.key !== 'Spacebar')) {
        return null;
    }

    if (event.ctrlKey || event.metaKey || event.altKey || event.shiftKey || event.isComposing) {
        return null;
    }

    if (event.type === 'keydown') {
        return 'prevent';
    }

    return event.type === 'keyup' ? 'activate' : null;
}

/** The form body: the link's fields, a GET form's own fields (the nudge's country), the CSRF token */
export function bodyEntries(fields = {}, formEntries = [], token = '') {
    const entries = [];

    Object.entries(fields || {}).forEach(([name, value]) => {
        if (typeof value === 'string' || typeof value === 'number') {
            entries.push([name, String(value)]);
        }
    });

    (formEntries || []).forEach(([name, value]) => {
        if (typeof name === 'string' && name !== '' && name !== TOKEN_FIELD && typeof value === 'string') {
            entries.push([name, value]);
        }
    });

    if (typeof token === 'string' && token !== '') {
        entries.push([TOKEN_FIELD, token]);
    }

    return entries;
}

export function createPostAction(env) {
    const pending = new Set();

    return {
        /**
         * Posts one action. {key (one request per key at a time), url, fields, formEntries, token, failedText}
         * Resolves to the outcome's kind ('busy' while the same key is still on its way).
         */
        async run({ key = null, url, fields, formEntries, token, failedText }) {
            const id = key ?? url;

            if (pending.has(id)) {
                return 'busy';
            }

            pending.add(id);

            let outcome;

            if (typeof env.progress === 'function') {
                env.progress('start');
            }

            try {
                const body = new env.FormData();
                bodyEntries(fields, formEntries, token).forEach(([name, value]) => body.append(name, value));

                const response = await env.fetch(url, {
                    method: 'POST',
                    body,
                    credentials: 'same-origin',
                    headers: { Accept: ACCEPT },
                });

                outcome = outcomeOf({
                    status: response.status,
                    ok: response.ok,
                    redirected: response.redirected,
                    url: response.url,
                    contentType: response.headers && typeof response.headers.get === 'function' ? response.headers.get('Content-Type') : null,
                    currentUrl: env.currentUrl(),
                });

                if (outcome.kind === 'stream') {
                    env.clearSnapshots();
                    env.notePost();
                    env.renderStream(await response.text());
                } else if (outcome.kind === 'visit' || outcome.kind === 'refresh') {
                    env.clearSnapshots();
                    env.notePost();
                    // The page fetch already followed the redirect: Turbo renders that answer, no second request. Like
                    // a Turbo POST form (FormSubmission: shouldCacheSnapshot = isSafe) the page left keeps no copy - a
                    // copy from before the tap would come back on Back
                    const responseHTML = await response.text();
                    env.visit(outcome.kind === 'visit' ? outcome.url : env.currentUrl(), {
                        action: outcome.kind === 'visit' ? outcome.action : 'replace',
                        shouldCacheSnapshot: false,
                        response: { statusCode: response.status, redirected: outcome.kind === 'visit', responseHTML },
                    });
                }
            } catch (error) {
                outcome = { kind: 'failed' };
            } finally {
                pending.delete(id);

                if (typeof env.progress === 'function') {
                    env.progress('end');
                }
            }

            if (outcome.kind === 'refused' || outcome.kind === 'failed') {
                env.haptic('reject');

                if (typeof failedText === 'string' && failedText !== '') {
                    env.toast(failedText);
                }
            }

            return outcome.kind;
        },
    };
}
