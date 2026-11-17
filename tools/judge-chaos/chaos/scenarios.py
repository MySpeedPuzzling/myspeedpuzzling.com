"""
The scenarios (tools/judge-chaos/README.md "Scenarios"). Each one is independent: its own tables of the chaos round,
its own browsers (fresh Chrome profiles), the proxy reset before and after. A scenario raises lib.Failed with what it
saw when the product does not do what the docs promise; `context['evidence']` and `context['notes']` go into the
results JSON either way.
"""

import io
import os
import statistics
import time

from selenium.webdriver.common.by import By

from . import lib
from .lib import (COMPETITION, PLAYERS, ROUND, ROUND_PAIRS, Browser, check, entry_id, expect_results, participant_id,
                  record_requests, seconds_for, typed)

QUICK = os.environ.get('QUICK', '0') == '1'
RECORD_PATH = r'/official-results/rounds/[0-9a-f-]+/changes'
REFEREE = PLAYERS['referee'][0]
ORGANISER = PLAYERS['organiser'][0]


def judge(context, who='referee', name=None, **options):
    browser = Browser(name or who, **options)
    context['browsers'].append(browser)
    browser.login(who)
    return browser


def network_down(proxy, *browsers):
    """The venue Wi-Fi gone: the proxy refuses every connection (the service worker's fetches too) and the browsers
    know it (navigator.onLine false, the `offline` event)."""
    proxy.down()
    for browser in browsers:
        browser.offline(True)


def network_up(proxy, *browsers):
    proxy.up()
    for browser in browsers:
        browser.offline(False)


def assert_results(context, tables, by, values=None):
    problems = expect_results(context['db'], tables, by, values)
    check(not problems, '; '.join(problems[:8]))


def assert_outbox_empty(browser):
    items = browser.outbox()
    check(items == [], 'the outbox still holds %s' % items)


# ---------------------------------------------------------------- 1

def s01_flapping(context):
    """1. 30 results while the network flaps (4 s up / 3 s down): all arrive exactly once, the page ends "All saved"."""
    db, proxy = context['db'], context['proxy']
    tables = list(range(1, 31))
    browser = judge(context)
    browser.open_live()
    browser.wait_offline_ready()
    since = db.now()
    mark = proxy.mark()
    proxy.state(flap={'up_s': 4, 'down_s': 3})
    started = time.time()
    states = set()
    for table in tables:
        browser.enter_result(table)
        states.add(browser.info()['pillText'])
    entering = time.time() - started
    proxy.up()
    drain_started = time.time()
    browser.wait_saved(120)
    context['evidence']['drain_seconds'] = round(time.time() - drain_started, 1)
    context['evidence']['entering_seconds'] = round(entering, 1)
    context['evidence']['pill_texts_seen'] = sorted(s for s in states if s)
    posts = record_requests(proxy, mark)
    context['evidence']['record_posts'] = len(posts)
    context['evidence']['record_outcomes'] = sorted({str(p['status']) + ' ' + str(p['outcome']) for p in posts})
    assert_results(context, tables, REFEREE)
    receipts = db.receipts_since(since)
    context['evidence']['receipts'] = receipts
    check(receipts == {'applied': 30}, 'receipts %s - expected exactly 30 applied' % receipts)
    assert_outbox_empty(browser)
    context['notes'].append('30 entered in %.0fs while flapping, drained %.1fs after, %d POSTs'
                            % (entering, context['evidence']['drain_seconds'], len(posts)))


# ---------------------------------------------------------------- 2

def s02_refresh_mid_save(context):
    """2. Reload right after Save (answer delayed / not yet sent), and a reload with a typed value and with the confirm
    step open: nothing lost, nothing doubled, the draft back."""
    db, proxy = context['db'], context['proxy']
    browser = judge(context)
    browser.open_live()
    since = db.now()
    mark = proxy.mark()

    # 31, 32: the answer is 3 s late, the judge reloads at once
    for table in (31, 32):
        proxy.rules([{'path': RECORD_PATH, 'method': 'POST', 'action': 'delay', 'ms': 3000, 'count': 1}])
        browser.open_table(table)
        browser.type_time(seconds_for(table))
        time.sleep(0.45)
        browser.click('[data-live-results-target="saveButton"]')
        browser.driver.refresh()
        browser.wait_live()
    # 33: reload in the same instant as the tap (no delay)
    proxy.rules([])
    browser.open_table(33)
    browser.type_time(seconds_for(33))
    time.sleep(0.45)
    browser.js("document.querySelector('[data-live-results-target=\"saveButton\"]').dispatchEvent(new PointerEvent('pointerdown', {bubbles: true}));"
               "document.querySelector('[data-live-results-target=\"saveButton\"]').click(); location.reload();")
    time.sleep(1)
    browser.wait_live()
    browser.wait_saved(30)

    # 34: a typed value, reload → the card with the value back
    browser.open_table(34)
    field = browser.driver.find_element(By.CSS_SELECTOR, '[data-live-results-target="timeInput"]')
    field.send_keys(typed(seconds_for(34)))
    time.sleep(0.5)
    browser.driver.refresh()
    browser.wait_live()
    info = browser.wait(lambda: (lambda i: i if i['entry'] else None)(browser.info()), timeout=10,
                        message='the draft of table 34 restored (entry card open)')
    context['evidence']['draft_typed'] = info
    check('034' in (info['entryName'] or ''), 'draft restored the wrong entry: %s' % info['entryName'])
    check(info['timeValue'] == typed(seconds_for(34)), 'typed value not restored: %r' % info['timeValue'])
    field = browser.driver.find_element(By.CSS_SELECTOR, '[data-live-results-target="timeInput"]')
    field.send_keys('\n')
    browser.wait(lambda: browser.info()['confirm'], timeout=5, message='confirm step')
    browser.save()

    # 35: the confirm step open, reload → confirm step back
    browser.open_table(35)
    browser.type_time(seconds_for(35))
    browser.driver.refresh()
    browser.wait_live()
    info = browser.wait(lambda: (lambda i: i if i['entry'] else None)(browser.info()), timeout=10,
                        message='the draft of table 35 restored')
    context['evidence']['draft_confirm'] = info
    check('035' in (info['entryName'] or ''), 'draft restored the wrong entry: %s' % info['entryName'])
    if info['confirm']:
        browser.save()
    else:
        context['notes'].append('confirm step came back as the entry card (value %r)' % info['timeValue'])
        browser.type_time(seconds_for(35))
        browser.save()

    browser.wait_saved(30)
    tables = [31, 32, 33, 34, 35]
    assert_results(context, tables, REFEREE)
    receipts = db.receipts_since(since)
    posts = record_requests(proxy, mark)
    context['evidence'].update(receipts=receipts, record_posts=len(posts),
                               record_outcomes=[(p['status'], p['outcome']) for p in posts])
    check(receipts == {'applied': 5}, 'receipts %s - expected exactly 5 applied' % receipts)
    assert_outbox_empty(browser)
    context['notes'].append('%d POSTs for 5 saves' % len(posts))


# ---------------------------------------------------------------- 3

def s03_offline_reload(context):
    """3. Offline reload (the worker's shell) → 5 results → back online → sent; a cold open of a table tag and a name
    tag offline; the 4 s silence path (blackhole)."""
    db, proxy = context['db'], context['proxy']
    browser = judge(context)
    ready_at = time.time()
    browser.open_live()
    browser.wait_offline_ready()
    browser.wait_snapshot()
    context['evidence']['first_snapshot_after_s'] = round(time.time() - ready_at, 1)
    since = db.now()

    network_down(proxy, browser)
    browser.driver.refresh()
    info = browser.wait_live(20)
    context['evidence']['offline_reload'] = info
    check(info['shell'], 'the offline reload did not get the shell: %s' % info['url'])
    check('No connection' in info['body'] or info['banner'], 'no offline-copy strip on the shell')
    for table in range(36, 41):
        browser.enter_result(table)
    info = browser.wait(lambda: (lambda i: i if '5 waiting' in (i['pillText'] or '') else None)(browser.info()), timeout=5,
                        message='"5 waiting - offline"')
    context['evidence']['after_5_offline'] = info

    # cold open of a table tag (no locale) and of a printed name tag, offline
    browser.go('/live/%s/t/41' % COMPETITION)
    info = browser.wait(lambda: (lambda i: i if i['entry'] else None)(browser.info()), timeout=20,
                        message='table tag 41 opened offline')
    context['evidence']['cold_table_tag'] = info
    check('041' in (info['entryName'] or ''), 'table tag 41 opened %s' % info['entryName'])
    browser.type_time(seconds_for(41))
    browser.save()
    browser.go('/en/live/%s/p/%s' % (COMPETITION, participant_id(42)))
    info = browser.wait(lambda: (lambda i: i if i['entry'] else None)(browser.info()), timeout=20,
                        message='name tag of entrant 42 opened offline')
    context['evidence']['cold_name_tag'] = info
    check('042' in (info['entryName'] or ''), 'name tag 42 opened %s' % info['entryName'])
    browser.type_time(seconds_for(42))
    browser.save()
    pending = browser.outbox()
    check(len(pending) == 7, 'outbox before going online: %d items' % len(pending))

    network_up(proxy, browser)
    started = time.time()
    browser.wait_saved(60)
    context['evidence']['sync_seconds'] = round(time.time() - started, 1)
    context['evidence']['url_after_live'] = browser.info()['url']
    tables = list(range(36, 43))
    assert_results(context, tables, REFEREE)
    receipts = db.receipts_since(since)
    context['evidence']['receipts'] = receipts
    check(receipts == {'applied': 7}, 'receipts %s - expected 7 applied' % receipts)

    # the network silent (connected, nothing answers): the worker's 4 s timeout answers the shell
    time.sleep(2.5)
    proxy.state(blackhole=True)
    started = time.time()
    browser.go('/en/live-results/%s' % ROUND)
    info = browser.wait(lambda: (lambda i: i if i['shell'] and i['stopwatch'] else None)(browser.info()), timeout=30,
                        message='the shell after 4 s of silence')
    context['evidence']['blackhole_seconds_to_shell'] = round(time.time() - started, 1)
    proxy.up()
    context['notes'].append('7 synced %.1fs after the network came back; silent network → shell in %.1fs'
                            % (context['evidence']['sync_seconds'], context['evidence']['blackhole_seconds_to_shell']))


def s03b_snapshot_timing(context):
    """3b. The offline copy right after opening (reload offline 1 s after the page went live) and the round's snapshot
    under a steady stream of the other judges' saves (does it keep being written?)."""
    db, proxy = context['db'], context['proxy']
    browser = judge(context, 'referee', name='judgeA')
    browser.open_live()
    browser.wait_offline_ready()
    early = browser.snapshots()
    network_down(proxy, browser)
    browser.driver.refresh()
    time.sleep(3)
    info = browser.info()
    context['evidence']['early_offline_reload'] = {'snapshots_before_reload': early, 'body': info['body'][:300],
                                                   'snapshots_after': browser.snapshots()}
    network_up(proxy, browser)
    early_ok = info['stopwatch'] not in ('', None) and 'No offline copy' not in info['body']

    # Starvation: the other judge saves a result about every second for 30 s; the first page stays open
    browser.open_live()
    first = browser.wait_snapshot()
    other = judge(context, 'organiser', name='judgeB')
    other.open_live()
    stream_started_ms = int(time.time() * 1000)
    writes = set()
    for table in range(201, 226):
        other.enter_result(table)
        writes.update(saved for key, saved in browser.snapshots() if key.endswith(ROUND))
    stream_ms = int(time.time() * 1000) - stream_started_ms
    during = sorted(w for w in writes if w > stream_started_ms)
    gaps = [b - a for a, b in zip([stream_started_ms] + during, during + [int(time.time() * 1000)])]
    context['evidence']['starvation'] = {'stream_ms': stream_ms, 'snapshot_writes_during': len(during),
                                         'longest_gap_ms': max(gaps) if gaps else None}
    other.wait_saved(30)
    problems = []
    if not early_ok:
        problems.append('reload offline right after opening: %r (the pagehide flush did not land; first write only '
                        'after the 2 s debounce settles)' % info['body'][:120])
    longest = context['evidence']['starvation']['longest_gap_ms'] or 0
    if not during or longest > 10000:
        problems.append('SNAPSHOT LAGS: %d snapshot writes during %.0f s of the other judge\'s saves (25 live '
                        'updates), the longest stretch without one %.1f s - an offline reload then shows the round '
                        'that old' % (len(during), stream_ms / 1000, longest / 1000))
    check(not problems, ' | '.join(problems))
    context['notes'].append('%d snapshot writes during a 25-save stream, longest gap %s ms'
                            % (len(during), context['evidence']['starvation']['longest_gap_ms']))


# ---------------------------------------------------------------- 4

def s04_two_judges(context):
    """4. Two judges, one entry, different values: the second gets the conflict, nothing silently overwritten - offline
    (46) and both online with live updates (47)."""
    db, proxy = context['db'], context['proxy']
    first = judge(context, 'referee', name='judgeA')
    second = judge(context, 'organiser', name='judgeB')
    first.open_live()
    second.open_live()

    # 46: B saves offline (no live updates), A saves online, B comes back
    second.block_urls(['*/.well-known/mercure*'])
    second.offline(True)
    second.enter_result(46, seconds_for(46) + 600)
    first.enter_result(46)
    first.wait_saved(20)
    second.block_urls([])
    second.offline(False)
    info = second.wait(lambda: (lambda i: i if i['pillState'] == 'problem' else None)(second.info()), timeout=45,
                       message='the conflict on the second judge')
    context['evidence']['conflict_pill'] = info['pillText']
    row = db.entries([46])[46]
    check(row['seconds'] == seconds_for(46) and row['by'] == REFEREE,
          'table 46 after the conflict: %s (the first judge saved %d)' % (row, seconds_for(46)))
    second.open_table(46)
    problems = second.info()['problems']
    context['evidence']['conflict_card'] = problems
    check(problems and 'meanwhile' in problems, 'no conflict on the card: %r' % problems)
    second.click('[data-action="live-results#takeTheirs"]')
    second.wait_saved(20)

    # 47: both online. B has the confirm step open with its value, A saves, the update reaches B, B taps Save
    second.back_to_find()
    second.open_table(47)
    second.type_time(seconds_for(47) + 600)
    before = second.info()
    first.enter_result(47)
    first.wait_saved(20)
    time.sleep(3)
    shown = second.info()
    context['evidence']['b_confirm_before_a'] = before['confirmNote']
    context['evidence']['b_confirm_after_a'] = shown['confirmNote']
    context['evidence']['b_entry_now_after_a'] = shown['entryNow']
    time.sleep(0.2)
    second.click('[data-live-results-target="saveButton"]')
    time.sleep(1)
    try:
        second.wait(lambda: second.info()['pillState'] in ('saved', 'problem'), timeout=20, message='B settled')
    except lib.Failed:
        pass
    after = second.info()
    row = db.entries([47])[47]
    context['evidence']['live_race'] = {'db': row, 'b_pill': after['pillText'], 'b_problems': after['problems']}
    a_value = typed(seconds_for(47))
    saw_a = a_value in (shown['confirmNote'] or '') or a_value in (shown['entryNow'] or '')
    if row['seconds'] == seconds_for(47) + 600:
        check(saw_a, 'SILENT OVERWRITE: B saved %s over A\'s %s - B\'s confirm step said %r (before A: %r)'
              % (typed(seconds_for(47) + 600), a_value, shown['confirmNote'], before['confirmNote']))
        context['notes'].append('47: B replaced A\'s value having seen it ("%s")' % shown['confirmNote'])
    else:
        check(after['pillState'] == 'problem', 'B\'s save of 47 vanished: DB %s, B pill %r' % (row, after['pillText']))
        context['notes'].append('47: B got the conflict')
    context['notes'].append('46: conflict shown (%s), first judge\'s value kept' % context['evidence']['conflict_pill'])


# ---------------------------------------------------------------- 5

SW_STATE_JS = r"""
const done = arguments[arguments.length - 1];
navigator.serviceWorker.getRegistration().then((r) => done({
    controller: navigator.serviceWorker.controller ? navigator.serviceWorker.controller.state : null,
    active: r && r.active ? r.active.state : null, waiting: !!(r && r.waiting), installing: !!(r && r.installing),
    changes: window.__chaosControllerChanges || 0, marker: window.__chaosMarker || null,
}));
"""


def s05_worker_update(context):
    """5. A deploy (changed service-worker.js) while the page is open with unsent results: the page keeps working, the
    outbox drains, the offline copy stays usable."""
    db, proxy = context['db'], context['proxy']
    browser = judge(context)
    browser.open_live()
    browser.wait_offline_ready()
    browser.js("window.__chaosMarker = 'before-update'; window.__chaosControllerChanges = 0;"
               "navigator.serviceWorker.addEventListener('controllerchange', () => { window.__chaosControllerChanges++; });")
    since = db.now()
    # The record endpoint "busy" (503 HTML), so the results stay unsent while the worker updates
    proxy.rules([{'path': RECORD_PATH, 'method': 'POST', 'action': 'status', 'status': 503, 'count': None}])
    for table in (51, 52, 53):
        browser.enter_result(table)
    proxy.state(sw_variant=int(time.time()))
    browser.js_async("const done = arguments[0]; navigator.serviceWorker.getRegistration().then((r) => r.update()).then(() => done(true), (e) => done(String(e)));")
    state = browser.wait(lambda: (lambda s: s if s['changes'] >= 1 or (s['active'] == 'activated' and not s['waiting'] and not s['installing'] and s['changes'] >= 1) else None)(browser.js_async(SW_STATE_JS)),
                         timeout=30, message='the new worker in control')
    context['evidence']['worker_after_update'] = state
    check(state['marker'] == 'before-update', 'the page reloaded itself on the worker update (marker %r)' % state['marker'])
    browser.enter_result(54)
    check(len(browser.outbox()) == 4, 'outbox while the server is busy: %d' % len(browser.outbox()))
    proxy.rules([])
    browser.wait_saved(150)  # the busy pause: 5 s, 15 s, 30 s …
    assert_results(context, [51, 52, 53, 54], REFEREE)

    # the offline copy after the update
    ready = browser.shell_ready()
    context['evidence']['shell_after_update'] = ready
    check(ready.get('ready'), 'the offline copy is gone after the update: %s' % ready)
    browser.wait_snapshot()
    network_down(proxy, browser)
    browser.driver.refresh()
    info = browser.wait_live(20)
    check(info['shell'], 'offline reload after the update did not get the shell')
    browser.enter_result(55)
    network_up(proxy, browser)
    browser.wait_saved(60)
    assert_results(context, [51, 52, 53, 54, 55], REFEREE)
    receipts = db.receipts_since(since)
    context['evidence']['receipts'] = receipts
    check(receipts == {'applied': 5}, 'receipts %s - expected 5 applied' % receipts)


# ---------------------------------------------------------------- 6

def sign_in_in_new_tab(browser, who):
    main = browser.driver.current_window_handle
    browser.driver.switch_to.new_window('tab')
    for name in ('PHPSESSID', 'REMEMBERME'):
        browser.driver.delete_cookie(name)
    browser.login(who)
    browser.driver.close()
    browser.driver.switch_to.window(main)


def s06_session_expires(context):
    """6. The referee's session expires mid-event: unsent results kept, "Sign in again", another account signing in on
    the phone never sends them, the referee signing in sends them - none lost, none sent as somebody else."""
    db, proxy = context['db'], context['proxy']
    browser = judge(context)
    browser.open_live()
    since = db.now()
    context['evidence']['cookies_before'] = sorted(browser.cookies())
    for name in ('PHPSESSID', 'REMEMBERME'):
        browser.driver.delete_cookie(name)
    tables = [61, 62, 63]
    for table in tables:
        browser.enter_result(table)
    info = browser.wait(lambda: (lambda i: i if i['pillState'] == 'blocked' else None)(browser.info()), timeout=30,
                        message='"Sign in again"')
    context['evidence']['signed_out'] = {'pill': info['pillText'], 'banner': info['banner']}
    check(len(browser.outbox()) == 3, 'outbox after the session expired: %s' % browser.outbox())
    check(db.receipts_since(since) == {}, 'something reached the server while signed out')

    # Another account signs in on this phone (a shared phone; the organiser lends theirs)
    sign_in_in_new_tab(browser, 'organiser')
    retry = [b for b in browser.driver.find_elements(By.CSS_SELECTOR, '[data-action="live-results#retry"]') if b.is_displayed()]
    if retry:
        retry[0].click()
    time.sleep(8)
    rows = db.entries(tables)
    by_organiser = [t for t, r in rows.items() if r['by'] == ORGANISER]
    context['evidence']['after_other_account'] = {'db': rows, 'pill': browser.info()['pillText'],
                                                  'banner': browser.info()['banner']}
    check(not by_organiser, 'SENT AS SOMEBODY ELSE: tables %s saved as the organiser after another account signed in '
                            '(the page was the referee\'s; pill %r)' % (by_organiser, browser.info()['pillText']))

    sign_in_in_new_tab(browser, 'referee')
    retry = [b for b in browser.driver.find_elements(By.CSS_SELECTOR, '[data-action="live-results#retry"]') if b.is_displayed()]
    if retry:
        retry[0].click()
    browser.wait_saved(60)
    assert_results(context, tables, REFEREE)
    receipts = db.receipts_since(since)
    context['evidence']['receipts'] = receipts
    check(receipts == {'applied': 3}, 'receipts %s - expected 3 applied' % receipts)


# ---------------------------------------------------------------- 7

def competitor(context, who, number):
    browser = Browser(who)
    context['browsers'].append(browser)
    browser.login(who)
    browser.go('/en/competitor/%s/p/%s' % (COMPETITION, participant_id(number)))
    return browser


def propose_status(browser):
    return browser.js("""const s = document.querySelector('.competitor-propose-status');
        const stop = document.querySelector('[data-competitor-propose="stop"]');
        const mode = s ? [...s.classList].find((c) => c.startsWith('is-')) : null;
        return {mode: mode ? mode.slice(3) : null,
                text: s ? s.textContent.replace(/\\s+/g, ' ').trim() : null, stop: !!stop};""")


def wait_mode(browser, modes, timeout=30):
    return browser.wait(lambda: (lambda s: s if s['mode'] in modes else None)(propose_status(browser)), timeout=timeout,
                        interval=0.5, message='competitor panel %s' % '/'.join(modes))


def tap_stop(browser):
    browser.click('[data-competitor-propose="stop"]', timeout=20)
    tapped_ms = int(time.time() * 1000)
    time.sleep(0.3)
    browser.click('[data-competitor-propose="confirm"]')
    return tapped_ms


def judge_confirms(judge_browser, number, context=None):
    judge_browser.back_to_find()
    started = time.time()
    judge_browser.wait(lambda: judge_browser.info()['proposalsButton'], timeout=30, interval=1,
                       message='"Proposals waiting" on the judge page')
    judge_browser.click('[data-live-results-target="proposalsButton"]')
    row = judge_browser.wait(lambda: next((r for r in judge_browser.driver.find_elements(By.CSS_SELECTOR, '.lr-row[data-action="live-results#openRow"]')
                                           if r.is_displayed() and ('Chaos Tester %03d' % number) in r.text), None),
                             timeout=90, interval=0.5, message='the proposal row of %03d' % number)
    if context is not None:
        context['evidence']['proposal_row_after_s_%d' % number] = round(time.time() - started, 1)
    row.click()
    info = judge_browser.wait(lambda: (lambda i: i if i['entry'] and i['proposal'] else None)(judge_browser.info()),
                              timeout=10, message='the proposal panel')
    check(('%03d' % number) in (info['entryName'] or ''), 'proposal row opened %s' % info['entryName'])
    time.sleep(0.5)
    judge_browser.click('[data-proposal-confirm]')
    judge_browser.wait_saved(30)
    return info


def s07_proposals_offline(context):
    """7. A competitor proposes offline → online → the judge confirms → "Confirmed"; a proposal + reload while offline
    keeps the tap's original time."""
    db, proxy = context['db'], context['proxy']
    referee = judge(context)
    referee.open_live()

    john = competitor(context, 'john', 300)
    wait_mode(john, ['stop'], 30) if propose_status(john)['mode'] else john.wait(lambda: propose_status(john)['stop'], timeout=30, message='Stop button')
    john.offline(True)
    tapped = tap_stop(john)
    status = wait_mode(john, ['offline', 'sending'], 15)
    context['evidence']['john_offline'] = status
    time.sleep(5)
    john.offline(False)
    status = wait_mode(john, ['pending'], 40)
    context['evidence']['john_pending'] = status
    info = judge_confirms(referee, 300, context)
    context['evidence']['judge_panel'] = info['proposal']
    status = wait_mode(john, ['confirmed', 'changed'], 30)
    context['evidence']['john_final'] = status
    rows = db.proposals(participant_id(300))
    context['evidence']['john_proposals'] = rows
    check(len(rows) == 1 and rows[0][1] == 'confirmed', 'john\'s proposals: %s' % rows)
    check(abs(rows[0][5] - tapped) < 3000, 'tap time %d vs the click at %d' % (rows[0][5], tapped))
    result = db.entries([300])[300]
    check(result['by'] == REFEREE and result['seconds'] == rows[0][7], 'entry 300: %s, decided %s' % (result, rows[0][7]))
    context['notes'].append('john: late_by %d ms, proposed %s, confirmed as %s' % (rows[0][4], typed(rows[0][2]), typed(rows[0][7])))

    # Jane: tap offline, reload offline, wait, back online
    jane = competitor(context, 'jane', 299)
    jane.wait(lambda: propose_status(jane)['stop'], timeout=30, message='Stop button')
    network_down(proxy, jane, referee)
    tapped = tap_stop(jane)
    wait_mode(jane, ['offline', 'sending'], 15)
    jane.driver.refresh()
    time.sleep(1)
    context['evidence']['jane_offline_reload'] = jane.js('return document.body ? document.body.innerText.slice(0, 200) : null')
    time.sleep(12)
    network_up(proxy, jane, referee)
    jane.go('/en/competitor/%s/p/%s' % (COMPETITION, participant_id(299)))
    status = wait_mode(jane, ['pending'], 40)
    context['evidence']['jane_pending'] = status
    rows = db.proposals(participant_id(299))
    context['evidence']['jane_proposals'] = rows
    check(len(rows) == 1 and rows[0][1] == 'pending', 'jane\'s proposals: %s' % rows)
    check(abs(rows[0][5] - tapped) < 3000, 'the tap kept its time? tapped_at %d vs the click at %d (%+d ms)'
          % (rows[0][5], tapped, rows[0][5] - tapped))
    context['notes'].append('jane: tap kept (%+d ms), late_by %d ms → judge sees both times' % (rows[0][5] - tapped, rows[0][4]))


# ---------------------------------------------------------------- 8

def s08_undo(context):
    """8. Undo of a sent save, of one still in the outbox, and of a confirmed proposal."""
    db, proxy = context['db'], context['proxy']
    browser = judge(context)
    browser.open_live()
    since = db.now()

    browser.enter_result(71)
    browser.wait_saved(20)
    browser.undo()
    info = browser.wait(lambda: (lambda i: i if i['entry'] else None)(browser.info()), timeout=5, message='entry reopened by Undo')
    context['evidence']['undo_sent'] = info['toast']
    browser.wait_saved(20)
    check(db.entries([71])[71]['seconds'] is None, '71 still has a result after Undo: %s' % db.entries([71])[71])

    network_down(proxy, browser)
    browser.back_to_find()
    browser.enter_result(72)
    browser.undo()
    time.sleep(0.5)
    check(browser.outbox() == [], 'outbox after undoing an unsent save: %s' % browser.outbox())
    network_up(proxy, browser)
    time.sleep(5)
    check(db.entries([72])[72]['seconds'] is None, '72 reached the server: %s' % db.entries([72])[72])
    receipts = db.receipts_since(since)
    context['evidence']['receipts'] = receipts
    check(receipts == {'applied': 2}, 'receipts %s - expected 2 (71 and its Undo)' % receipts)

    # A confirmed proposal undone
    browser.back_to_find()
    admin = competitor(context, 'admin', 298)
    admin.wait(lambda: propose_status(admin)['stop'], timeout=30, message='Stop button')
    tap_stop(admin)
    wait_mode(admin, ['pending'], 30)
    judge_confirms(browser, 298)
    browser.undo()
    browser.wait_saved(20)
    rows = db.proposals(participant_id(298))
    context['evidence']['proposal_after_undo'] = rows
    check(rows and rows[-1][1] == 'superseded' and rows[-1][8] == 'result_cleared', 'proposal after Undo: %s' % rows)
    check(db.entries([298])[298]['seconds'] is None, '298 kept its result: %s' % db.entries([298])[298])
    status = wait_mode(admin, ['cleared'], 30)
    context['evidence']['competitor_after_undo'] = status
    check(status['stop'], 'no Stop button again for the competitor after the judge undid the confirmation')


# ---------------------------------------------------------------- 9

def s09_server_bursts(context):
    """9. 500 / 429 / timeout / HTML 503 / 502 bursts on the saves, then answers lost after the server saved: backoff,
    nothing lost, nothing doubled, the pill right."""
    db, proxy = context['db'], context['proxy']
    browser = judge(context)
    browser.open_live()
    since = db.now()
    mark = proxy.mark()
    proxy.rules([
        {'path': RECORD_PATH, 'method': 'POST', 'action': 'status', 'status': 500, 'count': 3,
         'content_type': 'text/html', 'body': '<html>Internal Server Error</html>'},
        {'path': RECORD_PATH, 'method': 'POST', 'action': 'status', 'status': 429, 'retry_after': 3, 'count': 2},
        {'path': RECORD_PATH, 'method': 'POST', 'action': 'hang', 'count': 1},
        {'path': RECORD_PATH, 'method': 'POST', 'action': 'status', 'status': 503, 'count': 2},
        {'path': RECORD_PATH, 'method': 'POST', 'action': 'status', 'status': 502, 'count': 2},
    ])
    pills = set()
    for table in range(81, 91):
        browser.enter_result(table)
        pills.add((browser.info()['pillState'], browser.info()['pillText']))
    started = time.time()
    seen = set()
    deadline = time.time() + 300
    while time.time() < deadline:
        info = browser.info()
        seen.add((info['pillState'], info['pillText']))
        if info['pillState'] == 'saved':
            break
        time.sleep(1)
    context['evidence']['drain_seconds'] = round(time.time() - started, 1)
    context['evidence']['pill_states_seen'] = sorted(map(str, pills | seen))
    check(browser.info()['pillState'] == 'saved', 'not drained after 300 s: %s' % browser.info()['pillText'])
    check(not any(state == 'problem' for state, _ in seen | pills), 'a refusal/problem was shown for a transient failure: %s' % seen)
    assert_results(context, list(range(81, 91)), REFEREE)
    receipts = db.receipts_since(since)
    context['evidence']['receipts_bursts'] = receipts
    check(receipts == {'applied': 10}, 'receipts %s - expected 10 applied' % receipts)
    posts = record_requests(proxy, mark)
    context['evidence']['timeline'] = [(round((p['t'] - posts[0]['t']) / 1000, 1), p['status'], p['outcome']) for p in posts]

    # The server saved, its answer lost on the way back (3 times)
    since = db.now()
    mark = proxy.mark()
    proxy.rules([{'path': RECORD_PATH, 'method': 'POST', 'action': 'after', 'status': 502, 'count': 3}])
    for table in range(91, 96):
        browser.enter_result(table)
    browser.wait_saved(240)
    assert_results(context, list(range(91, 96)), REFEREE)
    receipts = db.receipts_since(since)
    context['evidence']['receipts_lost_answers'] = receipts
    check(receipts == {'applied': 5}, 'receipts %s - expected 5 applied (replays answered from the receipts)' % receipts)
    posts = record_requests(proxy, mark)
    context['evidence']['timeline_lost'] = [(round((p['t'] - posts[0]['t']) / 1000, 1), p['status'], p['outcome']) for p in posts]
    assert_outbox_empty(browser)
    context['notes'].append('bursts drained in %.0fs (%d POSTs); lost answers replayed (%d POSTs)'
                            % (context['evidence']['drain_seconds'], len(context['evidence']['timeline']), len(posts)))


# ---------------------------------------------------------------- 10

PERF_JS = r"""
if (!window.__chaosPerf) {
    const perf = window.__chaosPerf = {opens: [], sends: [], typedAt: null, savedAt: null};
    const q = (t) => document.querySelector(`[data-live-results-target="${t}"]`);
    document.addEventListener('keydown', (e) => {
        if (e.target === q('findInput') && perf.typedAt === null) { perf.typedAt = performance.now(); }
    }, true);
    new MutationObserver(() => {
        if (!q('entryView').hidden && perf.typedAt !== null) { perf.opens.push(performance.now() - perf.typedAt); perf.typedAt = null; }
    }).observe(q('entryView'), {attributes: true, attributeFilter: ['hidden']});
    q('saveButton').addEventListener('pointerdown', () => { perf.savedAt = performance.now(); }, true);
    new MutationObserver(() => {
        if (q('pill').dataset.state === 'saved' && perf.savedAt !== null) { perf.sends.push(performance.now() - perf.savedAt); perf.savedAt = null; }
    }).observe(q('pill'), {attributes: true, attributeFilter: ['data-state']});
}
return true;
"""


def percentile(values, p):
    ordered = sorted(values)
    return round(ordered[min(len(ordered) - 1, max(0, int(round(p * len(ordered) + 0.5)) - 1))], 1) if ordered else None


def s10_cpu_throttled(context):
    """10. CPU 6x, 375x812, 300 entries: table typed → card open, Save → sent (in-page timings)."""
    db = context['db']
    browser = judge(context)
    browser.cpu(6)
    started = time.time()
    browser.open_live()
    load = time.time() - started
    browser.js(PERF_JS)
    tables = list(range(111, 141))
    for table in tables:
        browser.enter_result(table)
        browser.wait_saved(20)
    perf = browser.js('return window.__chaosPerf')
    browser.cpu(1)
    opens, sends = perf['opens'], perf['sends']
    numbers = {
        'page_ready_s': round(load, 2),
        'open_ms': {'n': len(opens), 'p50': percentile(opens, .5), 'p95': percentile(opens, .95), 'max': round(max(opens), 1) if opens else None},
        'sent_ms': {'n': len(sends), 'p50': percentile(sends, .5), 'p95': percentile(sends, .95), 'max': round(max(sends), 1) if sends else None},
    }
    context['evidence']['numbers'] = numbers
    context['evidence']['raw'] = perf
    assert_results(context, tables, REFEREE)
    check(len(opens) == len(tables), 'measured %d card opens of %d' % (len(opens), len(tables)))
    context['notes'].append('6x CPU: ready %.1fs; table→card p50 %s / p95 %s ms; Save→sent p50 %s / p95 %s ms'
                            % (load, numbers['open_ms']['p50'], numbers['open_ms']['p95'], numbers['sent_ms']['p50'], numbers['sent_ms']['p95']))


# ---------------------------------------------------------------- 11

TAGS = {
    # kind: (print page query, selector of the tag to film, entry the scan should open on the chaos round)
    'person': ('kind=people&round=%s' % ROUND, '[data-qr-url*="/p/%s"]' % participant_id(9), 'Chaos Tester 009'),
    'table': ('kind=tables&layout=tents', '[data-qr-url$="/t/7"]', 'Chaos Tester 007'),
    'entry': ('kind=entries&round=%s' % ROUND, '[data-qr-url$="/e/%s"]' % entry_id(8), 'Chaos Tester 008'),
    'lineup': ('kind=lineups', '[data-qr-url*="/g/"]', None),
}


def write_y4m(png_bytes, path, frames=60):
    """The tag's QR (a screenshot of the printed SVG) on a white 640x480 frame, drifting and breathing a little."""
    from PIL import Image
    import math

    qr = Image.open(io.BytesIO(png_bytes)).convert('L')
    with open(path, 'wb') as out:
        out.write(b'YUV4MPEG2 W640 H480 F15:1 Ip A1:1 C420jpeg\n')
        for i in range(frames):
            scale = 1.0 + 0.04 * math.sin(i / 7.0)
            side = int(300 * scale)
            frame = Image.new('L', (640, 480), 255)
            frame.paste(qr.resize((side, side), Image.NEAREST),
                        (320 - side // 2 + int(8 * math.sin(i / 5.0)), 240 - side // 2 + int(6 * math.cos(i / 6.0))))
            out.write(b'FRAME\n')
            out.write(frame.tobytes())
            out.write(bytes([128]) * (320 * 240 * 2))


def make_videos(db, proxy, context=None):
    """The 4 tag kinds from the real print pages (organiser), filmed into media/<kind>.y4m. Returns {kind: url}."""
    browser = Browser('printer', mobile=False)
    if context is not None:
        context['browsers'].append(browser)
    urls = {}
    try:
        browser.login('organiser')
        for kind, (query, selector, _) in TAGS.items():
            browser.go('/en/name-tags/%s?%s' % (COMPETITION, query))
            element = browser.wait(lambda: (lambda els: els[0] if els else None)(browser.driver.find_elements(By.CSS_SELECTOR, selector)),
                                   timeout=20, message='the %s tag on the print page' % kind)
            browser.js('arguments[0].scrollIntoView({block: "center"})', element)
            svg = element.find_element(By.CSS_SELECTOR, 'svg')
            urls[kind] = element.get_attribute('data-qr-url')
            if kind == 'lineup':
                urls['lineup_label'] = browser.js("return arguments[0].closest('.tag, .tent, [class*=tag]').innerText", element)
            write_y4m(svg.screenshot_as_png, '/chaos/media/%s.y4m' % kind)
    finally:
        if context is None:
            browser.quit()
    with open('/chaos/media/urls.json', 'w') as handle:
        import json
        json.dump(urls, handle, indent=2)
    return urls


def scan(browser, timeout=25):
    browser.back_to_find()
    browser.click('[data-action="live-results#openScanner"]')
    started = time.time()
    info = browser.wait(lambda: (lambda i: i if (i['entry'] or i['findNotice']) and not i['scanner'] else None)(browser.info()),
                        timeout=timeout, interval=0.3, message='a scan result')
    info['scan_seconds'] = round(time.time() - started, 1)
    return info


def s11_offline_scanning(context):
    """11. Scanning the 4 printed tag kinds (fake camera) offline after the page prepared offline - zbar from the
    worker's cache for real, BarcodeDetector through a shim - and online."""
    db, proxy = context['db'], context['proxy']
    urls = make_videos(db, proxy, context)
    context['evidence']['tag_urls'] = urls
    results = {}

    for kind, (_, _, expected) in TAGS.items():
        browser = Browser('scan-%s' % kind, video='%s.y4m' % kind)
        context['browsers'].append(browser)
        browser.login('referee')
        native = browser.js("return 'BarcodeDetector' in window")
        if kind == 'lineup':
            browser.open_live(ROUND_PAIRS)  # its snapshot on this phone
            browser.wait_snapshot(ROUND_PAIRS)
        browser.open_live()
        ready = browser.wait_offline_ready()
        zbar = browser.js_async("const d = arguments[0]; caches.open('static-v7').then((c) => c.keys()).then((k) => d(k.map((r) => new URL(r.url).pathname).filter((p) => p.startsWith('/barcode-scanner/'))));")
        browser.wait_snapshot()
        network_down(proxy, browser)
        browser.driver.refresh()
        info = browser.wait_live(20)
        check(info['shell'], '%s: the offline reload did not get the shell' % kind)
        found = scan(browser)
        outcome = {'native_barcode_detector': native, 'zbar_cached': zbar, 'chaos_round': found['entryName'] or found['findNotice'],
                   'seconds': found['scan_seconds']}
        if kind == 'lineup':
            check(found['findNotice'] and 'does not play' in found['findNotice'],
                  'lineup tag on the chaos round: %r' % (found['entryName'] or found['findNotice']))
            browser.go('/en/live-results/%s' % ROUND_PAIRS)
            browser.wait_live(20)
            pairs = scan(browser)
            outcome['pairs_round'] = pairs['entryName'] or pairs['findNotice']
            check(pairs['entry'], 'lineup tag on the Pairs round (offline): %r' % outcome['pairs_round'])
        else:
            check(found['entry'] and expected in (found['entryName'] or ''),
                  '%s tag offline opened %r, expected %s' % (kind, found['entryName'] or found['findNotice'], expected))
        network_up(proxy, browser)
        results[kind] = outcome
        browser.quit()
        context['browsers'].remove(browser)

    # Online, zbar from the server, the table tag
    browser = Browser('scan-online', video='table.y4m')
    context['browsers'].append(browser)
    browser.login('referee')
    browser.open_live()
    found = scan(browser)
    results['table_online'] = {'opened': found['entryName'] or found['findNotice'], 'seconds': found['scan_seconds']}
    check('007' in (found['entryName'] or ''), 'online table scan opened %r' % results['table_online']['opened'])
    browser.quit()
    context['browsers'].remove(browser)

    # BarcodeDetector path: a shim answering the filmed tag's URL - proves the page takes the browser's own reader
    # when it exists (no zbar asked for, offline) and acts on its answer; not that Chrome's own decoder reads our QR
    shim = """window.BarcodeDetector = class { static async getSupportedFormats() { return ['qr_code']; }
        constructor(o) { window.__chaosDetector = (window.__chaosDetector || 0) + 1; }
        async detect(source) { window.__chaosDetects = (window.__chaosDetects || 0) + 1;
            return (window.__chaosDetects > 3) ? [{rawValue: %r, format: 'qr_code'}] : []; } };""" % urls['entry']
    browser = Browser('scan-shim', video='entry.y4m')
    context['browsers'].append(browser)
    browser.cdp('Page.addScriptToEvaluateOnNewDocument', {'source': shim})
    browser.login('referee')
    browser.open_live()
    ready = browser.wait_offline_ready()
    browser.wait_snapshot()
    network_down(proxy, browser)
    browser.driver.refresh()
    browser.wait_live(20)
    found = scan(browser)
    calls = browser.js('return {constructed: window.__chaosDetector || 0, detects: window.__chaosDetects || 0, zbar: typeof window.zbarWasm}')
    results['barcode_detector_shim'] = {'opened': found['entryName'] or found['findNotice'], 'calls': calls,
                                        'shell_entries': ready.get('entries')}
    check('008' in (found['entryName'] or ''), 'shimmed BarcodeDetector scan opened %r' % results['barcode_detector_shim']['opened'])
    check(calls['zbar'] == 'undefined', 'zbar was loaded although BarcodeDetector exists')
    network_up(proxy, browser)
    context['evidence']['scans'] = results
    context['notes'].append('; '.join('%s: %s' % (k, v.get('chaos_round') or v.get('opened')) for k, v in results.items()))


# ---------------------------------------------------------------- 12

def finished_now(browser, table):
    browser.open_table(table)
    browser.click('[data-live-results-target="finishedNow"]')
    browser.wait(lambda: browser.info()['confirm'], timeout=15, message='confirm after Finished now')
    tapped = time.time()
    browser.save()
    return tapped


def elapsed_at(db, moment):
    return moment - db.stopwatch_started_ms() / 1000


def s12a_mercure_down(context):
    """12a. Mercure unreachable (the page's live stream refused): saves still go out, the other judge's results arrive
    by the state poll."""
    db, proxy = context['db'], context['proxy']
    first = judge(context, 'referee', name='judgeA')
    second = judge(context, 'organiser', name='judgeB')
    first.open_live()
    second.open_live()
    proxy.state(mercure_down=True)
    for table in range(161, 166):
        first.enter_result(table)
    first.wait_saved(30)
    second.enter_result(166)
    second.wait_saved(30)
    started = time.time()
    first.back_to_find()
    seen = first.wait(lambda: 'Chaos Tester 166' in first.info()['body'], timeout=90, interval=2,
                      message='the other judge\'s 166 on the page without live updates')
    context['evidence']['other_result_after_s'] = round(time.time() - started, 1)
    assert_results(context, list(range(161, 166)), REFEREE)
    context['notes'].append('without Mercure the other judge\'s result showed after %.0fs (60 s poll)' % context['evidence']['other_result_after_s'])


CLOCK_SHIFT_JS = r"""
(() => {
    const RealDate = Date;
    window.__chaosShiftMs = %d;
    const shifted = () => RealDate.now() + window.__chaosShiftMs;
    class ShiftedDate extends RealDate {
        constructor(...args) { if (args.length === 0) { super(shifted()); } else { super(...args); } }
        static now() { return shifted(); }
    }
    window.Date = ShiftedDate;
})();
"""


def s12b_clock(context):
    """12b. The phone's clock 2 h wrong from the start, and jumping 1 h while the page is open: "Finished now" still
    saves the stopwatch's real elapsed time."""
    db = context['db']
    browser = Browser('referee')
    context['browsers'].append(browser)
    browser.cdp('Page.addScriptToEvaluateOnNewDocument', {'source': CLOCK_SHIFT_JS % (2 * 3600 * 1000)})
    browser.login('referee')
    browser.open_live()
    time.sleep(3)
    tapped = finished_now(browser, 170)
    browser.wait_saved(20)
    expected = elapsed_at(db, tapped)
    got = db.entries([170])[170]['seconds']
    context['evidence']['skewed'] = {'saved': got, 'expected': round(expected)}
    check(got is not None and abs(got - expected) <= 5, 'clock 2 h ahead: saved %s, the stopwatch said %.0f' % (got, expected))

    browser.js('window.__chaosShiftMs += 3600 * 1000')
    time.sleep(2)
    stopwatch = browser.info()['stopwatch']
    tapped = finished_now(browser, 171)
    browser.wait_saved(20)
    expected = elapsed_at(db, tapped)
    got = db.entries([171])[171]['seconds']
    context['evidence']['jumped'] = {'saved': got, 'expected': round(expected), 'stopwatch_shown': stopwatch}
    check(got is not None and abs(got - expected) <= 5,
          'CLOCK JUMP: the phone\'s clock jumped +1 h while the page was open - "Finished now" saved %s (%s), the stopwatch '
          'was at %s (%s); the page showed %r' % (got, typed(got or 0), round(expected), typed(int(expected)), stopwatch))


def s12c_background_tab(context):
    """12c. The tab frozen in the background (10 min, QUICK 2 min) while the other judge saves: back, it catches up and
    "Finished now" is right."""
    db = context['db']
    minutes = 2 if QUICK else 10
    first = judge(context, 'referee', name='judgeA')
    second = judge(context, 'organiser', name='judgeB')
    first.open_live()
    second.open_live()
    first.cdp('Page.setWebLifecycleState', {'state': 'frozen'})
    second.enter_result(175)
    second.wait_saved(20)
    deadline = time.time() + minutes * 60
    while time.time() < deadline:
        time.sleep(30)
        second.js('return 1')  # keep the other session alive
    first.cdp('Page.setWebLifecycleState', {'state': 'active'})
    started = time.time()
    first.back_to_find()
    first.wait(lambda: 'Chaos Tester 175' in first.info()['body'], timeout=90, interval=1,
               message='the other judge\'s 175 after the tab came back')
    context['evidence']['catch_up_s'] = round(time.time() - started, 1)
    tapped = finished_now(first, 176)
    first.wait_saved(20)
    expected = elapsed_at(db, tapped)
    got = db.entries([176])[176]['seconds']
    context['evidence']['finished_now'] = {'saved': got, 'expected': round(expected)}
    check(got is not None and abs(got - expected) <= 5, 'after %d min frozen: saved %s, the stopwatch said %.0f' % (minutes, got, expected))
    context['notes'].append('%d min frozen: caught up %.1fs after resume' % (minutes, context['evidence']['catch_up_s']))


def s13_tap_moment_kept(context):
    """13. Jan's rule: a stopped time is the tap's moment on the server-synced clock, never the receipt. The judge's
    "Finished now" offline (saved, and left in the confirm step), 65 s, one reload, online; a competitor's Stop
    offline, 65 s, reload, online, the judge's one-tap Confirm - every stored value = the tap."""
    db, proxy = context['db'], context['proxy']
    wait_s = 65
    browser = judge(context)
    browser.open_live()
    browser.wait_offline_ready()
    browser.wait_snapshot()
    network_down(proxy, browser)
    tapped_180 = finished_now(browser, 180)
    browser.open_table(181)
    browser.click('[data-live-results-target="finishedNow"]')
    browser.wait(lambda: browser.info()['confirm'], timeout=15, message='confirm after Finished now')
    tapped_181 = time.time()
    frozen_181 = browser.info()['confirmResult']
    time.sleep(wait_s)
    browser.driver.refresh()
    restored = browser.wait(lambda: (lambda i: i if i['confirm'] or i['entry'] else None)(browser.info()), timeout=20,
                            message='the draft of 181 after the offline reload')
    context['evidence']['restored_181'] = {k: restored[k] for k in ('confirm', 'confirmResult', 'confirmNote', 'entryName')}
    check(restored['confirm'] and restored['confirmResult'] == frozen_181,
          '181 after the reload: %r, frozen at the tap %r' % (restored['confirmResult'], frozen_181))
    browser.save()
    network_up(proxy, browser)
    browser.wait_saved(60)
    rows = db.entries([180, 181])
    want = {180: elapsed_at(db, tapped_180), 181: elapsed_at(db, tapped_181)}
    context['evidence']['judge'] = {t: {'stored': rows[t]['seconds'], 'tap_elapsed': round(want[t], 1)} for t in want}
    for table in (180, 181):
        check(rows[table]['seconds'] is not None and abs(rows[table]['seconds'] - want[table]) <= 2,
              'table %d stored %s, the tap was at %.0f (sent %d s later)' % (table, rows[table]['seconds'], want[table], wait_s))

    # The competitor (entry 300 cleared for this scenario)
    john = competitor(context, 'john', 300)
    john.wait(lambda: propose_status(john)['stop'], timeout=30, message='Stop button')
    network_down(proxy, john, browser)
    tapped = tap_stop(john)
    tap_elapsed = elapsed_at(db, tapped / 1000)
    wait_mode(john, ['offline', 'sending'], 15)
    time.sleep(wait_s)
    john.driver.refresh()
    time.sleep(2)
    network_up(proxy, john, browser)
    john.go('/en/competitor/%s/p/%s' % (COMPETITION, participant_id(300)))
    wait_mode(john, ['pending'], 40)
    rows = db.proposals(participant_id(300))
    context['evidence']['proposal_before_confirm'] = rows
    check(len(rows) == 1, 'proposals of 300: %s' % rows)
    check(abs(rows[0][5] - tapped) <= 2000, 'tappedAtMs %d vs the tap at %d' % (rows[0][5], tapped))
    check(abs(rows[0][2] - tap_elapsed) <= 2, 'proposed %d s, the tap was at %.0f s' % (rows[0][2], tap_elapsed))
    browser.wait_live()
    judge_confirms(browser, 300, context)
    rows = db.proposals(participant_id(300))
    result = db.entries([300])[300]['seconds']
    context['evidence']['proposal_after_confirm'] = rows
    context['evidence']['competitor'] = {'tap_elapsed': round(tap_elapsed, 1), 'proposed': rows[0][2],
                                         'receipt': rows[0][3], 'late_by_ms': rows[0][4], 'stored_result': result}
    check(result is not None and abs(result - tap_elapsed) <= 2,
          'RECEIPT, NOT TAP: the judge\'s one-tap Confirm stored %s (%s) - the competitor tapped at %.0f (%s), the '
          'proposal arrived %.0f s later (receipt %s); live_results_controller defaults a late proposal to the receipt'
          % (result, typed(result or 0), tap_elapsed, typed(int(tap_elapsed)), rows[0][4] / 1000, typed(rows[0][3])))


SCENARIOS = {
    's01_flapping': (s01_flapping, range(1, 31)),
    's02_refresh_mid_save': (s02_refresh_mid_save, range(31, 36)),
    's03_offline_reload': (s03_offline_reload, range(36, 43)),
    's03b_snapshot_timing': (s03b_snapshot_timing, range(201, 226)),
    's04_two_judges': (s04_two_judges, [46, 47]),
    's05_worker_update': (s05_worker_update, range(51, 56)),
    's06_session_expires': (s06_session_expires, [61, 62, 63]),
    's07_proposals_offline': (s07_proposals_offline, [299, 300]),
    's08_undo': (s08_undo, [71, 72, 298]),
    's09_server_bursts': (s09_server_bursts, range(81, 96)),
    's10_cpu_throttled': (s10_cpu_throttled, range(111, 141)),
    's11_offline_scanning': (s11_offline_scanning, []),
    's12a_mercure_down': (s12a_mercure_down, range(161, 167)),
    's12b_clock': (s12b_clock, [170, 171]),
    's12c_background_tab': (s12c_background_tab, [175, 176]),
    's13_tap_moment_kept': (s13_tap_moment_kept, [180, 181, 300]),
}
