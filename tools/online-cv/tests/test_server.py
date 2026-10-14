"""
The CV worker's HTTP rules and its answers (server.py): the allowlist, the download limits, the queue, the errors the
app tells apart (retry later / give up) and two end-to-end checks on the bench's synthetic puzzles. Run by test.sh in
a throwaway container: python -m unittest discover -s tests -v (from tools/online-cv, tools/ mounted read-only).
"""

from __future__ import annotations

import http.client
import json
import os
import socket
import subprocess
import sys
import threading
import time
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from unittest import mock

import cv2
import numpy as np

HERE = Path(__file__).resolve()
sys.path.insert(0, str(HERE.parents[1]))
sys.path.insert(0, str(HERE.parents[2] / "online-bench" / "completeness"))

import completeness  # noqa: E402
import server  # noqa: E402
from selftest import build_case  # noqa: E402

CASES = {}


def case(index: int, motif: str, dropped: int) -> dict:
    """A synthetic finish of the bench (selftest.build_case, seed 7) as the files a store would hold"""
    key = (index, motif, dropped)

    if key not in CASES:
        built = build_case(index, motif, dropped, "contrasting", 7)
        CASES[key] = {
            # Lossless: the photos went through JPEG already (selftest.photograph) - the verdicts stay the bench's
            "finish": cv2.imencode(".png", built["finish_photo"])[1].tobytes(),
            "mat": cv2.imencode(".png", built["mat_photo"])[1].tobytes(),
            "reference": cv2.imencode(".png", built["reference"])[1].tobytes(),
            "pieces": built["rows"] * built["columns"],
            "centres": list(built["centres"].values()),
        }

    return CASES[key]


class Fixtures:
    """An object store stand-in: path -> how to answer; every request counted"""

    def __init__(self):
        self.routes = {}
        self.requests = []
        fixtures = self

        class FixtureHandler(BaseHTTPRequestHandler):
            def log_message(self, format, *args):  # noqa: A002
                pass

            def do_GET(self):  # noqa: N802
                path = self.path.split("?")[0]
                fixtures.requests.append(path)
                route = fixtures.routes.get(path, {"status": 404, "body": b"missing"})
                time.sleep(route.get("delay", 0))
                self.send_response(route.get("status", 200))

                if "location" in route:
                    self.send_header("Location", route["location"])

                body = route.get("body", b"")

                if route.get("length", True):
                    self.send_header("Content-Length", str(len(body)))

                self.end_headers()

                try:
                    if "cut" in route:
                        # The whole body's length declared, only its start sent - then the connection closes
                        self.wfile.write(body[:route["cut"]])
                        self.wfile.flush()
                        self.close_connection = True
                    elif "dribble" in route:
                        for index in range(len(body)):
                            self.wfile.write(body[index:index + 1])
                            self.wfile.flush()
                            time.sleep(route["dribble"])
                    else:
                        self.wfile.write(body)
                except (BrokenPipeError, ConnectionResetError):
                    pass

        self.server = ThreadingHTTPServer(("127.0.0.1", 0), FixtureHandler)
        self.server.daemon_threads = True
        self.port = self.server.server_address[1]
        threading.Thread(target=self.server.serve_forever, daemon=True).start()

    def url(self, path: str) -> str:
        return f"http://127.0.0.1:{self.port}{path}?X-Amz-Signature=secret"

    def close(self):
        self.server.shutdown()
        self.server.server_close()


class WorkerTestCase(unittest.TestCase):
    def setUp(self):
        self.fixtures = Fixtures()
        self.servers = []
        self.start()

    def tearDown(self):
        for running in self.servers:
            running.shutdown()
            running.server_close()

        self.fixtures.close()

    def start(self, **overrides):
        config = server.Config(**{
            "listen_host": "127.0.0.1",
            "listen_port": 0,
            "allowed_hosts": server.parse_allowed_hosts(f"127.0.0.1:{self.fixtures.port}"),
            "fetch_timeout": 5.0,
            **overrides,
        })
        running = server.make_server(config)
        threading.Thread(target=running.serve_forever, daemon=True).start()
        self.servers.append(running)
        self.worker = running

        return running

    def restart(self, **overrides):
        running = self.servers.pop()
        running.shutdown()
        running.server_close()

        return self.start(**overrides)

    def call(self, method: str, path: str, body: object = None, raw: bytes | None = None, running=None):
        port = (running or self.worker).server_address[1]
        connection = http.client.HTTPConnection("127.0.0.1", port, timeout=30)
        data = raw if raw is not None else (None if body is None else json.dumps(body).encode())
        headers = {"Content-Type": "application/json"} if data is not None else {}
        connection.request(method, path, body=data, headers=headers)
        response = connection.getresponse()
        payload = response.read()
        connection.close()

        return response.status, json.loads(payload) if payload else None, dict(response.getheaders())

    def check(self, **body):
        return self.call("POST", "/check", body)

    def serve(self, path: str, **route) -> str:
        self.fixtures.routes[path] = route

        return self.fixtures.url(path)


class AllowlistTest(unittest.TestCase):
    def test_entries_are_read_and_unreadable_ones_left_out(self):
        allowed = server.parse_allowed_hosts(" Minio:9000, store.Example.com ,[::1]:8080, bad:port, a:1:2, , x/y, user@h, :80, h:70000")

        self.assertEqual(allowed, frozenset({("minio", 9000), ("store.example.com", None), ("::1", 8080)}))
        self.assertEqual(server.parse_allowed_hosts(""), frozenset())

    def test_a_url_matches_host_and_effective_port(self):
        allowed = server.parse_allowed_hosts("minio:9000,store.example.com,plain:80")
        cases = {
            "http://minio:9000/puzzle/online/a.jpg?sig=1": True,
            "http://MINIO:9000/puzzle/a.jpg": True,
            "http://minio/puzzle/a.jpg": False,
            "http://minio:9001/puzzle/a.jpg": False,
            "https://store.example.com/bucket/a.jpg": True,
            "https://store.example.com:443/bucket/a.jpg": True,
            "http://store.example.com/bucket/a.jpg": True,
            "https://store.example.com:8443/bucket/a.jpg": False,
            "http://plain/a.jpg": True,
            "https://plain/a.jpg": False,
            "http://user:pass@minio:9000/a.jpg": False,
            "http://minio:9000@evil.example/a.jpg": False,
            "ftp://minio:9000/a.jpg": False,
            "file:///etc/passwd": False,
            "gopher://minio:9000/": False,
            "http://minio:9000/a b.jpg": False,
            "http://minio:99999/a.jpg": False,
            "http://evil.example/a.jpg": False,
            "http://minio.evil.example:9000/a.jpg": False,
            "//minio:9000/a.jpg": False,
            "http://minio:9000/a\x7f.jpg": False,
            # Printable ASCII only: http.client could not even send these (a 500 before)
            "http://minio:9000/puzzle/café.jpg": False,
            "http://mínio:9000/a.jpg": False,
            "http://minio:9000/a.jpg?sig=☃": False,
            "http://minio:9000/puzzle/caf%C3%A9.jpg": True,
            "": False,
            None: False,
        }

        for url, expected in cases.items():
            self.assertEqual(server.url_allowed(url, allowed), expected, url)

    def test_nothing_is_allowed_by_default(self):
        self.assertFalse(server.url_allowed("http://minio:9000/a.jpg", server.parse_allowed_hosts("")))
        self.assertEqual(server.Config.from_env({}).allowed_hosts, frozenset())


class CutShortTest(unittest.TestCase):
    """
    A photo cut short is told by its own structure (server.complete()), before the decoder: OpenCV 4.14 refuses it
    (None - "unreadable": a finish photo given up for good, an empty mat or a reference dropped from the check), other
    decoders fill the rest grey - either way not the photo that was taken, and a download to try again.
    """

    @classmethod
    def setUpClass(cls):
        image = cv2.imdecode(np.frombuffer(case(0, "texture", 0)["finish"], np.uint8), cv2.IMREAD_COLOR)
        cls.jpegs = {
            "baseline": cv2.imencode(".jpg", image, [cv2.IMWRITE_JPEG_QUALITY, 90])[1].tobytes(),
            "progressive": cv2.imencode(".jpg", image, [cv2.IMWRITE_JPEG_PROGRESSIVE, 1])[1].tobytes(),
            "restart markers": cv2.imencode(".jpg", image, [cv2.IMWRITE_JPEG_RST_INTERVAL, 4])[1].tobytes(),
        }
        cls.png = cv2.imencode(".png", image)[1].tobytes()

    def test_a_whole_photo_is_complete(self):
        for name, data in self.jpegs.items():
            self.assertTrue(server.complete(data), name)
            # A phone's trailer after the end marker (a motion photo's video) changes nothing
            self.assertTrue(server.complete(data + b"\x00\x00\x00\x18ftypmp42" + bytes(range(256)) * 40), name)

        self.assertTrue(server.complete(self.png))
        self.assertTrue(server.complete(b"\x00\x00\x00\x18ftypheic" + b"\x00" * 200), "other formats are the decoder's")

    def test_a_photo_cut_anywhere_is_not(self):
        for name, data in [*self.jpegs.items(), ("png", self.png)]:
            for share in (0.001, 0.05, 0.3, 0.5, 0.9, 0.999):
                self.assertFalse(server.complete(data[:max(3, int(len(data) * share))]), f"{name} cut at {share}")

            self.assertFalse(server.complete(data[:-1]), f"{name} without its last byte")

    def test_an_end_marker_inside_a_segment_is_not_the_end(self):
        # An EXIF thumbnail ends with its own end marker - inside the APP1 segment, before the photo's scan
        thumbnail = cv2.imencode(".jpg", np.zeros((16, 16, 3), np.uint8))[1].tobytes()
        payload = b"Exif\x00\x00" + thumbnail
        app1 = b"\xff\xe1" + (len(payload) + 2).to_bytes(2, "big") + payload
        data = self.jpegs["baseline"][:2] + app1 + self.jpegs["baseline"][2:]

        self.assertTrue(server.complete(data))
        self.assertFalse(server.complete(data[:len(data) - len(self.jpegs["baseline"]) // 2]))


class RequestTest(WorkerTestCase):
    def test_health(self):
        status, body, _ = self.call("GET", "/health")

        self.assertEqual(status, 200)
        self.assertEqual(body["status"], "ok")
        self.assertEqual(body["version"], server.VERSION)
        self.assertEqual(body["version"], f"w{server.WORKER_VERSION}-c{completeness.VERSION}")
        self.assertEqual(body["busy"], 0)
        self.assertEqual(body["capacity"], 9)
        self.assertEqual(body["allowedHosts"], 1)

    def test_other_paths_are_not_found(self):
        self.assertEqual(self.call("GET", "/check")[0], 404)
        self.assertEqual(self.call("POST", "/health", {})[0], 404)

    def test_an_invalid_request_is_400(self):
        url = self.serve("/a.jpg", body=b"x")
        bodies = [
            [url],
            {},
            {"finishUrl": None},
            {"finishUrl": ""},
            {"finishUrl": 12},
            {"finishUrl": url, "emptyMatUrl": 3},
            {"finishUrl": url, "pieceCount": True},
            {"finishUrl": url, "pieceCount": 1},
            {"finishUrl": url, "pieceCount": 100_001},
            {"finishUrl": url, "pieceCount": "500"},
        ]

        for body in bodies:
            status, answer, _ = self.check(**body) if isinstance(body, dict) else self.call("POST", "/check", body)
            self.assertEqual(status, 400, body)
            self.assertEqual(answer["error"], "invalid_request")

        status, answer, _ = self.call("POST", "/check", raw=b"{not json")
        self.assertEqual((status, answer["error"]), (400, "invalid_request"))
        self.assertEqual(self.fixtures.requests, [])

    def test_a_large_body_is_413(self):
        status, answer, _ = self.call("POST", "/check", raw=b"{" + b" " * (server.MAX_BODY_BYTES + 10) + b"}")

        self.assertEqual((status, answer["error"]), (413, "payload_too_large"))

    def test_a_url_of_another_host_is_refused_before_anything_is_downloaded(self):
        finish = self.serve("/finish.jpg", body=case(0, "texture", 0)["finish"])

        for field_name, name, url in [
            ("finishUrl", "finish", "http://169.254.169.254/latest/meta-data/"),
            ("emptyMatUrl", "emptyMat", f"http://localhost:{self.fixtures.port}/mat.jpg"),
            ("referenceUrl", "reference", f"http://127.0.0.1:{self.fixtures.port + 1}/ref.png"),
            ("referenceUrl", "reference", f"file:///etc/passwd"),
        ]:
            body = {"finishUrl": finish, "pieceCount": 500, field_name: url}
            status, answer, _ = self.check(**body)

            self.assertEqual(status, 403, url)
            self.assertEqual(answer, {"error": "url_not_allowed", "input": name})

        self.assertEqual(self.fixtures.requests, [])

    def test_a_redirect_is_never_followed(self):
        target = self.serve("/elsewhere.jpg", body=case(0, "texture", 0)["finish"])
        finish = self.serve("/finish.jpg", status=302, location=target)

        status, answer, _ = self.check(finishUrl=finish, pieceCount=1000)

        self.assertEqual((status, answer), (422, {"error": "finish_unavailable", "status": 302}))
        self.assertEqual(self.fixtures.requests, ["/finish.jpg"])

    def test_a_download_larger_than_the_cap_is_refused_while_read(self):
        self.restart(max_bytes=10_000)
        declared = self.serve("/declared.jpg", body=b"\xff" * 50_000)
        undeclared = self.serve("/undeclared.jpg", body=b"\xff" * 50_000, length=False)

        for url in (declared, undeclared):
            status, answer, _ = self.check(finishUrl=url, pieceCount=500)
            self.assertEqual((status, answer), (422, {"error": "finish_too_large"}), url)

    def test_a_slow_download_ends_at_the_deadline(self):
        self.restart(fetch_timeout=1.0)
        url = self.serve("/slow.jpg", body=b"\xff" * 200, dribble=0.05)
        started = time.monotonic()

        status, answer, _ = self.check(finishUrl=url, pieceCount=500)

        self.assertEqual((status, answer), (502, {"error": "download_failed", "input": "finish", "status": None, "reason": "timeout"}))
        self.assertLess(time.monotonic() - started, 2.0)

    def test_a_store_that_never_answers_ends_at_the_deadline(self):
        self.restart(fetch_timeout=0.8)
        url = self.serve("/hanging.jpg", body=b"x", delay=3)
        started = time.monotonic()

        status, answer, _ = self.check(finishUrl=url, pieceCount=500)

        self.assertEqual((status, answer["error"]), (502, "download_failed"))
        self.assertLess(time.monotonic() - started, 2.0)

    def test_a_full_queue_answers_busy_at_once(self):
        self.restart(workers=1, queue=0)
        slow = self.serve("/slow-finish.jpg", body=case(0, "texture", 0)["finish"], delay=1.5)
        quick = self.serve("/finish.jpg", body=case(0, "texture", 0)["finish"])
        first = {}

        def call_first():
            first["answer"] = self.check(finishUrl=slow, pieceCount=1000)

        thread = threading.Thread(target=call_first)
        thread.start()

        for _ in range(100):
            if self.worker.worker.busy == 1:
                break

            time.sleep(0.02)

        started = time.monotonic()
        status, answer, headers = self.check(finishUrl=quick, pieceCount=1000)

        self.assertEqual((status, answer), (503, {"error": "busy"}))
        self.assertEqual(headers.get("Retry-After"), "5")
        self.assertLess(time.monotonic() - started, 0.5)

        thread.join()
        self.assertEqual(first["answer"][0], 200)
        self.assertEqual(self.worker.worker.busy, 0)
        self.assertEqual(self.check(finishUrl=quick, pieceCount=1000)[0], 200)

    def test_a_finish_photo_the_store_no_longer_has_is_permanent_and_a_failing_store_is_not(self):
        gone = self.serve("/gone.jpg", status=404, body=b"NoSuchKey")
        refused = self.serve("/refused.jpg", status=403, body=b"Expired")
        failing = self.serve("/failing.jpg", status=500, body=b"oops")
        throttled = self.serve("/throttled.jpg", status=503, body=b"slow down")
        garbage = self.serve("/garbage.jpg", body=b"not a jpeg at all" * 10)

        with socket.socket() as probe:
            probe.bind(("127.0.0.1", 0))
            closed_port = probe.getsockname()[1]

        self.assertEqual(self.check(finishUrl=gone, pieceCount=500)[:2], (422, {"error": "finish_unavailable", "status": 404}))
        self.assertEqual(self.check(finishUrl=refused, pieceCount=500)[:2], (422, {"error": "finish_unavailable", "status": 403}))
        self.assertEqual(self.check(finishUrl=failing, pieceCount=500)[:2], (502, {"error": "download_failed", "input": "finish", "status": 500, "reason": "status"}))
        self.assertEqual(self.check(finishUrl=throttled, pieceCount=500)[:2], (502, {"error": "download_failed", "input": "finish", "status": 503, "reason": "status"}))
        self.assertEqual(self.check(finishUrl=garbage, pieceCount=500)[:2], (422, {"error": "finish_unreadable"}))

        self.restart(allowed_hosts=server.parse_allowed_hosts(f"127.0.0.1:{self.fixtures.port},127.0.0.1:{closed_port}"))
        status, answer, _ = self.check(finishUrl=f"http://127.0.0.1:{closed_port}/a.jpg", pieceCount=500)
        self.assertEqual((status, answer), (502, {"error": "download_failed", "input": "finish", "status": None, "reason": "connection"}))

    def test_an_optional_input_gone_or_unreadable_leaves_the_check_without_it(self):
        puzzle = case(0, "texture", 0)
        finish = self.serve("/finish.jpg", body=puzzle["finish"])
        mat = self.serve("/mat.jpg", status=404, body=b"NoSuchKey")
        reference = self.serve("/reference.heic", body=b"\x00\x00\x00\x18ftypheic" + b"\x00" * 200)

        status, answer, _ = self.check(finishUrl=finish, emptyMatUrl=mat, referenceUrl=reference, pieceCount=puzzle["pieces"])

        self.assertEqual(status, 200)
        self.assertEqual(answer["inputs"], {"emptyMat": "unavailable: 404", "reference": "unreadable"})
        self.assertEqual((answer["verdict"], answer["method"]), ("unsure", "none"))
        self.assertEqual(answer["details"], {"holes": None, "grid": None})

    def test_an_optional_input_whose_store_fails_is_tried_again_later(self):
        puzzle = case(0, "texture", 0)
        finish = self.serve("/finish.jpg", body=puzzle["finish"])
        mat = self.serve("/mat.jpg", status=503, body=b"slow down")

        status, answer, _ = self.check(finishUrl=finish, emptyMatUrl=mat, pieceCount=puzzle["pieces"])

        self.assertEqual((status, answer), (502, {"error": "download_failed", "input": "emptyMat", "status": 503, "reason": "status"}))

    def test_an_optional_input_whose_signature_the_store_refuses_is_tried_again_later(self):
        # A clock or key problem, an expired URL - the photo is not gone; going on without it would weaken the verdict
        puzzle = case(0, "texture", 0)
        finish = self.serve("/finish.jpg", body=puzzle["finish"])
        mat = self.serve("/mat.jpg", body=puzzle["mat"])

        for status_code in server.SIGNATURE_STATUSES:
            refused = self.serve(f"/refused-{status_code}.jpg", status=status_code, body=b"SignatureDoesNotMatch")

            status, answer, _ = self.check(finishUrl=finish, emptyMatUrl=refused, pieceCount=puzzle["pieces"])
            self.assertEqual((status, answer), (502, {"error": "download_failed", "input": "emptyMat", "status": status_code, "reason": "status"}))

            status, answer, _ = self.check(finishUrl=finish, emptyMatUrl=mat, referenceUrl=refused, pieceCount=puzzle["pieces"])
            self.assertEqual((status, answer), (502, {"error": "download_failed", "input": "reference", "status": status_code, "reason": "status"}))

    def test_a_download_cut_short_is_tried_again_later(self):
        puzzle = case(0, "texture", 0)
        finish = self.serve("/finish.jpg", body=puzzle["finish"])

        # The whole length declared, half of it sent - the store's connection dropped
        for field_name, name, photo in (("finishUrl", "finish", "finish"), ("emptyMatUrl", "emptyMat", "mat"), ("referenceUrl", "reference", "reference")):
            cut = self.serve(f"/cut-{name}.png", body=puzzle[photo], cut=len(puzzle[photo]) // 2)
            body = {"finishUrl": finish, "emptyMatUrl": None, "referenceUrl": None, "pieceCount": puzzle["pieces"], field_name: cut}

            status, answer, _ = self.check(**body)
            self.assertEqual((status, answer), (502, {"error": "download_failed", "input": name, "status": None, "reason": "truncated"}), name)

        # No length declared (the connection's end is the body's end): the photo itself tells - a JPEG without its end
        # marker, a PNG without its IEND
        jpeg = cv2.imencode(".jpg", cv2.imdecode(np.frombuffer(puzzle["finish"], np.uint8), cv2.IMREAD_COLOR))[1].tobytes()
        self.assertEqual(self.check(finishUrl=self.serve("/whole.jpg", body=jpeg, length=False), pieceCount=puzzle["pieces"])[0], 200)

        for path, data in (("/short.jpg", jpeg[:len(jpeg) * 2 // 3]), ("/short.png", puzzle["finish"][:len(puzzle["finish"]) * 2 // 3])):
            status, answer, _ = self.check(finishUrl=self.serve(path, body=data, length=False), pieceCount=puzzle["pieces"])
            self.assertEqual((status, answer), (502, {"error": "download_failed", "input": "finish", "status": None, "reason": "truncated"}), path)

    def test_a_waiting_request_downloads_nothing_before_its_turn(self):
        self.restart(workers=1, queue=1)
        puzzle = case(0, "texture", 0)
        first = self.serve("/first.jpg", body=puzzle["finish"], delay=1.0)
        second = self.serve("/second.jpg", body=puzzle["finish"])
        answers = {}

        def call(name, url):
            answers[name] = self.check(finishUrl=url, pieceCount=puzzle["pieces"])[0]

        threads = [threading.Thread(target=call, args=("first", first))]
        threads[0].start()

        for _ in range(100):
            if self.fixtures.requests == ["/first.jpg"]:
                break

            time.sleep(0.01)

        threads.append(threading.Thread(target=call, args=("second", second)))
        threads[1].start()

        for _ in range(100):
            if self.worker.worker.busy == 2:
                break

            time.sleep(0.01)

        time.sleep(0.2)
        self.assertEqual(self.worker.worker.busy, 2)
        # Admitted and waiting - nothing of its own downloaded, nothing decoded
        self.assertEqual(self.fixtures.requests, ["/first.jpg"])

        for thread in threads:
            thread.join()

        self.assertEqual(answers, {"first": 200, "second": 200})
        self.assertEqual(self.fixtures.requests, ["/first.jpg", "/second.jpg"])

    def test_a_url_with_other_than_ascii_is_refused_not_a_500(self):
        finish = self.serve("/finish.jpg", body=case(0, "texture", 0)["finish"])

        status, answer, _ = self.check(finishUrl=finish, referenceUrl=f"http://127.0.0.1:{self.fixtures.port}/café.jpg", pieceCount=500)

        self.assertEqual((status, answer), (403, {"error": "url_not_allowed", "input": "reference"}))
        self.assertEqual(self.fixtures.requests, [])

    def test_a_body_shorter_than_its_length_gives_the_thread_back(self):
        port = self.worker.server_address[1]

        def answer(client: socket.socket) -> bytes:
            # Everything until the worker closes the connection
            received = b""

            while chunk := client.recv(4096):
                received += chunk

            return received

        with mock.patch.object(server.Handler, "timeout", 0.5):
            # Fewer bytes than declared, the connection held open: 408 after the timeout, the connection closed
            with socket.create_connection(("127.0.0.1", port), timeout=5) as client:
                client.sendall(b"POST /check HTTP/1.1\r\nHost: cv\r\nContent-Type: application/json\r\nContent-Length: 100\r\n\r\n{\"finishUrl\"")
                started = time.monotonic()
                received = answer(client)

            self.assertTrue(received.startswith(b"HTTP/1.0 408 "), received)
            self.assertTrue(received.endswith(b'{"error": "request_timeout"}'), received)
            self.assertLess(time.monotonic() - started, 3.0)

            # Fewer bytes than declared, the client's side closed: 400 at once
            with socket.create_connection(("127.0.0.1", port), timeout=5) as client:
                client.sendall(b"POST /check HTTP/1.1\r\nHost: cv\r\nContent-Length: 100\r\n\r\n{}")
                client.shutdown(socket.SHUT_WR)
                received = answer(client)

            self.assertTrue(received.startswith(b"HTTP/1.0 400 "), received)

            # Nothing at all after connecting: the thread gives up too
            with socket.create_connection(("127.0.0.1", port), timeout=5) as client:
                self.assertEqual(answer(client), b"")

        self.assertEqual(self.call("GET", "/health")[1]["busy"], 0)
        self.assertEqual(self.fixtures.requests, [])

    def test_a_check_that_breaks_answers_500_and_the_worker_stays_up(self):
        finish = self.serve("/finish.jpg", body=case(0, "texture", 0)["finish"])

        with mock.patch.object(completeness, "check_photo", side_effect=RuntimeError("a bug")), mock.patch("sys.stderr"):
            status, answer, _ = self.check(finishUrl=finish, pieceCount=500)

        self.assertEqual((status, answer), (500, {"error": "check_failed"}))
        self.assertEqual(self.call("GET", "/health")[1]["busy"], 0)

    def test_the_log_never_carries_a_url(self):
        finish = self.serve("/finish.jpg", body=b"garbage")

        with mock.patch("builtins.print") as printed:
            self.check(finishUrl=finish, pieceCount=500)

        lines = [call.args[0] for call in printed.call_args_list]
        self.assertEqual(len(lines), 1)
        self.assertNotIn("Signature", lines[0])
        self.assertNotIn("finish.jpg", lines[0])
        self.assertEqual(json.loads(lines[0])["status"], 422)


class CheckTest(WorkerTestCase):
    def test_a_complete_puzzle_with_the_empty_mat_and_the_reference(self):
        puzzle = case(0, "texture", 0)

        status, answer, _ = self.check(
            finishUrl=self.serve("/finish.jpg", body=puzzle["finish"]),
            emptyMatUrl=self.serve("/mat.jpg", body=puzzle["mat"]),
            referenceUrl=self.serve("/reference.png", body=puzzle["reference"]),
            pieceCount=puzzle["pieces"],
        )

        self.assertEqual(status, 200)
        self.assertEqual(answer["verdict"], "complete")
        self.assertEqual(answer["method"], "holes+grid")
        self.assertEqual(answer["gaps"], [])
        self.assertEqual(answer["image"], {"width": 1920, "height": 1080})
        self.assertEqual(answer["inputs"], {"emptyMat": "used", "reference": "used"})
        self.assertEqual(answer["version"], server.VERSION)
        self.assertEqual(answer["details"]["holes"]["verdict"], "complete")
        self.assertEqual(answer["details"]["grid"]["verdict"], "complete")
        self.assertGreater(answer["confidence"], 0)
        self.assertGreaterEqual(answer["ms"], answer["checkMs"])
        json.dumps(answer)

    def test_a_missing_piece_is_a_gap_where_it_is(self):
        puzzle = case(6, "texture", 1)

        for mat, reference, method in [(True, True, "holes+grid"), (True, False, "holes"), (False, True, "grid")]:
            status, answer, _ = self.check(
                finishUrl=self.serve("/finish.jpg", body=puzzle["finish"]),
                emptyMatUrl=self.serve("/mat.jpg", body=puzzle["mat"]) if mat else None,
                referenceUrl=self.serve("/reference.png", body=puzzle["reference"]) if reference else None,
                pieceCount=puzzle["pieces"],
            )

            self.assertEqual(status, 200, method)
            self.assertEqual((answer["verdict"], answer["method"]), ("gaps", method))
            self.assertTrue(all(set(box) == {"x", "y", "w", "h"} and all(isinstance(v, int) for v in box.values()) for box in answer["gaps"]))

            for x, y in puzzle["centres"]:
                self.assertTrue(any(box["x"] - 20 <= x <= box["x"] + box["w"] + 20 and box["y"] - 20 <= y <= box["y"] + box["h"] + 20 for box in answer["gaps"]), method)

    def test_without_the_piece_count_nothing_is_decided(self):
        puzzle = case(0, "texture", 0)

        status, answer, _ = self.check(
            finishUrl=self.serve("/finish.jpg", body=puzzle["finish"]),
            emptyMatUrl=self.serve("/mat.jpg", body=puzzle["mat"]),
            pieceCount=None,
        )

        self.assertEqual(status, 200)
        self.assertEqual((answer["verdict"], answer["method"], answer["confidence"]), ("unsure", "none", 0.0))


def free_port() -> int:
    with socket.socket() as probe:
        probe.bind(("127.0.0.1", 0))

        return probe.getsockname()[1]


def process_memory_kb(pid: int) -> dict:
    """VmRSS (now) and VmHWM (the peak so far) of a process, in kB - Linux only"""
    values = {}

    for line in Path(f"/proc/{pid}/status").read_text().splitlines():
        name, _, value = line.partition(":")

        if name in ("VmRSS", "VmHWM"):
            values[name] = int(value.split()[0])

    return values


class LoadTest(unittest.TestCase):
    """
    Memory under load: a waiting request holds no photo - the compute slot is taken before anything is downloaded, so
    nine requests at once with three large photos each (24 MP, 72 MB decoded) hold about one check's photos at the
    peak, never nine. The worker runs as a process of its own (its peak RSS from /proc); one check alone first, then
    nine at once in a fresh process.
    """

    WIDTH, HEIGHT = 6000, 4000
    REQUESTS = 9

    @classmethod
    def setUpClass(cls):
        if not Path("/proc/self/status").exists():
            raise unittest.SkipTest("reads the worker's memory from /proc (Linux)")

        puzzle = case(0, "texture", 0)
        cls.photos = {}

        for name in ("finish", "mat", "reference"):
            image = cv2.imdecode(np.frombuffer(puzzle[name], np.uint8), cv2.IMREAD_COLOR)
            large = cv2.resize(image, (cls.WIDTH, cls.HEIGHT), interpolation=cv2.INTER_CUBIC)
            cls.photos[name] = cv2.imencode(".jpg", large, [cv2.IMWRITE_JPEG_QUALITY, 90])[1].tobytes()

        cls.pieces = puzzle["pieces"]

    def setUp(self):
        self.fixtures = Fixtures()
        self.processes = []

        for name, body in self.photos.items():
            self.fixtures.routes[f"/{name}.jpg"] = {"body": body}

    def tearDown(self):
        for process in self.processes:
            process.kill()
            process.wait()

        self.fixtures.close()

    def spawn(self) -> tuple[subprocess.Popen, int]:
        port = free_port()
        process = subprocess.Popen(
            [sys.executable, str(HERE.parents[1] / "server.py")],
            env={
                **os.environ,
                "ONLINE_CV_LISTEN": f"127.0.0.1:{port}",
                "ONLINE_CV_ALLOWED_HOSTS": f"127.0.0.1:{self.fixtures.port}",
                "ONLINE_CV_WORKERS": "1",
                "ONLINE_CV_QUEUE": str(self.REQUESTS - 1),
                "ONLINE_CV_FETCH_TIMEOUT": "60",
                # As the image runs it (Dockerfile): glibc's allocator gives a freed photo back to the system at once
                # instead of keeping it in the arena of the request thread that held it
                "MALLOC_ARENA_MAX": "2",
                "MALLOC_MMAP_THRESHOLD_": "1048576",
            },
            stdout=subprocess.DEVNULL,
        )
        self.processes.append(process)

        for _ in range(300):
            try:
                connection = http.client.HTTPConnection("127.0.0.1", port, timeout=1)
                connection.request("GET", "/health")

                if connection.getresponse().status == 200:
                    connection.close()

                    return process, port
            except OSError:
                time.sleep(0.1)

        self.fail("the worker did not start")

    def run_checks(self, port: int, count: int) -> list:
        body = json.dumps({
            "finishUrl": self.fixtures.url("/finish.jpg"),
            "emptyMatUrl": self.fixtures.url("/mat.jpg"),
            "referenceUrl": self.fixtures.url("/reference.jpg"),
            "pieceCount": self.pieces,
        }).encode()
        answers = [None] * count

        def call(index: int):
            connection = http.client.HTTPConnection("127.0.0.1", port, timeout=300)
            connection.request("POST", "/check", body=body, headers={"Content-Type": "application/json"})
            response = connection.getresponse()
            answers[index] = (response.status, json.loads(response.read()))
            connection.close()

        threads = [threading.Thread(target=call, args=(index,)) for index in range(count)]

        for thread in threads:
            thread.start()

        for thread in threads:
            thread.join()

        return answers

    def peak_growth_mb(self, count: int) -> tuple[float, list]:
        process, port = self.spawn()
        baseline = process_memory_kb(process.pid)["VmRSS"]
        answers = self.run_checks(port, count)
        peak = process_memory_kb(process.pid)["VmHWM"]

        return (peak - baseline) / 1024, answers

    def test_nine_requests_at_once_hold_about_one_checks_photos(self):
        one, answers = self.peak_growth_mb(1)
        self.assertEqual([status for status, _ in answers], [200])
        nine, answers = self.peak_growth_mb(self.REQUESTS)
        self.assertEqual([status for status, _ in answers], [200] * self.REQUESTS)
        decoded = 3 * self.WIDTH * self.HEIGHT * 3 / 1024 / 1024
        print(f"\n  peak RSS growth: one check {one:.0f} MB, {self.REQUESTS} at once {nine:.0f} MB (one check's photos decoded: {decoded:.0f} MB)", file=sys.stderr)

        # One check at a time (ONLINE_CV_WORKERS=1): the waiting eight hold nothing - allocator slack, never another
        # check's photos
        self.assertLess(nine, one + decoded * 0.5)


class MergedBoxesTest(unittest.TestCase):
    def test_the_same_hole_found_by_both_checks_is_one_box(self):
        boxes = completeness._merge_overlapping([
            {"x": 100, "y": 100, "w": 40, "h": 40, "pieces": 1.0},
            {"x": 95, "y": 98, "w": 48, "h": 44},
            {"x": 400, "y": 300, "w": 40, "h": 40, "cell": [3, 4]},
        ])

        self.assertEqual(boxes, [{"x": 95, "y": 98, "w": 48, "h": 44}, {"x": 400, "y": 300, "w": 40, "h": 40}])


class LoosePiecesTest(unittest.TestCase):
    """The holes check alone and an unfinished puzzle (completeness.HolesOptions.loose_area): a band missing along an
    edge leaves a clean, smaller rectangle - only the pieces lying next to it tell"""

    def test_an_unfinished_puzzle_with_its_pieces_next_to_it_is_never_complete(self):
        import http_selftest

        built = [http_selftest.build_unfinished(index, motif, 7) for motif in ("texture", "smooth") for index in range(4)]
        bands = [puzzle for puzzle in built if puzzle["edge"]]
        self.assertGreaterEqual(len(bands), 2, "the synthetic set holds bands along an edge")

        for puzzle in bands:
            verdict, method, details = completeness.check_photo(puzzle["finish_photo"], mat_bgr=puzzle["mat_photo"], pieces=puzzle["rows"] * puzzle["columns"])

            self.assertEqual(method, "holes")
            self.assertNotEqual(verdict.verdict, "complete", details["holes"])

    def test_a_complete_puzzle_alone_on_its_mat_has_nothing_loose(self):
        built = build_case(0, "smooth", 0, "contrasting", 7)
        verdict = completeness.check_holes(built["mat_photo"], built["finish_photo"], built["rows"] * built["columns"])

        self.assertEqual(verdict.verdict, "complete")
        self.assertEqual(verdict.details["loose"], 0)


if __name__ == "__main__":
    unittest.main()
