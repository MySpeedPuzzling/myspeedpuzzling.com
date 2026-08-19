import { Controller } from '@hotwired/stimulus';

/**
 * An optional filter behind an explicit switch. The panel with the inputs is shown -
 * and its fields are enabled - only while the switch is on, so a filter that is not in
 * use never submits a value (no more "it says 60 minutes but it is not applied").
 *
 * The switch is usually a checkbox (role="switch"); radios work too - mark the one that
 * means "off" with data-filter-toggle-opens="false". Fields may carry a
 * data-filter-toggle-default that is filled in the first time the switch is turned on.
 *
 * Usage:
 * <div data-controller="filter-toggle">
 *     <input type="checkbox" data-filter-toggle-target="switch" data-action="filter-toggle#update">
 *     <div data-filter-toggle-target="panel">
 *         <input name="since" data-filter-toggle-target="field" data-filter-toggle-default="6">
 *     </div>
 * </div>
 */
export default class extends Controller {
    static targets = ['switch', 'panel', 'field'];

    connect() {
        this.update();
    }

    update() {
        const on = this.switchTargets.some((element) => element.checked && element.dataset.filterToggleOpens !== 'false');

        this.panelTargets.forEach((panel) => panel.classList.toggle('d-none', !on));
        this.fieldTargets.forEach((field) => {
            field.disabled = !on;

            if (on && field.value === '' && field.dataset.filterToggleDefault !== undefined) {
                field.value = field.dataset.filterToggleDefault;
            }
        });
    }
}
