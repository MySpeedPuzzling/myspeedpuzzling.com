"""
judge-chaos runner: `python -m chaos.run [--list] [scenario…]` (through chaos.sh run). Every scenario prints one
PASS/FAIL line; the whole run is written to <OUT>/results-<time>.json (+ evidence JSON and failure screenshots).
"""

import datetime
import json
import sys
import time
import traceback

from . import lib
from .scenarios import SCENARIOS


def main(argv):
    if '--list' in argv:
        for name, (function, _) in SCENARIOS.items():
            print('%-28s %s' % (name, (function.__doc__ or '').strip().splitlines()[0]))
        return 0

    if '--videos-only' in argv:
        from .scenarios import make_videos
        make_videos(lib.Db(), lib.Proxy())
        return 0

    names = [a for a in argv if not a.startswith('--')] or list(SCENARIOS)
    unknown = [n for n in names if n not in SCENARIOS]
    if unknown:
        print('unknown scenario(s): %s (chaos.sh list)' % ', '.join(unknown))
        return 2

    stamp = datetime.datetime.now(datetime.UTC).strftime('%Y%m%dT%H%M%SZ')
    results = {'started': stamp, 'site': lib.SITE, 'scenarios': []}
    proxy = lib.Proxy()
    db = lib.Db()

    for name in names:
        function, tables = SCENARIOS[name]
        proxy.reset()
        if tables:
            db.clear_tables(tables)
        context = {'name': name, 'db': db, 'proxy': proxy, 'browsers': [], 'evidence': {}, 'notes': []}
        started = time.time()
        status = 'PASS'
        error = None
        try:
            function(context)
        except lib.Failed as failure:
            status, error = 'FAIL', str(failure)
        except Exception as failure:  # the tool itself broke - still a red line, with the trace
            status, error = 'ERROR', '%s: %s' % (type(failure).__name__, failure)
            context['evidence']['trace'] = traceback.format_exc()
        finally:
            shots = []
            for browser in context['browsers']:
                if browser.ignored_taps:
                    context['evidence']['ignored_save_taps_%s' % browser.name] = browser.ignored_taps
                    context['notes'].append('%s: %d Save tap(s) did nothing' % (browser.name, len(browser.ignored_taps)))
                if status != 'PASS':
                    shot = browser.shot('%s-%s' % (stamp, name))
                    if shot:
                        shots.append(shot)
                    try:
                        context['evidence']['page_%s' % browser.name] = browser.info()
                    except Exception:
                        pass
                    context['evidence']['console_%s' % browser.name] = browser.console()[-30:]
                browser.quit()
            proxy.reset()
        seconds = round(time.time() - started, 1)
        record = {'name': name, 'status': status, 'seconds': seconds, 'error': error, 'notes': context['notes'],
                  'evidence': context['evidence'], 'screenshots': shots}
        results['scenarios'].append(record)
        print('%s %-28s %6.1fs %s' % (status, name, seconds, error or '; '.join(context['notes'])[:300]), flush=True)
        lib.save_evidence('results-%s' % stamp, results)

    # The live stream must have worked, or the live-update scenarios measured only the polling fallback
    streams = {}
    for entry in proxy.log():
        if entry['path'].startswith('/.well-known/mercure') and entry['method'] == 'GET':
            streams[str(entry['status'])] = streams.get(str(entry['status']), 0) + 1
    results['mercure_streams_by_status'] = streams
    lib.save_evidence('results-%s' % stamp, results)
    print('Mercure streams by status: %s%s' % (streams, '  WARNING: refused streams - check the hub/JWT setup' if any(k in ('401', '403') for k in streams) else ''))

    passed = sum(1 for s in results['scenarios'] if s['status'] == 'PASS')
    print('%d/%d passed - %s/results-%s.json' % (passed, len(results['scenarios']), lib.OUT, stamp))
    return 0 if passed == len(results['scenarios']) else 1


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
