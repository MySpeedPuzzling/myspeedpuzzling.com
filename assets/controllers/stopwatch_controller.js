import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'hours', 'minutes', 'seconds',
        'startBtn', 'pauseBtn', 'resumeBtn', 'finishBtn', 'finishLink',
        'nameDisplay', 'nameInput',
        'milestoneBar', 'milestoneLabel', 'milestoneProgress', 'milestoneAvatar',
        'wakeLockToggle', 'wakeLockCheckbox',
        'otherStopwatches',
        'rankDisplay',
    ];

    static values = {
        status: String,
        stopwatchId: String,
        totalSeconds: Number,
        lastStart: String,
        serverNow: String,
        startUrl: String,
        puzzleId: String,
        finishUrlTemplate: String,
        resetUrl: String,
        milestones: Array,
        soloTimes: Array,
        labelSolvedTimes: String,
        labelCurrentRanking: String,
        labelDeleteConfirm: String,
    };

    connect() {
        this._serverOffset = this._calculateServerOffset();
        this._totalPreviousMs = this.totalSecondsValue * 1000;
        this._lastStartTimestamp = this.lastStartValue ? new Date(this.lastStartValue).getTime() : null;
        this._rafId = null;
        this._wakeLock = null;
        this._currentMilestoneIndex = 0;
        this._actionQueue = JSON.parse(localStorage.getItem('stopwatch-queue') || '[]');
        this._lastRank = null;

        this._updateButtons();
        this._hideWakeLockIfUnsupported();
        this._initRankDisplay();

        if (this.statusValue === 'running') {
            this._startRaf();
            this._requestWakeLock();
        } else {
            this._renderTime(this._totalPreviousMs);
        }

        this._initMilestones();
        this._replayQueue();

        this._onlineHandler = () => this._replayQueue();
        window.addEventListener('online', this._onlineHandler);

        this._visibilityHandler = () => {
            if (document.visibilityState === 'visible' && this.statusValue === 'running' && this._wakeLock === null) {
                if (this.hasWakeLockCheckboxTarget && this.wakeLockCheckboxTarget.checked) {
                    this._requestWakeLock();
                }
            }
        };
        document.addEventListener('visibilitychange', this._visibilityHandler);
    }

    disconnect() {
        this._stopRaf();
        this._releaseWakeLock();
        window.removeEventListener('online', this._onlineHandler);
        document.removeEventListener('visibilitychange', this._visibilityHandler);
    }

    // --- Actions ---

    async start() {
        if (!navigator.onLine) return;

        this._setButtonsLoading();

        try {
            const body = {};
            if (this.puzzleIdValue) {
                body.puzzleId = this.puzzleIdValue;
            }

            const response = await this._apiPost(this.startUrlValue, body);
            const data = await response.json();

            this.stopwatchIdValue = data.stopwatchId;
            this.statusValue = 'running';
            this._totalPreviousMs = 0;
            this._lastStartTimestamp = Date.now();

            this._updateFinishLink();
            this._updateButtons();
            this._startRaf();
            this._requestWakeLock();

            history.replaceState(null, '', window.location.href);
        } catch {
            this._updateButtons();
        }
    }

    async pause() {
        if (!this.stopwatchIdValue) return;

        const elapsedMs = this._getElapsedMs();
        this._stopRaf();
        this.statusValue = 'paused';
        this._totalPreviousMs = elapsedMs;
        this._lastStartTimestamp = null;
        this._updateButtons();
        this._renderTime(elapsedMs);
        this._releaseWakeLock();

        try {
            await this._apiPost(`/api/stopwatch/${this.stopwatchIdValue}/pause`);
        } catch {
            this._enqueueAction('pause', this.stopwatchIdValue);
        }
    }

    async resume() {
        if (!this.stopwatchIdValue) return;

        this.statusValue = 'running';
        this._lastStartTimestamp = Date.now();
        this._updateButtons();
        this._startRaf();
        this._requestWakeLock();

        try {
            await this._apiPost(`/api/stopwatch/${this.stopwatchIdValue}/resume`);
        } catch {
            this._enqueueAction('resume', this.stopwatchIdValue);
        }
    }

    async reset() {
        if (!this.stopwatchIdValue) return;

        this._stopRaf();
        this._releaseWakeLock();

        const stopwatchId = this.stopwatchIdValue;

        try {
            await this._apiPost(`/api/stopwatch/${stopwatchId}/reset`);
        } catch {
            this._enqueueAction('reset', stopwatchId);
        }

        window.location.replace(this.resetUrlValue);
    }

    async deleteOther(event) {
        const stopwatchId = event.params.stopwatchId;
        if (!stopwatchId) return;

        if (!confirm(this.labelDeleteConfirmValue)) return;

        const row = event.currentTarget.closest('.list-group-item');

        try {
            await this._apiPost(`/api/stopwatch/${stopwatchId}/reset`);
            if (row) row.remove();
        } catch {
            // Silent fail
        }
    }

    async toggleWakeLock() {
        if (!this.hasWakeLockCheckboxTarget) return;

        if (this.wakeLockCheckboxTarget.checked) {
            await this._requestWakeLock();
        } else {
            this._releaseWakeLock();
        }
    }

    editName() {
        if (!this.hasNameInputTarget) return;
        this.nameDisplayTarget.classList.add('d-none');
        this.nameInputTarget.classList.remove('d-none');
        this.nameInputTarget.focus();
    }

    async saveName() {
        if (!this.hasNameInputTarget) return;
        const name = this.nameInputTarget.value.trim() || null;

        this.nameDisplayTarget.textContent = name || '';
        this.nameDisplayTarget.classList.remove('d-none');
        this.nameInputTarget.classList.add('d-none');

        if (!this.stopwatchIdValue) return;

        try {
            await this._apiPost(`/api/stopwatch/${this.stopwatchIdValue}/rename`, { name });
        } catch {
            // Silent fail for rename
        }
    }

    saveNameOnEnter(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            this.saveName();
        }
    }

    // --- Timer Engine ---

    _startRaf() {
        this._stopRaf();
        const tick = () => {
            const elapsed = this._getElapsedMs();
            this._renderTime(elapsed);
            this._updateMilestoneProgress(elapsed);
            this._updateRank(elapsed);
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

    _getElapsedMs() {
        if (this.statusValue !== 'running' || this._lastStartTimestamp === null) {
            return this._totalPreviousMs;
        }
        return Date.now() - this._lastStartTimestamp + this._totalPreviousMs;
    }

    _renderTime(ms) {
        const totalSeconds = Math.floor(ms / 1000);
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
        const serverTime = new Date(this.serverNowValue).getTime();
        return Date.now() - serverTime;
    }

    // --- UI State ---

    _updateButtons() {
        const status = this.statusValue;

        this._toggleTarget('startBtn', !status || status === 'not_started');
        this._toggleTarget('pauseBtn', status === 'running');
        this._toggleTarget('resumeBtn', status === 'paused');
        this._toggleTarget('finishBtn', status === 'paused');
    }

    _setButtonsLoading() {
        this._toggleTarget('startBtn', false);
        this._toggleTarget('pauseBtn', false);
        this._toggleTarget('resumeBtn', false);
    }

    _toggleTarget(name, show) {
        const hasTarget = `has${name.charAt(0).toUpperCase()}${name.slice(1)}Target`;
        if (this[hasTarget]) {
            this[`${name}Target`].classList.toggle('d-none', !show);
        }
    }

    _hideWakeLockIfUnsupported() {
        if (!('wakeLock' in navigator) && this.hasWakeLockToggleTarget) {
            this.wakeLockToggleTarget.classList.add('d-none');
        }
    }

    _updateFinishLink() {
        if (!this.hasFinishLinkTarget || !this.stopwatchIdValue) return;
        const url = this.finishUrlTemplateValue.replace('__STOPWATCH_ID__', this.stopwatchIdValue);
        this.finishLinkTarget.setAttribute('href', url);
    }

    // --- Milestones ---

    _initMilestones() {
        if (!this.milestonesValue || this.milestonesValue.length === 0) return;

        this._milestones = this.milestonesValue;
        this._currentMilestoneIndex = 0;

        const elapsed = this._getElapsedMs();
        while (this._currentMilestoneIndex < this._milestones.length &&
               this._milestones[this._currentMilestoneIndex].timeSeconds * 1000 <= elapsed) {
            this._currentMilestoneIndex++;
        }

        this._updateMilestoneProgress(elapsed);
    }

    _updateMilestoneProgress(elapsedMs) {
        if (!this._milestones || this._milestones.length === 0) return;
        if (!this.hasMilestoneBarTarget) return;

        const prevIndex = this._currentMilestoneIndex;

        while (this._currentMilestoneIndex < this._milestones.length &&
               this._milestones[this._currentMilestoneIndex].timeSeconds * 1000 <= elapsedMs) {
            this._currentMilestoneIndex++;
        }

        // When transitioning to next milestone, instantly reset progress bar (no animation)
        if (this._currentMilestoneIndex !== prevIndex) {
            this.milestoneProgressTarget.style.transition = 'none';
            this.milestoneProgressTarget.style.width = '0%';
            // Force reflow then re-enable animation
            this.milestoneProgressTarget.offsetWidth;
            this.milestoneProgressTarget.style.transition = '';
        }

        if (this._currentMilestoneIndex >= this._milestones.length) {
            this.milestoneBarTarget.classList.add('d-none');
            return;
        }

        this.milestoneBarTarget.classList.remove('d-none');

        const milestone = this._milestones[this._currentMilestoneIndex];
        const milestoneMs = milestone.timeSeconds * 1000;

        const prevMs = this._currentMilestoneIndex > 0
            ? this._milestones[this._currentMilestoneIndex - 1].timeSeconds * 1000
            : 0;
        const range = milestoneMs - prevMs;
        const progress = range > 0 ? Math.min(((elapsedMs - prevMs) / range) * 100, 100) : 0;

        const timeStr = this._formatMilestoneTime(milestone.timeSeconds);
        const starIcon = milestone.type === 'favorite' ? '<i class="ci-star-filled text-warning me-1"></i>' : '';
        const rankStr = milestone.rank ? `<span class="stopwatch-milestone-rank">#${milestone.rank}</span> ` : '';
        this.milestoneLabelTarget.innerHTML = `${starIcon}${rankStr}${milestone.label} <span class="stopwatch-milestone-time">${timeStr}</span>`;
        this.milestoneProgressTarget.style.width = `${progress}%`;

        if (this.hasMilestoneAvatarTarget) {
            if (milestone.avatar) {
                this.milestoneAvatarTarget.innerHTML = `<img src="${milestone.avatar}" alt="" class="rounded-circle" style="width: 28px; height: 28px; object-fit: cover;">`;
                this.milestoneAvatarTarget.classList.remove('d-none');
            } else {
                this.milestoneAvatarTarget.classList.add('d-none');
                this.milestoneAvatarTarget.innerHTML = '';
            }
        }
    }

    _formatMilestoneTime(totalSeconds) {
        const h = Math.floor(totalSeconds / 3600);
        const m = Math.floor((totalSeconds % 3600) / 60);
        const s = totalSeconds % 60;
        return `${this._pad(h)}:${this._pad(m)}:${this._pad(s)}`;
    }

    // --- Live Rank ---

    _initRankDisplay() {
        if (!this.hasRankDisplayTarget) return;
        if (!this.soloTimesValue || this.soloTimesValue.length === 0) return;

        this.rankDisplayTarget.textContent = this.labelSolvedTimesValue;
    }

    _updateRank(elapsedMs) {
        if (!this.hasRankDisplayTarget) return;
        if (!this.soloTimesValue || this.soloTimesValue.length === 0) return;

        const currentSeconds = Math.floor(elapsedMs / 1000);
        let rank = 1;
        for (const time of this.soloTimesValue) {
            if (currentSeconds > time) {
                rank++;
            } else {
                break;
            }
        }

        const total = this.soloTimesValue.length + 1;

        if (rank !== this._lastRank) {
            this._lastRank = rank;
            this.rankDisplayTarget.textContent = this.labelCurrentRankingValue.replace('%rank%', rank).replace('%total%', total);
        }
    }

    // --- Wake Lock ---

    async _requestWakeLock() {
        if (!('wakeLock' in navigator)) return;
        try {
            this._wakeLock = await navigator.wakeLock.request('screen');
            this._wakeLock.addEventListener('release', () => {
                this._wakeLock = null;
                this._syncWakeLockCheckbox();
            });
            this._syncWakeLockCheckbox();
        } catch {
            this._syncWakeLockCheckbox();
        }
    }

    _releaseWakeLock() {
        if (this._wakeLock) {
            this._wakeLock.release();
            this._wakeLock = null;
            this._syncWakeLockCheckbox();
        }
    }

    _syncWakeLockCheckbox() {
        if (!this.hasWakeLockCheckboxTarget) return;
        this.wakeLockCheckboxTarget.checked = this._wakeLock !== null;
    }

    // --- Offline Queue ---

    _enqueueAction(action, stopwatchId) {
        this._actionQueue.push({ action, stopwatchId, timestamp: Date.now() });
        localStorage.setItem('stopwatch-queue', JSON.stringify(this._actionQueue));
    }

    async _replayQueue() {
        if (this._actionQueue.length === 0) return;
        const queue = [...this._actionQueue];
        this._actionQueue = [];
        localStorage.removeItem('stopwatch-queue');

        for (const item of queue) {
            try {
                await this._apiPost(`/api/stopwatch/${item.stopwatchId}/${item.action}`);
            } catch {
                this._enqueueAction(item.action, item.stopwatchId);
                break;
            }
        }
    }

    async _apiPost(url, body = null) {
        const options = {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
        };

        if (body !== null) {
            options.body = JSON.stringify(body);
        }

        const response = await fetch(url, options);
        if (!response.ok) {
            throw new Error(`API error: ${response.status}`);
        }
        return response;
    }
}
