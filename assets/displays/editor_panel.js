// The display editor's property panel and layers list (docs/features/competitions-management/toolkit/D-displays-overlays.md
// §8.2, competition toolkit D6). Every field comes from schema.js (STYLE_SPEC, PROP_SPECS, FRAME_LIMITS ...) - the
// normaliser decides what is kept, this file only offers it. DOM by createElement + textContent only.
//
// The panel never writes the design itself: it hands a change function to `commit(change, key, rebuild)`, where
// change(design) edits a copy, `key` makes typing in one field one undo step, `rebuild` re-draws the panel (choices that
// show or hide other fields).

import {
    BACKGROUND_FITS, BACKGROUND_TYPES, FONTS, FRAME_LIMITS, NAME_MAX_LENGTH, PHASES, PROP_SPECS, RESULTS_TYPES,
    STYLE_SPEC, WHEN_PUZZLE, WHEN_RESULTS,
} from './schema.js';
import { CATALOGUE } from './editor_state.js';

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

export class EditorPanel {
    constructor({ document, t, commit, getImages, upload, uploadAvailable, kind }) {
        this.document = document;
        this.t = t;
        this.commit = commit;
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

    numberField(parent, { label, fieldKey, value, min, max, step = 1, optional = false, onValue }) {
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

    selectField(parent, { label, fieldKey, value, options, optional = false, onValue }) {
        const { row, id } = this.row(parent, label, fieldKey);
        const select = this.el('select', 'form-select form-select-sm', null, row);
        select.id = id;
        select.dataset.field = fieldKey;

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

    checkField(parent, { label, fieldKey, checked, onValue }) {
        const row = this.el('div', 'form-check display-editor__field', null, parent);
        const input = this.el('input', 'form-check-input', null, row);
        input.type = 'checkbox';
        input.id = `de-${fieldKey.replace(/[^a-z0-9_-]/gi, '-')}`;
        input.dataset.field = fieldKey;
        input.checked = checked === true;
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

    colorField(parent, { label, fieldKey, value, nullable = true, onValue }) {
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
        text.placeholder = nullable ? this.t('default') : '#rrggbb';
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

        if (element === null) {
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
        this.styleFields(style, element, edit);
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
                    break;
                case 'int':
                    this.numberField(parent, { label, fieldKey, value, min: spec.min, max: spec.max, onValue: (v) => set(v, fieldKey) });
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
                default:
                    break;
            }
        }
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

    styleFields(parent, element, edit) {
        const id = element.id;
        const style = element.style;
        const setStyle = (key, value, coalesce = null, rebuild = false) => edit((target) => {
            if (value === null) {
                delete target.style[key];
            } else {
                target.style[key] = value;
            }
        }, coalesce, rebuild);

        for (const spec of STYLE_SPEC) {
            const label = this.t(`style.${spec.key}`);
            const fieldKey = `${id}.style.${spec.key}`;
            const value = style[spec.key] ?? null;

            switch (spec.kind) {
                case 'enum':
                case 'weight':
                    this.selectField(parent, { label, fieldKey, value, options: spec.options, optional: true, onValue: (v) => setStyle(spec.key, v) });
                    break;
                case 'int':
                    this.numberField(parent, { label, fieldKey, value, min: spec.min, max: spec.max, optional: true, onValue: (v) => setStyle(spec.key, v, fieldKey) });
                    break;
                case 'decimal':
                    this.numberField(parent, {
                        label, fieldKey, value, min: spec.min, max: spec.max, step: spec.max <= 2 ? 0.05 : 0.5, optional: true,
                        onValue: (v) => setStyle(spec.key, v, fieldKey),
                    });
                    break;
                case 'color':
                    this.colorField(parent, { label, fieldKey, value, onValue: (v, rebuild = false) => setStyle(spec.key, v, rebuild ? null : fieldKey, rebuild) });
                    break;
                case 'bool':
                    this.checkField(parent, { label, fieldKey, checked: value === true, onValue: (v) => setStyle(spec.key, v ? true : null) });
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
            const selected = element.id === editorState.selectedId;
            const li = this.el('li', `display-editor__layer${selected ? ' is-selected' : ''}${element.hidden ? ' is-hidden' : ''}`, null, list);
            li.dataset.layer = element.id;

            const name = this.el('button', 'display-editor__layer-name', null, li);
            name.type = 'button';
            name.setAttribute('aria-pressed', selected ? 'true' : 'false');
            this.el('span', null, element.name !== '' ? element.name : this.t(`type.${element.type}`), name);
            name.addEventListener('click', () => actions.select(element.id));

            if (RESULTS_TYPES.includes(element.type) && element.props.source === 'live') {
                const chip = this.el('span', 'badge bg-danger ms-1', this.t('live_badge'), name);
                chip.title = this.t('live_warning');
            }

            const button = (icon, label, action, disabled = false) => {
                const node = this.el('button', 'btn btn-sm btn-link p-0 display-editor__layer-action', null, li);
                node.type = 'button';
                node.title = label;
                node.setAttribute('aria-label', label);
                node.disabled = disabled;
                node.dataset.layerAction = action;
                this.el('i', `bi ${icon}`, null, node);
                node.addEventListener('click', () => actions[action](element.id));
            };

            button(element.hidden ? 'bi-eye-slash' : 'bi-eye', this.t(element.hidden ? 'show' : 'hide'), 'toggleHidden');
            button(element.locked ? 'bi-lock-fill' : 'bi-unlock', this.t(element.locked ? 'unlock' : 'lock'), 'toggleLocked');
            button('bi-arrow-up', this.t('layer_up'), 'up', position === 0);
            button('bi-arrow-down', this.t('layer_down'), 'down', position === layers.length - 1);
            button('bi-copy', this.t('duplicate'), 'duplicate');
            button('bi-trash', this.t('delete'), 'remove');
        });
    }
}
