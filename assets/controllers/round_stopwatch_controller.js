import { Controller } from '@hotwired/stimulus';

const POLL_FALLBACK_MS = 3000;

export default class extends Controller {
    static targets = ['hours', 'minutes', 'seconds', 'status'];

    static values = {
        status: String,
        startedAt: String,
        stoppedAt: String,
        minutesLimit: Number,
        serverNow: String,
        mercureUrl: String,
        topic: String,
        stateUrl: String,
    };

    connect() {
        this._rafId = null;
        this._startTimestamp = null;
        this._stopTimestamp = null;
        this._pollId = null;
        this._eventSource = null;

        // Initial offset from server-rendered serverNow. Not round-trip
        // compensated (we have no t0 for the page request) — _refetchAndApply
        // will refine it within the first SSE open or poll cycle.
        this._serverOffset = this.serverNowValue
            ? Date.now() - new Date(this.serverNowValue).getTime()
            : 0;

        this._applyState({
            status: this.statusValue || null,
            startedAt: this.startedAtValue || null,
            stoppedAt: this.stoppedAtValue || null,
            minutesLimit: this.minutesLimitValue,
        });

        this._connectMercure();
        this._startPolling();

        this._visibilityHandler = () => {
            if (document.visibilityState === 'visible') {
                this._refetchAndApply();
            }
        };
        document.addEventListener('visibilitychange', this._visibilityHandler);
    }

    disconnect() {
        this._stopRaf();
        this._stopPolling();
        if (this._eventSource) {
            this._eventSource.close();
            this._eventSource = null;
        }
        if (this._visibilityHandler) {
            document.removeEventListener('visibilitychange', this._visibilityHandler);
            this._visibilityHandler = null;
        }
    }

    _connectMercure() {
        if (!this.mercureUrlValue || !this.topicValue) return;

        const url = new URL(this.mercureUrlValue);
        url.searchParams.append('topic', this.topicValue);

        this._eventSource = new EventSource(url);

        this._eventSource.addEventListener('open', () => {
            // SSE is healthy — stop the polling fallback and refetch once to
            // recalibrate the clock offset and catch up on anything missed
            // while the connection was down.
            this._stopPolling();
            this._refetchAndApply();
        });

        this._eventSource.addEventListener('message', (event) => {
            // SSE payload carries the full state shape — apply directly to
            // avoid a refetch round-trip. Offset stays from the most recent
            // refetch (open / visibility / poll).
            try {
                const data = JSON.parse(event.data);
                this._applyState(data);
            } catch {
                // Malformed message — fall back to authoritative refetch.
                this._refetchAndApply();
            }
        });

        this._eventSource.addEventListener('error', () => {
            // Connection failed (e.g. 401) or dropped. Browser auto-retries
            // for transient errors; polling kicks on so state still syncs.
            this._startPolling();
        });
    }

    _startPolling() {
        if (this._pollId !== null) return;
        this._pollId = setInterval(() => {
            if (document.visibilityState === 'visible') {
                this._refetchAndApply();
            }
        }, POLL_FALLBACK_MS);
    }

    _stopPolling() {
        if (this._pollId !== null) {
            clearInterval(this._pollId);
            this._pollId = null;
        }
    }

    async _refetchAndApply() {
        if (!this.stateUrlValue) return;
        const t0 = Date.now();
        try {
            const response = await fetch(this.stateUrlValue, { cache: 'no-store' });
            const t1 = Date.now();
            if (!response.ok) return;
            const data = await response.json();
            // NTP-style midpoint compensation: assume the server generated
            // serverNow halfway through the round trip. Cuts offset error
            // roughly in half versus naive `Date.now() - serverNow`.
            if (data.serverNow) {
                const midpoint = t0 + (t1 - t0) / 2;
                this._serverOffset = midpoint - new Date(data.serverNow).getTime();
            }
            this._applyState(data);
        } catch {
            // Ignore — next signal (SSE message, visibility, poll) retries.
        }
    }

    _applyState({ status, startedAt, stoppedAt, minutesLimit }) {
        if (typeof minutesLimit === 'number') {
            this.minutesLimitValue = minutesLimit;
        }

        this._stopRaf();

        if (status === 'running' && startedAt) {
            this._startTimestamp = new Date(startedAt).getTime() + this._serverOffset;
            this._stopTimestamp = null;
            this._updateStatus('running');
            this._startRaf();
        } else if (status === 'stopped' && startedAt) {
            this._startTimestamp = new Date(startedAt).getTime() + this._serverOffset;
            // Legacy rows pre-dating stoppedAt fall back to "now" — won't keep
            // growing because RAF isn't running.
            this._stopTimestamp = stoppedAt
                ? new Date(stoppedAt).getTime() + this._serverOffset
                : Date.now();
            this._renderTime(this._stopTimestamp - this._startTimestamp);
            this._updateStatus('stopped');
        } else {
            this._startTimestamp = null;
            this._stopTimestamp = null;
            this._renderTime(0);
            this._updateStatus('');
        }
    }

    _startRaf() {
        this._stopRaf();
        const tick = () => {
            if (this._startTimestamp === null) return;
            let elapsed = Date.now() - this._startTimestamp;
            const limitMs = this.minutesLimitValue * 60 * 1000;

            if (elapsed >= limitMs) {
                elapsed = limitMs;
                this._renderTime(elapsed);
                this._updateStatus('times_up');
                this._stopRaf();
                return;
            }

            this._renderTime(elapsed);
            this._rafId = requestAnimationFrame(tick);
        };
        this._rafId = requestAnimationFrame(tick);
    }

    _stopRaf() {
        if (this._rafId !== null) {
            cancelAnimationFrame(this._rafId);
            this._rafId = null;
        }
    }

    _renderTime(ms) {
        const totalSeconds = Math.max(0, Math.floor(ms / 1000));
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;

        this.hoursTarget.textContent = this._pad(hours);
        this.minutesTarget.textContent = this._pad(minutes);
        this.secondsTarget.textContent = this._pad(seconds);
    }

    _pad(n) {
        return n < 10 ? '0' + n : String(n);
    }

    _updateStatus(status) {
        if (!this.hasStatusTarget) return;

        const labels = {
            '': this.statusTarget.dataset.labelNotStarted ?? 'Not started',
            'running': this.statusTarget.dataset.labelRunning ?? 'Running',
            'stopped': this.statusTarget.dataset.labelStopped ?? 'Stopped',
            'times_up': this.statusTarget.dataset.labelTimesUp ?? "Time's up!",
        };

        this.statusTarget.textContent = labels[status] ?? labels[''];

        this.statusTarget.classList.remove('text-muted', 'text-success', 'text-warning', 'text-danger');
        switch (status) {
            case 'running':
                this.statusTarget.classList.add('text-success');
                break;
            case 'stopped':
                this.statusTarget.classList.add('text-warning');
                break;
            case 'times_up':
                this.statusTarget.classList.add('text-danger');
                break;
            default:
                this.statusTarget.classList.add('text-muted');
        }
    }
}
