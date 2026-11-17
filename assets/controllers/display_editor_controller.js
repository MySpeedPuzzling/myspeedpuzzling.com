/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { createRenderer } from '../displays/render.js';
import { EditorState } from '../displays/editor_state.js';
import { EditorPanel, translator } from '../displays/editor_panel.js';
import { previewState } from '../displays/sample_data.js';
import { HANDLES, hitTest, keepsRatio, moveFrame, nudgeFrame, resizeFrame, toPercent } from '../displays/geometry.js';
import { RESULTS_TYPES } from '../displays/schema.js';
import '../styles/display_editor.scss';

/**
 * The display design editor (templates/competition_toolkit/display_editor.html.twig, D-displays-overlays.md §8.2,
 * competition toolkit D6) - lazy, loaded on this page only. The stage is the display's own renderer (render.js) at
 * scale with an overlay for selection, handles and guides on top; the document lives in EditorState (undo/redo,
 * unsaved flag), the fields in EditorPanel (schema-driven), the frame maths in geometry.js.
 *
 * Save is explicit (a half-edited design must never reach a hall screen): POST save_competition_display
 * {design, baseVersion, liveConfirmed} → adopt the server's normalised design; 422 live_not_confirmed → ask once,
 * resend confirmed; 409 → load the saved version or overwrite it.
 */
export default class extends Controller {
    static targets = [
        'stageWrap', 'stage', 'overlay', 'panel', 'layers', 'catalogue', 'notice', 'status', 'undo', 'redo', 'save',
        'phase', 'data', 'published', 'revealed', 'snap', 'toolbar',
    ];

    static values = { config: Object };

    connect() {
        const config = this.configValue;
        this.config = config;
        this.t = translator(config.labels ?? {});
        this.images = {};
        this.baseState = config.state ?? {};
        this.clockOffsetMs = typeof this.baseState.serverNowMs === 'number' ? this.baseState.serverNowMs - Date.now() : 0;
        this.saving = false;
        this.drag = null;
        this.frameRequest = null;
        this.renderedDesign = null;

        this.state = new EditorState({
            design: config.design,
            designVersion: config.designVersion,
            competitionId: config.competitionId,
            texts: { text: this.t('text_default') },
        });

        this.renderer = createRenderer({
            document,
            root: this.stageTarget,
            design: this.state.design,
            competitionId: config.competitionId,
            kind: config.kind,
        });
        this.renderedDesign = this.state.design;

        this.panel = new EditorPanel({
            document,
            t: this.t,
            kind: config.kind,
            uploadAvailable: typeof config.uploadUrl === 'string' && config.uploadUrl !== '',
            getImages: () => ({ ...(this.baseState.images ?? {}), ...this.images }),
            upload: (file) => this.upload(file),
            commit: (change, key, rebuild) => {
                if (this.state.changeDesign(change, key)) {
                    this.refresh({ panel: rebuild === true });
                }
            },
        });
        this.panel.renderCatalogue(this.catalogueTarget);

        this.onResize = () => this.fit();
        this.resizeObserver = typeof ResizeObserver === 'function' ? new ResizeObserver(this.onResize) : null;
        this.resizeObserver?.observe(this.stageWrapTarget);

        // The side panels stick below the sticky toolbar, which wraps to more rows on narrower screens
        this.toolbarObserver = typeof ResizeObserver === 'function' ? new ResizeObserver(() => this.publishToolbarHeight()) : null;
        this.toolbarObserver?.observe(this.toolbarTarget);
        this.publishToolbarHeight();
        window.addEventListener('resize', this.onResize);

        this.onKeyDown = (event) => this.keyDown(event);
        this.onKeyUp = (event) => {
            if (event.key.startsWith('Arrow')) {
                this.state.seal();
                this.refresh({ panel: true });
            }
        };
        document.addEventListener('keydown', this.onKeyDown);
        document.addEventListener('keyup', this.onKeyUp);

        this.onBeforeUnload = (event) => {
            if (this.state.dirty) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        this.onBeforeVisit = (event) => {
            if (this.state.dirty && !window.confirm(this.t('leave_confirm'))) {
                event.preventDefault();
            }
        };
        window.addEventListener('beforeunload', this.onBeforeUnload);
        document.addEventListener('turbo:before-visit', this.onBeforeVisit);

        this.overlayTarget.addEventListener('pointerdown', (event) => this.pointerDown(event));
        this.overlayTarget.addEventListener('pointermove', (event) => this.pointerMove(event));
        this.overlayTarget.addEventListener('pointerup', (event) => this.pointerUp(event));
        this.overlayTarget.addEventListener('pointercancel', (event) => this.pointerUp(event));

        this.stageWrapTarget.addEventListener('dragover', (event) => {
            if (this.panel.uploadAvailable && event.dataTransfer?.types?.includes('Files')) {
                event.preventDefault();
            }
        });
        this.stageWrapTarget.addEventListener('drop', (event) => this.dropFile(event));

        this.ticker = window.setInterval(() => {
            if (this.dataTarget.value === 'display') {
                this.renderPreview();
            }
        }, 1000);

        this.element.dataset.editorReady = '1';
        this.fit();
        this.refresh({ panel: true });
    }

    disconnect() {
        this.resizeObserver?.disconnect();
        this.toolbarObserver?.disconnect();
        window.removeEventListener('resize', this.onResize);
        document.removeEventListener('keydown', this.onKeyDown);
        document.removeEventListener('keyup', this.onKeyUp);
        window.removeEventListener('beforeunload', this.onBeforeUnload);
        document.removeEventListener('turbo:before-visit', this.onBeforeVisit);
        window.clearInterval(this.ticker);

        if (this.frameRequest !== null) {
            cancelAnimationFrame(this.frameRequest);
        }
    }

    // ---- drawing

    publishToolbarHeight() {
        const height = Math.round(this.toolbarTarget.getBoundingClientRect().height);

        if (height > 0) {
            this.element.style.setProperty('--de-toolbar-height', `${height}px`);
        }
    }

    fit() {
        const width = this.stageWrapTarget.clientWidth;
        const height = Math.round((width * 9) / 16);
        this.stageWrapTarget.style.height = `${height}px`;
        this.renderer.fit(width, height);
    }

    preview() {
        const sample = this.dataTarget.value !== 'display';

        return previewState(this.baseState, {
            mode: sample ? 'sample' : 'display',
            phase: this.phaseTarget.value,
            published: this.publishedTarget.checked,
            revealed: this.revealedTarget.checked,
            nowMs: Date.now() + this.clockOffsetMs,
            design: this.state.design,
            images: this.images,
            labels: { round: this.t('sample.round'), category: this.t('sample.category'), puzzle: this.t('sample.puzzle') },
        });
    }

    renderPreview() {
        if (this.renderedDesign !== this.state.design) {
            this.renderer.setDesign(this.state.design);
            this.renderedDesign = this.state.design;
        }

        const { state, nowMs } = this.preview();
        this.renderer.render(state, nowMs);
    }

    refresh({ panel = false } = {}) {
        this.renderPreview();
        this.drawOverlay();
        this.panel.renderLayers(this.layersTarget, this.state, {
            select: (id) => this.select(id),
            toggleHidden: (id) => this.change(() => this.state.toggleHidden(id)),
            toggleLocked: (id) => this.change(() => this.state.toggleLocked(id)),
            up: (id) => this.change(() => this.state.moveLayer(id, 'up')),
            down: (id) => this.change(() => this.state.moveLayer(id, 'down')),
            duplicate: (id) => this.change(() => this.state.duplicateElement(id), true),
            remove: (id) => this.change(() => this.state.removeElement(id), true),
        });

        if (panel) {
            this.panel.render(this.panelTarget, this.state);
        }

        this.undoTarget.disabled = !this.state.canUndo;
        this.redoTarget.disabled = !this.state.canRedo;
        this.saveTarget.disabled = this.saving;
        this.element.classList.toggle('is-dirty', this.state.dirty);
        this.element.dataset.designVersion = String(this.state.designVersion);

        if (!this.saving) {
            this.statusTarget.textContent = this.state.dirty ? this.t('unsaved') : this.t('saved');
        }
    }

    change(run, panel = true) {
        run();
        this.refresh({ panel });
    }

    drawOverlay() {
        const overlay = this.overlayTarget;

        while (overlay.firstChild) {
            overlay.firstChild.remove();
        }

        for (const element of this.state.design.elements) {
            if (!element.hidden && RESULTS_TYPES.includes(element.type) && element.props.source === 'live') {
                const chip = document.createElement('span');
                chip.className = 'display-editor__live-chip';
                chip.textContent = this.t('live_badge');
                chip.style.left = `${element.frame.x}%`;
                chip.style.top = `${element.frame.y}%`;
                overlay.appendChild(chip);
            }
        }

        const element = this.state.selected;

        if (element !== null) {
            const box = document.createElement('div');
            box.className = `display-editor__selection${element.locked ? ' is-locked' : ''}`;
            box.style.left = `${element.frame.x}%`;
            box.style.top = `${element.frame.y}%`;
            box.style.width = `${element.frame.w}%`;
            box.style.height = `${element.frame.h}%`;
            box.dataset.selection = element.id;

            if (!element.locked) {
                for (const handle of HANDLES) {
                    const node = document.createElement('span');
                    node.className = `display-editor__handle display-editor__handle--${handle}`;
                    node.dataset.handle = handle;
                    box.appendChild(node);
                }
            }

            overlay.appendChild(box);
        }

        for (const guide of this.drag?.guides ?? []) {
            const line = document.createElement('div');
            line.className = `display-editor__guide display-editor__guide--${guide.axis}`;
            line.style[guide.axis === 'x' ? 'left' : 'top'] = `${guide.at}%`;
            overlay.appendChild(line);
        }
    }

    select(id) {
        this.state.select(id);
        this.refresh({ panel: true });
    }

    // ---- stage pointer work

    pointerDown(event) {
        if (event.button !== 0) {
            return;
        }

        const rect = this.overlayTarget.getBoundingClientRect();
        const point = toPercent(event.clientX - rect.left, event.clientY - rect.top, rect);
        const handle = event.target?.dataset?.handle ?? null;
        let element = this.state.selected;

        if (handle === null) {
            const id = hitTest(this.state.design.elements, point);

            if (id !== this.state.selectedId) {
                this.select(id);
            }

            element = this.state.selected;
        }

        this.overlayTarget.focus({ preventScroll: true });

        if (element === null || element.locked) {
            return;
        }

        event.preventDefault();
        this.overlayTarget.setPointerCapture(event.pointerId);
        this.dragCount = (this.dragCount ?? 0) + 1;
        this.drag = {
            id: element.id,
            type: element.type,
            handle,
            start: { ...element.frame },
            startX: event.clientX,
            startY: event.clientY,
            rect,
            key: `drag:${this.dragCount}`,
            guides: [],
            pending: null,
        };
    }

    pointerMove(event) {
        const drag = this.drag;

        if (drag === null) {
            return;
        }

        const delta = toPercent(event.clientX - drag.startX, event.clientY - drag.startY, drag.rect);
        const snap = this.snapTarget.checked;
        // 6 screen pixels of snapping distance, whatever the zoom
        const options = { snap, thresholdX: (6 / drag.rect.width) * 100, thresholdY: (6 / drag.rect.height) * 100 };
        const result = drag.handle === null
            ? moveFrame(drag.start, delta.x, delta.y, options)
            : resizeFrame(drag.start, drag.handle, delta.x, delta.y, {
                ...options,
                keepRatio: keepsRatio(drag.type, event.shiftKey),
                fromCentre: event.altKey,
            });

        drag.pending = result.frame;
        drag.guides = result.guides;

        // Moves batched per animation frame
        if (this.frameRequest === null) {
            this.frameRequest = requestAnimationFrame(() => {
                this.frameRequest = null;

                if (this.drag !== null && this.drag.pending !== null) {
                    this.state.setFrame(this.drag.id, this.drag.pending, this.drag.key);
                    this.renderPreview();
                    this.drawOverlay();
                }
            });
        }
    }

    pointerUp(event) {
        const drag = this.drag;

        if (drag === null) {
            return;
        }

        if (this.overlayTarget.hasPointerCapture?.(event.pointerId)) {
            this.overlayTarget.releasePointerCapture(event.pointerId);
        }

        if (drag.pending !== null) {
            this.state.setFrame(drag.id, drag.pending, drag.key);
        }

        this.drag = null;
        this.state.seal();
        this.refresh({ panel: true });
    }

    // ---- keyboard

    keyDown(event) {
        const target = event.target;
        const typing = target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));
        const mod = event.ctrlKey || event.metaKey;
        const key = event.key.toLowerCase();

        if (mod && key === 's') {
            event.preventDefault();
            this.save();

            return;
        }

        if (typing || !this.element.isConnected) {
            return;
        }

        if (mod && key === 'z') {
            event.preventDefault();
            this.change(() => (event.shiftKey ? this.state.redo() : this.state.undo()));

            return;
        }

        if (mod && key === 'y') {
            event.preventDefault();
            this.change(() => this.state.redo());

            return;
        }

        const selected = this.state.selected;

        if (selected === null) {
            return;
        }

        if (mod && key === 'd') {
            event.preventDefault();
            this.change(() => this.state.duplicateElement(selected.id));

            return;
        }

        if (event.key === 'Delete' || event.key === 'Backspace') {
            event.preventDefault();
            this.change(() => this.state.removeElement(selected.id));

            return;
        }

        if (event.key === 'Escape') {
            this.select(null);

            return;
        }

        if (!selected.locked) {
            const frame = nudgeFrame(selected.frame, event.key, event.shiftKey);

            if (frame !== null) {
                event.preventDefault();
                this.state.setFrame(selected.id, frame, `nudge:${selected.id}`);
                this.refresh();
            }
        }
    }

    // ---- toolbar actions

    add() {
        const type = this.catalogueTarget.value;

        if (type) {
            this.change(() => this.state.addElement(type));
        }
    }

    selectCanvas() {
        this.select(null);
    }

    saveClicked() {
        this.save(false);
    }

    undo() {
        this.change(() => this.state.undo());
    }

    redo() {
        this.change(() => this.state.redo());
    }

    previewChanged() {
        const sample = this.dataTarget.value !== 'display';
        this.phaseTarget.disabled = !sample;
        this.publishedTarget.disabled = !sample;
        this.revealedTarget.disabled = !sample;
        this.renderPreview();
    }

    // ---- pictures

    async upload(file) {
        const url = this.config.uploadUrl;

        if (!url) {
            return null;
        }

        const body = new FormData();
        body.append('file', file);
        this.notify(this.t('uploading'), 'info');

        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-Token': this.config.csrfToken },
                body,
            });
            const answer = await response.json().catch(() => ({}));

            if (response.ok && typeof answer.path === 'string' && typeof answer.url === 'string') {
                this.images[answer.path] = answer.url;
                this.notify(null);

                return answer.path;
            }

            this.notify(typeof answer.message === 'string' ? answer.message : this.t('upload_failed'), 'danger');
        } catch {
            this.notify(this.t('upload_failed'), 'danger');
        }

        return null;
    }

    async dropFile(event) {
        const file = event.dataTransfer?.files?.[0];

        if (!file || !this.panel.uploadAvailable) {
            return;
        }

        event.preventDefault();
        const path = await this.upload(file);

        if (path === null) {
            return;
        }

        const id = this.state.addElement('image');

        if (id !== null) {
            this.state.withElement(id, (element) => {
                element.props.source = 'upload';
                element.props.path = path;
            });
            this.state.seal();
        }

        this.refresh({ panel: true });
    }

    // ---- notices (in the page, never browser dialogs except the leave guard)

    notify(text, tone = 'info', actions = []) {
        const notice = this.noticeTarget;

        while (notice.firstChild) {
            notice.firstChild.remove();
        }

        if (text === null) {
            notice.className = '';
            notice.hidden = true;

            return;
        }

        notice.hidden = false;
        notice.className = `alert alert-${tone} py-2 px-3 small mb-2 d-flex flex-wrap align-items-center gap-2`;
        notice.dataset.tone = tone;
        const span = document.createElement('span');
        span.textContent = text;
        notice.appendChild(span);

        for (const action of actions) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `btn btn-sm ${action.primary ? 'btn-primary' : 'btn-outline-secondary'}`;
            button.textContent = action.label;
            button.dataset.noticeAction = action.name;
            button.addEventListener('click', action.run);
            notice.appendChild(button);
        }
    }

    // ---- save

    async save(liveConfirmed = false) {
        if (this.saving) {
            return;
        }

        const payload = this.state.savePayload(liveConfirmed);

        if (payload.design === null) {
            this.notify(this.t('save_failed'), 'danger');

            return;
        }

        this.saving = true;
        this.saveTarget.disabled = true;
        this.statusTarget.textContent = this.t('saving');
        let response;
        let answer = {};

        try {
            response = await fetch(this.config.saveUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-Token': this.config.csrfToken,
                },
                body: JSON.stringify(payload),
            });
            answer = await response.json().catch(() => ({}));
        } catch {
            response = null;
        } finally {
            this.saving = false;
        }

        if (response === null) {
            this.notify(this.t('save_offline'), 'danger');
            this.refresh();

            return;
        }

        if (response.ok && answer.design && typeof answer.designVersion === 'number') {
            this.state.adoptSaved(answer.design, answer.designVersion);
            this.notify(this.t('saved_notice'), 'success');
            this.refresh({ panel: true });

            return;
        }

        if (response.status === 409 && typeof answer.designVersion === 'number') {
            const theirs = answer.designVersion;
            this.notify(this.t('conflict'), 'warning', [
                { name: 'load', label: this.t('load_saved'), run: () => this.loadSaved() },
                {
                    name: 'overwrite',
                    label: this.t('overwrite'),
                    primary: true,
                    run: () => {
                        this.state.designVersion = theirs;
                        this.save(liveConfirmed);
                    },
                },
            ]);
            this.refresh();

            return;
        }

        const errors = Array.isArray(answer.errors) ? answer.errors : [];

        if (response.status === 422 && errors.length > 0 && errors.every((error) => error.code === 'live_not_confirmed')) {
            this.notify(this.t('live_confirm'), 'warning', [
                { name: 'cancel', label: this.t('cancel'), run: () => this.notify(null) },
                { name: 'confirm-live', label: this.t('live_confirm_button'), primary: true, run: () => this.save(true) },
            ]);
            this.refresh();

            return;
        }

        if (response.status === 422 && errors.length > 0) {
            const match = /^\/elements\/(\d+)/.exec(errors[0].path ?? '');
            const element = match ? this.state.design.elements[Number(match[1])] : null;

            if (element) {
                this.state.select(element.id);
            }

            this.notify(errors.map((error) => error.message ?? error.code).join(' '), 'danger');
            this.refresh({ panel: true });

            return;
        }

        this.notify(typeof answer.message === 'string' ? answer.message : this.t('save_failed'), 'danger');
        this.refresh();
    }

    async loadSaved() {
        try {
            const response = await fetch(this.config.designUrl, { cache: 'no-store', credentials: 'omit', headers: { Accept: 'application/json' } });
            const answer = await response.json();

            if (response.ok && answer.design && typeof answer.designVersion === 'number') {
                this.state.replaceWithSaved(answer.design, answer.designVersion);
                this.notify(null);
                this.refresh({ panel: true });

                return;
            }
        } catch {
            // said below
        }

        this.notify(this.t('save_failed'), 'danger');
    }
}
