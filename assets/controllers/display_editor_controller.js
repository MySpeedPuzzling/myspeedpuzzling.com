/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { createRenderer } from '../displays/render.js';
import { EditorState } from '../displays/editor_state.js';
import { EditorPanel, translator } from '../displays/editor_panel.js';
import { previewState } from '../displays/sample_data.js';
import { HANDLES, hitTest, keepsRatio, moveFrame, nudgeFrame, resizeFrame, snapLines, toPercent } from '../displays/geometry.js';
import { boundsOf, groupKeepsRatio, marqueeHits, marqueeRect, moveGroup, nudgeGroup, resizeGroup } from '../displays/arrange.js';
import { RESULTS_TYPES } from '../displays/schema.js';
import '../styles/display_editor.scss';

// Screen pixels a pointer moves before a press becomes a drag
const DEAD_ZONE = 3;

// Shift, Ctrl or Cmd: add to / take out of the selection
function isAdditive(event) {
    return event?.shiftKey === true || event?.ctrlKey === true || event?.metaKey === true;
}

function frameMap(id, frame) {
    return frame === null ? null : { [id]: frame };
}

/**
 * The display design editor (templates/competition_toolkit/display_editor.html.twig, D-displays-overlays.md §8.2,
 * competition toolkit D6) - lazy, loaded on this page only. The stage is the display's own renderer (render.js) at
 * scale with an overlay for selection, handles and guides on top; the document lives in EditorState (undo/redo,
 * unsaved flag), the fields in EditorPanel (schema-driven), the frame maths in geometry.js and arrange.js (v2 E1:
 * multi-select by Shift/Ctrl/Cmd+click, the layers list, an area drag or Ctrl+A; group move/resize/nudge, align,
 * distribute; snapping to the other elements).
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
            standings: config.standings ?? [],
            divisions: config.divisions ?? [],
            challenges: config.challenges ?? [],
            uploadAvailable: typeof config.uploadUrl === 'string' && config.uploadUrl !== '',
            getImages: () => ({ ...(this.baseState.images ?? {}), ...this.images }),
            upload: (file) => this.upload(file),
            commit: (change, key, rebuild) => {
                if (this.state.changeDesign(change, key)) {
                    this.refresh({ panel: rebuild === true });
                }
            },
            act: (name, arg) => this.selectionAction(name, arg),
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
        // macOS turns Ctrl+click into a right click: the editor's Ctrl+click (add to the selection) wins on the stage
        this.overlayTarget.addEventListener('contextmenu', (event) => {
            if (event.ctrlKey) {
                event.preventDefault();
            }
        });

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
            labels: { round: this.t('sample.round'), category: this.t('sample.category'), puzzle: this.t('sample.puzzle'), standing: this.t('sample.standing'), message: this.t('sample.message') },
            standingTexts: this.config.standingTexts ?? {},
            bracketTexts: this.config.bracketTexts ?? {},
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
            select: (id, event) => (isAdditive(event) ? this.toggle(id) : this.select(id)),
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
        const selection = this.state.selectedElements();

        if (element === null && selection.length > 1) {
            this.drawGroup(overlay, selection);
        }

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

        if (this.drag?.mode === 'marquee' && this.drag.marquee) {
            for (const id of this.drag.hits) {
                const hit = this.state.element(id);

                if (hit !== null) {
                    overlay.appendChild(this.outline(hit.frame, 'display-editor__member is-hit'));
                }
            }

            overlay.appendChild(this.outline(this.drag.marquee, 'display-editor__marquee'));
        }

        for (const guide of this.drag?.guides ?? []) {
            const line = document.createElement('div');
            line.className = `display-editor__guide display-editor__guide--${guide.axis}${guide.sibling ? ' display-editor__guide--sibling' : ''}`;
            line.style[guide.axis === 'x' ? 'left' : 'top'] = `${guide.at}%`;
            overlay.appendChild(line);
        }
    }

    outline(frame, className) {
        const node = document.createElement('div');
        node.className = className;
        node.style.left = `${frame.x}%`;
        node.style.top = `${frame.y}%`;
        node.style.width = `${frame.w}%`;
        node.style.height = `${frame.h}%`;

        return node;
    }

    // A thin outline per member (dashed when locked) and one box with the 8 handles around the movable ones
    drawGroup(overlay, selection) {
        for (const member of selection) {
            const outline = this.outline(member.frame, `display-editor__member${member.locked ? ' is-locked' : ''}`);
            outline.dataset.member = member.id;
            overlay.appendChild(outline);
        }

        const bounds = boundsOf(selection.filter((member) => !member.locked).map((member) => member.frame));

        if (bounds === null) {
            return;
        }

        const box = this.outline(bounds, 'display-editor__selection is-group');
        box.dataset.selection = 'group';

        for (const handle of HANDLES) {
            const node = document.createElement('span');
            node.className = `display-editor__handle display-editor__handle--${handle}`;
            node.dataset.handle = handle;
            box.appendChild(node);
        }

        overlay.appendChild(box);
    }

    select(id) {
        this.state.select(id);
        this.refresh({ panel: true });
    }

    toggle(id) {
        this.state.toggleSelected(id);
        this.refresh({ panel: true });
    }

    // The multi-selection panel's actions (EditorPanel `act`)
    selectionAction(name, arg) {
        switch (name) {
            case 'align':
                this.change(() => this.state.alignSelected(arg));
                break;
            case 'distribute':
                this.change(() => this.state.distributeSelected(arg));
                break;
            case 'frame': {
                const frames = {};

                for (const element of this.state.movableSelected()) {
                    frames[element.id] = { [arg.key]: arg.value };
                }

                if (this.state.setFrames(frames, `sel.frame.${arg.key}`)) {
                    this.refresh();
                }
                break;
            }
            case 'edit':
                if (this.state.editSelected(arg.change, arg.key)) {
                    this.refresh({ panel: arg.rebuild === true });
                }
                break;
            case 'hidden':
                this.change(() => this.state.toggleHiddenSelected());
                break;
            case 'locked':
                this.change(() => this.state.toggleLockedSelected());
                break;
            case 'duplicate':
                this.change(() => this.state.duplicateSelected());
                break;
            case 'remove':
                this.change(() => this.state.removeSelected());
                break;
            default:
                break;
        }
    }

    // ---- stage pointer work

    /**
     * A press on the stage → one drag of mode move / resize / marquee / press (a press becomes an area selection once
     * it moves, else it is a click). Shift/Ctrl/Cmd add to the selection; nothing commits within the 3 px dead zone.
     */
    pointerDown(event) {
        if (event.button !== 0) {
            return;
        }

        const rect = this.overlayTarget.getBoundingClientRect();
        const point = toPercent(event.clientX - rect.left, event.clientY - rect.top, rect);
        const handle = event.target?.dataset?.handle ?? null;
        const additive = isAdditive(event);
        let mode = 'move';
        let collapseTo = null;
        let pressId = null;

        this.overlayTarget.focus({ preventScroll: true });

        if (handle !== null) {
            mode = 'resize';
        } else {
            const id = hitTest(this.state.design.elements, point);
            const hit = id === null ? null : this.state.element(id);

            if (hit !== null && !hit.locked) {
                if (!this.state.isSelected(id)) {
                    if (additive) {
                        this.toggle(id);
                    } else {
                        this.select(id);
                    }
                } else if (additive) {
                    // Shift/Ctrl/Cmd+click on a selected element takes it out, no drag
                    this.toggle(id);

                    return;
                } else if (this.state.selectedIds.length > 1) {
                    // A release without moving selects only this one
                    collapseTo = id;
                }
            } else if (hit === null && !additive && this.insideGroup(point)) {
                // Inside the multi-selection's box on no element: drags the selection
                mode = 'move';
            } else {
                mode = 'press';
                pressId = id;
            }
        }

        const items = mode === 'press' ? [] : this.state.movableItems();

        if (mode !== 'press' && items.length === 0) {
            return;
        }

        event.preventDefault();
        this.overlayTarget.setPointerCapture(event.pointerId);
        this.dragCount = (this.dragCount ?? 0) + 1;
        this.drag = {
            mode,
            items,
            handle,
            additive,
            collapseTo,
            pressId,
            startPoint: point,
            startX: event.clientX,
            startY: event.clientY,
            rect,
            key: `drag:${this.dragCount}`,
            // The others' edges and centres, once - they do not move during the drag
            lines: mode === 'press' ? null : snapLines(this.state.design.elements, items.map((item) => item.id)),
            guides: [],
            pending: null,
            moved: false,
            marquee: null,
            hits: [],
        };
    }

    insideGroup(point) {
        if (this.state.selectedIds.length < 2) {
            return false;
        }

        const bounds = boundsOf(this.state.movableSelected().map((element) => element.frame));

        return bounds !== null && point.x >= bounds.x && point.x <= bounds.x + bounds.w && point.y >= bounds.y && point.y <= bounds.y + bounds.h;
    }

    pointerMove(event) {
        const drag = this.drag;

        if (drag === null) {
            return;
        }

        // A 3 px dead zone: the jitter of a plain click neither moves nor snaps anything (no undo step)
        if (!drag.moved && Math.hypot(event.clientX - drag.startX, event.clientY - drag.startY) < DEAD_ZONE) {
            return;
        }

        drag.moved = true;

        if (drag.mode === 'press' || drag.mode === 'marquee') {
            drag.mode = 'marquee';
            const point = toPercent(event.clientX - drag.rect.left, event.clientY - drag.rect.top, drag.rect);
            drag.marquee = marqueeRect(drag.startPoint, point);
            drag.hits = marqueeHits(this.state.design.elements, drag.marquee);
            this.scheduleFrame();

            return;
        }

        const delta = toPercent(event.clientX - drag.startX, event.clientY - drag.startY, drag.rect);
        const snap = this.snapTarget.checked;
        // 6 screen pixels of snapping distance, whatever the zoom
        const options = { snap, thresholdX: (6 / drag.rect.width) * 100, thresholdY: (6 / drag.rect.height) * 100, lines: drag.lines };
        const single = drag.items.length === 1 ? drag.items[0] : null;
        let result;

        if (drag.mode === 'resize') {
            const resize = {
                ...options,
                keepRatio: single !== null ? keepsRatio(single.type, event.shiftKey) : groupKeepsRatio(drag.items.map((item) => item.type), event.shiftKey),
                fromCentre: event.altKey,
            };

            if (single !== null) {
                const resized = resizeFrame(single.frame, drag.handle, delta.x, delta.y, resize);
                result = { frames: { [single.id]: resized.frame }, guides: resized.guides };
            } else {
                result = resizeGroup(drag.items, drag.handle, delta.x, delta.y, resize);
            }
        } else if (single !== null) {
            const moved = moveFrame(single.frame, delta.x, delta.y, options);
            result = { frames: { [single.id]: moved.frame }, guides: moved.guides };
        } else {
            result = moveGroup(drag.items, delta.x, delta.y, options);
        }

        drag.pending = result.frames;
        drag.guides = result.guides;
        this.scheduleFrame();
    }

    // Moves batched per animation frame
    scheduleFrame() {
        if (this.frameRequest !== null) {
            return;
        }

        this.frameRequest = requestAnimationFrame(() => {
            this.frameRequest = null;

            if (this.drag === null) {
                return;
            }

            if (this.drag.pending !== null) {
                this.state.setFrames(this.drag.pending, this.drag.key);
                this.renderPreview();
            }

            this.drawOverlay();
        });
    }

    pointerUp(event) {
        const drag = this.drag;

        if (drag === null) {
            return;
        }

        if (this.overlayTarget.hasPointerCapture?.(event.pointerId)) {
            this.overlayTarget.releasePointerCapture(event.pointerId);
        }

        this.drag = null;

        if (drag.mode === 'marquee') {
            this.state.selectIds(drag.hits, drag.additive);
        } else if (drag.mode === 'press') {
            // A click on a locked element or the empty canvas (today's click)
            if (drag.pressId !== null) {
                if (drag.additive) {
                    this.state.toggleSelected(drag.pressId);
                } else {
                    this.state.select(drag.pressId);
                }
            } else if (!drag.additive) {
                this.state.select(null);
            }
        } else if (drag.pending !== null) {
            this.state.setFrames(drag.pending, drag.key);
        } else if (!drag.moved && drag.collapseTo !== null) {
            this.state.select(drag.collapseTo);
        }

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

        if (mod && key === 'a') {
            event.preventDefault();
            this.state.selectAll();
            this.refresh({ panel: true });

            return;
        }

        if (this.state.selectedIds.length === 0) {
            return;
        }

        if (mod && key === 'd') {
            event.preventDefault();
            this.change(() => this.state.duplicateSelected());

            return;
        }

        if (event.key === 'Delete' || event.key === 'Backspace') {
            event.preventDefault();
            this.change(() => this.state.removeSelected());

            return;
        }

        if (event.key === 'Escape') {
            this.select(null);

            return;
        }

        const items = this.state.movableItems();

        if (items.length === 0) {
            return;
        }

        const frames = items.length === 1
            ? frameMap(items[0].id, nudgeFrame(items[0].frame, event.key, event.shiftKey))
            : nudgeGroup(items, event.key, event.shiftKey);

        if (frames !== null) {
            event.preventDefault();
            this.state.setFrames(frames, `nudge:${items.map((item) => item.id).join(',')}`);
            this.refresh();
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
