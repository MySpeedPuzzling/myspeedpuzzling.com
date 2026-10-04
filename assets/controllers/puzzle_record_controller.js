import { Controller } from '@hotwired/stimulus';

// A puzzle's record in the moderators' forms - the review of a change request and the direct edit: every field is
// editable, and each is marked by what saving does with it - the player's proposal, the moderator's own edit, or the
// current value kept although something was proposed - and the summary above the save button lists it, so an edit
// is never mistaken for the proposal. A field carries data-current (and data-proposed when the player proposed it)
// as raw form values; the direct edit has no proposal, so only edits are marked there.
// The image field's value is "keep" / "proposed" (radios) or "upload" - a photo in its drop area (chosen, or kept
// from a refused submit) is used instead of either, and picking keep / proposed again drops that photo.
export default class extends Controller {
    static targets = ['field', 'summary'];
    static values = {
        proposedLabel: String,
        editLabel: String,
        keptLabel: String,
        changesHeading: String,
        keptHeading: String,
        noChanges: String,
        dropText: String,
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
        const current = (field.dataset.current || '').trim();

        if ('proposed' in field.dataset && value === (field.dataset.proposed || '').trim()) {
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

        this.toggleRole(field, 'use-proposed', 'proposed' in field.dataset && value !== (field.dataset.proposed || '').trim());
        this.toggleRole(field, 'use-current', value !== (field.dataset.current || '').trim());
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
        if (this.uploadOf(field)) {
            return this.hasUpload(field) ? 'upload' : this.checkedImage(field);
        }

        return (this.inputOf(field).value || '').trim();
    }

    setValue(field, value) {
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

    checkedImage(field) {
        const checked = field.querySelector('input[type="radio"]:checked');
        return checked ? checked.value : 'keep';
    }

    uploadOf(field) {
        return field.querySelector('.file-drop-input');
    }

    hasUpload(field) {
        const input = this.uploadOf(field);
        const token = field.querySelector('[data-kept-photo-target="token"]');

        return (input.files && input.files.length > 0) || Boolean(token && token.value);
    }

    // Back to an empty drop area: no file, no kept photo, no crop button
    clearUpload(field) {
        if (!this.hasUpload(field)) {
            return;
        }

        const area = field.querySelector('.file-drop-area');
        const icon = area.querySelector('[data-role="drop-icon"]');
        const token = area.querySelector('[data-kept-photo-target="token"]');

        this.uploadOf(field).value = '';

        if (token) {
            token.value = '';
        }

        area.querySelector('[data-kept-photo-target="note"]')?.remove();
        area.querySelector('.file-drop-edit-btn')?.remove();

        icon.className = 'file-drop-icon';
        icon.innerHTML = '<i class="ci-cloud-upload"></i>';
        area.querySelector('.file-drop-message').textContent = this.dropTextValue;
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
