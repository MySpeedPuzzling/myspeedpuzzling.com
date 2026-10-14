/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { readSetting, SETTING_OFF, writeSetting } from '../haptics.js';

/**
 * Settings › Preferences › "Vibration" (docs/features/navigation README §4.13 G10, GEST-10): a per-device switch kept in
 * localStorage (msp:haptics, default on) - nothing goes to the server, so the page's HTML is the same for everybody on the
 * web. The card is shown only on Android (`.os-android-only`: CSS on the device OS that assets/platform.js os() reads).
 * The switch is read on connect (no change event: turning the stored value on or off fires no haptic) and stored before
 * the page-wide listener of assets/haptics.js plays the toggle's haptic - so turning it on is felt at once, turning it
 * off is silent. A storage that refuses (a private window, site data blocked) leaves the switch working for this page
 * only: assets/haptics.js keeps the value in memory (writeSetting() / readSetting()). Rendered on the web only - inside
 * the native app no haptic plays (templates/edit-profile.html.twig).
 */
export default class extends Controller {
    static targets = ['switch'];

    connect() {
        if (this.hasSwitchTarget) {
            this.switchTarget.checked = readSetting() !== SETTING_OFF;
        }
    }

    change() {
        if (this.hasSwitchTarget) {
            writeSetting(this.switchTarget.checked);
        }
    }
}
