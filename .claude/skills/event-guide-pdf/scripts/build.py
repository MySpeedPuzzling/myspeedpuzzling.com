#!/usr/bin/env python3
"""Build an event's organiser guide PDF: discover the event's facts, take the screenshots, render the PDF.

One re-runnable command (see ../SKILL.md):

    python3 .claude/skills/event-guide-pdf/scripts/build.py <event-folder>
    python3 .claude/skills/event-guide-pdf/scripts/build.py <event-folder> --only discover|shoot|render
    python3 .claude/skills/event-guide-pdf/scripts/build.py <event-folder> --shots judge-table,desk

The event folder lives OUTSIDE the repository (real event data never goes into the public repo) and holds:
    event.json   - host, slug, accounts, logo, output name (see ../templates/event.example.json)
    shots.json   - the screenshots (see ../templates/shots.example.json)
    guide.html   - the content, with {{placeholders}} and <figure data-shot="..."> slots
Written by the command: facts.json, shots/<name>.jpg + shots/<name>.json, build/, <output>.pdf

Read-only by design: the only writes are the sandbox gate and the sign-in; every page gets a guard that refuses
non-GET requests and form submits, so a shot can never record a result, start a stopwatch or save a setting.
"""
from __future__ import annotations

import argparse
import base64
import html
import http.cookiejar
import json
import os
import re
import shutil
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

SKILL_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
REPO_DIR = os.path.dirname(os.path.dirname(os.path.dirname(SKILL_DIR)))
SELENIUM_IMAGE = os.environ.get('GUIDE_SELENIUM_IMAGE', 'selenium/standalone-chromium:latest')
UUID = r'[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'

VIEWPORTS = {
    'desktop': {'width': 1280, 'height': 860, 'scale': 1.5, 'mobile': False},
    'wide': {'width': 1440, 'height': 900, 'scale': 1.25, 'mobile': False},
    'phone': {'width': 390, 'height': 844, 'scale': 2, 'mobile': True},
    'display': {'width': 1920, 'height': 1080, 'scale': 1, 'mobile': False},
}

# Injected into every page before its own scripts: refuse anything that could change data.
READ_ONLY_GUARD = r"""
(() => {
  const allowed = (url) => /\/(login|sandbox\/enter)(\?|$)/.test(String(url || ''));
  const refuse = (what) => { console.warn('[guide read-only] refused ' + what); };
  const origFetch = window.fetch;
  window.fetch = function (input, init) {
    const method = ((init && init.method) || (input && input.method) || 'GET').toUpperCase();
    const url = typeof input === 'string' ? input : (input && input.url);
    if (method !== 'GET' && method !== 'HEAD' && !allowed(url)) {
      refuse(method + ' ' + url);
      return Promise.reject(new TypeError('read-only guide build'));
    }
    return origFetch.apply(this, arguments);
  };
  const origOpen = XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open = function (method, url) {
    this.__guideBlocked = String(method).toUpperCase() !== 'GET' && !allowed(url);
    return origOpen.apply(this, arguments);
  };
  const origSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.send = function () {
    if (this.__guideBlocked) { refuse('XHR'); return; }
    return origSend.apply(this, arguments);
  };
  if (navigator.sendBeacon) { navigator.sendBeacon = function () { refuse('beacon'); return true; }; }
  const origSubmit = HTMLFormElement.prototype.submit;
  HTMLFormElement.prototype.submit = function () {
    if (allowed(this.action)) { return origSubmit.apply(this, arguments); }
    refuse('form ' + this.action);
  };
  HTMLFormElement.prototype.requestSubmit = function () { refuse('form ' + this.action); };
  document.addEventListener('submit', (e) => {
    if (!allowed(e.target && e.target.action)) { e.preventDefault(); e.stopImmediatePropagation(); refuse('submit'); }
  }, true);
})();
"""


def log(msg: str) -> None:
    print(msg, flush=True)


def load_json(path: str, default=None):
    if not os.path.exists(path):
        if default is not None:
            return default
        sys.exit(f'Missing {path}')
    with open(path, encoding='utf-8') as f:
        return json.load(f)


def slugify(text: str) -> str:
    return re.sub(r'-+', '-', re.sub(r'[^a-z0-9]+', '-', text.lower())).strip('-')


# --------------------------------------------------------------------------------------------- HTTP (discovery)

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Site:
    """A signed-in HTTP client for read-only GETs (discovery). Sign-in = the sandbox gate + the password form."""

    def __init__(self, cfg: dict, account: dict | None):
        self.host = cfg['host'].rstrip('/')
        self.cfg = cfg
        self.jar = http.cookiejar.CookieJar()
        headers = [('User-Agent', 'Mozilla/5.0 (event-guide-pdf)'), ('Origin', self.host), ('Referer', self.host + '/login')]
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.op.addheaders = headers
        self.raw = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)
        self.raw.addheaders = headers
        if cfg.get('sandbox_gate'):
            self.op.open(urllib.request.Request(self.host + '/sandbox/enter', data=b'', method='POST'))
        if account:
            _, page = self.get('/login')
            token = re.search(r'name="_csrf_token" value="([^"]*)"', page)
            if not token:
                sys.exit('Sign-in form not found on /login')
            data = urllib.parse.urlencode({'_csrf_token': token.group(1), 'email': account['email'], 'password': account['password']})
            self.op.open(urllib.request.Request(self.host + '/login', data=data.encode()))
            _, check = self.get('/login')
            if 'id="login-form"' in check:
                sys.exit(f"Sign-in failed for {account['email']}")

    def get(self, path: str) -> tuple[int, str]:
        try:
            r = self.op.open(self.host + path)
            return r.status, r.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace')

    def location(self, path: str) -> str | None:
        try:
            self.raw.open(self.host + path)
        except urllib.error.HTTPError as e:
            return e.headers.get('Location')
        return None


def text_of(fragment: str) -> str:
    return html.unescape(re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', fragment))).strip()


def discover(folder: str, cfg: dict) -> dict:
    """Facts about the event, read as the organiser through the pages (GET only) - ids, URLs, names."""
    host = cfg['host'].rstrip('/')
    loc = cfg.get('locale', 'en')
    org = Site(cfg, cfg['accounts']['organiser'])
    slug = cfg['slug']
    code, event_page = org.get(f'/{loc}/events/{slug}')
    if code != 200:
        sys.exit(f'Event page /{loc}/events/{slug} answered {code}')
    event_id = re.search(rf'/{loc}/competitor/({UUID})', event_page) or re.search(rf'/{loc}/edit-event/({UUID})', event_page) or re.search(rf'/{loc}/join-event/({UUID})', event_page)
    if not event_id:
        sys.exit('Event id not found on the event page (is the organiser account on the event team?)')
    eid = event_id.group(1)
    title = re.search(r'<h1[^>]*>(.*?)</h1>', event_page, re.S)
    organization = re.search(rf'href="(/{loc}/organizations/[^"]+)"', event_page)
    E = f'{host}/{loc}'

    facts: dict = {
        'host': host,
        'slug': slug,
        'event': {
            'id': eid,
            'title': text_of(title.group(1)) if title else slug,
            'url': f'{E}/events/{slug}',
            'short_url': f"{host.split('://')[-1]}/{loc}/events/{slug}",
        },
        'organization': {'url': host + organization.group(1)} if organization else {},
        'pages': {
            'edit': f'{E}/edit-event/{eid}',
            'toolkit': f'{E}/manage-event-toolkit/{eid}',
            'rounds': f'{E}/manage-event-rounds/{eid}',
            'participants': f'{E}/participants-sheet/{eid}',
            'registration': f'{E}/manage-event-registration/{eid}',
            'referees': f'{E}/manage-event-referees/{eid}',
            'results_overview': f'{E}/manage-event-results/{eid}',
            'standings': f'{E}/manage-event-standings/{eid}',
            'advancement': f'{E}/manage-event-advancement/{eid}',
            'divisions': f'{E}/manage-event-toolkit/{eid}/divisions',
            'displays': f'{E}/manage-displays/{eid}',
            'live_control': f'{E}/display-control/{eid}',
            'page_content': f'{E}/manage-event-page/{eid}',
            'duplicate': f'{E}/duplicate-event/{eid}',
            'name_tags': f'{E}/name-tags/{eid}',
            'competitors': f'{E}/competitor/{eid}',
            'wizard': f'{E}/new-event',
        },
        'rounds': {},
        'standings': {},
        'displays': {},
        'people': {},
    }

    # Rounds: id + name + the format line from the toolkit hub, slug from the studio redirect.
    _, hub = org.get(f'/{loc}/manage-event-toolkit/{eid}')
    for part in hub.split('data-toolkit-round="')[1:]:
        rid = part[:36]
        name = re.search(r'<span class="fw-semibold">(.*?)</span>', part, re.S)
        fmt = re.search(r'data-toolkit-round-format="[^"]*">(.*?)</div>', part, re.S)
        target = org.location(f'/{loc}/analytics-studio/{rid}') or ''
        rslug = target.rstrip('/').split('/')[-1] if 'analytics-studio/' in target else slugify(text_of(name.group(1)) if name else rid)
        facts['rounds'][rslug] = {
            'id': rid,
            'name': text_of(name.group(1)) if name else rslug,
            'format': text_of(fmt.group(1)) if fmt else '',
            'slug': rslug,
            'desk': f'{E}/manage-round-results/{rid}',
            'judge': f'{E}/live-results/{rid}',
            'stopwatch': f'{E}/manage-round-stopwatch/{rid}',
            'seating': f'{E}/round-seating/{rid}',
            'puzzles': f'{E}/manage-round-puzzles/{rid}',
            'results': f'{E}/events/{slug}/results/{rslug}',
            'studio': f'{E}/events/{slug}/analytics-studio/{rslug}',
        }
    if not facts['rounds']:
        sys.exit('No rounds found on the toolkit hub')

    # Standings: ids + names from the hub, public URLs by name from the event page.
    public = {text_of(t): h for h, t in re.findall(rf'href="(/{loc}/events/{re.escape(slug)}/standings/[^"]+)"[^>]*>(.*?)</a>', event_page, re.S)}
    for sid, name in re.findall(rf'/manage-event-standings/{eid}/({UUID})/edit"[^>]*>(.*?)</a>', hub, re.S):
        name = text_of(name)
        path = public.get(name)
        key = path.rstrip('/').split('/')[-1] if path else slugify(name)
        facts['standings'][key] = {
            'id': sid,
            'name': name,
            'public': host + path if path else '',
            'embed': f'{host}/embed/standing/{sid}',
            'edit': f'{E}/manage-event-standings/{eid}/{sid}/edit',
        }

    # Displays: name, kind and public link from the displays page.
    _, disp = org.get(f'/{loc}/manage-displays/{eid}')
    for part in disp.split('data-display="')[1:]:
        did = part[:36]
        if not re.fullmatch(UUID, did):
            continue
        token = re.search(r'data-copy-link-url-value="([^"]+/display/[A-Za-z0-9]+)"', part)
        name = re.search(r'<(?:span|strong|div)[^>]*class="[^"]*fw-semibold[^"]*"[^>]*>(.*?)</', part, re.S)
        dname = text_of(name.group(1)) if name else did
        facts['displays'][slugify(dname)] = {
            'id': did,
            'name': dname,
            'url': token.group(1) if token else '',
            'editor': f'{E}/display-editor/{did}',
            'settings': f'{E}/display-editor/{did}/settings',
        }

    # People: the competitor account's own competitor page ("You" in the public list).
    comp = cfg['accounts'].get('competitor')
    if comp:
        site = Site(cfg, comp)
        _, lst = site.get(f'/{loc}/competitor/{eid}')
        mine = re.search(rf'href="(/{loc}/competitor/{eid}/[a-z]/[^"]+)"[^>]*>((?:(?!</a>).)*?\bYou\b.*?)</a>', lst, re.S)
        if mine:
            facts['people']['competitor_page'] = host + mine.group(1)
            facts['people']['competitor_name'] = text_of(re.sub(r'#\w+|\bYou\b', '', mine.group(2)))
        else:
            log('  ! the competitor account has no "You" row in the competitor list')

    with open(os.path.join(folder, 'facts.json'), 'w', encoding='utf-8') as f:
        json.dump(facts, f, indent=2, ensure_ascii=False)
    log(f"  facts: {len(facts['rounds'])} rounds, {len(facts['standings'])} standings, {len(facts['displays'])} displays")
    return facts


# --------------------------------------------------------------------------------------------- placeholders

PLACEHOLDER = re.compile(r'\{\{\s*([\w.\-]+)\s*\}\}')


def resolve(text: str, facts: dict, missing: set) -> str:
    def one(m):
        node = facts
        for key in m.group(1).split('.'):
            if isinstance(node, dict) and key in node:
                node = node[key]
            else:
                missing.add(m.group(1))
                return m.group(0)
        return html.escape(str(node), quote=True) if not isinstance(node, (dict, list)) else m.group(0)
    return PLACEHOLDER.sub(one, text)


# --------------------------------------------------------------------------------------------- browser

class Browser:
    """Own Selenium container (--rm, own port, removed on exit) driven over raw WebDriver HTTP + Chrome DevTools."""

    def __init__(self, folder: str):
        s = socket.socket()
        s.bind(('127.0.0.1', 0))
        self.port = s.getsockname()[1]
        s.close()
        self.name = f'event-guide-chrome-{os.getpid()}'
        self.base = f'http://127.0.0.1:{self.port}'
        log(f'  starting {SELENIUM_IMAGE} as {self.name} on :{self.port}')
        subprocess.run(['docker', 'run', '-d', '--rm', '--name', self.name, '-p', f'127.0.0.1:{self.port}:4444',
                        '--shm-size=2g', '-e', 'SE_NODE_MAX_SESSIONS=1', '-v', f'{os.path.abspath(folder)}:/guide:ro',
                        SELENIUM_IMAGE], check=True, stdout=subprocess.DEVNULL)
        for _ in range(90):
            try:
                if self.call('GET', '/status').get('value', {}).get('ready'):
                    break
            except Exception:
                pass
            time.sleep(1)
        else:
            self.stop()
            sys.exit('Selenium did not start')
        self.session = None

    def stop(self) -> None:
        subprocess.run(['docker', 'stop', '-t', '2', self.name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    def call(self, method: str, path: str, body=None, timeout=120):
        data = json.dumps(body).encode() if body is not None else None
        req = urllib.request.Request(self.base + path, data=data, method=method, headers={'Content-Type': 'application/json'})
        try:
            with urllib.request.urlopen(req, timeout=timeout) as r:
                return json.loads(r.read().decode())
        except urllib.error.HTTPError as e:
            raise RuntimeError(f'{method} {path}: {e.read().decode()[:400]}')

    def s(self, method: str, path: str, body=None):
        return self.call(method, f'/session/{self.session}{path}', body)['value']

    def start(self) -> None:
        caps = {'capabilities': {'alwaysMatch': {'browserName': 'chrome', 'goog:chromeOptions': {
            'args': ['--headless=new', '--window-size=1440,1000', '--hide-scrollbars', '--force-color-profile=srgb',
                     '--lang=en-GB', '--disable-features=Translate'],
            'prefs': {'intl.accept_languages': 'en-GB,en'}}}}}
        self.session = self.call('POST', '/session', caps)['value']['sessionId']

    def quit(self) -> None:
        if self.session:
            try:
                self.call('DELETE', f'/session/{self.session}')
            except Exception:
                pass
            self.session = None

    def cdp(self, cmd: str, params: dict | None = None):
        return self.s('POST', '/goog/cdp/execute', {'cmd': cmd, 'params': params or {}})

    def js(self, script: str, *args):
        return self.s('POST', '/execute/sync', {'script': script, 'args': list(args)})

    def go(self, url: str) -> None:
        self.s('POST', '/url', {'url': url})

    def wait_for(self, selector: str, timeout: float = 15) -> bool:
        end = time.time() + timeout
        while time.time() < end:
            if self.js('return !!document.querySelector(arguments[0])', selector):
                return True
            time.sleep(0.3)
        return False

    def viewport(self, name: str) -> dict:
        vp = VIEWPORTS[name]
        self.cdp('Emulation.setDeviceMetricsOverride', {'width': vp['width'], 'height': vp['height'],
                                                         'deviceScaleFactor': vp['scale'], 'mobile': vp['mobile']})
        self.cdp('Emulation.setTouchEmulationEnabled', {'enabled': vp['mobile'], 'maxTouchPoints': 5})
        return vp

    def sign_in(self, cfg: dict, account: dict | None) -> None:
        host = cfg['host'].rstrip('/')
        if cfg.get('sandbox_gate'):
            self.go(host + '/')
            self.wait_for('body')
            self.js("var f=document.querySelector('form[action$=\"/sandbox/enter\"]'); if(f){f.submit();}")
            time.sleep(2)
        if account:
            self.go(host + '/login')
            if not self.wait_for('#login-form'):
                sys.exit('Sign-in form not found in the browser')
            for field, value in (('email', account['email']), ('password', account['password'])):
                el = self.s('POST', '/element', {'using': 'css selector', 'value': f'#login-form input[name="{field}"]'})
                eid = list(el.values())[0]
                self.s('POST', f'/element/{eid}/clear', {})
                self.s('POST', f'/element/{eid}/value', {'text': value})
            self.js("document.querySelector('#login-form').submit()")
            time.sleep(2.5)
            if '/login' in self.s('GET', '/url'):
                sys.exit(f"Browser sign-in failed for {account['email']}")
        # From here on, every document gets the read-only guard before its own scripts.
        self.cdp('Page.addScriptToEvaluateOnNewDocument', {'source': READ_ONLY_GUARD})


# One way to find an element everywhere (marks, clips, clicks): a CSS selector and/or a text it contains; with a
# text, the innermost matching element wins, so {"contains": "Publish results"} lands on the button, not the page.
FIND_JS = r"""
window.__guideFind = (spec) => {
  if (typeof spec === 'string') { spec = {selector: spec}; }
  let els = Array.from(document.querySelectorAll(spec.selector || 'body *'));
  els = els.filter(e => { const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0; });
  if (spec.contains) {
    const t = spec.contains;
    els = els.filter(e => (e.innerText || e.textContent || '').includes(t));
    els = els.filter(e => !els.some(o => o !== e && e.contains(o)));
  }
  return els[spec.index || 0] || null;
};
"""

MARK_JS = FIND_JS + r"""
const [marks, clip] = arguments;
return marks.map(m => {
  const e = window.__guideFind(m);
  if (!e) { return null; }
  const r = e.getBoundingClientRect();
  return {x: r.left + scrollX - clip.x, y: r.top + scrollY - clip.y, w: r.width, h: r.height};
});
"""

CLIP_JS = FIND_JS + r"""
const [spec, pad, maxH, full, from] = arguments;
const docW = document.documentElement.scrollWidth;
if (from) {
  // From an element's top down: the page's own width, max_height tall (no scrolling needed).
  const e = window.__guideFind(from);
  if (!e) { return null; }
  const y = Math.max(0, e.getBoundingClientRect().top + scrollY - pad);
  return {x: 0, y, width: innerWidth, height: Math.min(maxH, document.documentElement.scrollHeight - y)};
}
if (spec) {
  const e = window.__guideFind(spec);
  if (!e) { return null; }
  const r = e.getBoundingClientRect();
  const x = Math.max(0, r.left + scrollX - pad), y = Math.max(0, r.top + scrollY - pad);
  return {x, y, width: Math.min(r.width + 2 * pad, docW - x), height: Math.min(r.height + 2 * pad, maxH)};
}
const h = full ? Math.min(document.documentElement.scrollHeight, maxH) : innerHeight;
return {x: 0, y: full ? 0 : scrollY, width: innerWidth, height: h};
"""

CLICK_JS = FIND_JS + r"""
const e = window.__guideFind(arguments[0]);
if (!e) { return false; }
if (arguments[1]) { e.scrollIntoView({block: 'start'}); scrollBy(0, -arguments[2]); return true; }
e.click();
return true;
"""


def run_actions(b: Browser, actions: list) -> list[str]:
    """Only actions that look: open a tab/card/details, type a table into a search, scroll. The guard refuses writes."""
    problems = []
    for a in actions:
        if 'wait' in a:
            time.sleep(a['wait'] / 1000)
        elif 'type' in a:
            el = b.js(FIND_JS + 'return window.__guideFind(arguments[0]);', a['into'])
            if not el:
                problems.append(f"type target missing: {json.dumps(a['into'])}")
                continue
            b.s('POST', f"/element/{list(el.values())[0]}/value", {'text': a['type']})
        elif 'click' in a or 'scroll' in a:
            spec = a.get('click') or a.get('scroll')
            if not b.js(CLICK_JS, spec, 'scroll' in a, a.get('offset', 16)):
                problems.append(f"{'click' if 'click' in a else 'scroll'} target missing: {json.dumps(spec)}")
            time.sleep(a.get('pause', 400) / 1000)
        elif 'js' in a:
            b.js(a['js'])
    return problems


def shoot(folder: str, cfg: dict, facts: dict, only: set | None) -> list[str]:
    shots = load_json(os.path.join(folder, 'shots.json'))
    out_dir = os.path.join(folder, 'shots')
    os.makedirs(out_dir, exist_ok=True)
    problems: list[str] = []
    order: list[str] = []
    by_account: dict[str, list] = {}
    for shot in shots:
        if only and shot['name'] not in only:
            continue
        by_account.setdefault(shot.get('account', 'organiser'), []).append(shot)
        order.append(shot['name'])
    if not order:
        return problems
    b = Browser(folder)
    try:
        for account_key, items in by_account.items():
            account = None if account_key == 'guest' else cfg['accounts'][account_key]
            log(f'  as {account_key}: {len(items)} shots')
            b.start()
            try:
                b.sign_in(cfg, account)
                for shot in items:
                    try:
                        problems += shoot_one(b, cfg, facts, shot, out_dir)
                    except Exception as e:  # one broken shot never stops the others
                        problems.append(f"{shot['name']}: failed - {str(e)[:200]}")
                        log(f"    ! {shot['name']} failed")
            finally:
                b.quit()
    finally:
        b.stop()
    return problems


def shoot_one(b: Browser, cfg: dict, facts: dict, shot: dict, out_dir: str) -> list[str]:
    name = shot['name']
    missing: set = set()
    url = html.unescape(resolve(shot['url'], facts, missing))
    if missing:
        return [f'{name}: unresolved {sorted(missing)} in url']
    vp = b.viewport(shot.get('viewport', 'desktop'))
    # A page may restore what the previous shot opened (the judge page keeps tab drafts) - start each shot clean.
    b.js('try { sessionStorage.clear(); } catch (e) {}')
    b.go(url)
    b.wait_for('body')
    if shot.get('wait_for') and not b.wait_for(shot['wait_for'], shot.get('timeout', 20)):
        log(f"    ! {name}: wait_for {shot['wait_for']} not found")
    css = cfg.get('css', '') + shot.get('css', '')
    if css:
        b.js("const s=document.createElement('style'); s.textContent=arguments[0]; document.head.appendChild(s);", css)
    problems = [f'{name}: {p}' for p in run_actions(b, shot.get('actions', []))]
    time.sleep(shot.get('settle', 1500) / 1000)
    b.js("document.activeElement && document.activeElement.blur && arguments[0] && document.activeElement.blur()", not shot.get('keep_focus'))
    clip = b.js(CLIP_JS, shot.get('clip'), shot.get('pad', 12), shot.get('max_height', 2600), shot.get('full', False), shot.get('clip_from'))
    if clip is None:
        problems.append(f"{name}: clip {json.dumps(shot.get('clip') or shot.get('clip_from'))} not found - shot the viewport")
        clip = b.js(CLIP_JS, None, 0, 2600, False, None)
    boxes = b.js(MARK_JS, shot.get('marks', []), clip)
    marks = []
    for i, (m, box) in enumerate(zip(shot.get('marks', []), boxes), start=1):
        if box is None:
            problems.append(f"{name}: mark {i} not found {json.dumps(m)}")
            continue
        if box['x'] < -4 or box['y'] < -4 or box['x'] > clip['width'] or box['y'] > clip['height']:
            problems.append(f"{name}: mark {i} lies outside the shot (raise max_height or move the clip) {json.dumps(m)}")
            continue
        marks.append({'n': m.get('n', i), **{k: round(v, 1) for k, v in box.items()}, 'pin': m.get('pin', 'l')})
    params = {'format': 'jpeg', 'quality': shot.get('quality', 82), 'captureBeyondViewport': True,
              'clip': {'x': clip['x'], 'y': clip['y'], 'width': clip['width'], 'height': clip['height'], 'scale': 1}}
    data = b.cdp('Page.captureScreenshot', params)['data']
    with open(os.path.join(out_dir, f'{name}.jpg'), 'wb') as f:
        f.write(base64.b64decode(data))
    meta = {'name': name, 'url': url, 'viewport': shot.get('viewport', 'desktop'), 'width': clip['width'],
            'height': clip['height'], 'marks': marks, 'taken': time.strftime('%Y-%m-%d %H:%M')}
    with open(os.path.join(out_dir, f'{name}.json'), 'w', encoding='utf-8') as f:
        json.dump(meta, f, indent=1)
    log(f"    {name} ({vp['width']}px, {len(marks)} marks)")
    return problems


# --------------------------------------------------------------------------------------------- render

FIGURE = re.compile(r'<figure([^>]*)\bdata-shot="([^"]+)"([^>]*)>', re.S)


def figure_html(folder: str, name: str, attrs: str) -> tuple[str, str | None]:
    meta_path = os.path.join(folder, 'shots', f'{name}.json')
    if not os.path.exists(meta_path):
        return f'<figure{attrs} data-shot="{name}"><div class="shot-missing">Screenshot "{name}" not taken yet</div>', f'shot {name} missing'
    meta = load_json(meta_path)
    w, h = meta['width'], meta['height']
    pins = []
    for m in meta['marks']:
        left, top = 100 * m['x'] / w, 100 * m['y'] / h
        bw, bh = 100 * m['w'] / w, 100 * m['h'] / h
        # Where the pin sits: 'l' (default) just left of the box, 'r' just right of it, 'tl' on its top-left corner -
        # beside the text, so the pin never hides the words it explains.
        gap = 100 * 14 / w
        mode = m.get('pin', 'l')
        if mode == 'r':
            px, py = min(left + bw + gap, 97.0), top + bh / 2
        elif mode == 'tl':
            px, py = left, top
        else:
            px, py = max(left - gap, 100 * 12 / w), top + bh / 2
        pins.append(f'<span class="mark-box" style="left:{left:.2f}%;top:{top:.2f}%;width:{bw:.2f}%;height:{bh:.2f}%"></span>'
                    f'<span class="pin" style="left:{px:.2f}%;top:{py:.2f}%">{m["n"]}</span>')
    cls = f'shot-frame vp-{meta["viewport"]}'
    stamp = f'?t={int(os.path.getmtime(os.path.join(folder, "shots", name + ".jpg")))}'
    frame = (f'<div class="{cls}" style="--ratio:{w}/{h}"><img src="../shots/{name}.jpg{stamp}" alt="">{"".join(pins)}</div>')
    return f'<figure{attrs} data-shot="{name}" data-vp="{meta["viewport"]}">{frame}', None


def build_html(folder: str, cfg: dict, facts: dict) -> tuple[str, list[str]]:
    build = os.path.join(folder, 'build')
    os.makedirs(os.path.join(build, 'fonts'), exist_ok=True)
    problems: list[str] = []
    # Brand assets straight from the repository, so the guide follows the site's look.
    for font in ('rubik-latin.woff2', 'rubik-latin-ext.woff2'):
        shutil.copy(os.path.join(REPO_DIR, 'public', 'fonts', 'rubik', font), os.path.join(build, 'fonts', font))
    shutil.copy(os.path.join(REPO_DIR, 'public', 'img', 'speedpuzzling-logo.svg'), os.path.join(build, 'msp-logo.svg'))
    hero = os.path.join(REPO_DIR, 'public', 'img', 'for-organizers-hero.png')
    if os.path.exists(hero):
        shutil.copy(hero, os.path.join(build, 'hero.png'))
    shutil.copy(os.path.join(SKILL_DIR, 'templates', 'guide.css'), os.path.join(build, 'guide.css'))
    logo = cfg.get('logo')
    if logo:
        src = os.path.join(folder, logo)
        dst = os.path.join(build, 'event-logo.png')
        if shutil.which('sips') and os.path.getsize(src) > 300_000:
            subprocess.run(['sips', '-Z', '700', src, '--out', dst], check=True, stdout=subprocess.DEVNULL)
        else:
            shutil.copy(src, dst)

    with open(os.path.join(folder, 'guide.html'), encoding='utf-8') as f:
        source = f.read()
    missing: set = set()
    page = resolve(source, {**facts, 'built_on': time.strftime('%-d %B %Y')}, missing)
    problems += [f'unresolved placeholder {{{{{m}}}}}' for m in sorted(missing)]

    def fig(m):
        out, problem = figure_html(folder, m.group(2), m.group(1) + m.group(3))
        if problem:
            problems.append(problem)
        return out
    page = FIGURE.sub(fig, page)
    with open(os.path.join(build, 'guide.html'), 'w', encoding='utf-8') as f:
        f.write(page)
    return 'build/guide.html', problems


def render(folder: str, cfg: dict, facts: dict) -> list[str]:
    rel, problems = build_html(folder, cfg, facts)
    out = os.path.join(folder, cfg.get('output', 'guide') + '.pdf')
    b = Browser(folder)
    try:
        b.start()
        b.go('file:///guide/' + rel)
        b.wait_for('body')
        b.js('return document.fonts.ready.then(() => true)')
        time.sleep(1.5)
        footer = cfg.get('footer', 'MySpeedPuzzling - competition toolkit guide')
        pdf = b.cdp('Page.printToPDF', {
            'paperWidth': 8.27, 'paperHeight': 11.69, 'printBackground': True, 'preferCSSPageSize': True,
            'displayHeaderFooter': True, 'headerTemplate': '<span></span>',
            'footerTemplate': ('<div style="width:100%;font:8px Helvetica,Arial,sans-serif;color:#7d879c;padding:0 14mm;'
                               f'display:flex;justify-content:space-between"><span>{html.escape(footer)}</span>'
                               '<span><span class="pageNumber"></span> / <span class="totalPages"></span></span></div>'),
            'marginTop': 0.5, 'marginBottom': 0.6, 'marginLeft': 0.55, 'marginRight': 0.55,
        })['data']
        b.quit()
    finally:
        b.stop()
    raw = base64.b64decode(pdf)
    with open(out, 'wb') as f:
        f.write(raw)
    pages = len(re.findall(rb'/Type\s*/Page[^s]', raw))
    log(f'  PDF: {out} - {pages} pages, {len(raw) / 1_000_000:.1f} MB')
    return problems


# --------------------------------------------------------------------------------------------- main

def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('folder', help='the event folder (outside the repository)')
    ap.add_argument('--only', choices=['discover', 'shoot', 'render'], help='run one step (default: all three)')
    ap.add_argument('--shots', help='comma-separated shot names to retake (default: all)')
    args = ap.parse_args()
    folder = os.path.abspath(args.folder)
    if os.path.commonpath([folder, REPO_DIR]) == REPO_DIR and '/.claude/worktrees/' not in folder:
        sys.exit('The event folder must live outside the repository (real event data never goes into the public repo).')
    cfg = load_json(os.path.join(folder, 'event.json'))
    problems: list[str] = []
    steps = [args.only] if args.only else ['discover', 'shoot', 'render']
    facts = load_json(os.path.join(folder, 'facts.json'), {}) if 'discover' not in steps else None
    if 'discover' in steps:
        log('1/3 discover (read-only, as the organiser)')
        facts = discover(folder, cfg)
    if 'shoot' in steps:
        log('2/3 shoot')
        only = set(args.shots.split(',')) if args.shots else None
        problems += shoot(folder, cfg, facts, only)
    if 'render' in steps:
        log('3/3 render')
        problems += render(folder, cfg, facts)
    if problems:
        log('\nCHECK THESE (the UI or the event changed?):')
        for p in problems:
            log('  - ' + p)
        sys.exit(1)
    log('\nDone, nothing to check.')


if __name__ == '__main__':
    main()
