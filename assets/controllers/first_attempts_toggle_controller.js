import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["firstAttempt", "allAttempts"];

    connect() {
        this.toggleDivs();
    }

    toggle(event) {
        this.toggleDivs(event.currentTarget.checked);
    }

    toggleDivs(isChecked = false) {
        this.firstAttemptTargets.forEach((element) => {
            element.classList.toggle("d-none", !isChecked);
        });
        this.allAttemptsTargets.forEach((element) => {
            element.classList.toggle("d-none", isChecked);
        });
    }
}
