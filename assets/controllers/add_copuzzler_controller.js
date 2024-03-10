import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["puzzlersGroup", "puzzlerTemplate", "addPuzzlerBtn"];
    puzzlerCounter = 0;

    connect() {
        this.initializePuzzlers();
    }

    addPuzzler() {
        if (this.puzzlersGroupTarget.children.length < 6) {
            this.puzzlersGroupTarget.insertAdjacentHTML('beforeend', this.puzzlerTemplateTarget.innerHTML);
            const newPuzzler = this.puzzlersGroupTarget.lastElementChild;
            this.initializePuzzler(newPuzzler, ++this.puzzlerCounter);
            this.updateCounters();
            this.checkButtonVisibility();
            this.disableSelectedOptionsGlobally();
        }
    }

    removePuzzler(event) {
        const puzzler = event.target.closest('[data-puzzler]');
        if (puzzler) {
            puzzler.remove();
            this.updateCounters();
            this.checkButtonVisibility();
            this.updateSelectOptions();
            this.disableSelectedOptionsGlobally();
        }
    }

    selectChanged(event) {
        const select = event.target;
        const input = select.closest('.input-group').querySelector('input[name="group_players[]"]');
        this.adjustInputStateBasedOnSelect(select, input);
        this.updateSelectOptions(select, select.value);
        this.disableSelectedOptionsGlobally();
    }

    initializePuzzlers() {
        const puzzlers = this.puzzlersGroupTarget.querySelectorAll('.input-group');
        puzzlers.forEach((puzzler, index) => {
            this.initializePuzzler(puzzler, index);
        });
        this.disableSelectedOptionsGlobally();
        this.updateCounters();
        this.checkButtonVisibility();
    }

    initializePuzzler(puzzler, counter) {
        const input = puzzler.querySelector('input[name="group_players[]"]');
        const label = puzzler.querySelector('label');
        const select = puzzler.querySelector('.group-favorite-player-select');

        if (input && select) {
            const uniqueId = `input-${counter}`;
            input.id = uniqueId;
            if (label) label.setAttribute('for', uniqueId);
            select.id = `select-${counter}`;
            this.adjustInputStateBasedOnSelect(select, input, true);
        }
    }

    adjustInputStateBasedOnSelect(select, input, isInitializing = false) {
        const selectedValue = select.value;
        if (selectedValue !== '') {
            input.value = `#${selectedValue}`;
            input.setAttribute('readonly', true);
        } else {
            if (!isInitializing) {
                input.value = '';
            }
            input.removeAttribute('readonly');
        }

        this.updateSelectOptions(select, selectedValue);
    }

    updateCounters() {
        const counters = this.puzzlersGroupTarget.querySelectorAll('[data-counter]');
        counters.forEach((counter, index) => {
            counter.textContent = index + 1;
        });
    }

    checkButtonVisibility() {
        if (this.puzzlersGroupTarget.children.length >= 6) {
            this.addPuzzlerBtnTarget.classList.add('hidden');
        } else {
            this.addPuzzlerBtnTarget.classList.remove('hidden');
        }
    }

    updateSelectOptions(changedSelect, selectedValue) {
        const selects = this.puzzlersGroupTarget.querySelectorAll('.group-favorite-player-select');
        const selectedValues = Array.from(selects).map(select => select.value);

        selects.forEach(select => {
            Array.from(select.options).forEach(option => {
                if (selectedValues.includes(option.value) && selectedValue !== option.value && select !== changedSelect) {
                    option.disabled = true;
                } else {
                    option.disabled = false;
                }
            });
        });
    }

    disableSelectedOptionsGlobally() {
        const selects = this.puzzlersGroupTarget.querySelectorAll('.group-favorite-player-select');
        const selectedValues = Array.from(selects).map(select => select.value).filter(value => value !== '');

        selects.forEach(select => {
            Array.from(select.options).forEach(option => {
                if (selectedValues.includes(option.value) && option.value !== select.value) {
                    option.disabled = true;
                }
            });
        });
    }
}
