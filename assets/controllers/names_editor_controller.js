/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { foldSearchText } from '../search_fold.js';

/**
 * The names editor (templates/puzzle/_names_editor.html.twig, docs/features/puzzle-names/README.md): adds and removes
 * "Other names" rows from the form's prototype, swaps a row with the main title ("Make main title"), splits a main title
 * holding " / " and moves a main title that does not look English to the other names. Warnings only - nothing here
 * blocks a save, the server validates. Every text is in the template.
 *
 * Rows are numbered 0, 1, 2... in the order they stand after every change, so the list is saved in the order shown.
 * Changes made by a button announce themselves as `names-editor:change` (typing already sends input/change events).
 */
export default class extends Controller {
    static targets = [
        'main', 'mainLanguage', 'mainLanguageSelect', 'mainLanguageToggle',
        'splitWarning', 'englishWarning', 'rows', 'row', 'empty', 'add', 'template',
    ];

    static values = {
        maxNames: Number,
        collectionName: String,
        collectionId: String,
    };

    connect() {
        this.update();
    }

    add() {
        if (this.rowTargets.length >= this.maxNamesValue) {
            return;
        }

        const row = this.newRow();
        this.rowsTarget.append(row);
        this.changed();
        this.nameInput(row).focus();
    }

    remove(event) {
        this.rowOf(event.target).remove();
        this.changed();
    }

    // The row's name becomes the main title, the main title takes the row's place - in the main title's language;
    // the promoted name's language becomes the main title's, unless it is English
    makeMain(event) {
        const row = this.rowOf(event.target);
        const promoted = this.nameInput(row).value.trim();

        if (promoted === '') {
            return;
        }

        const promotedLanguage = this.languageSelect(row).value;
        const oldMain = this.mainTarget.value.trim();

        if (oldMain !== '') {
            const demoted = this.newRow(oldMain);
            this.copyLanguage(this.mainLanguageSelectTarget, this.languageSelect(demoted));
            demoted.dataset.wasMain = '';
            row.replaceWith(demoted);
        } else {
            row.remove();
        }

        this.mainTarget.value = promoted;
        this.copyLanguage(this.languageSelect(row), this.mainLanguageSelectTarget, promotedLanguage === 'en' ? '' : promotedLanguage);

        if (this.mainLanguageSelectTarget.value !== '') {
            this.showMainLanguage();
        }

        this.changed();
    }

    // "A / B / C": A stays the main title, B and C become other names - without a language, which the moderator sets
    split() {
        const [first, ...others] = this.mainTarget.value.split(' / ').map((part) => part.trim()).filter((part) => part !== '');

        this.mainTarget.value = first || '';

        const before = this.rowTargets[0] || null;
        others.slice(0, Math.max(0, this.maxNamesValue - this.rowTargets.length)).forEach((name) => {
            const row = this.newRow(name, '');
            this.rowsTarget.insertBefore(row, before);
        });

        this.changed();
    }

    // The title printed on a box without an English one: it goes to the other names, the moderator types the English title
    moveToOtherNames() {
        const row = this.newRow(this.mainTarget.value.trim());
        this.copyLanguage(this.mainLanguageSelectTarget, this.languageSelect(row));
        row.dataset.wasMain = '';
        this.rowsTarget.prepend(row);

        this.mainTarget.value = '';
        this.mainLanguageSelectTarget.value = '';
        this.changed();
        this.mainTarget.focus();
    }

    showMainLanguage() {
        this.mainLanguageTarget.hidden = false;
        this.mainLanguageToggleTarget.hidden = true;
    }

    update() {
        const main = this.mainTarget.value;
        const severalNames = main.includes(' / ');

        this.splitWarningTarget.hidden = !severalNames;
        this.englishWarningTarget.hidden = severalNames || this.mainLanguageSelectTarget.value !== '' || !this.looksNonEnglish(main);

        const seen = new Set([foldSearchText(main)]);

        this.rowTargets.forEach((row) => {
            const key = foldSearchText(this.nameInput(row).value);
            this.note(row, 'duplicate').hidden = key === '' || !seen.has(key);
            seen.add(key);

            this.note(row, 'was-main').hidden = !('wasMain' in row.dataset) || this.languageSelect(row).value !== '';
        });

        this.emptyTarget.hidden = this.rowTargets.length > 0;
        this.addTarget.hidden = this.rowTargets.length >= this.maxNamesValue;
    }

    changed() {
        this.renumber();
        this.update();
        this.dispatch('change');
    }

    // A letter outside ASCII - an English title rarely has one (warning only, "Café" is fine to keep)
    looksNonEnglish(name) {
        return /(?![\u0000-\u007F])\p{L}/u.test(name);
    }

    newRow(name = '', language = null) {
        const html = this.templateTarget.innerHTML.replaceAll('__name__', String(this.rowTargets.length));
        const holder = document.createElement('div');
        holder.innerHTML = html.trim();

        const row = holder.firstElementChild;
        this.nameInput(row).value = name;

        // null = the prototype's default (the page language)
        if (language !== null) {
            this.languageSelect(row).value = language;
        }

        return row;
    }

    // Selects the source's language on the target - an option the target lacks (a tag outside the list) is copied over
    copyLanguage(source, target, value = source.value) {
        if (value !== '' && !Array.from(target.options).some((option) => option.value === value)) {
            const sourceOption = Array.from(source.options).find((option) => option.value === value);
            target.add(new Option(sourceOption ? sourceOption.text : value, value));
        }

        target.value = value;
    }

    renumber() {
        const name = this.collectionNameValue;
        const id = this.collectionIdValue;

        // collection[7][name] → collection[1][name], collection_7_name → collection_1_name
        this.rowTargets.forEach((row, index) => {
            row.querySelectorAll('[name], [id]').forEach((element) => {
                if (element.name && element.name.startsWith(`${name}[`)) {
                    element.name = name + element.name.slice(name.length).replace(/^\[[^\]]*\]/, `[${index}]`);
                }

                if (element.id && element.id.startsWith(`${id}_`)) {
                    element.id = `${id}_${index}_${element.id.slice(id.length + 1).replace(/^[^_]*_/, '')}`;
                }
            });
        });
    }

    rowOf(element) {
        return element.closest('[data-names-editor-target="row"]');
    }

    nameInput(row) {
        return row.querySelector('[data-role="name"]');
    }

    languageSelect(row) {
        return row.querySelector('[data-role="language"]');
    }

    note(row, role) {
        return row.querySelector(`[data-role="${role}"]`);
    }
}
