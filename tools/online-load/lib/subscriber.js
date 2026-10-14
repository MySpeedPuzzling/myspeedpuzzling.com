/**
 * One participant phone's live stream (README §5.3; assets/official_results_events.js): the round's public stopwatch
 * topic through Mercure, with the subscriber token the participant state carries. Like the phone it fetches the state
 * on every (re)connect - that is where the token comes from - and reconnects after a drop with a growing pause.
 *
 * Measured: the time to the stream's headers (`sse_connect`) and every update's delay from the moment the server read
 * the state it carries (`asOfMs`) to its arrival (`sse_event_latency`, on the server's clock: the state answer is the
 * clock sample - a run from another machine has its clock offset taken out).
 */

import http from 'k6/http';
import sse from 'k6/x/sse';
import { sleep } from 'k6';
import * as metrics from './metrics.js';

// official_results_events.js: 1 s, 2 s, 4 s … at most 30 s between attempts
const RECONNECT_BACKOFF_MS = [1000, 2000, 4000, 8000, 15000, 30000];

export function subscribe({ index, token, config, endAt }) {
    const headers = {
        'User-Agent': `msp-online-load/1 (k6; participant ${index + 1}; stream)`,
        Accept: 'application/json',
    };

    if (config.spoofIps) {
        headers['X-Forwarded-For'] = `198.18.${Math.floor(index / 250)}.${(index % 250) + 1}`;
    }

    let failures = 0;
    let first = true;

    while (Date.now() < endAt) {
        const phase = first ? 'join' : 'waiting';
        const w0 = Date.now();
        const res = http.get(`${config.baseUrl}/api/compete/${token}/state`, {
            headers,
            tags: { endpoint: 'state', phase },
            timeout: '15s',
            redirects: 0,
        });
        const w1 = Date.now();
        first = false;

        metrics.reqDuration.add(res.timings.duration, { endpoint: 'state', phase });
        metrics.reqCount.add(1, { endpoint: 'state', sc: metrics.statusClass(res.status) });

        let state = null;

        try {
            state = res.status === 200 ? JSON.parse(res.body) : null;
        } catch (e) {
            state = null;
        }

        const subscription = state?.mercure ?? null;

        if (!subscription || typeof subscription.url !== 'string' || !Array.isArray(subscription.topics)) {
            failures += 1;
            sleep(RECONNECT_BACKOFF_MS[Math.min(failures, RECONNECT_BACKOFF_MS.length) - 1] / 1000);
            continue;
        }

        // The state answer is this stream's clock sample: server time = local + offset
        const offsetMs = Number.isFinite(state.serverNowMs) ? state.serverNowMs - (w0 + (w1 - w0) / 2) : 0;
        const query = subscription.topics.map((topic) => `topic=${encodeURIComponent(topic)}`).join('&');
        const streamHeaders = { ...headers, Accept: 'text/event-stream' };

        if (typeof subscription.token === 'string' && subscription.token !== '') {
            streamHeaders.Authorization = `Bearer ${subscription.token}`;
        }

        const openedAt = Date.now();
        let opened = false;

        const response = sse.open(`${subscription.url}?${query}`, { headers: streamHeaders, tags: { endpoint: 'sse' } }, (client) => {
            client.on('open', () => {
                opened = true;
                metrics.sseConnects.add(1);
                metrics.sseConnect.add(Date.now() - openedAt);
            });

            client.on('event', (event) => {
                if (event.data) {
                    metrics.sseEvents.add(1);

                    try {
                        const payload = JSON.parse(event.data);

                        if (Number.isFinite(payload.asOfMs)) {
                            metrics.sseEventLatency.add(Date.now() + offsetMs - payload.asOfMs);
                        }
                    } catch (e) {
                        // Not a state - counted, not timed
                    }
                }

                // Mercure's keep-alive comments are the only ticks a quiet stream gets
                if (Date.now() >= endAt) {
                    client.close();
                }
            });

            client.on('error', () => {
                metrics.sseErrors.add(1);
            });
        });

        if (response && response.status && response.status !== 200) {
            metrics.sseErrors.add(1);
        }

        if (Date.now() >= endAt) {
            break;
        }

        failures = opened && response?.status === 200 ? 1 : failures + 1;
        sleep(RECONNECT_BACKOFF_MS[Math.min(failures, RECONNECT_BACKOFF_MS.length) - 1] / 1000);
    }
}
