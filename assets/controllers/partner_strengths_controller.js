import { Controller } from '@hotwired/stimulus';

/*
 * "Good at" in the partner finder listing form (docs/features/partner-finder.md D3): at most N presets ticked; custom
 * chips typed one at a time (Enter, a comma or "+ add"), shown as typed, at most M, each once (compared folded). A typed
 * chip reading like a preset label ticks the preset instead. Suggestions: the chips other puzzlers use at the event (the
 * finder page carries them - data-partner-finder-custom-chips - or the controller's own value) and the preset labels.
 * The real field stays the comma-separated input the server reads; the server's normalizer has the last word.
 */
export default class extends Controller {
    static targets = ['preset', 'field', 'chips', 'entryRow', 'entry', 'suggestions'];

    static values = {
        maxPresets: { type: Number, default: 4 },
        maxCustom: { type: Number, default: 5 },
        maxLength: { type: Number, default: 30 },
        suggestions: { type: Array, default: [] },
    };

    connect() {
        this.chipList = [];
        splitChips(this.fieldTarget.value).forEach((chip) => this.push(chip));

        this.fieldTarget.hidden = true;
        this.chipsTarget.hidden = false;
        this.entryRowTarget.hidden = false;

        this.fillSuggestions();
        this.render();
    }

    keydown(event) {
        if (event.key === 'Enter' || event.key === ',') {
            event.preventDefault();
            this.add();
        }
    }

    add() {
        splitChips(this.entryTarget.value).forEach((chip) => {
            const preset = this.presetTargets.find((input) => fold(input.dataset.label || '') === fold(chip) || fold(input.value.replace(/_/g, ' ')) === fold(chip));

            if (preset) {
                if (!preset.checked && this.checkedPresets() < this.maxPresetsValue) {
                    preset.checked = true;
                }

                return;
            }

            this.push(chip);
        });

        this.entryTarget.value = '';
        this.render();
        this.entryTarget.focus();
    }

    remove(event) {
        const index = Number(event.currentTarget.dataset.index);
        this.chipList.splice(index, 1);
        this.render();
        this.entryTarget.focus();
    }

    limitPresets(event) {
        if (event.currentTarget.checked && this.checkedPresets() > this.maxPresetsValue) {
            event.currentTarget.checked = false;
        }
    }

    push(chip) {
        const text = chip.replace(/\s+/g, ' ').trim().slice(0, this.maxLengthValue);

        if (text === '' || this.chipList.length >= this.maxCustomValue) {
            return;
        }

        if (this.chipList.some((existing) => fold(existing) === fold(text))) {
            return;
        }

        this.chipList.push(text);
    }

    checkedPresets() {
        return this.presetTargets.filter((input) => input.checked).length;
    }

    render() {
        this.chipsTarget.replaceChildren(...this.chipList.map((chip, index) => {
            const element = document.createElement('span');
            element.className = 'pf-chip pf-chip-custom';
            element.append(document.createTextNode(chip));

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'pf-chip-remove';
            button.dataset.index = String(index);
            button.dataset.action = 'partner-strengths#remove';
            button.setAttribute('aria-label', `× ${chip}`);
            button.textContent = '×';
            element.append(button);

            return element;
        }));

        this.chipsTarget.hidden = this.chipList.length === 0;
        this.fieldTarget.value = this.chipList.join(', ');
        this.entryTarget.disabled = this.chipList.length >= this.maxCustomValue;
    }

    fillSuggestions() {
        if (!this.hasSuggestionsTarget) {
            return;
        }

        const page = document.querySelector('[data-partner-finder-custom-chips]');
        let fromPage = [];

        try {
            fromPage = page ? JSON.parse(page.dataset.partnerFinderCustomChips || '[]') : [];
        } catch {
            fromPage = [];
        }

        const seen = new Set();
        const options = [];

        [...this.suggestionsValue, ...fromPage, ...this.presetTargets.map((input) => input.dataset.label || '')].forEach((text) => {
            const key = fold(text);

            if (key !== '' && !seen.has(key)) {
                seen.add(key);
                const option = document.createElement('option');
                option.value = text;
                options.push(option);
            }
        });

        this.suggestionsTarget.replaceChildren(...options);
    }
}

function splitChips(value) {
    return (value || '').split(',').map((chip) => chip.trim()).filter((chip) => chip !== '');
}

// Close to SearchText::fold(): accents and apostrophes off, lower case, whitespace collapsed
function fold(text) {
    return text
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/['`´ʹʼ‘’‛′＇]/g, '')
        .toLowerCase()
        .replace(/\s+/g, ' ')
        .trim();
}
