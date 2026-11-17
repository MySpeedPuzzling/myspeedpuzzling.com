"""
Judge page timings (tools/competition-load/README.md "Judge page timings"): the live entry page of the load round
(300 entrants) on a throttled mid-range phone - Chrome via Selenium, CDP CPU throttling and network conditions,
375x812 - cold (fresh profile, nothing cached) and warm (the service worker and the HTTP cache filled), then in-page
timings of table typed → card open and Save → "All saved".

Run by `load.sh timings` (the stack up with ROUND_FORMAT=total); prints one JSON object.
Env: SITE, SELENIUM, CREDENTIALS, CPU (4), NETWORK_PROFILE (slow4g | fast3g), SAVES (20), FIRST_TABLE (201).
"""

import json
import os
import time

from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys

SITE = os.environ['SITE']
SELENIUM = os.environ['SELENIUM']
CPU = float(os.environ.get('CPU', '4'))
PROFILE = os.environ.get('NETWORK_PROFILE', 'slow4g')
SAVES = int(os.environ.get('SAVES', '20'))
FIRST_TABLE = int(os.environ.get('FIRST_TABLE', '201'))

with open(os.environ.get('CREDENTIALS', '/out/credentials.json')) as handle:
    DATA = json.load(handle)

ROUND = DATA['roundId']
# Chrome DevTools' presets (latency in ms, throughput in bytes/s)
NETWORKS = {
    'slow4g': {'latency': 150, 'downloadThroughput': 1.6 * 1024 * 1024 / 8, 'uploadThroughput': 750 * 1024 / 8},
    'fast3g': {'latency': 562.5, 'downloadThroughput': 1.44 * 1024 * 1024 / 8, 'uploadThroughput': 675 * 1024 / 8},
}

TIMING_JS = r"""
const nav = performance.getEntriesByType('navigation')[0] || {};
const paint = Object.fromEntries(performance.getEntriesByType('paint').map((p) => [p.name, p.startTime]));
const state = performance.getEntriesByType('resource').filter((r) => r.name.includes('/official-results/rounds/'));
const build = performance.getEntriesByType('resource').filter((r) => r.name.includes('/build/'));
return {
    ttfb: nav.responseStart, htmlDone: nav.responseEnd, domContentLoaded: nav.domContentLoadedEventEnd, load: nav.loadEventEnd,
    htmlBytes: nav.transferSize, fromServiceWorker: nav.workerStart > 0,
    firstContentfulPaint: paint['first-contentful-paint'] || null,
    state: state.map((r) => ({duration: Math.round(r.duration), start: Math.round(r.startTime), transferBytes: r.transferSize, bodyBytes: r.decodedBodySize})),
    buildTransferBytes: build.reduce((sum, r) => sum + (r.transferSize || 0), 0),
    buildFiles: build.length,
};
"""

READY_JS = r"""
const q = (t) => document.querySelector(`[data-live-results-target="${t}"]`);
const pill = q('pill');
const watch = q('stopwatch');
const text = (el) => el ? el.textContent.replace(/\s+/g, ' ').trim() : '';
const ready = !!pill && ['saved', 'waiting', 'problem', 'blocked'].includes(pill.dataset.state) && !text(q('pillText')).includes('Loading') && text(watch) !== '';
return ready ? performance.now() : null;
"""

PERF_JS = r"""
if (!window.__loadPerf) {
    const perf = window.__loadPerf = {opens: [], sends: [], typedAt: null, savedAt: null};
    const q = (t) => document.querySelector(`[data-live-results-target="${t}"]`);
    document.addEventListener('keydown', (e) => {
        if (e.target === q('findInput') && perf.typedAt === null) { perf.typedAt = performance.now(); }
    }, true);
    new MutationObserver(() => {
        if (!q('entryView').hidden && perf.typedAt !== null) { perf.opens.push(performance.now() - perf.typedAt); perf.typedAt = null; }
    }).observe(q('entryView'), {attributes: true, attributeFilter: ['hidden']});
    q('saveButton').addEventListener('pointerdown', () => { perf.savedAt = performance.now(); }, true);
    q('saveButton').addEventListener('click', () => { if (perf.savedAt === null) { perf.savedAt = performance.now(); } }, true);
    new MutationObserver(() => {
        if (q('pill').dataset.state === 'saved' && perf.savedAt !== null) { perf.sends.push(performance.now() - perf.savedAt); perf.savedAt = null; }
    }).observe(q('pill'), {attributes: true, attributeFilter: ['data-state']});
}
return true;
"""

SHELL_READY_JS = r"""
const done = arguments[arguments.length - 1];
(async () => {
    const names = await caches.keys();
    const shell = names.find((n) => n.startsWith('live-shell-'));
    done(!!shell && (await (await caches.open(shell)).keys()).length > 0 && !!navigator.serviceWorker.controller);
})().catch(() => done(false));
"""


def browser():
    options = webdriver.ChromeOptions()
    for arg in ('--unsafely-treat-insecure-origin-as-secure=%s' % SITE, '--no-first-run', '--lang=en-US',
                '--window-size=430,932', '--disable-backgrounding-occluded-windows', '--disable-renderer-backgrounding'):
        options.add_argument(arg)
    options.add_experimental_option('mobileEmulation', {
        'deviceMetrics': {'width': 375, 'height': 812, 'pixelRatio': 2.0, 'touch': True},
        'userAgent': 'Mozilla/5.0 (Linux; Android 14; Pixel 7a) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36',
    })
    options.unhandled_prompt_behavior = 'accept'
    driver = webdriver.Remote(command_executor=SELENIUM, options=options)
    driver.command_executor._commands['executeCdpCommand'] = ('POST', '/session/$sessionId/goog/cdp/execute')
    driver.set_page_load_timeout(120)
    driver.set_script_timeout(60)
    return driver


def cdp(driver, cmd, params=None):
    return driver.execute('executeCdpCommand', {'cmd': cmd, 'params': params or {}})['value']


def throttle(driver, on):
    cdp(driver, 'Network.enable')
    conditions = NETWORKS[PROFILE] if on else {'latency': 0, 'downloadThroughput': -1, 'uploadThroughput': -1}
    cdp(driver, 'Network.emulateNetworkConditions', {'offline': False, **conditions})
    cdp(driver, 'Emulation.setCPUThrottlingRate', {'rate': CPU if on else 1})


def wait(predicate, timeout=60, interval=0.05):
    deadline = time.time() + timeout
    while time.time() < deadline:
        value = predicate()
        if value:
            return value
        time.sleep(interval)
    raise RuntimeError('timed out')


def open_page(driver):
    """A navigation to the round's live entry: the page's own timings + when it showed the round ready (stopwatch on,
    the state loaded) - performance.now() at the first poll that saw it, polled every 50 ms."""
    driver.get(SITE + '/en/live-results/' + ROUND)
    ready = wait(lambda: driver.execute_script(READY_JS))
    timings = driver.execute_script(TIMING_JS)
    timings['ready'] = round(ready)
    return {k: (round(v) if isinstance(v, float) else v) for k, v in timings.items()}


def percentile(values, p):
    ordered = sorted(values)
    return round(ordered[min(len(ordered) - 1, max(0, int(round(p * len(ordered) + 0.5)) - 1))], 1) if ordered else None


def typed(seconds):
    return '%d:%02d:%02d' % (seconds // 3600, seconds % 3600 // 60, seconds % 60)


def visible(driver, target):
    return driver.execute_script(
        "const el = document.querySelector(`[data-live-results-target=\"${arguments[0]}\"]`); return !!el && !el.hidden && el.offsetParent !== null;",
        target)


def enter(driver, table):
    field = driver.find_element(By.CSS_SELECTOR, '[data-live-results-target="findInput"]')
    field.clear()
    field.send_keys(str(table))
    time.sleep(0.2)
    if not visible(driver, 'entryView'):
        field.send_keys(Keys.ENTER)
    wait(lambda: visible(driver, 'entryView'), timeout=20)
    time_field = driver.find_element(By.CSS_SELECTOR, '[data-live-results-target="timeInput"]')
    time_field.clear()
    time_field.send_keys(typed(3600 + table))
    time_field.send_keys(Keys.ENTER)
    wait(lambda: visible(driver, 'confirmView'), timeout=20)
    time.sleep(0.45)  # Save ignores a tap within 350 ms of the confirm step (C7)
    driver.find_element(By.CSS_SELECTOR, '[data-live-results-target="saveButton"]').click()
    wait(lambda: visible(driver, 'findView'), timeout=20)
    wait(lambda: driver.execute_script("return document.querySelector('[data-live-results-target=\"pill\"]').dataset.state === 'saved'"), timeout=60)


def main():
    judge = DATA['judges'][0]
    driver = browser()
    result = {'cpu': CPU, 'network': PROFILE}
    try:
        driver.get(SITE + '/-/health-check/container')
        driver.add_cookie({'name': 'REMEMBERME', 'value': judge['cookie'], 'path': '/', 'httpOnly': True})
        # Sign in (the remember-me cookie becomes a session) without the throttle - only the page itself is measured
        driver.get(SITE + '/en/official-results/rounds/%s' % ROUND)
        cdp(driver, 'Network.clearBrowserCache')

        throttle(driver, True)
        result['cold'] = open_page(driver)
        throttle(driver, False)
        wait(lambda: driver.execute_async_script(SHELL_READY_JS), timeout=120, interval=1)

        throttle(driver, True)
        result['warm'] = open_page(driver)
        result['warm_again'] = open_page(driver)

        driver.execute_script(PERF_JS)
        for table in range(FIRST_TABLE, FIRST_TABLE + SAVES):
            enter(driver, table)
        perf = driver.execute_script('return window.__loadPerf')
        result['table_to_card_ms'] = {'n': len(perf['opens']), 'p50': percentile(perf['opens'], .5), 'p95': percentile(perf['opens'], .95), 'max': round(max(perf['opens']), 1) if perf['opens'] else None}
        result['save_to_saved_ms'] = {'n': len(perf['sends']), 'p50': percentile(perf['sends'], .5), 'p95': percentile(perf['sends'], .95), 'max': round(max(perf['sends']), 1) if perf['sends'] else None}
        throttle(driver, False)
    finally:
        driver.quit()
    print(json.dumps(result, indent=2))


if __name__ == '__main__':
    main()
