import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["addPuzzleForm", "addPuzzleChosen", "addPuzzleChosenItem"];

    changePuzzle(event) {
        if (event.target.type === 'radio') {
            this.addPuzzleFormTarget.classList.add('hidden');
            this.addPuzzleChosenTarget.classList.remove('hidden');

            let clonedLi = event.target.closest('li').cloneNode(true);
            clonedLi.querySelectorAll('input').forEach(input => input.remove());

            this.addPuzzleChosenItemTarget.innerHTML = '';
            this.addPuzzleChosenItemTarget.appendChild(clonedLi);

            setTimeout(() => {
                this.addPuzzleChosenTarget.scrollIntoView({ behavior: 'smooth' });
            }, 100);
        }
    }

    changeLink(event) {
        event.preventDefault();
        this.addPuzzleChosenTarget.classList.add('hidden');
        this.addPuzzleFormTarget.classList.remove('hidden');
    }
}
