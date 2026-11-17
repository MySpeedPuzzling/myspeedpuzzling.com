"""
The venue network between the browser and the app (tools/judge-chaos/README.md "The proxy").

Two layers in one process:
  - a raw TCP front on :8080 - the browser's only way to the site. It can refuse every connection (`down`: the venue
    Wi-Fi is gone - connection refused, every open connection, Mercure's stream included, is cut at once), swallow
    them (`blackhole`: connected, nothing ever comes back - a captive or dead uplink, the browser's own timeouts run)
    or flap between up and down on a schedule. This is what the service worker's own fetches see too - CDP's
    `Network.emulateNetworkConditions` does not reliably reach them.
  - an HTTP layer behind it (aiohttp on 127.0.0.1:8081) that forwards to the app (`UPSTREAM`) and to Mercure
    (`/.well-known/mercure` → `MERCURE_UPSTREAM`, so the hub is on the page's own origin and goes down with the venue
    network) and injects failures per path: N answers of status S (500, 429 with Retry-After, 503 HTML …), a delay, a
    hang (never answered), and a changed `/service-worker.js` (a "deploy" of a new worker - the bytes change, the
    browser installs it).

Control API on :9999 (JSON): GET /state, POST /state (merge: {"down": bool, "blackhole": bool, "flap": {"up_s",
"down_s"} | null, "rules": [...], "sw_variant": int, "mercure_down": bool}), POST /reset, GET /log?since=<n>,
POST /cut (close every open connection now).
A rule: {"path": regex, "method": "POST"|null, "action": "status"|"delay"|"hang"|"after" (forwarded, the answer
replaced by `status` - the app did it, the phone never hears), "status": 500, "retry_after": 3,
"body": "...", "ms": 2000, "count": n (null = forever), "ratio": 0..1}.
"""

import asyncio
import json
import os
import random
import re
import socket
import time

from aiohttp import ClientSession, ClientTimeout, DummyCookieJar, TCPConnector, web

UPSTREAM = os.environ.get('UPSTREAM', 'http://rlt3-web:8080')
MERCURE_UPSTREAM = os.environ.get('MERCURE_UPSTREAM', 'http://rlt3-mercure:80')
FRONT_PORT = int(os.environ.get('FRONT_PORT', '8080'))
HTTP_PORT = 8081
CONTROL_PORT = int(os.environ.get('CONTROL_PORT', '9999'))

HOP_BY_HOP = {'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailers',
              'transfer-encoding', 'upgrade', 'content-length'}

STATE = {'down': False, 'blackhole': False, 'flap': None, 'rules': [], 'sw_variant': 0, 'mercure_down': False}
LOG = []          # every request the HTTP layer saw: {n, t, method, path, status, outcome, ms}
CONNECTIONS = set()  # (client_writer, upstream_writer) of the TCP front
FLAP = {'task': None}


def now_ms():
    return int(time.time() * 1000)


def network_up():
    return not STATE['down'] and not STATE['blackhole']


# ---------------------------------------------------------------- TCP front

async def pipe(reader, writer):
    try:
        while True:
            data = await reader.read(65536)
            if not data:
                break
            writer.write(data)
            await writer.drain()
    except (ConnectionError, asyncio.CancelledError, OSError):
        pass
    finally:
        try:
            writer.close()
        except Exception:
            pass


def hard_close(writer):
    try:
        sock = writer.get_extra_info('socket')
        if sock is not None:
            # RST, not FIN: the browser sees a reset connection at once, like a dropped Wi-Fi
            sock.setsockopt(socket.SOL_SOCKET, socket.SO_LINGER, b'\x01\x00\x00\x00\x00\x00\x00\x00')
        writer.transport.abort()
    except Exception:
        pass


async def front(client_reader, client_writer):
    if STATE['down']:
        hard_close(client_writer)
        return

    if STATE['blackhole']:
        # Accept and never answer - until the mode ends, then cut
        while STATE['blackhole']:
            await asyncio.sleep(0.2)
        hard_close(client_writer)
        return

    try:
        up_reader, up_writer = await asyncio.open_connection('127.0.0.1', HTTP_PORT)
    except OSError:
        hard_close(client_writer)
        return

    pair = (client_writer, up_writer)
    CONNECTIONS.add(pair)
    try:
        await asyncio.gather(pipe(client_reader, up_writer), pipe(up_reader, client_writer))
    finally:
        CONNECTIONS.discard(pair)


def cut_all():
    for client_writer, up_writer in list(CONNECTIONS):
        hard_close(client_writer)
        try:
            up_writer.transport.abort()
        except Exception:
            pass
    CONNECTIONS.clear()


def set_network(down=None, blackhole=None):
    was_up = network_up()
    if down is not None:
        STATE['down'] = bool(down)
    if blackhole is not None:
        STATE['blackhole'] = bool(blackhole)
    if was_up and not network_up():
        cut_all()


async def flapper(up_s, down_s):
    try:
        while True:
            set_network(down=False)
            await asyncio.sleep(up_s)
            set_network(down=True)
            await asyncio.sleep(down_s)
    except asyncio.CancelledError:
        set_network(down=False)
        raise


def set_flap(flap):
    if FLAP['task'] is not None:
        FLAP['task'].cancel()
        FLAP['task'] = None
    STATE['flap'] = flap
    if flap:
        FLAP['task'] = asyncio.get_event_loop().create_task(flapper(float(flap['up_s']), float(flap['down_s'])))


# ---------------------------------------------------------------- HTTP layer

def matching_rule(request):
    for rule in STATE['rules']:
        if rule.get('method') and rule['method'].upper() != request.method:
            continue
        if not re.search(rule['path'], request.path_qs):
            continue
        if rule.get('count') is not None and rule['count'] <= 0:
            continue
        if rule.get('ratio') is not None and random.random() >= float(rule['ratio']):
            continue
        if rule.get('count') is not None:
            rule['count'] -= 1
        rule['hits'] = rule.get('hits', 0) + 1
        return rule
    return None


async def forward(request, session):
    path = request.path_qs
    mercure = request.path.startswith('/.well-known/mercure')
    base = MERCURE_UPSTREAM if mercure else UPSTREAM
    started = time.time()
    entry = {'n': len(LOG) + 1, 't': now_ms(), 'method': request.method, 'path': path, 'status': None, 'outcome': None}
    LOG.append(entry)

    if mercure and STATE['mercure_down']:
        entry.update(status=502, outcome='mercure_down')
        return web.Response(status=502, text='Bad Gateway (judge-chaos: mercure down)')

    rule = matching_rule(request)
    if rule is not None:
        action = rule.get('action', 'status')
        if action == 'hang':
            entry['outcome'] = 'hang'
            while True:
                await asyncio.sleep(3600)
        if action == 'delay':
            entry['outcome'] = 'delay %d' % rule.get('ms', 1000)
            await asyncio.sleep(rule.get('ms', 1000) / 1000)
        if action == 'status':
            status = int(rule.get('status', 500))
            headers = {'Content-Type': rule.get('content_type', 'text/html; charset=UTF-8')}
            if rule.get('retry_after') is not None:
                headers['Retry-After'] = str(rule['retry_after'])
            if rule.get('drain_body', True) and request.can_read_body:
                await request.read()
            entry.update(status=status, outcome='injected')
            return web.Response(status=status, headers=headers,
                                text=rule.get('body', '<html><body>judge-chaos %d</body></html>' % status))

    body = await request.read() if request.can_read_body else None
    headers = [(k, v) for k, v in request.headers.items() if k.lower() not in HOP_BY_HOP]
    if request.path == '/service-worker.js' and STATE['sw_variant']:
        # The browser's update check is conditional - a "deploy" must answer the new bytes, never 304
        # and the bytes uncompressed, so the line appended below lands in the script, not in a gzip stream
        headers = [(k, v) for k, v in headers if k.lower() not in ('if-none-match', 'if-modified-since', 'accept-encoding')]
        headers.append(('Accept-Encoding', 'identity'))
    try:
        upstream = await session.request(request.method, base + path, headers=headers, data=body,
                                         allow_redirects=False, auto_decompress=False)
    except Exception as error:  # the app itself unreachable
        entry.update(status=502, outcome='upstream_error %s' % type(error).__name__)
        return web.Response(status=502, text='Bad Gateway (judge-chaos)')

    if rule is not None and rule.get('action') == 'after':
        # The app did the work, its answer never reaches the phone (a connection that died on the way back)
        await upstream.read()
        upstream.release()
        entry.update(status=int(rule.get('status', 502)), outcome='answer lost (app said %d)' % upstream.status)
        return web.Response(status=int(rule.get('status', 502)), text='judge-chaos: answer lost')

    response_headers = [(k, v) for k, v in upstream.headers.items() if k.lower() not in HOP_BY_HOP]

    if request.path == '/service-worker.js' and STATE['sw_variant'] and upstream.status == 200:
        content = await upstream.read()
        upstream.release()
        content = content + ('\n// judge-chaos deploy variant %d\n' % STATE['sw_variant']).encode()
        response_headers = [(k, v) for k, v in response_headers
                            if k.lower() not in ('content-encoding', 'etag', 'last-modified')]
        entry.update(status=200, outcome='sw_variant %d' % STATE['sw_variant'])
        response = web.Response(status=200, body=content)
        for k, v in response_headers:
            response.headers.add(k, v)
        return response

    response = web.StreamResponse(status=upstream.status, reason=upstream.reason)
    for k, v in response_headers:
        response.headers.add(k, v)
    entry['status'] = upstream.status
    try:
        await response.prepare(request)
        async for chunk in upstream.content.iter_any():
            await response.write(chunk)
        await response.write_eof()
        entry['outcome'] = 'ok'
    except (ConnectionError, asyncio.CancelledError) as error:
        entry['outcome'] = 'cut %s' % type(error).__name__
        raise
    finally:
        entry['ms'] = int((time.time() - started) * 1000)
        upstream.release()
    return response


async def http_app():
    session = ClientSession(connector=TCPConnector(limit=0, force_close=False),
                            timeout=ClientTimeout(total=None, sock_connect=10),
                            cookie_jar=DummyCookieJar())  # never a jar: every browser carries its own cookies
    app = web.Application(client_max_size=64 * 1024 * 1024)

    async def handler(request):
        return await forward(request, session)

    app.router.add_route('*', '/{tail:.*}', handler)
    return app


# ---------------------------------------------------------------- control API

async def control_app():
    app = web.Application()

    async def get_state(request):
        return web.json_response({**STATE, 'connections': len(CONNECTIONS), 'log_size': len(LOG)})

    async def post_state(request):
        data = await request.json()
        if 'rules' in data:
            STATE['rules'] = data['rules']
        if 'sw_variant' in data:
            STATE['sw_variant'] = int(data['sw_variant'])
        if 'mercure_down' in data:
            STATE['mercure_down'] = bool(data['mercure_down'])
            if STATE['mercure_down']:
                cut_all()  # the open stream goes with it; other connections reconnect at once
        if 'flap' in data:
            set_flap(data['flap'])
        if 'down' in data or 'blackhole' in data:
            set_network(down=data.get('down'), blackhole=data.get('blackhole'))
        return await get_state(request)

    async def reset(request):
        set_flap(None)
        STATE.update(rules=[], sw_variant=0, mercure_down=False)
        set_network(down=False, blackhole=False)
        return await get_state(request)

    async def get_log(request):
        since = int(request.query.get('since', '0'))
        return web.json_response(LOG[since:])

    async def cut(request):
        cut_all()
        return web.json_response({'ok': True})

    app.router.add_get('/state', get_state)
    app.router.add_post('/state', post_state)
    app.router.add_post('/reset', reset)
    app.router.add_get('/log', get_log)
    app.router.add_post('/cut', cut)
    return app


async def main():
    http_runner = web.AppRunner(await http_app(), access_log=None)
    await http_runner.setup()
    await web.TCPSite(http_runner, '127.0.0.1', HTTP_PORT).start()

    control_runner = web.AppRunner(await control_app(), access_log=None)
    await control_runner.setup()
    await web.TCPSite(control_runner, '0.0.0.0', CONTROL_PORT).start()

    server = await asyncio.start_server(front, '0.0.0.0', FRONT_PORT, reuse_address=True)
    print('judge-chaos proxy: :%d -> %s (mercure %s), control :%d' % (FRONT_PORT, UPSTREAM, MERCURE_UPSTREAM,
                                                                       CONTROL_PORT), flush=True)
    async with server:
        await server.serve_forever()


if __name__ == '__main__':
    asyncio.run(main())
