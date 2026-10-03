import { Controller } from '@hotwired/stimulus';

/**
 * Tabs of the Players page's country sections (docs/features/players-page/README.md): every order and every Cup board
 * is already rendered, a tab only shows another one - no request. Without JavaScript the panel the server left visible
 * stays.
 *
 * A section may have several tab groups (the Country Cup: period and measure). Each tab names its group and value
 * (data-players-tabs-group-param / -value-param); a panel names its value per group as data-tab-<group> and is shown
 * when it matches the selected tab of every group it names - a panel without data-tab-period does not care about the
 * period.
 */
export default class extends Controller {
    static targets = ['tab', 'panel'];

    select(event) {
        this.activate(event.currentTarget, false);
    }

    // Arrow keys, Home and End move within one tab group (WAI-ARIA tabs pattern, automatic activation)
    navigate(event) {
        const tab = event.currentTarget;
        const tabs = this.groupTabs(tab.dataset.playersTabsGroupParam);
        const index = tabs.indexOf(tab);
        let next = null;

        if (event.key === 'ArrowRight') {
            next = tabs[(index + 1) % tabs.length];
        } else if (event.key === 'ArrowLeft') {
            next = tabs[(index - 1 + tabs.length) % tabs.length];
        } else if (event.key === 'Home') {
            next = tabs[0];
        } else if (event.key === 'End') {
            next = tabs[tabs.length - 1];
        }

        if (next) {
            event.preventDefault();
            this.activate(next, true);
        }
    }

    activate(tab, focus) {
        this.groupTabs(tab.dataset.playersTabsGroupParam).forEach((candidate) => {
            const selected = candidate === tab;
            candidate.setAttribute('aria-selected', selected ? 'true' : 'false');
            candidate.tabIndex = selected ? 0 : -1;
        });

        if (focus) {
            tab.focus();
        }

        const selectedTabs = this.tabTargets.filter((candidate) => candidate.getAttribute('aria-selected') === 'true');

        this.panelTargets.forEach((panel) => {
            panel.hidden = !selectedTabs.every((selectedTab) => {
                const value = panel.getAttribute(`data-tab-${selectedTab.dataset.playersTabsGroupParam}`);

                return value === null || value === selectedTab.dataset.playersTabsValueParam;
            });
        });
    }

    groupTabs(group) {
        return this.tabTargets.filter((candidate) => candidate.dataset.playersTabsGroupParam === group);
    }
}
