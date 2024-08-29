// assets/controllers/tab_switcher_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['select', 'tab'];

    connect() {
        this.switchTab(); // Ensure the default tab is shown on load
    }

    switchTab() {
        const selectedValue = this.selectTarget.value;

        this.tabTargets.forEach((tab) => {
            if (tab.dataset.tab === selectedValue) {
                tab.classList.remove('d-none');
            } else {
                tab.classList.add('d-none');
            }
        });
    }
}
