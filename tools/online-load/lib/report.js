/**
 * The run's markdown report from k6's end-of-test summary (handleSummary): latency per endpoint (p50 / p95 / p99),
 * answers by status, the API's error codes, the start and the finish wave, uploads per store with the failover
 * timeline, the live stream. Numbers only - README.md says how to read them.
 */

import { ENDPOINTS, ERROR_CODES, PHASES, REFUSAL_REASONS, STATUS_CLASSES, STORES } from './metrics.js';

function metric(data, name) {
    return data.metrics[name]?.values ?? null;
}

function count(data, name) {
    return metric(data, name)?.count ?? 0;
}

function ms(value) {
    if (!Number.isFinite(value)) {
        return '-';
    }

    return Math.abs(value) >= 10000 ? `${(value / 1000).toFixed(1)} s` : `${Math.round(value)} ms`;
}

function at(epochMs) {
    return Number.isFinite(epochMs) && epochMs > 0 ? new Date(epochMs).toISOString().replace('.000Z', 'Z') : '-';
}

function table(header, rows) {
    const lines = [`| ${header.join(' | ')} |`, `|${header.map(() => '---').join('|')}|`];

    for (const row of rows) {
        lines.push(`| ${row.join(' | ')} |`);
    }

    return lines.join('\n');
}

function trendRow(values) {
    return values === null ? ['-', '-', '-', '-'] : [ms(values.med), ms(values['p(95)']), ms(values['p(99)']), ms(values.max)];
}

export function buildReport(data, config) {
    const seconds = Math.max(1, (data.state?.testRunDurationMs ?? 0) / 1000);
    const lines = [];

    lines.push(`# Online competitions load test - ${config.profile}, ${config.participants} participants`);
    lines.push('');
    lines.push(table(['', ''], [
        ['Target', `\`${config.baseUrl}\``],
        ['Participants', `${config.participants} (links ${config.offset + 1}-${config.offset + config.participants})${config.sse ? ', each with its live stream' : ', no live streams'}${config.spoofIps ? ', one client IP each (X-Forwarded-For)' : ''}`],
        ['Run', `${(seconds / 60).toFixed(1)} min, joined over ${config.joinSpreadMs / 1000} s; started ${at(config.startedAtMs)}`],
        ['Expected start', config.startAtMs ? at(config.startAtMs) : 'from the state (the organiser schedules it)'],
        ['Finishes', `${Math.round(config.finishShare * 100)} % tap Finished ${config.profile === 'finish_wave' ? `within ${config.finishWindowMs / 1000} s from ${config.finishAfterMs / 1000} s after GO` : `from ${config.finishAfterMs / 1000} s after GO to a minute before the end`}, ${Math.round(config.undoShare * 100)} % of them take it back`],
    ]));
    lines.push('');

    // Throughput and latency per endpoint
    const totals = ENDPOINTS.map((endpoint) => STATUS_CLASSES.reduce((sum, statusClass) => sum + count(data, `req_count{endpoint:${endpoint},sc:${statusClass}}`), 0));
    const allRequests = totals.reduce((a, b) => a + b, 0);

    lines.push(`## Requests: ${allRequests} in ${seconds.toFixed(0)} s = ${(allRequests / seconds).toFixed(1)} req/s`);
    lines.push('');
    lines.push('Latency = k6 `http_req_duration`: from sending the request to the last byte of the answer.');
    lines.push('');
    lines.push(table(['Endpoint', 'Requests', 'req/s', 'p50', 'p95', 'p99', 'max'], ENDPOINTS.map((endpoint, i) => [
        endpoint,
        String(totals[i]),
        (totals[i] / seconds).toFixed(2),
        ...trendRow(metric(data, `req_duration{endpoint:${endpoint}}`)),
    ])));
    lines.push('');

    lines.push('### Answers by status');
    lines.push('');
    lines.push(table(['Endpoint', ...STATUS_CLASSES.map((statusClass) => (statusClass === 'net' ? 'no answer' : statusClass))], ENDPOINTS.map((endpoint) => [
        endpoint,
        ...STATUS_CLASSES.map((statusClass) => String(count(data, `req_count{endpoint:${endpoint},sc:${statusClass}}`))),
    ])));
    lines.push('');

    const errors = ERROR_CODES.map((code) => [code, count(data, `api_error{code:${code}}`)]).filter(([, n]) => n > 0);
    lines.push(`### API error codes: ${errors.length === 0 ? 'none' : errors.map(([code, n]) => `\`${code}\` ${n}`).join(', ')}`);
    lines.push('');
    const refusals = REFUSAL_REASONS.map((reason) => [reason, count(data, `event_refused{reason:${reason}}`)]).filter(([, n]) => n > 0);
    lines.push(`Events acknowledged: ${count(data, 'events_acked')}; refused: ${refusals.length === 0 ? 'none' : refusals.map(([reason, n]) => `\`${reason}\` ${n}`).join(', ')}.`);
    lines.push('');

    lines.push('### By phase (every endpoint)');
    lines.push('');
    lines.push(table(['Phase', 'Requests', 'p50', 'p95', 'p99', 'max'], PHASES.map((phase) => {
        const values = metric(data, `req_duration{phase:${phase}}`);

        return [phase, String(values?.count ?? 0), ...trendRow(values)];
    })));
    lines.push('');
    lines.push('`start` = within ±3 s of GO (state fetches, clock samples, start.seen, start photos); `finish` = the finish wave.');
    lines.push('');

    // The start
    const learned = metric(data, 'start_learned_before');
    const late = metric(data, 'start_seen_late');
    lines.push('## The start');
    lines.push('');
    lines.push(table(['', ''], [
        ['Phones that showed GO', `${count(data, 'start_seen')} of ${config.participants}`],
        ['GO shown after the start by (their synced clock)', late ? `p50 ${ms(late.med)}, p95 ${ms(late['p(95)'])}, max ${ms(late.max)}` : '-'],
        ['Start known before GO', learned ? `p50 ${ms(learned.med)}, min ${ms(learned.min)} (from a heartbeat or a fetch - a phone's push arrives in its stream VU)` : '-'],
        ['Clock sync, best round trip', metric(data, 'clock_best_rtt') ? `p50 ${ms(metric(data, 'clock_best_rtt').med)}, p95 ${ms(metric(data, 'clock_best_rtt')['p(95)'])}` : '-'],
    ]));
    lines.push('');

    // Finishes
    const evidence = metric(data, 'finish_evidence');
    lines.push('## Finishes and time-up');
    lines.push('');
    lines.push(table(['', ''], [
        ['Finished taps', String(count(data, 'finishes'))],
        ['Tap → photo + burst uploaded and reported', evidence ? `p50 ${ms(evidence.med)}, p95 ${ms(evidence['p(95)'])}, max ${ms(evidence.max)} (${evidence.count} taps complete)` : '-'],
        ["Time's up while solving (end photo + pieces)", String(count(data, 'timeups'))],
    ]));
    lines.push('');

    // Uploads and the failover timeline
    lines.push('## Evidence uploads');
    lines.push('');
    lines.push(table(['Store', 'Presigned', 'Uploaded', 'MB', 'Failed PUTs'], STORES.map((store) => [
        store,
        String(count(data, `presigns{store:${store}}`)),
        String(count(data, `uploads{store:${store}}`)),
        (count(data, `upload_bytes{store:${store}}`) / 1048576).toFixed(1),
        String(count(data, `put_failures{store:${store}}`)),
    ])));
    lines.push('');

    const firstFailure = metric(data, 'first_put_failure_at')?.min ?? null;
    const firstSecondary = metric(data, 'first_secondary_upload_at')?.min ?? null;
    lines.push(`PUTs of an item tried again after a failure: ${count(data, 'put_retries')}. Left on the phones at the end: ${count(data, 'outbox_left_media')} photos / bursts, ${count(data, 'outbox_left_events')} events.`);
    lines.push('');

    if (firstFailure !== null || firstSecondary !== null) {
        lines.push(table(['Failover timeline', ''], [
            ['First failed PUT', at(firstFailure)],
            ['First upload landed on the second store', at(firstSecondary)],
            ['Failure → first upload on the second store', firstFailure !== null && firstSecondary !== null ? `${((firstSecondary - firstFailure) / 1000).toFixed(1)} s` : '-'],
            ['Last upload on the primary (latest phone)', at(metric(data, 'last_primary_upload_at')?.max ?? null)],
        ]));
        lines.push('');
    }

    // The live stream
    if (config.sse) {
        const connect = metric(data, 'sse_connect');
        const latency = metric(data, 'sse_event_latency');
        lines.push('## Live stream (Mercure)');
        lines.push('');
        lines.push(table(['', 'p50', 'p95', 'p99', 'max'], [
            [`Connect to headers (${count(data, 'sse_connects')} connects)`, ...trendRow(connect)],
            [`Update latency, asOfMs → arrival (${count(data, 'sse_events')} updates)`, ...trendRow(latency)],
        ]));
        lines.push('');
        lines.push(`Stream errors: ${count(data, 'sse_errors')}.`);
        lines.push('');
    }

    return lines.join('\n');
}
