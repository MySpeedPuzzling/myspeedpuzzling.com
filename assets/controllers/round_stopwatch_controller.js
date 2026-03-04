import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['hours', 'minutes', 'seconds', 'status'];

    static values = {
        status: String,
        startedAt: String,
        minutesLimit: Number,
        serverNow: String,
        mercureUrl: String,
        topic: String,
    };

    connect() {
        this._serverOffset = this._calculateServerOffset();
        this._rafId = null;
        this._startTimestamp = null;
        this._stopped = false;

        if (this.statusValue === 'running' && this.startedAtValue) {
            this._startTimestamp = new Date(this.startedAtValue).getTime() + this._serverOffset;
            this._startRaf();
        } else if (this.statusValue === 'stopped' && this.startedAtValue) {
            this._startTimestamp = new Date(this.startedAtValue).getTime() + this._serverOffset;
            this._stopped = true;
            this._renderTime(Date.now() - this._startTimestamp);
        } else {
            this._renderTime(0);
        }

        this._connectMercure();
    }

    disconnect() {
        this._stopRaf();
        if (this._eventSource) {
            this._eventSource.close();
            this._eventSource = null;
        }
    }

    _connectMercure() {
        if (!this.mercureUrlValue || !this.topicValue) return;

        const url = new URL(this.mercureUrlValue);
        url.searchParams.append('topic', this.topicValue);

        this._eventSource = new EventSource(url);

        this._eventSource.addEventListener('message', (event) => {
            try {
                const data = JSON.parse(event.data);
                this._handleMercureMessage(data);
            } catch { /* ignore non-JSON */ }
        });
    }

    _handleMercureMessage(data) {
        switch (data.action) {
            case 'start':
                this._stopped = false;
                this._startTimestamp = new Date(data.startedAt).getTime() + this._serverOffset;
                if (data.minutesLimit) {
                    this.minutesLimitValue = data.minutesLimit;
                }
                this._updateStatus('running');
                this._startRaf();
                break;

            case 'stop':
                this._stopped = true;
                this._stopRaf();
                this._updateStatus('stopped');
                break;

            case 'reset':
                this._stopped = false;
                this._stopRaf();
                this._startTimestamp = null;
                this._renderTime(0);
                this._updateStatus('');
                break;
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

    _calculateServerOffset() {
        if (!this.serverNowValue) return 0;
        return Date.now() - new Date(this.serverNowValue).getTime();
    }

    _updateStatus(status) {
        if (!this.hasStatusTarget) return;

        const labels = {
            '': this.statusTarget.dataset.labelNotStarted || 'Not started',
            'running': this.statusTarget.dataset.labelRunning || 'Running',
            'stopped': this.statusTarget.dataset.labelStopped || 'Stopped',
            'times_up': this.statusTarget.dataset.labelTimesUp || "Time's up!",
        };

        this.statusTarget.textContent = labels[status] || labels[''];

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
