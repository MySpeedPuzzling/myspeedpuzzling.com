import { Controller } from '@hotwired/stimulus';

// Change request review: every field of the puzzle is editable. Each field is marked by what approving does
// with it - the player's proposal, the reviewer's own edit, or the current value kept although something was
// proposed - and the summary above the approve button lists it, so an edit is never mistaken for the proposal.
// A field carries data-current (and data-proposed when the player proposed it) as raw form values.
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

    // Proposed = orange, the reviewer's edit = indigo (the theme's primary is too close to orange to tell apart)
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

    disconnect() {
        this.revokePreview();
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

    photoChosen(event) {
        const field = this.fieldOf(event.target);
        const file = event.target.files && event.target.files[0];
        const preview = field.querySelector('[data-role="upload-preview"]');
        const placeholder = field.querySelector('[data-role="upload-placeholder"]');

        this.revokePreview();

        if (file) {
            this.setValue(field, 'upload');
            this.previewUrl = URL.createObjectURL(file);
            preview.src = this.previewUrl;
        }

        preview.classList.toggle('d-none', !file);
        placeholder.classList.toggle('d-none', Boolean(file));
    }

    // proposed: the proposal goes in · edit: the reviewer's own value · kept: proposed, but the current value stays
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

        // A proposal that is not going in is struck through
        field.querySelectorAll('[data-role="proposed-value"]').forEach((element) => {
            element.classList.toggle('text-decoration-line-through', state === 'kept');
            element.classList.toggle('opacity-50', state === 'kept');
        });

        const value = this.valueOf(field);
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
        const radios = field.querySelectorAll('input[type="radio"]');

        if (radios.length > 0) {
            const checked = Array.from(radios).find((radio) => radio.checked);
            return checked ? checked.value : '';
        }

        return (this.inputOf(field).value || '').trim();
    }

    setValue(field, value) {
        const radios = field.querySelectorAll('input[type="radio"]');

        if (radios.length > 0) {
            radios.forEach((radio) => {
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

    inputOf(field) {
        return field.querySelector('select[name], textarea[name], input[name]:not([type="file"])');
    }

    fieldOf(element) {
        return element.closest('[data-change-request-review-target="field"]');
    }

    toggleRole(field, role, visible) {
        field.querySelectorAll(`[data-role="${role}"]`).forEach((element) => {
            element.classList.toggle('d-none', !visible);
        });
    }

    revokePreview() {
        if (this.previewUrl) {
            URL.revokeObjectURL(this.previewUrl);
            this.previewUrl = null;
        }
    }
}
