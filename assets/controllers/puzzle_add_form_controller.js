import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller for the unified puzzle add form.
 * Handles switching between Speed Puzzling, Relax, and Collection modes.
 */
export default class extends Controller {
    static targets = [
        'modeInput',           // Hidden input for mode value
        'timeAndDateSection',  // Card with time + date (Speed + Relax)
        'timeSection',         // Time field only (Speed only)
        'firstAttemptSection', // First attempt checkbox (Speed only)
        'groupSection',        // Group players (Speed + Relax)
        'competitionSection',  // Competition (Speed only)
        'commonSection',       // Comment, photo (Speed + Relax)
        'collectionSection',   // Collection fields (Collection only)
        'newCollectionFields', // New collection name/visibility (Collection, when creating new)
    ];

    static values = {
        mode: { type: String, default: 'speed_puzzling' },
        systemId: { type: String, default: '__system_collection__' }
    };

    connect() {
        this.updateVisibility();

        // Watch for collection field changes to show/hide new collection fields
        if (this.hasCollectionSectionTarget) {
            const collectionInput = this.collectionSectionTarget.querySelector('input[type="text"], select');
            if (collectionInput) {
                collectionInput.addEventListener('change', this.handleCollectionChange.bind(this));
            }
        }
    }

    switchMode(event) {
        this.modeValue = event.target.value;
        this.updateModeInput();
        this.updateVisibility();
    }

    updateModeInput() {
        if (this.hasModeInputTarget) {
            this.modeInputTarget.value = this.modeValue;
        }
    }

    updateVisibility() {
        const isSpeed = this.modeValue === 'speed_puzzling';
        const isRelax = this.modeValue === 'relax';
        const isCollection = this.modeValue === 'collection';

        // Time and date card (Speed + Relax)
        if (this.hasTimeAndDateSectionTarget) {
            this.timeAndDateSectionTarget.classList.toggle('d-none', isCollection);
        }

        // Time field (Speed only)
        if (this.hasTimeSectionTarget) {
            this.timeSectionTarget.classList.toggle('d-none', !isSpeed);
        }

        // First attempt checkbox (Speed only)
        if (this.hasFirstAttemptSectionTarget) {
            this.firstAttemptSectionTarget.classList.toggle('d-none', !isSpeed);
        }

        // Group section (Speed + Relax)
        if (this.hasGroupSectionTarget) {
            this.groupSectionTarget.classList.toggle('d-none', isCollection);
        }

        // Competition section (Speed only)
        if (this.hasCompetitionSectionTarget) {
            this.competitionSectionTarget.classList.toggle('d-none', !isSpeed);
        }

        // Common section: comment, photo (Speed + Relax)
        if (this.hasCommonSectionTarget) {
            this.commonSectionTarget.classList.toggle('d-none', isCollection);
        }

        // Collection section (Collection only)
        if (this.hasCollectionSectionTarget) {
            this.collectionSectionTarget.classList.toggle('d-none', !isCollection);
        }
    }

    handleCollectionChange(event) {
        const value = event.target.value;
        // If value is not a UUID and not the system collection ID, it's a new collection name
        const isNewCollection = value && !this.isUuid(value) && value !== this.systemIdValue;

        if (this.hasNewCollectionFieldsTarget) {
            this.newCollectionFieldsTarget.classList.toggle('d-none', !isNewCollection);
        }
    }

    isUuid(value) {
        const uuidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-7][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
        return uuidRegex.test(value);
    }
}
