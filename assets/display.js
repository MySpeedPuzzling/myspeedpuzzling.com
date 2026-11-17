// The competition display page (templates/competition_display/display.html.twig, competition toolkit D4) - the
// Encore entry `display`: no Stimulus, no Turbo, nothing of the site. Reads the inline design + first state, draws
// them with assets/displays/render.js and keeps them live with assets/displays/runtime.js. This file is the thin DOM
// adapter: fetch, the viewport, visibility, wake lock, the cursor and the full-screen button of a screen.

import './styles/display.scss';
import { createRenderer } from './displays/render.js';
import { DisplayRuntime } from './displays/runtime.js';
import { monotonicNow } from './round_stopwatch_clock.js';

const FETCH_TIMEOUT_MS = 10000;
const CURSOR_IDLE_MS = 3000;

function readJson(root, name) {
    try {
        return JSON.parse(root.getAttribute(name) ?? 'null');
    } catch {
        return null;
    }
}

/**
 * Retry-After in seconds (the only form our limiter sends) - null when missing or unreadable (the runtime waits its own
 * default then)
 */
function retryAfterMs(header) {
    const seconds = Number.parseInt(header ?? '', 10);

    return Number.isFinite(seconds) && seconds >= 0 ? seconds * 1000 : null;
}

async function getJson(url) {
    const controller = typeof AbortController === 'function' ? new AbortController() : null;
    const timer = controller ? setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS) : null;
    // The clock's samples: on its monotonic clock (round_stopwatch_clock.js, D2)
    const sentAtMs = monotonicNow();

    try {
        const response = await fetch(url, {
            cache: 'no-store',
            credentials: 'omit',
            headers: { Accept: 'application/json' },
            signal: controller?.signal,
        });
        const receivedAtMs = monotonicNow();

        if (response.status === 404) {
            return { status: 'gone' };
        }

        // Too many requests on this link from this address (the display_public limit): wait as long as asked
        if (response.status === 429) {
            return { status: 'busy', retryAfterMs: retryAfterMs(response.headers.get('Retry-After')) };
        }

        if (!response.ok) {
            return { status: 'error' };
        }

        return { status: 'ok', body: await response.json(), sentAtMs, receivedAtMs };
    } catch {
        return { status: 'error' };
    } finally {
        if (timer !== null) {
            clearTimeout(timer);
        }
    }
}

function start() {
    const root = document.getElementById('msp-display');

    if (root === null) {
        return;
    }

    const kind = root.dataset.kind === 'overlay' ? 'overlay' : 'screen';
    const state = readJson(root, 'data-state') ?? {};
    const design = readJson(root, 'data-design') ?? { schema: 1 };
    const stateUrl = root.dataset.stateUrl;
    const designUrl = root.dataset.designUrl;

    const renderer = createRenderer({ document, root, design, competitionId: root.dataset.competitionId ?? '', kind });
    const fit = () => renderer.fit(window.innerWidth, window.innerHeight);
    fit();
    window.addEventListener('resize', fit);

    const runtime = new DisplayRuntime({
        state,
        design,
        renderer,
        fetchState: async () => {
            const answer = await getJson(stateUrl);

            return answer.status === 'ok' ? { status: 'ok', state: answer.body, sentAtMs: answer.sentAtMs, receivedAtMs: answer.receivedAtMs } : answer;
        },
        fetchDesign: async () => {
            const answer = await getJson(designUrl);

            return answer.status === 'ok' ? { status: 'ok', designVersion: answer.body?.designVersion, design: answer.body?.design } : answer;
        },
        reload: () => window.location.reload(),
        requestWakeLock: navigator.wakeLock ? () => navigator.wakeLock.request('screen') : null,
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            runtime.onVisible();
        } else {
            runtime.clock.hidden();
        }
    });

    runtime.start();

    // A screen: the cursor hides after 3 s without movement, a full-screen button shows while the mouse moves. An
    // overlay never shows any UI.
    if (kind === 'screen') {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'msp-display__fullscreen';
        button.textContent = root.dataset.fullscreenLabel ?? 'Full screen';
        button.addEventListener('click', () => {
            const element = document.documentElement;

            if (document.fullscreenElement) {
                document.exitFullscreen?.();
            } else if (element.requestFullscreen) {
                element.requestFullscreen().catch(() => {});
            }
        });
        document.body.appendChild(button);

        let idleTimer = null;
        const idle = () => {
            idleTimer = null;
            document.body.classList.remove('msp-display-body--pointer');
        };
        document.addEventListener('mousemove', () => {
            document.body.classList.add('msp-display-body--pointer');

            if (idleTimer !== null) {
                clearTimeout(idleTimer);
            }

            idleTimer = setTimeout(idle, CURSOR_IDLE_MS);
        });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
} else {
    start();
}
