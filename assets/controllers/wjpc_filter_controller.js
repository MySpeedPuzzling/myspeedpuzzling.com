import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["participantItem", "nameFilter"];

    filter() {
        const searchInput = this.normalizeString(this.nameFilterTarget.value).split(' ');

        this.participantItemTargets.forEach(participant => {
            const participantName = this.normalizeString(participant.dataset.name);
            const matchesAllWords = searchInput.every(word => participantName.includes(word));

            participant.style.display = matchesAllWords ? '' : 'none';
        });

        this.updateNoResultsMessage();
    }

    updateNoResultsMessage() {
        document.querySelectorAll('.filter-no-results').forEach(noResultsElement => {
            // Find the common ancestor element that contains both the noResultsElement and puzzle items
            let commonAncestor = noResultsElement.parentElement;
            while (commonAncestor && !commonAncestor.querySelector('[data-puzzle-filter-target="puzzleItem"]')) {
                commonAncestor = commonAncestor.parentElement;
            }

            if (commonAncestor) {
                const puzzleItems = commonAncestor.querySelectorAll('[data-wjpc-filter-target="participantItem"]');
                const anyVisible = Array.from(puzzleItems).some(item => item.style.display !== 'none');
                noResultsElement.classList.toggle('hidden', anyVisible);
            }
        });
    }

    normalizeString(str) {
        return str.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();
    }
}
