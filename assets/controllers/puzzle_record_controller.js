import { Controller } from '@hotwired/stimulus';
import { clearPhoto, hasPhoto } from '../photo_drop_area.js';

// A puzzle's record in the moderators' forms - the review of a change request and the direct edit: every field is
// editable, and each is marked by what saving does with it - the player's proposal, the moderator's own edit, or the
// current value kept although something was proposed - and the summary above the save button lists it, so an edit
// is never mistaken for the proposal. A field carries data-current (and data-proposed when the player proposed it)
// as raw form values; the direct edit has no proposal, so only edits are marked there.
// The image field's value is "keep" / "proposed" (radios) or "upload" - a photo in its drop area (chosen, or kept
// from a refused submit) is used instead of either, and picking keep / proposed again drops that photo.
// The names card (data-names, admin/_puzzle_record_names.html.twig) holds the names editor: its value is every name with
// its language in order, data-current / data-proposed hold the names as JSON ({name, nameLanguage, alternativeNames}).
// A code card (data-codes - the EANs, the brand codes) has one input per code (optional_rows_controller.js): its value
// is the codes typed, in order, data-current / data-proposed hold them as a JSON list.
export default class extends Controller {
    static targets = ['field', 'summary'];
    static values = {
        proposedLabel: String,
        editLabel: String,
        keptLabel: String,
        changesHeading: String,
        keptHeading: String,
        noChanges: String,
    };

    // Proposed = orange, the moderator's edit = indigo (the theme's primary is too close to orange to tell apart)
    static badgeClasses = {
        proposed: ['bg-warning', 'text-dark'],
        edit: ['bg-accent'],
        kept: ['bg-secondary', 'text-dark'],
    };

    static borderClasses = {
        proposed: 'border-warning',
        edit: 'border-accent',
    };

    connect() {
        this.update();
    }

    update() {
        const changes = [];
        const kept = [];

        this.fieldTargets.forEach((field) => {
            const state = this.stateOf(field);
            this.mark(field, state);

            if (state === 'proposed' || state === 'edit') {
                changes.push({ label: field.dataset.label, state });
            } else if (state === 'kept') {
                kept.push(field.dataset.label);
            }
        });

        this.renderSummary(changes, kept);
    }

    useProposed(event) {
        const field = this.fieldOf(event.target);
        this.setValue(field, field.dataset.proposed);
        this.update();
    }

    useCurrent(event) {
        const field = this.fieldOf(event.target);
        this.setValue(field, field.dataset.current);
        this.update();
    }

    // Picking keep / proposed is a choice against the uploaded photo
    imageChosen(event) {
        this.clearUpload(this.fieldOf(event.target));
        this.update();
    }

    // proposed: the proposal goes in · edit: the moderator's own value · kept: proposed, but the current value stays
    stateOf(field) {
        const value = this.valueOf(field);
        const current = this.currentOf(field);

        if ('proposed' in field.dataset && value === this.proposedOf(field)) {
            return value === current ? 'unchanged' : 'proposed';
        }

        if (value === current) {
            return 'proposed' in field.dataset ? 'kept' : 'unchanged';
        }

        return 'edit';
    }

    mark(field, state) {
        const badge = field.querySelector('[data-role="badge"]');
        const labels = { proposed: this.proposedLabelValue, edit: this.editLabelValue, kept: this.keptLabelValue };

        badge.classList.remove(...Object.values(this.constructor.badgeClasses).flat());
        badge.classList.toggle('d-none', state === 'unchanged');
        badge.textContent = labels[state] || '';

        if (state !== 'unchanged') {
            badge.classList.add(...this.constructor.badgeClasses[state]);
        }

        field.classList.remove('border-2', ...Object.values(this.constructor.borderClasses));

        if (this.constructor.borderClasses[state]) {
            field.classList.add('border-2', this.constructor.borderClasses[state]);
        }

        const value = this.valueOf(field);

        // A proposal that is not going in is struck through
        field.querySelectorAll('[data-role~="proposed-value"]').forEach((element) => {
            element.classList.toggle('text-decoration-line-through', state === 'kept');
            element.classList.toggle('opacity-50', state === 'kept');
        });

        // An uploaded photo is used instead of the image options
        field.querySelectorAll('[data-role~="image-option"]').forEach((element) => {
            element.classList.toggle('opacity-50', value === 'upload');
        });

        this.toggleRole(field, 'use-proposed', 'proposed' in field.dataset && value !== this.proposedOf(field));
        this.toggleRole(field, 'use-current', value !== this.currentOf(field));
        this.toggleRole(field, 'current-hint', state === 'edit');
    }

    renderSummary(changes, kept) {
        if (!this.hasSummaryTarget) {
            return;
        }

        const summary = this.summaryTarget;
        summary.replaceChildren();

        if (changes.length === 0) {
            const nothing = document.createElement('p');
            nothing.className = 'text-muted mb-0';
            nothing.textContent = this.noChangesValue;
            summary.append(nothing);
        } else {
            const heading = document.createElement('div');
            heading.className = 'fw-semibold mb-1';
            heading.textContent = this.changesHeadingValue;

            const list = document.createElement('ul');
            list.className = 'list-unstyled d-flex flex-wrap gap-2 mb-0';

            changes.forEach(({ label, state }) => {
                const item = document.createElement('li');
                item.className = 'd-flex align-items-center gap-1 border rounded px-2 py-1';
                item.append(label);

                const badge = document.createElement('span');
                badge.className = ['badge', ...this.constructor.badgeClasses[state]].join(' ');
                badge.textContent = state === 'proposed' ? this.proposedLabelValue : this.editLabelValue;
                item.append(badge);

                list.append(item);
            });

            summary.append(heading, list);
        }

        if (kept.length > 0) {
            const keptLine = document.createElement('p');
            keptLine.className = 'text-muted mt-2 mb-0';
            keptLine.textContent = `${this.keptHeadingValue} ${kept.join(', ')}`;
            summary.append(keptLine);
        }
    }

    valueOf(field) {
        if (this.isNames(field)) {
            return this.namesKey(this.namesEditorState(field));
        }

        if (this.isCodes(field)) {
            return this.codesKey(Array.from(field.querySelectorAll('[data-optional-rows-target="row"] input')).map((input) => input.value));
        }

        if (this.uploadOf(field)) {
            return this.hasUpload(field) ? 'upload' : this.checkedImage(field);
        }

        return (this.inputOf(field).value || '').trim();
    }

    currentOf(field) {
        return this.comparable(field, field.dataset.current);
    }

    proposedOf(field) {
        return this.comparable(field, field.dataset.proposed);
    }

    comparable(field, value) {
        if (this.isCodes(field)) {
            return this.codesKey(JSON.parse(value || '[]'));
        }

        return this.isNames(field) ? this.namesKey(JSON.parse(value || '{}')) : (value || '').trim();
    }

    setValue(field, value) {
        if (this.isNames(field)) {
            const editor = field.querySelector('[data-controller~="names-editor"]');
            this.application.getControllerForElementAndIdentifier(editor, 'names-editor')?.load(JSON.parse(value || '{}'));
            return;
        }

        if (this.isCodes(field)) {
            this.application.getControllerForElementAndIdentifier(field, 'optional-rows')?.load(JSON.parse(value || '[]'));
            return;
        }

        if (this.uploadOf(field)) {
            this.clearUpload(field);
            field.querySelectorAll('input[type="radio"]').forEach((radio) => {
                radio.checked = radio.value === value;
            });
            return;
        }

        const input = this.inputOf(field);

        // The brand select is a TomSelect - set it through TomSelect so its control shows the value too
        if (input.tomselect) {
            input.tomselect.setValue(value || '', true);
        } else {
            input.value = value || '';
        }
    }

    isNames(field) {
        return 'names' in field.dataset;
    }

    isCodes(field) {
        return 'codes' in field.dataset;
    }

    // One string for comparing code lists: trimmed, empty inputs left out - like the server saves them
    codesKey(codes) {
        return JSON.stringify(codes.map((code) => (code || '').trim()).filter((code) => code !== ''));
    }

    // The names as the editor shows them - read from its inputs, so it works before the editor's controller loaded
    namesEditorState(field) {
        const value = (selector, root = field) => root.querySelector(selector)?.value || '';

        return {
            name: value('[data-names-editor-target="main"]'),
            nameLanguage: value('[data-names-editor-target="mainLanguageSelect"]'),
            alternativeNames: Array.from(field.querySelectorAll('[data-names-editor-target="row"]')).map((row) => ({
                name: value('[data-role="name"]', row),
                language: value('[data-role="language"]', row),
            })),
        };
    }

    // One string for comparing names: trimmed, rows without a name left out - like the server saves them
    namesKey(names) {
        return JSON.stringify([
            (names.name || '').trim(),
            names.nameLanguage || '',
            (names.alternativeNames || [])
                .filter((alternative) => (alternative.name || '').trim() !== '')
                .map((alternative) => [alternative.name.trim(), alternative.language || '']),
        ]);
    }

    checkedImage(field) {
        const checked = field.querySelector('input[type="radio"]:checked');
        return checked ? checked.value : 'keep';
    }

    uploadOf(field) {
        return field.querySelector('.file-drop-input');
    }

    hasUpload(field) {
        return hasPhoto(field.querySelector('.file-drop-area'));
    }

    clearUpload(field) {
        const area = field.querySelector('.file-drop-area');
        clearPhoto(area, area.dataset.dropText);
    }

    inputOf(field) {
        return field.querySelector('select[name], textarea[name], input[name]:not([type="file"])');
    }

    fieldOf(element) {
        return element.closest('[data-puzzle-record-target="field"]');
    }

    toggleRole(field, role, visible) {
        field.querySelectorAll(`[data-role~="${role}"]`).forEach((element) => {
            element.classList.toggle('d-none', !visible);
        });
    }
}
