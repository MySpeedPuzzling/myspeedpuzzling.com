// The display editor's property panel and layers list (docs/features/competitions-management/toolkit/D-displays-overlays.md
// §8.2, competition toolkit D6). Every field comes from schema.js (STYLE_SPEC, PROP_SPECS, FRAME_LIMITS ...) - the
// normaliser decides what is kept, this file only offers it. DOM by createElement + textContent only.
//
// The panel never writes the design itself: it hands a change function to `commit(change, key, rebuild)`, where
// change(design) edits a copy, `key` makes typing in one field one undo step, `rebuild` re-draws the panel (choices that
// show or hide other fields). A multi-selection (v2 E1) gets its own view (renderSelection): arrange (align/distribute),
// shared or "Mixed" position and style fields, hide/lock/duplicate/delete - those go through `act(name, arg)`.

import {
    BACKGROUND_FITS, BACKGROUND_TYPES, FONTS, FRAME_LIMITS, NAME_MAX_LENGTH, PHASES, PROP_SPECS, RESULTS_TYPES,
    STYLE_SPEC, WHEN_PUZZLE, WHEN_RESULTS,
} from './schema.js';
import { CATALOGUE } from './editor_state.js';
import { ALIGN_EDGES } from './arrange.js';

const ALIGN_ICONS = {
    left: 'bi-align-start',
    center: 'bi-align-center',
    right: 'bi-align-end',
    top: 'bi-align-top',
    middle: 'bi-align-middle',
    bottom: 'bi-align-bottom',
};
const FRAME_KEYS = ['x', 'y', 'w', 'h', 'rotate'];
const MIXED_OPTION = '__mixed';

function sameJson(values) {
    const first = JSON.stringify(values[0] ?? null);

    return values.every((value) => JSON.stringify(value ?? null) === first);
}

const COLOR_PATTERN = /^#[0-9a-fA-F]{6}(?:[0-9a-fA-F]{2})?$/;

export function humanise(key) {
    const words = String(key).replace(/([a-z])([A-Z])/g, '$1 $2').replace(/[_.]+/g, ' ').trim().toLowerCase();

    return words.charAt(0).toUpperCase() + words.slice(1);
}

export function optionKey(value) {
    return String(value).replace(/[^a-z0-9_]/gi, '_');
}

/**
 * The translated label of an editor key, else a readable form of its last part.
 */
export function translator(labels) {
    return (key, fallback = null) => {
        const value = labels?.[key];

        return typeof value === 'string' && value !== '' ? value : (fallback ?? humanise(key.split('.').pop()));
    };
}

const LAYER_DETAIL_LENGTH = 40;

function snippet(value) {
    const text = String(value ?? '').replace(/\s+/g, ' ').trim();

    return text.length > LAYER_DETAIL_LENGTH ? `${text.slice(0, LAYER_DETAIL_LENGTH - 1).trimEnd()}…` : text;
}

/**
 * What the layers list says about an element: its own name (else its type) and a hint telling two of a kind apart -
 * the type when it has a name, else what it shows (a text's words, a field's choice, a picture's source ...).
 */
export function layerLabel(element, t) {
    const type = t(`type.${element.type}`);
    const props = element.props ?? {};
    let shows = '';

    switch (element.type) {
        case 'text':
            shows = snippet(props.text);
            break;
        case 'field':
            shows = snippet([props.prefix, t(`opt.${optionKey(props.field ?? 'round_name')}`), props.suffix].filter((part) => typeof part === 'string' && part.trim() !== '').join(' '));
            break;
        case 'image':
            shows = props.source && props.source !== 'upload' ? t(`opt.${optionKey(props.source)}`) : '';
            break;
        case 'rotator':
            shows = Array.isArray(props.images) && props.images.length > 0 ? `× ${props.images.length}` : '';
            break;
        case 'qr':
            shows = props.target ? t(`opt.${optionKey(props.target)}`) : '';
            break;
        default:
            shows = '';
    }

    const name = typeof element.name === 'string' ? element.name.trim() : '';

    if (name === '') {
        return { title: type, detail: shows };
    }

    const detail = [type, shows].filter((part) => part !== '' && part !== name).join(' · ');

    return { title: name, detail };
}

export class EditorPanel {
    constructor({ document, t, commit, act = () => {}, getImages, upload, uploadAvailable, kind, standings = [], divisions = [], challenges = [] }) {
        this.document = document;
        // The event's standings for the standings element's picker: [{id, name, published}]
        this.standings = Array.isArray(standings) ? standings : [];
        // A results element's views (real events L2b): the event's divisions [{id, name, shortName}] and the puzzle
        // positions of its solve-all blocks [{position, labels}] - no field without them
        this.divisions = Array.isArray(divisions) ? divisions : [];
        this.challenges = Array.isArray(challenges) ? challenges : [];
        this.t = t;
        this.commit = commit;
        // The multi-selection's actions: act('align', edge), act('distribute', axis), act('frame', {key, value}),
        // act('edit', {change, key, rebuild}), act('hidden'), act('locked'), act('duplicate'), act('remove')
        this.act = act;
        this.getImages = getImages;
        this.upload = upload;
        this.uploadAvailable = uploadAvailable;
        this.kind = kind;
    }

    el(tag, className = null, text = null, parent = null) {
        const node = this.document.createElement(tag);

        if (className) {
            node.className = className;
        }

        if (text !== null) {
            node.textContent = text;
        }

        if (parent) {
            parent.appendChild(node);
        }

        return node;
    }

    // ---- generic fields

    row(parent, label, fieldKey) {
        const row = this.el('div', 'display-editor__field', null, parent);
        const id = `de-${fieldKey.replace(/[^a-z0-9_-]/gi, '-')}`;
        const labelNode = this.el('label', 'form-label small mb-1', label, row);
        labelNode.htmlFor = id;

        return { row, id };
    }

    section(parent, title) {
        const section = this.el('section', 'display-editor__section', null, parent);
        this.el('h3', 'display-editor__section-title', title, section);

        return section;
    }

    numberField(parent, { label, fieldKey, value, min, max, step = 1, optional = false, placeholder = null, onValue }) {
        const { row, id } = this.row(parent, label, fieldKey);
        const input = this.el('input', 'form-control form-control-sm', null, row);
        input.type = 'number';
        input.id = id;
        input.dataset.field = fieldKey;
        input.min = String(min);
        input.max = String(max);
        input.step = String(step);
        input.value = value === null || value === undefined ? '' : String(value);

        if (optional) {
            input.placeholder = this.t('default');
        }

        if (placeholder !== null) {
            input.placeholder = placeholder;
        }

        input.addEventListener('input', () => {
            if (input.value === '') {
                if (optional) {
                    onValue(null);
                }

                return;
            }

            const number = Number(input.value);

            if (Number.isFinite(number)) {
                onValue(number);
            }
        });

        return input;
    }

    selectField(parent, { label, fieldKey, value, options, optional = false, mixed = false, onValue }) {
        const { row, id } = this.row(parent, label, fieldKey);
        const select = this.el('select', 'form-select form-select-sm', null, row);
        select.id = id;
        select.dataset.field = fieldKey;

        if (mixed) {
            const option = this.el('option', null, this.t('mixed'), select);
            option.value = MIXED_OPTION;
            option.disabled = true;
        }

        if (optional) {
            const option = this.el('option', null, this.t('default'), select);
            option.value = '';
        }

        for (const item of options) {
            const option = this.el('option', null, this.t(`opt.${optionKey(item)}`), select);
            option.value = String(item);
            option.selected = String(item) === String(value);
        }

        if (optional && (value === null || value === undefined)) {
            select.value = '';
        }

        if (mixed) {
            select.value = MIXED_OPTION;
        }

        select.addEventListener('change', () => {
            if (select.value === '') {
                onValue(null);

                return;
            }

            const chosen = options.find((item) => String(item) === select.value);
            onValue(chosen === undefined ? null : chosen);
        });

        return select;
    }

    checkField(parent, { label, fieldKey, checked, indeterminate = false, onValue }) {
        const row = this.el('div', 'form-check display-editor__field', null, parent);
        const input = this.el('input', 'form-check-input', null, row);
        input.type = 'checkbox';
        input.id = `de-${fieldKey.replace(/[^a-z0-9_-]/gi, '-')}`;
        input.dataset.field = fieldKey;
        input.checked = checked === true;

        if (indeterminate) {
            input.indeterminate = true;
        }

        const labelNode = this.el('label', 'form-check-label small', label, row);
        labelNode.htmlFor = input.id;
        input.addEventListener('change', () => onValue(input.checked));

        return input;
    }

    subsetField(parent, { label, fieldKey, value, options, onValue }) {
        const group = this.el('fieldset', 'display-editor__field', null, parent);
        this.el('legend', 'form-label small mb-1', label, group);
        const chosen = new Set(Array.isArray(value) ? value : []);

        for (const item of options) {
            this.checkField(group, {
                label: this.t(`opt.${optionKey(item)}`),
                fieldKey: `${fieldKey}.${item}`,
                checked: chosen.has(item),
                onValue: (checked) => {
                    if (checked) {
                        chosen.add(item);
                    } else {
                        chosen.delete(item);
                    }

                    onValue(options.filter((option) => chosen.has(option)));
                },
            });
        }

        return group;
    }

    textField(parent, { label, fieldKey, value, max, multiline = false, onValue }) {
        const { row, id } = this.row(parent, label, fieldKey);
        const input = this.el(multiline ? 'textarea' : 'input', 'form-control form-control-sm', null, row);

        if (!multiline) {
            input.type = 'text';
        } else {
            input.rows = 3;
        }

        input.id = id;
        input.dataset.field = fieldKey;
        input.maxLength = max;
        input.value = value ?? '';
        input.addEventListener('input', () => onValue(input.value));

        return input;
    }

    colorField(parent, { label, fieldKey, value, nullable = true, placeholder = null, onValue }) {
        const { row, id } = this.row(parent, label, fieldKey);
        const group = this.el('div', 'input-group input-group-sm', null, row);
        const picker = this.el('input', 'form-control form-control-color display-editor__swatch', null, group);
        picker.type = 'color';
        picker.value = typeof value === 'string' && COLOR_PATTERN.test(value) ? value.slice(0, 7) : '#000000';
        picker.setAttribute('aria-label', label);
        const text = this.el('input', 'form-control', null, group);
        text.type = 'text';
        text.id = id;
        text.dataset.field = fieldKey;
        text.maxLength = 9;
        text.placeholder = placeholder ?? (nullable ? this.t('default') : '#rrggbb');
        text.value = value ?? '';
        text.spellcheck = false;

        picker.addEventListener('input', () => {
            // Keeps a chosen transparency (#rrggbbaa) when only the colour changes
            const alpha = COLOR_PATTERN.test(text.value) && text.value.length === 9 ? text.value.slice(7) : '';
            text.value = picker.value + alpha;
            onValue(text.value.toLowerCase());
        });
        text.addEventListener('input', () => {
            if (COLOR_PATTERN.test(text.value)) {
                picker.value = text.value.slice(0, 7);
                onValue(text.value.toLowerCase());
            } else if (text.value === '' && nullable) {
                onValue(null);
            }
        });

        if (nullable) {
            const clear = this.el('button', 'btn btn-outline-secondary', '×', group);
            clear.type = 'button';
            clear.title = this.t('reset');
            clear.setAttribute('aria-label', this.t('reset'));
            clear.addEventListener('click', () => {
                text.value = '';
                onValue(null, true);
            });
        }

        return text;
    }

    // ---- pictures

    imagePicker(parent, { label, fieldKey, value, onPick, allowNone = true }) {
        const wrap = this.el('div', 'display-editor__field', null, parent);
        this.el('div', 'form-label small mb-1', label, wrap);
        const list = this.el('div', 'display-editor__images', null, wrap);
        list.dataset.field = fieldKey;
        const images = this.getImages();

        for (const [path, url] of Object.entries(images)) {
            const button = this.el('button', `display-editor__image${path === value ? ' is-selected' : ''}`, null, list);
            button.type = 'button';
            button.dataset.imagePath = path;
            button.setAttribute('aria-pressed', path === value ? 'true' : 'false');
            const img = this.el('img', null, null, button);
            img.alt = '';
            img.loading = 'lazy';

            if (/^https?:\/\//i.test(url)) {
                img.src = url;
            }

            button.addEventListener('click', () => onPick(path));
        }

        if (Object.keys(images).length === 0) {
            this.el('div', 'small text-muted', this.t('no_images'), list);
        }

        const actions = this.el('div', 'd-flex flex-wrap gap-2 mt-1', null, wrap);

        if (this.uploadAvailable) {
            const uploadLabel = this.el('label', 'btn btn-sm btn-outline-primary mb-0', null, actions);
            this.el('i', 'bi bi-upload me-1', null, uploadLabel);
            this.el('span', null, this.t('upload'), uploadLabel);
            const input = this.el('input', 'visually-hidden', null, uploadLabel);
            input.type = 'file';
            input.accept = 'image/jpeg,image/png,image/webp,image/gif';
            input.dataset.uploadFor = fieldKey;
            input.addEventListener('change', async () => {
                const file = input.files?.[0];
                input.value = '';

                if (file) {
                    const path = await this.upload(file);

                    if (path !== null) {
                        onPick(path);
                    }
                }
            });
        }

        if (allowNone && value) {
            const none = this.el('button', 'btn btn-sm btn-outline-secondary', this.t('remove_image'), actions);
            none.type = 'button';
            none.addEventListener('click', () => onPick(null));
        }

        return wrap;
    }

    // ---- the panel

    render(container, editorState) {
        const focused = this.document.activeElement?.dataset?.field ?? null;

        while (container.firstChild) {
            container.firstChild.remove();
        }

        const element = editorState.selected;

        if (editorState.selectedIds.length > 1) {
            delete container.dataset.elementIndex;
            this.renderSelection(container, editorState);
        } else if (element === null) {
            this.renderCanvas(container, editorState.design);
        } else {
            this.renderElement(container, element, editorState.design.elements.indexOf(element));
        }

        if (focused !== null) {
            const again = container.querySelector(`[data-field="${focused.replace(/["\\]/g, '')}"]`);
            again?.focus();
        }
    }

    renderCanvas(container, design) {
        const t = this.t;
        const background = design.canvas.background;
        const canvas = this.section(container, t('canvas'));
        this.el('p', 'small text-muted', t(this.kind === 'overlay' ? 'canvas_help_overlay' : 'canvas_help_screen'), canvas);

        this.selectField(canvas, {
            label: t('background_type'),
            fieldKey: 'canvas.type',
            value: background.type,
            options: BACKGROUND_TYPES,
            onValue: (value) => this.commit((next) => {
                next.canvas.background.type = value;
            }, null, true),
        });

        if (background.type === 'color' || background.type === 'image') {
            this.colorField(canvas, {
                label: t('background_color'),
                fieldKey: 'canvas.color',
                value: background.color,
                nullable: false,
                onValue: (value) => this.commit((next) => {
                    next.canvas.background.color = value;
                }, 'canvas.color'),
            });
        }

        if (background.type === 'chroma') {
            this.el('p', 'small text-muted', t('chroma_help'), canvas);
            this.colorField(canvas, {
                label: t('chroma_color'),
                fieldKey: 'canvas.chroma',
                value: background.chroma,
                nullable: false,
                onValue: (value) => this.commit((next) => {
                    next.canvas.background.chroma = value;
                }, 'canvas.chroma'),
            });
        }

        if (background.type === 'image') {
            const image = background.image;
            this.imagePicker(canvas, {
                label: t('background_image'),
                fieldKey: 'canvas.image',
                value: image?.path ?? null,
                onPick: (path) => this.commit((next) => {
                    next.canvas.background.image = path === null ? null : { fit: 'cover', opacity: 1, ...(next.canvas.background.image ?? {}), path };
                }, null, true),
            });

            if (image) {
                this.selectField(canvas, {
                    label: t('prop.fit'),
                    fieldKey: 'canvas.image.fit',
                    value: image.fit,
                    options: BACKGROUND_FITS,
                    onValue: (value) => this.commit((next) => {
                        next.canvas.background.image.fit = value;
                    }),
                });
                this.numberField(canvas, {
                    label: t('style.opacity'),
                    fieldKey: 'canvas.image.opacity',
                    value: image.opacity,
                    min: 0,
                    max: 1,
                    step: 0.05,
                    onValue: (value) => this.commit((next) => {
                        next.canvas.background.image.opacity = value;
                    }, 'canvas.image.opacity'),
                });
            }
        }

        const theme = this.section(container, t('theme'));
        this.selectField(theme, {
            label: t('theme_font'),
            fieldKey: 'theme.font',
            value: design.theme.font,
            options: FONTS,
            onValue: (value) => this.commit((next) => {
                next.theme.font = value;
            }),
        });
        this.colorField(theme, {
            label: t('theme_color'),
            fieldKey: 'theme.color',
            value: design.theme.color,
            nullable: false,
            onValue: (value) => this.commit((next) => {
                next.theme.color = value;
            }, 'theme.color'),
        });
        this.colorField(theme, {
            label: t('theme_accent'),
            fieldKey: 'theme.accent',
            value: design.theme.accent,
            nullable: false,
            onValue: (value) => this.commit((next) => {
                next.theme.accent = value;
            }, 'theme.accent'),
        });
    }

    renderElement(container, element, index) {
        const t = this.t;
        const id = element.id;
        const edit = (change, key = null, rebuild = false) => this.commit((next) => {
            const target = next.elements.find((item) => item.id === id);

            if (target) {
                change(target);
            }
        }, key, rebuild);

        const head = this.el('div', 'display-editor__panel-head', null, container);
        this.el('div', 'fw-semibold', t(`type.${element.type}`), head);
        container.dataset.elementIndex = String(index);

        if (RESULTS_TYPES.includes(element.type) && element.props.source === 'live') {
            this.el('div', 'alert alert-danger py-1 px-2 small mb-2', t('live_warning'), container);
        }

        const general = this.section(container, t('section.general'));
        this.textField(general, {
            label: t('name'),
            fieldKey: `${id}.name`,
            value: element.name,
            max: NAME_MAX_LENGTH,
            onValue: (value) => edit((target) => {
                target.name = value;
            }, `${id}.name`),
        });

        const position = this.section(container, t('section.position'));
        const grid = this.el('div', 'display-editor__grid', null, position);

        for (const key of ['x', 'y', 'w', 'h', 'rotate']) {
            const [min, max] = FRAME_LIMITS[key];
            this.numberField(grid, {
                label: t(`frame.${key}`),
                fieldKey: `${id}.frame.${key}`,
                value: element.frame[key],
                min,
                max,
                step: key === 'rotate' ? 1 : 0.1,
                onValue: (value) => edit((target) => {
                    target.frame[key] = value;
                }, `${id}.frame.${key}`),
            });
        }

        // One element aligns to the edges and centre of the display
        this.alignButtons(position, element.locked);
        this.el('div', 'form-text mt-0 mb-2', t('align_canvas_help'), position);

        const visibility = this.section(container, t('section.visibility'));
        this.subsetField(visibility, {
            label: t('when.phases'),
            fieldKey: `${id}.when.phases`,
            value: element.when.phases,
            options: PHASES,
            onValue: (value) => edit((target) => {
                target.when.phases = value;
            }),
        });
        this.selectField(visibility, {
            label: t('when.results'),
            fieldKey: `${id}.when.results`,
            value: element.when.results,
            options: WHEN_RESULTS,
            onValue: (value) => edit((target) => {
                target.when.results = value;
            }),
        });
        this.selectField(visibility, {
            label: t('when.puzzle'),
            fieldKey: `${id}.when.puzzle`,
            value: element.when.puzzle,
            options: WHEN_PUZZLE,
            onValue: (value) => edit((target) => {
                target.when.puzzle = value;
            }),
        });

        const props = PROP_SPECS[element.type] ?? [];

        if (props.length > 0) {
            const section = this.section(container, t('section.content'));
            this.propFields(section, element, props, element.props, [], edit);
        }

        const style = this.section(container, t('section.style'));
        this.styleFields(style, element.style, new Set(), edit, id);
    }

    alignButtons(parent, disabled = false) {
        const row = this.el('div', 'display-editor__arrange display-editor__align btn-group btn-group-sm mb-1', null, parent);
        row.setAttribute('role', 'group');

        for (const edge of ALIGN_EDGES) {
            this.iconButton(row, ALIGN_ICONS[edge], this.t(`align.${edge}`), disabled, () => this.act('align', edge)).dataset.align = edge;
        }

        return row;
    }

    iconButton(parent, icon, label, disabled, run) {
        const button = this.el('button', 'btn btn-outline-secondary', null, parent);
        button.type = 'button';
        button.title = label;
        button.setAttribute('aria-label', label);
        button.disabled = disabled;
        this.el('i', `bi ${icon}`, null, button);
        button.addEventListener('click', run);

        return button;
    }

    /**
     * More than one element selected: the count, Arrange (align ×6 to the selection's bounds, distribute ×2), the
     * position and style fields with the shared value or "Mixed", hide/lock/duplicate/delete for all.
     */
    renderSelection(container, editorState) {
        const t = this.t;
        const selected = editorState.selectedElements();
        const movable = selected.filter((element) => !element.locked);

        const head = this.el('div', 'display-editor__panel-head', null, container);
        this.el('div', 'fw-semibold', t('selected_count').replace('%count%', String(selected.length)), head);
        head.dataset.selectedCount = String(selected.length);

        const arrange = this.section(container, t('section.arrange'));
        this.alignButtons(arrange, movable.length < 2);
        const distribute = this.el('div', 'display-editor__arrange btn-group btn-group-sm mb-1 ms-1', null, arrange);
        distribute.setAttribute('role', 'group');

        for (const axis of ['horizontal', 'vertical']) {
            const button = this.iconButton(distribute, `bi-distribute-${axis}`, t(`distribute.${axis}`), movable.length < 3, () => this.act('distribute', axis));
            button.dataset.distribute = axis;

            if (movable.length < 3) {
                button.title = `${t(`distribute.${axis}`)} - ${t('distribute_help')}`;
            }
        }

        if (movable.length < 3) {
            this.el('div', 'form-text mt-0', t('distribute_help'), arrange);
        }

        if (movable.length < selected.length) {
            this.el('div', 'form-text mt-0', t('locked_skipped'), arrange);
        }

        if (movable.length > 0) {
            const position = this.section(container, t('section.position'));
            const grid = this.el('div', 'display-editor__grid', null, position);

            for (const key of FRAME_KEYS) {
                const [min, max] = FRAME_LIMITS[key];
                const values = movable.map((element) => element.frame[key]);
                const shared = sameJson(values);
                this.numberField(grid, {
                    label: t(`frame.${key}`),
                    fieldKey: `sel.frame.${key}`,
                    value: shared ? values[0] : null,
                    min,
                    max,
                    step: key === 'rotate' ? 1 : 0.1,
                    placeholder: shared ? null : t('mixed'),
                    onValue: (value) => this.act('frame', { key, value }),
                });
            }
        }

        const style = this.section(container, t('section.style'));
        const values = {};
        const mixed = new Set();

        for (const spec of STYLE_SPEC) {
            const all = selected.map((element) => element.style[spec.key] ?? null);

            if (sameJson(all)) {
                if (all[0] !== null) {
                    values[spec.key] = all[0];
                }
            } else {
                mixed.add(spec.key);
            }
        }

        const edit = (change, key = null, rebuild = false) => this.act('edit', { change, key, rebuild });
        this.styleFields(style, values, mixed, edit, 'sel');

        const actions = this.section(container, t('section.selection'));
        const row = this.el('div', 'd-flex flex-wrap gap-2', null, actions);
        const anyVisible = selected.some((element) => !element.hidden);
        const anyUnlocked = selected.some((element) => !element.locked);
        const action = (label, icon, name) => {
            const button = this.el('button', 'btn btn-sm btn-outline-secondary', null, row);
            button.type = 'button';
            button.dataset.selectionAction = name;
            this.el('i', `bi ${icon} me-1`, null, button);
            this.el('span', null, label, button);
            button.addEventListener('click', () => this.act(name));

            return button;
        };
        action(t(anyVisible ? 'hide_all' : 'show_all'), anyVisible ? 'bi-eye-slash' : 'bi-eye', 'hidden');
        action(t(anyUnlocked ? 'lock_all' : 'unlock_all'), anyUnlocked ? 'bi-lock' : 'bi-unlock', 'locked');
        action(t('duplicate'), 'bi-copy', 'duplicate');
        action(t('delete'), 'bi-trash', 'remove').classList.replace('btn-outline-secondary', 'btn-outline-danger');
    }

    propLabel(type, key) {
        const specific = this.t(`prop.${type}.${key}`, '');

        return specific !== '' ? specific : this.t(`prop.${key}`);
    }

    propFields(parent, element, specs, values, path, edit) {
        const id = element.id;

        for (const spec of specs) {
            if (spec.onlyWhen && values[spec.onlyWhen.key] !== spec.onlyWhen.value) {
                continue;
            }

            const fieldPath = [...path, spec.key];
            const fieldKey = `${id}.props.${fieldPath.join('.')}`;
            const label = this.propLabel(element.type, fieldPath.join('_'));
            const value = values[spec.key];
            const set = (newValue, key = null, rebuild = false) => edit((target) => {
                let holder = target.props;

                for (const part of path) {
                    holder[part] = holder[part] ?? {};
                    holder = holder[part];
                }

                holder[spec.key] = newValue;
            }, key, rebuild);

            switch (spec.kind) {
                case 'enum':
                    this.selectField(parent, { label, fieldKey, value, options: spec.options, onValue: (v) => set(v, null, true) });

                    if (spec.key === 'source' && RESULTS_TYPES.includes(element.type)) {
                        this.el('div', 'form-text mt-0 mb-2', this.t(value === 'live' ? 'source_live_help' : 'source_published_help'), parent);
                    }

                    if (spec.key === 'category' && element.type === 'bracket') {
                        this.el('div', 'form-text mt-0 mb-2', this.t('prop.category_help'), parent);
                    }
                    break;
                case 'int':
                    this.numberField(parent, { label, fieldKey, value, min: spec.min, max: spec.max, optional: spec.optional === true, onValue: (v) => set(v, fieldKey) });
                    break;
                case 'bool':
                    this.checkField(parent, { label, fieldKey, checked: value, onValue: (v) => set(v) });
                    break;
                case 'color':
                    this.colorField(parent, {
                        label,
                        fieldKey,
                        value,
                        nullable: spec.default === null,
                        onValue: (v, rebuild = false) => set(v, rebuild ? null : fieldKey, rebuild),
                    });
                    break;
                case 'text':
                    this.textField(parent, { label, fieldKey, value, max: spec.max, multiline: spec.multiline, onValue: (v) => set(v, fieldKey) });
                    break;
                case 'url':
                    this.textField(parent, { label, fieldKey, value, max: spec.max, onValue: (v) => set(v, fieldKey) });
                    this.el('div', 'form-text mt-0 mb-2', this.t('url_help'), parent);
                    break;
                case 'subset':
                    this.subsetField(parent, { label, fieldKey, value, options: spec.options, onValue: (v) => set(v) });
                    break;
                case 'colorMap': {
                    const group = this.el('fieldset', 'display-editor__field', null, parent);
                    this.el('legend', 'form-label small mb-1', label, group);

                    for (const key of spec.keys) {
                        this.colorField(group, {
                            label: this.t(`opt.${optionKey(key)}`),
                            fieldKey: `${fieldKey}.${key}`,
                            value: value?.[key] ?? null,
                            onValue: (v, rebuild = false) => edit((target) => {
                                target.props[spec.key] = { ...(target.props[spec.key] ?? {}), [key]: v };
                            }, rebuild ? null : `${fieldKey}.${key}`, rebuild),
                        });
                    }
                    break;
                }
                case 'textMap': {
                    const group = this.el('fieldset', 'display-editor__field', null, parent);
                    this.el('legend', 'form-label small mb-1', label, group);
                    this.el('div', 'form-text mt-0 mb-1', this.t('status_texts_help'), group);

                    for (const key of spec.keys) {
                        this.textField(group, {
                            label: this.t(`opt.${optionKey(key)}`),
                            fieldKey: `${fieldKey}.${key}`,
                            value: value?.[key] ?? '',
                            max: spec.max,
                            onValue: (v) => edit((target) => {
                                target.props[spec.key] = { ...(target.props[spec.key] ?? {}), [key]: v === '' ? null : v };
                            }, `${fieldKey}.${key}`),
                        });
                    }
                    break;
                }
                case 'object': {
                    const group = this.el('fieldset', 'display-editor__field', null, parent);
                    this.el('legend', 'form-label small mb-1', label, group);
                    this.propFields(group, element, spec.fields, value ?? {}, fieldPath, edit);
                    break;
                }
                case 'path':
                    this.imagePicker(parent, { label, fieldKey, value, onPick: (path) => set(path, null, true) });
                    break;
                case 'images':
                    this.rotatorImages(parent, { label, fieldKey, value: value ?? [], set });
                    break;
                case 'standing':
                    this.standingField(parent, { label, fieldKey, value, onValue: (v) => set(v, null, true) });
                    break;
                case 'division':
                    this.divisionField(parent, { label, fieldKey, value, challenge: values.challenge, onValue: (v) => set(v, null, true) });
                    break;
                case 'challenge':
                    this.challengeField(parent, { label, fieldKey, value, onValue: (v) => set(v, null, true) });
                    break;
                default:
                    break;
            }
        }
    }

    // `auto` or one of the event's standings; an unpublished one is marked - the display shows nothing for it
    standingField(parent, { label, fieldKey, value, onValue }) {
        const { row, id } = this.row(parent, label, fieldKey);
        const select = this.el('select', 'form-select form-select-sm', null, row);
        select.id = id;
        select.dataset.field = fieldKey;
        const options = [{ value: 'auto', text: this.t('prop.standing_auto') }];

        for (const standing of this.standings) {
            if (typeof standing?.id !== 'string' || typeof standing.name !== 'string') {
                continue;
            }

            options.push({
                value: standing.id,
                text: standing.published === true ? standing.name : `${standing.name} - ${this.t('prop.standing_unpublished')}`,
            });
        }

        // A chosen standing deleted meanwhile still shows as chosen (the display shows nothing for it)
        if (value !== 'auto' && !options.some((option) => option.value === value)) {
            options.push({ value, text: `? - ${this.t('prop.standing_unpublished')}` });
        }

        for (const item of options) {
            const option = this.el('option', null, item.text, select);
            option.value = item.value;
            option.selected = item.value === value;
        }

        select.addEventListener('change', () => onValue(select.value));

        const chosen = this.standings.find((standing) => standing?.id === value);

        if (value !== 'auto' && chosen?.published !== true) {
            this.el('div', 'form-text mt-0 mb-2 text-warning', this.t('prop.standing_unpublished'), parent);
        } else {
            this.el('div', 'form-text mt-0 mb-2', this.t(value === 'auto' ? 'prop.standing_auto_help' : 'prop.standing_help'), parent);
        }

        return select;
    }

    // A select over `options` [{value, text}] with a first "none" option ('' = the prop removed) - the views' fields
    viewSelect(parent, { label, fieldKey, value, noneText, options, onValue }) {
        const { row, id } = this.row(parent, label, fieldKey);
        const select = this.el('select', 'form-select form-select-sm', null, row);
        select.id = id;
        select.dataset.field = fieldKey;
        const all = [{ value: '', text: noneText }, ...options];

        // A chosen value the event no longer has (a deleted division, a block made smaller) still shows as chosen
        if (value !== null && value !== undefined && !all.some((item) => item.value === String(value))) {
            all.push({ value: String(value), text: `? (${value})` });
        }

        for (const item of all) {
            const option = this.el('option', null, item.text, select);
            option.value = item.value;
            option.selected = item.value === (value === null || value === undefined ? '' : String(value));
        }

        select.addEventListener('change', () => onValue(select.value));

        return select;
    }

    // Real events L2b: one division's entries with their division rank - only when the event has divisions
    divisionField(parent, { label, fieldKey, value, challenge, onValue }) {
        if (this.divisions.length === 0 && (value === null || value === undefined)) {
            return null;
        }

        const options = this.divisions
            .filter((division) => typeof division?.id === 'string' && typeof division.name === 'string')
            .map((division) => ({ value: division.id, text: division.name }));
        const select = this.viewSelect(parent, {
            label, fieldKey, value, noneText: this.t('prop.division_all'), options, onValue: (v) => onValue(v === '' ? null : v),
        });
        const help = typeof challenge === 'number' && value !== null && value !== undefined ? 'prop.division_with_challenge' : 'prop.division_help';
        this.el('div', 'form-text mt-0 mb-2', this.t(help), parent);

        return select;
    }

    // Real events L2b: one puzzle of a solve-all block ranked by its own time - only when the event has a block
    challengeField(parent, { label, fieldKey, value, onValue }) {
        if (this.challenges.length === 0 && (value === null || value === undefined)) {
            return null;
        }

        const options = this.challenges
            .filter((challenge) => Number.isInteger(challenge?.position))
            .map((challenge) => {
                const labels = (Array.isArray(challenge.labels) ? challenge.labels : []).filter((item) => typeof item === 'string');
                const name = this.t('prop.challenge_puzzle').split('%position%').join(String(challenge.position));

                return { value: String(challenge.position), text: labels.length > 0 ? `${name} - ${labels.join(' / ')}` : name };
            });
        const select = this.viewSelect(parent, {
            label, fieldKey, value, noneText: this.t('prop.challenge_round'), options, onValue: (v) => onValue(v === '' ? null : Number(v)),
        });
        this.el('div', 'form-text mt-0 mb-2', this.t('prop.challenge_help'), parent);

        return select;
    }

    rotatorImages(parent, { label, fieldKey, value, set }) {
        const wrap = this.el('div', 'display-editor__field', null, parent);
        this.el('div', 'form-label small mb-1', label, wrap);
        const list = this.el('ol', 'display-editor__rotator', null, wrap);
        const images = this.getImages();

        value.forEach((item, index) => {
            const li = this.el('li', 'display-editor__rotator-item', null, list);
            const img = this.el('img', null, null, li);
            img.alt = '';
            const url = images[item.path];

            if (typeof url === 'string' && /^https?:\/\//i.test(url)) {
                img.src = url;
            }

            const remove = this.el('button', 'btn btn-sm btn-link text-danger p-0 ms-auto', this.t('remove_image'), li);
            remove.type = 'button';
            remove.addEventListener('click', () => set(value.filter((_, at) => at !== index), null, true));
        });

        this.imagePicker(wrap, {
            label: this.t('add_image'),
            fieldKey: `${fieldKey}.add`,
            value: null,
            allowNone: false,
            onPick: (path) => {
                if (path !== null) {
                    set([...value, { path }], null, true);
                }
            },
        });
    }

    /**
     * The style fields over `style` (values by key); `mixed` = keys whose values differ across a multi-selection (shown
     * empty with "Mixed"), `fieldPrefix` = the element id (or `sel`) of the fields' keys.
     */
    styleFields(parent, style, mixed, edit, fieldPrefix) {
        const mixedText = this.t('mixed');
        const setStyle = (key, value, coalesce = null, rebuild = false) => edit((target) => {
            if (value === null) {
                delete target.style[key];
            } else {
                target.style[key] = value;
            }
        }, coalesce, rebuild);

        for (const spec of STYLE_SPEC) {
            const label = this.t(`style.${spec.key}`);
            const fieldKey = `${fieldPrefix}.style.${spec.key}`;
            const value = style[spec.key] ?? null;
            const isMixed = mixed.has(spec.key);
            const placeholder = isMixed ? mixedText : null;

            switch (spec.kind) {
                case 'enum':
                case 'weight':
                    this.selectField(parent, { label, fieldKey, value, options: spec.options, optional: true, mixed: isMixed, onValue: (v) => setStyle(spec.key, v) });
                    break;
                case 'int':
                    this.numberField(parent, { label, fieldKey, value, min: spec.min, max: spec.max, optional: true, placeholder, onValue: (v) => setStyle(spec.key, v, fieldKey) });
                    break;
                case 'decimal':
                    this.numberField(parent, {
                        label, fieldKey, value, min: spec.min, max: spec.max, step: spec.max <= 2 ? 0.05 : 0.5, optional: true, placeholder,
                        onValue: (v) => setStyle(spec.key, v, fieldKey),
                    });
                    break;
                case 'color':
                    this.colorField(parent, { label, fieldKey, value, placeholder, onValue: (v, rebuild = false) => setStyle(spec.key, v, rebuild ? null : fieldKey, rebuild) });
                    break;
                case 'bool':
                    this.checkField(parent, { label, fieldKey, checked: value === true, indeterminate: isMixed, onValue: (v) => setStyle(spec.key, v ? true : null) });
                    break;
                case 'gradient': {
                    const group = this.el('fieldset', 'display-editor__field', null, parent);
                    this.el('legend', 'form-label small mb-1', label, group);
                    const current = value ?? { from: null, to: null, angle: spec.angle[2] };
                    const setPart = (part, v, rebuild = false) => {
                        const next = { ...current, [part]: v };
                        // Kept only with both colours (the normaliser drops a half gradient)
                        setStyle('gradient', next.from && next.to ? next : null, rebuild ? null : `${fieldKey}.${part}`, rebuild);
                        current[part] = v;
                    };
                    this.colorField(group, { label: this.t('style.gradient_from'), fieldKey: `${fieldKey}.from`, value: current.from, onValue: (v, r) => setPart('from', v, r) });
                    this.colorField(group, { label: this.t('style.gradient_to'), fieldKey: `${fieldKey}.to`, value: current.to, onValue: (v, r) => setPart('to', v, r) });
                    this.numberField(group, {
                        label: this.t('style.gradient_angle'), fieldKey: `${fieldKey}.angle`, value: current.angle, min: spec.angle[0], max: spec.angle[1],
                        onValue: (v) => setPart('angle', v),
                    });
                    break;
                }
                case 'border': {
                    const group = this.el('fieldset', 'display-editor__field', null, parent);
                    this.el('legend', 'form-label small mb-1', label, group);
                    const current = value ?? { width: spec.width[2], color: null };
                    const setPart = (part, v, rebuild = false) => {
                        const next = { ...current, [part]: v };
                        setStyle('border', next.color ? next : null, rebuild ? null : `${fieldKey}.${part}`, rebuild);
                        current[part] = v;
                    };
                    this.colorField(group, { label: this.t('style.border_color'), fieldKey: `${fieldKey}.color`, value: current.color, onValue: (v, r) => setPart('color', v, r) });
                    this.numberField(group, {
                        label: this.t('style.border_width'), fieldKey: `${fieldKey}.width`, value: current.width, min: spec.width[0], max: spec.width[1],
                        onValue: (v) => setPart('width', v),
                    });
                    break;
                }
                default:
                    break;
            }
        }
    }

    // ---- layers and the add menu

    renderCatalogue(select) {
        while (select.firstChild) {
            select.firstChild.remove();
        }

        for (const { group, types } of CATALOGUE) {
            const optgroup = this.el('optgroup', null, null, select);
            optgroup.label = this.t(`group.${group}`);

            for (const type of types) {
                const option = this.el('option', null, this.t(`type.${type}`), optgroup);
                option.value = type;
            }
        }
    }

    renderLayers(list, editorState, actions) {
        while (list.firstChild) {
            list.firstChild.remove();
        }

        const layers = editorState.layers();

        if (layers.length === 0) {
            this.el('li', 'small text-muted py-2', this.t('no_elements'), list);

            return;
        }

        layers.forEach((element, position) => {
            const selected = editorState.isSelected(element.id);
            const li = this.el('li', `display-editor__layer${selected ? ' is-selected' : ''}${element.hidden ? ' is-hidden' : ''}`, null, list);
            li.dataset.layer = element.id;

            const row = this.el('div', 'display-editor__layer-row', null, li);
            const { title, detail } = layerLabel(element, this.t);
            const name = this.el('button', 'display-editor__layer-name', null, row);
            name.type = 'button';
            name.title = detail !== '' ? `${title} - ${detail}` : title;
            name.setAttribute('aria-pressed', selected ? 'true' : 'false');
            const titleLine = this.el('span', 'display-editor__layer-title', null, name);
            this.el('span', 'display-editor__layer-text', title, titleLine);

            if (RESULTS_TYPES.includes(element.type) && element.props.source === 'live') {
                const chip = this.el('span', 'badge bg-danger flex-shrink-0', this.t('live_badge'), titleLine);
                chip.title = this.t('live_warning');
            }

            if (detail !== '') {
                this.el('span', 'display-editor__layer-detail', detail, name);
            }

            // The click carries Shift/Ctrl/Cmd: the controller adds or removes the layer then
            name.addEventListener('click', (event) => actions.select(element.id, event));

            const button = (parent, icon, label, action, disabled = false) => {
                const node = this.el('button', 'btn btn-sm btn-link p-0 display-editor__layer-action', null, parent);
                node.type = 'button';
                node.title = label;
                node.setAttribute('aria-label', label);
                node.disabled = disabled;
                node.dataset.layerAction = action;
                this.el('i', `bi ${icon}`, null, node);
                node.addEventListener('click', () => actions[action](element.id));
            };

            // Visibility and lock are states worth seeing on every row; the rest acts on the selected layer only
            button(row, element.hidden ? 'bi-eye-slash' : 'bi-eye', this.t(element.hidden ? 'show' : 'hide'), 'toggleHidden');
            button(row, element.locked ? 'bi-lock-fill' : 'bi-unlock', this.t(element.locked ? 'unlock' : 'lock'), 'toggleLocked');

            if (element.id === editorState.selectedId) {
                const tools = this.el('div', 'display-editor__layer-tools', null, li);
                button(tools, 'bi-arrow-up', this.t('layer_up'), 'up', position === 0);
                button(tools, 'bi-arrow-down', this.t('layer_down'), 'down', position === layers.length - 1);
                button(tools, 'bi-copy', this.t('duplicate'), 'duplicate');
                button(tools, 'bi-trash', this.t('delete'), 'remove');
            }
        });
    }
}
