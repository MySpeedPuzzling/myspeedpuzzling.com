"""
Shared pieces of the judge-chaos scenarios (tools/judge-chaos/README.md): the proxy's control API, the throwaway
database, a WebDriver browser that knows the live entry page and the competitor page, and the result records.
"""

import base64
import json
import os
import socket
import time
import urllib.request

import psycopg
from selenium import webdriver
from selenium.common.exceptions import (ElementClickInterceptedException, JavascriptException,
                                        StaleElementReferenceException, TimeoutException, WebDriverException)
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys

PREFIX = os.environ.get('NAME_PREFIX', 'rlt3')
SITE = os.environ.get('SITE', 'http://%s-proxy:8080' % PREFIX)
PROXY_CONTROL = os.environ.get('PROXY_CONTROL', 'http://%s-proxy:9999' % PREFIX)
SELENIUM = os.environ.get('SELENIUM', 'http://%s-selenium:4444' % PREFIX)
REDIS = (os.environ.get('REDIS_HOST', '%s-redis' % PREFIX), 6379)
DATABASE_URL = os.environ['CHAOS_DATABASE_URL']
OUT = os.environ.get('OUT', '/out')
MEDIA_IN_SELENIUM = '/chaos-media'

COMPETITION = '018d0020-0000-0000-0000-000000000001'
ROUND = '018d0099-0000-0000-0000-000000000001'
ROUND_PAIRS = '018d0020-0000-0000-0000-000000000104'
PASSWORD = 'judge-chaos-1'

PLAYERS = {
    'referee': ('018d0000-0000-0000-0000-000000000004', 'player3@speedpuzzling.cz', 'Michael Johnson'),
    'organiser': ('018d0000-0000-0000-0000-000000000005', 'player4@speedpuzzling.cz', 'Sarah Williams'),
    'john': ('018d0000-0000-0000-0000-000000000001', 'player1@speedpuzzling.cz', 'John Doe'),
    'jane': ('018d0000-0000-0000-0000-000000000002', 'player2@speedpuzzling.cz', 'Jane Smith'),
    'admin': ('018d0000-0000-0000-0000-000000000003', 'admin@speedpuzzling.cz', 'Admin User'),
}


def participant_id(n):
    return '018d0099-0000-0000-0001-%012d' % n


def entry_id(n):
    return '018d0099-0000-0000-0002-%012d' % n


def seconds_for(table):
    """The value a scenario types for a table - unique per table, so a value in the wrong row shows."""
    return 3600 + table


def typed(seconds):
    return '%d:%02d:%02d' % (seconds // 3600, seconds % 3600 // 60, seconds % 60)


class Failed(Exception):
    pass


def check(condition, message):
    if not condition:
        raise Failed(message)


# ---------------------------------------------------------------- proxy

def _http(method, url, data=None, timeout=10):
    body = json.dumps(data).encode() if data is not None else None
    request = urllib.request.Request(url, data=body, method=method, headers={'Content-Type': 'application/json'})
    with urllib.request.urlopen(request, timeout=timeout) as response:
        return json.loads(response.read() or b'null')


class Proxy:
    def state(self, **changes):
        return _http('POST', PROXY_CONTROL + '/state', changes) if changes else _http('GET', PROXY_CONTROL + '/state')

    def reset(self):
        return _http('POST', PROXY_CONTROL + '/reset', {})

    def down(self):
        return self.state(down=True)

    def up(self):
        return self.state(down=False, blackhole=False, flap=None)

    def log(self, since=0):
        return _http('GET', PROXY_CONTROL + '/log?since=%d' % since)

    def mark(self):
        return self.state()['log_size']

    def rules(self, rules):
        return self.state(rules=rules)


def record_requests(proxy, since):
    return [entry for entry in proxy.log(since) if '/official-results/rounds/' in entry['path'] and entry['method'] == 'POST']


# ---------------------------------------------------------------- database

class Db:
    def __init__(self):
        self.connection = psycopg.connect(DATABASE_URL, autocommit=True)

    def all(self, sql, params=()):
        with self.connection.cursor() as cursor:
            cursor.execute(sql, params)
            return cursor.fetchall() if cursor.description is not None else []

    def one(self, sql, params=()):
        rows = self.all(sql, params)
        return rows[0] if rows else None

    def now(self):
        return self.one("SELECT now() AT TIME ZONE 'UTC'")[0]

    def entries(self, tables):
        rows = self.all(
            """SELECT table_number, result_seconds, result_pieces_placed, result_did_not_start, result_entered_by_id::text
               FROM competition_participant_round WHERE round_id = %s AND table_number = ANY(%s)""",
            (ROUND, list(tables)))
        return {row[0]: {'seconds': row[1], 'pieces': row[2], 'dns': row[3], 'by': row[4]} for row in rows}

    def receipts_since(self, since):
        rows = self.all(
            'SELECT status, count(*) FROM round_result_change_receipt WHERE round_id = %s AND received_at >= %s GROUP BY status',
            (ROUND, since.replace(microsecond=0)))
        return {status: count for status, count in rows}

    def proposals(self, participant):
        return self.all(
            """SELECT p.id::text, p.status, p.proposed_seconds, p.receipt_seconds, p.late_by_ms, p.tapped_at_ms,
                      p.received_at_ms, p.decided_seconds, p.decision_reason
               FROM competition_time_proposal p WHERE p.round_id = %s AND p.proposed_by_participant_id = %s
               ORDER BY p.received_at_ms""", (ROUND, participant))

    def stopwatch_started_ms(self):
        started = self.one('SELECT stopwatch_started_at FROM competition_round WHERE id = %s', (ROUND,))[0]
        return int(started.replace(tzinfo=__import__('datetime').timezone.utc).timestamp() * 1000)

    def clear_tables(self, tables):
        """Back to "no result" for a scenario's own tables, so it can run again (the tool's data only)."""
        self.all("""DELETE FROM competition_time_proposal WHERE participant_round_id IN
                    (SELECT id FROM competition_participant_round WHERE round_id = %s AND table_number = ANY(%s))""",
                 (ROUND, list(tables)))
        self.all("""UPDATE competition_participant_round SET result_seconds = NULL, result_pieces_placed = NULL,
                    result_did_not_start = false, result_entered_at = NULL, result_entered_by_id = NULL, result_puzzles = NULL
                    WHERE round_id = %s AND table_number = ANY(%s)""", (ROUND, list(tables)))


def expect_results(db, tables, by, values=None):
    """Every table holds exactly its value, entered by `by` - the problems as a list (empty = fine)."""
    rows = db.entries(tables)
    problems = []
    for table in tables:
        want = (values or {}).get(table, seconds_for(table))
        row = rows.get(table)
        if row is None or row['seconds'] != want:
            problems.append('table %d: %s, expected %s' % (table, None if row is None else row['seconds'], want))
        elif by is not None and row['by'] != by:
            problems.append('table %d entered by %s, expected %s' % (table, row['by'], by))
    return problems


def flush_rate_limits():
    """The login limiter (5 a minute per e-mail) lives in the stack's own Redis cache db - a test signs in more often."""
    with socket.create_connection(REDIS, timeout=5) as connection:
        connection.sendall(b'*2\r\n$6\r\nSELECT\r\n$1\r\n1\r\n*1\r\n$7\r\nFLUSHDB\r\n')
        time.sleep(0.2)
        connection.recv(1024)


# ---------------------------------------------------------------- browser

PAGE_INFO_JS = r"""
const q = (t) => document.querySelector(`[data-live-results-target="${t}"]`);
const shown = (el) => !!el && !el.hidden && el.offsetParent !== null;
const pill = q('pill');
const text = (el) => el ? el.textContent.replace(/\s+/g, ' ').trim() : null;
return {
    url: location.href,
    shell: !!document.querySelector('[data-live-results-shell-value="true"]'),
    pillState: pill ? pill.dataset.state : null,
    pillText: text(q('pillText')),
    banner: shown(q('banner')) ? text(q('banner')) : null,
    find: shown(q('findView')),
    entry: shown(q('entryView')),
    confirm: shown(q('confirmView')),
    entryName: shown(q('entryView')) ? text(q('entryName')) : null,
    entryTable: shown(q('entryView')) ? text(q('entryTable')) : null,
    entryNow: shown(q('entryView')) ? text(q('entryNow')) : null,
    problems: shown(q('entryView')) ? text(q('entryProblems')) : null,
    confirmNote: shown(q('confirmView')) ? text(q('confirmNote')) : null,
    confirmResult: shown(q('confirmView')) ? text(q('confirmResult')) : null,
    timeValue: q('timeInput') ? q('timeInput').value : null,
    findNotice: shown(q('findNotice')) ? text(q('findNotice')) : null,
    toast: shown(q('toast')) ? text(q('toastText')) : null,
    stopwatch: text(q('stopwatch')),
    proposalsButton: shown(q('proposalsButton')) ? text(q('proposalsButton')) : null,
    proposal: shown(q('proposal')) ? text(q('proposal')) : null,
    scanner: shown(q('scanner')),
    scanHint: q('scanHint') ? text(q('scanHint')) : null,
    body: document.body ? document.body.innerText.slice(0, 600) : '',
};
"""

OUTBOX_JS = r"""
const done = arguments[arguments.length - 1];
const open = indexedDB.open('msp-official-results');
open.onerror = () => done({error: 'open'});
open.onsuccess = () => {
    const db = open.result;
    if (!db.objectStoreNames.contains('outbox')) { db.close(); done([]); return; }
    const all = db.transaction('outbox').objectStore('outbox').getAll();
    all.onsuccess = () => { db.close(); done(all.result.map((r) => ({id: r.id, userId: r.userId, entryRef: r.entryRef, field: r.field,
        to: r.to, state: r.state, attempts: r.attempts}))); };
    all.onerror = () => { db.close(); done({error: 'read'}); };
};
"""

SNAPSHOTS_JS = r"""
const done = arguments[arguments.length - 1];
const open = indexedDB.open('msp-live-entry');
open.onerror = () => done([]);
open.onsuccess = () => {
    const db = open.result;
    if (!db.objectStoreNames.contains('snapshots')) { db.close(); done([]); return; }
    const all = db.transaction('snapshots').objectStore('snapshots').getAll();
    all.onsuccess = () => { db.close(); done(all.result.map((r) => [r.key, r.savedAt])); };
    all.onerror = () => { db.close(); done([]); };
};
"""

SHELL_READY_JS = r"""
const done = arguments[arguments.length - 1];
(async () => {
    const names = await caches.keys();
    const shell = names.find((n) => n.startsWith('live-shell-'));
    if (!shell) { done({ready: false, caches: names}); return; }
    const keys = await (await caches.open(shell)).keys();
    done({ready: keys.length > 0, caches: names, entries: keys.map((k) => new URL(k.url).pathname)});
})().catch((e) => done({ready: false, error: String(e)}));
"""


class Browser:
    """One Chrome (its own profile) in the stack's Selenium, as a phone: 375x812 mobile emulation."""

    def __init__(self, name, video=None, mobile=True, extra_args=()):
        self.name = name
        options = webdriver.ChromeOptions()
        for arg in ('--unsafely-treat-insecure-origin-as-secure=%s' % SITE, '--force-prefers-reduced-motion',
                    '--disable-features=Translate,OptimizationHints', '--no-first-run', '--lang=en-US',
                    '--window-size=430,932', *extra_args):
            options.add_argument(arg)
        if video is not None:
            options.add_argument('--use-fake-ui-for-media-stream')
            options.add_argument('--use-fake-device-for-media-stream')
            options.add_argument('--use-file-for-fake-video-capture=%s/%s' % (MEDIA_IN_SELENIUM, video))
        if mobile:
            options.add_experimental_option('mobileEmulation', {
                'deviceMetrics': {'width': 375, 'height': 812, 'pixelRatio': 2.0, 'touch': True},
                'userAgent': 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) '
                             'Chrome/131.0.0.0 Mobile Safari/537.36 judge-chaos',
            })
        options.set_capability('goog:loggingPrefs', {'browser': 'ALL'})
        # A reload with unsent items asks "Leave site?" (beforeunload) - the phone's user says yes
        options.unhandled_prompt_behavior = 'accept'
        self.driver = webdriver.Remote(command_executor=SELENIUM, options=options)
        self.driver.command_executor._commands['executeCdpCommand'] = ('POST', '/session/$sessionId/goog/cdp/execute')
        self.driver.set_script_timeout(90)
        self.driver.set_page_load_timeout(60)
        self.offline_emulated = False
        self.ignored_taps = []

    # -- plumbing
    def cdp(self, cmd, params=None):
        return self.driver.execute('executeCdpCommand', {'cmd': cmd, 'params': params or {}})['value']

    def js(self, script, *args):
        return self.driver.execute_script(script, *args)

    def js_async(self, script, *args):
        return self.driver.execute_async_script(script, *args)

    def go(self, path):
        try:
            self.driver.get(path if path.startswith('http') else SITE + path)
        except TimeoutException:
            pass

    def quit(self):
        try:
            self.driver.quit()
        except Exception:
            pass

    def shot(self, label):
        path = '%s/%s-%s-%d.png' % (OUT, label, self.name, int(time.time()))
        try:
            self.driver.save_screenshot(path)
        except WebDriverException:
            return None
        return path

    def console(self):
        try:
            return [entry['message'][:300] for entry in self.driver.get_log('browser')]
        except Exception:
            return []

    def wait(self, predicate, timeout=20, interval=0.2, message='condition'):
        deadline = time.time() + timeout
        last = None
        while time.time() < deadline:
            try:
                last = predicate()
                if last:
                    return last
            except (JavascriptException, StaleElementReferenceException):
                pass
            time.sleep(interval)
        raise Failed('%s: timed out after %ss waiting for %s (last: %s)' % (self.name, timeout, message, last))

    # -- network
    def offline(self, on=True):
        self.cdp('Network.enable')
        self.cdp('Network.emulateNetworkConditions', {'offline': bool(on), 'latency': 0, 'downloadThroughput': -1,
                                                      'uploadThroughput': -1})
        self.offline_emulated = bool(on)

    def block_urls(self, patterns):
        self.cdp('Network.enable')
        self.cdp('Network.setBlockedURLs', {'urls': patterns})

    def cpu(self, rate):
        self.cdp('Emulation.setCPUThrottlingRate', {'rate': rate})

    # -- sign-in
    def login(self, who):
        _, email, _ = PLAYERS[who]
        flush_rate_limits()
        self.go('/login')
        self.wait(lambda: self.driver.find_elements(By.CSS_SELECTOR, '#login-form input[name=email]'), message='login form')
        self.driver.find_element(By.CSS_SELECTOR, '#login-form input[name=email]').send_keys(email)
        password = self.driver.find_element(By.CSS_SELECTOR, '#login-form input[name=password]')
        password.send_keys(PASSWORD)
        password.send_keys(Keys.ENTER)
        self.wait(lambda: '/login' not in self.driver.current_url, timeout=20, message='signed in')

    def cookies(self):
        return {cookie['name']: cookie for cookie in self.driver.get_cookies()}

    # -- the live entry page
    def info(self):
        return self.js(PAGE_INFO_JS)

    def open_live(self, round_id=ROUND, wait_ready=True, query=''):
        self.go('/en/live-results/%s%s' % (round_id, query))
        if wait_ready:
            self.wait_live()

    def wait_live(self, timeout=30):
        return self.wait(lambda: (lambda i: i if i['pillState'] in ('saved', 'waiting', 'problem', 'blocked')
                                  and 'Loading' not in (i['pillText'] or '') and i['stopwatch'] not in ('', None) else None)(self.info()),
                         timeout=timeout, message='the live entry page ready')

    def outbox(self):
        return self.js_async(OUTBOX_JS)

    def shell_ready(self):
        return self.js_async(SHELL_READY_JS)

    def wait_offline_ready(self, timeout=60):
        return self.wait(lambda: (lambda s: s if s.get('ready') else None)(self.shell_ready()), timeout=timeout,
                         interval=1, message='the offline copy stored (live-shell cache)')

    def wait_saved(self, timeout=90):
        """"All saved" on the pill and nothing left in the outbox (the pill alone may still show the state before a
        save that is being stored)."""
        return self.wait(lambda: (lambda i: i if i['pillState'] == 'saved' and self.outbox() == [] else None)(self.info()),
                         timeout=timeout, interval=0.5, message='"All saved" and an empty outbox')

    def snapshots(self):
        return self.js_async(SNAPSHOTS_JS)

    def wait_snapshot(self, round_id=ROUND, newer_than_ms=0, timeout=30):
        """The round's snapshot (C4) on this device, saved after `newer_than_ms` - the offline copy has data to show."""
        def stored():
            for key, saved_at in self.snapshots():
                if key.endswith(':' + round_id) and saved_at > newer_than_ms:
                    return saved_at
            return None
        return self.wait(stored, timeout=timeout, interval=0.25, message='the round snapshot in IndexedDB')

    def find_input(self):
        return self.driver.find_element(By.CSS_SELECTOR, '[data-live-results-target="findInput"]')

    def back_to_find(self):
        info = self.info()
        if info['find']:
            return
        buttons = [b for b in self.driver.find_elements(By.CSS_SELECTOR, '[data-action="live-results#backToFind"]') if b.is_displayed()]
        if buttons:
            buttons[0].click()
        self.wait(lambda: self.info()['find'], timeout=5, message='Find')

    def open_table(self, table):
        """Types the table number into Find like a judge (a unique table opens by itself, else Enter)."""
        self.back_to_find()
        field = self.find_input()
        field.clear()
        field.send_keys(str(table))
        time.sleep(0.15)
        if not self.info()['entry']:
            field.send_keys(Keys.ENTER)
        info = self.wait(lambda: (lambda i: i if i['entry'] else None)(self.info()), timeout=10, message='entry card of table %d' % table)
        check(info['entryTable'] is None or str(table) in info['entryTable'],
              'table %d typed, card of %s opened' % (table, info['entryTable']))
        return info

    def click(self, css, timeout=10):
        def clickable():
            for element in self.driver.find_elements(By.CSS_SELECTOR, css):
                if element.is_displayed():
                    return element
            return None
        element = self.wait(clickable, timeout=timeout, message=css)
        try:
            element.click()
        except ElementClickInterceptedException:
            self.js('arguments[0].click()', element)

    def type_time(self, seconds):
        field = self.driver.find_element(By.CSS_SELECTOR, '[data-live-results-target="timeInput"]')
        field.clear()
        field.send_keys(typed(seconds))
        field.send_keys(Keys.ENTER)
        self.wait(lambda: self.info()['confirm'], timeout=10, message='the confirm step')

    def save(self):
        time.sleep(0.45)  # Save ignores a tap within 350 ms of the confirm step showing up (C7)
        before = self.info()
        self.click('[data-live-results-target="saveButton"]')
        try:
            self.wait(lambda: self.info()['find'], timeout=3, message='back to Find after Save')
        except Failed:
            # The tap did nothing - kept as evidence (what was on the screen), then tapped once more
            after = self.info()
            self.ignored_taps.append({'before': {k: before[k] for k in ('banner', 'pillText', 'confirmResult')},
                                      'after': {k: after[k] for k in ('banner', 'pillText', 'confirm', 'entry')}})
            if after['confirm']:
                time.sleep(0.45)
                self.click('[data-live-results-target="saveButton"]')
            self.wait(lambda: self.info()['find'], timeout=10, message='back to Find after a second Save tap')

    def enter_result(self, table, seconds=None):
        self.open_table(table)
        self.type_time(seconds if seconds is not None else seconds_for(table))
        self.save()

    def undo(self):
        self.click('[data-live-results-target="toastUndo"]', timeout=5)


def save_evidence(name, data):
    path = '%s/%s.json' % (OUT, name)
    with open(path, 'w') as handle:
        json.dump(data, handle, indent=2, default=str)
    return path
