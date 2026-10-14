/**
 * Every metric the load script records (created in the init context, as k6 requires). The report (report.js) reads
 * them from the summary; the per-endpoint and per-status splits are sub-metrics that thresholds() declares.
 */

import { Counter, Trend } from 'k6/metrics';

// The participant API and what the phone fetches besides it; `put` = the upload to object storage
export const ENDPOINTS = ['page', 'time', 'state', 'heartbeat', 'events', 'media', 'put'];
// Answers by status: 2xx, the refusals the API names (contract §6), other 4xx, 5xx, and no answer at all (`net`)
export const STATUS_CLASSES = ['2xx', '404', '409', '413', '422', '429', '4xx', '503', '5xx', 'net'];
// The API's error codes (contract §6) - anything else counts as `other`
export const ERROR_CODES = [
    'unknown_link', 'invalid_json', 'invalid_fields', 'request_too_large', 'round_not_online', 'not_entered',
    'entry_confirmed', 'media_too_large', 'media_kind_unknown', 'media_quota_exceeded', 'too_many_pending_media',
    'too_many_events', 'rate_limited', 'retry', 'other',
];
export const REFUSAL_REASONS = ['unknown_type', 'invalid_fields', 'wrong_round', 'not_entered', 'too_many_events', 'other'];
export const STORES = ['primary', 'secondary'];
// Requests near the start (±START_WINDOW_MS around GO) and in the finish wave
export const PHASES = ['join', 'waiting', 'start', 'running', 'finish', 'ended'];

export const reqDuration = new Trend('req_duration', true);
export const reqCount = new Counter('req_count');
export const apiError = new Counter('api_error');
export const eventRefused = new Counter('event_refused');
export const eventsAcked = new Counter('events_acked');

export const uploads = new Counter('uploads');
export const uploadBytes = new Counter('upload_bytes');
export const putFailures = new Counter('put_failures');
// A PUT of an item that failed before (the phone's outbox trying again)
export const putRetries = new Counter('put_retries');
// Presigned uploads by the store the server picked
export const presigns = new Counter('presigns');
// When each phone's first upload failed, and when its first upload landed on the second store (epoch ms - the
// summary's min is the first of all phones): the failover drill's timeline
export const firstPutFailureAt = new Trend('first_put_failure_at');
export const firstSecondaryUploadAt = new Trend('first_secondary_upload_at');
export const lastPrimaryUploadAt = new Trend('last_primary_upload_at');
// What a phone still held when the run ended
export const outboxLeftMedia = new Counter('outbox_left_media');
export const outboxLeftEvents = new Counter('outbox_left_events');

// The start: how long before GO the phone knew the start, and how late it showed GO (both on its synced clock)
export const startLearnedBefore = new Trend('start_learned_before', true);
export const startSeenLate = new Trend('start_seen_late', true);
export const startSeen = new Counter('start_seen');
// A finish burst from the tap to its media.uploaded acknowledged
export const finishEvidence = new Trend('finish_evidence', true);
export const finishes = new Counter('finishes');
export const timeups = new Counter('timeups');
// The phone's clock sync: the best round trip of a sync (its error bound is half of it)
export const clockBestRtt = new Trend('clock_best_rtt', true);

export const sseConnect = new Trend('sse_connect', true);
export const sseEventLatency = new Trend('sse_event_latency', true);
export const sseEvents = new Counter('sse_events');
export const sseErrors = new Counter('sse_errors');
export const sseConnects = new Counter('sse_connects');

/**
 * The sub-metrics the report needs - k6 puts a tag split into the summary only when a threshold names it. The
 * expressions never fail a run.
 */
export function thresholds() {
    const always = ['max>=0'];
    const result = {};

    for (const endpoint of ENDPOINTS) {
        result[`req_duration{endpoint:${endpoint}}`] = always;

        for (const statusClass of STATUS_CLASSES) {
            result[`req_count{endpoint:${endpoint},sc:${statusClass}}`] = ['count>=0'];
        }
    }

    for (const phase of PHASES) {
        result[`req_duration{phase:${phase}}`] = always;
    }

    for (const code of ERROR_CODES) {
        result[`api_error{code:${code}}`] = ['count>=0'];
    }

    for (const reason of REFUSAL_REASONS) {
        result[`event_refused{reason:${reason}}`] = ['count>=0'];
    }

    for (const store of STORES) {
        result[`uploads{store:${store}}`] = ['count>=0'];
        result[`upload_bytes{store:${store}}`] = ['count>=0'];
        result[`put_failures{store:${store}}`] = ['count>=0'];
        result[`presigns{store:${store}}`] = ['count>=0'];
    }

    return result;
}

export function statusClass(status) {
    if (!status) {
        return 'net';
    }

    if (status >= 200 && status < 300) {
        return '2xx';
    }

    const named = String(status);

    if (STATUS_CLASSES.includes(named)) {
        return named;
    }

    return status >= 500 ? '5xx' : '4xx';
}
