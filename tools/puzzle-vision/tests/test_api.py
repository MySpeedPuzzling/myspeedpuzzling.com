import base64

import httpx
import pytest

from puzzle_vision.api import (AlreadyProposed, ApiError, AnalysisResult, BadRequest, InternalApi, StaleFingerprint, Unauthorized,
                               uuid_before)

from .conftest import BASE, TOKEN, pid


def test_claim_sends_requires_as_a_list_and_answers_work_items(api, server):
    server.analyses[("artwork", pid(0))] = {"puzzleId": pid(0), "analyzer": "artwork", "analyzerVersion": "x", "imageFingerprint": server.puzzles[pid(0)]["imageFingerprint"],
                                            "analyzedAt": None, "receivedAt": "2026-10-18T10:00:00+00:00", "result": {"k": 1}}
    items = api.claim("colors", "1.0.0+t", "mac-m1-air", limit=10, lease_seconds=600, requires=("artwork",))
    assert [i.puzzle_id for i in items] == [pid(0)]  # only the puzzle with a current artwork
    body = server.requests[-1]["json"]
    assert body == {"analyzer": "colors", "version": "1.0.0+t", "worker": "mac-m1-air", "limit": 10, "leaseSeconds": 600, "requires": ["artwork"]}
    assert items[0].claims["colors"]["worker"] == "mac-m1-air"


def test_claim_without_requires_leaves_the_field_out_and_clamps_limits(api, server):
    api.claim("ocr", "v", "w", limit=999, lease_seconds=10)
    body = server.requests[-1]["json"]
    assert "requires" not in body and body["limit"] == 200 and body["leaseSeconds"] == 60


def test_invalid_requires_is_a_400_contract_error(api):
    with pytest.raises(BadRequest) as e:
        api.claim("colors", "v", "w", requires=("nonsense",))
    assert e.value.body["errors"]["requires"]
    with pytest.raises(BadRequest):
        api.list_images(analyzer="artwork", status="stale", requires=("artwork",))


def test_list_sends_requires_comma_separated(api, server):
    api.list_images(analyzer="describe", version="v", status="stale", limit=5, requires=("artwork", "ocr"))
    assert server.requests[-1]["params"]["requires"] == "artwork,ocr"


def test_two_workers_never_get_the_same_image_and_own_claims_come_after_fresh_work(api, server):
    a = api.claim("ocr", "v", "w1", limit=2)
    b = api.claim("ocr", "v", "w2", limit=2)
    assert not {i.puzzle_id for i in a} & {i.puzzle_id for i in b}
    again = api.claim("ocr", "v", "w1", limit=5)  # 1 fresh left, then its own 2
    assert [i.puzzle_id for i in again] == [pid(4), pid(0), pid(1)]


def test_release_own_claims(api, server):
    api.claim("ocr", "v", "w1", limit=3)
    assert api.release("w1", analyzer="ocr", puzzle_ids=[pid(0)]) == 1
    assert server.requests[-1]["json"] == {"worker": "w1", "analyzer": "ocr", "puzzleIds": [pid(0)]}
    assert api.release("w1") == 2


def _result(p, fp, analyzer="ocr"):
    return AnalysisResult(p, analyzer, "v", fp, {"schema": 1, "lines": []})


def test_store_splits_into_batches_of_200_and_maps_outcomes(api, server):
    for i in range(5, 450):
        server.add_puzzle(i)
    results = [_result(p, server.puzzles[p]["imageFingerprint"]) for p in sorted(server.puzzles)]
    results[3] = _result(results[3].puzzle_id, "deadbeefdeadbeef")
    out = api.store(results)
    posts = [r for r in server.requests if r["path"] == "/internal-api/puzzle-image-analyses"]
    assert [len(r["json"]["items"]) for r in posts] == [200, 200, 50]
    assert [o.status for o in out].count("stored") == 449
    assert out[3].status == "stale_fingerprint" and out[3].current_fingerprint == server.puzzles[results[3].puzzle_id]["imageFingerprint"]


def test_put_on_a_changed_picture_raises_stale_fingerprint(api, server):
    with pytest.raises(StaleFingerprint):
        api.put_analysis(_result(pid(0), "0000000000000bad"))


def test_429_waits_retry_after_then_succeeds(api, server, sleeps):
    server.fail_next["GET /internal-api/puzzle-images"] = [httpx.Response(429, headers={"Retry-After": "7"}), httpx.Response(503)]
    page = api.list_images(limit=1)
    assert page["puzzles"] and sleeps[:2] == [7.0, 2.0]


def test_429_without_retry_after_backs_off_long(api, server, sleeps):
    server.fail_next["POST /internal-api/puzzle-image-claims"] = [httpx.Response(429)]
    api.claim("ocr", "v", "w")
    assert sleeps == [30.0]


def test_gives_up_after_max_retries(server, sleeps):
    client = InternalApi(BASE, lambda: TOKEN, transport=httpx.MockTransport(server.handler), min_interval=0, max_retries=2, sleep=sleeps.append)
    server.fail_next["GET /internal-api/puzzle-images"] = [httpx.Response(500)] * 3
    with pytest.raises(ApiError, match="gave up"):
        client.list_images()


def test_bulk_read_follows_next_cursor_even_after_a_short_page(api, server):
    for i in range(5):
        server.analyses[("embed_dinov2", pid(i))] = {"puzzleId": pid(i), "analyzer": "embed_dinov2", "analyzerVersion": "v",
                                                     "imageFingerprint": server.puzzles[pid(i)]["imageFingerprint"], "analyzedAt": None,
                                                     "receivedAt": "x", "result": {"cls": "AAA="}}
    server.page_limit_cap = 2  # the server ends pages early (~8 MB rule)
    rows = list(api.iter_analyses("embed_dinov2", limit=500))
    assert [r["puzzleId"] for r in rows] == [pid(i) for i in range(5)]
    assert server.requests[-1]["params"]["onlyCurrent"] == "1"


def test_token_is_read_for_every_request_and_never_kept(server):
    calls = []

    def provider():
        calls.append(1)
        return TOKEN

    client = InternalApi(BASE, provider, transport=httpx.MockTransport(server.handler), min_interval=0)
    client.list_images()
    client.list_images()
    assert len(calls) == 2
    assert TOKEN not in repr(client) and TOKEN not in str(vars(client))


def test_wrong_token_is_unauthorized_without_echoing_it(server):
    client = InternalApi(BASE, lambda: "wrong-secret", transport=httpx.MockTransport(server.handler), min_interval=0)
    with pytest.raises(Unauthorized) as e:
        client.list_images()
    assert "wrong-secret" not in str(e.value)


def test_propose_image_change_and_never_twice(api, server):
    p = pid(1)
    fp = server.puzzles[p]["imageFingerprint"]
    answer = api.propose_image_change(p, b"\xff\xd8jpeg", confidence=0.91234, model_version="artwork-crop 1.0.0", source_image_fingerprint=fp, tags=["artwork_crop"])
    assert answer["changeRequestId"]
    body = server.requests[-1]["json"]
    assert base64.b64decode(body["image"]) == b"\xff\xd8jpeg"
    assert body["source"] == "image_pipeline" and body["tags"] == ["artwork_crop"] and body["confidence"] == 0.9123
    assert body["sourceImageFingerprint"] == fp and body["modelVersion"] == "artwork-crop 1.0.0"
    with pytest.raises(AlreadyProposed):
        api.propose_image_change(p, b"x", confidence=0.9, model_version="m", source_image_fingerprint=fp, tags=["artwork_crop"])
    with pytest.raises(StaleFingerprint):
        api.propose_image_change(pid(2), b"x", confidence=0.9, model_version="m", source_image_fingerprint="old", tags=["artwork_crop"])


def test_change_request_listing_pages_and_approve_carries_the_note(api, server):
    for i in range(3):
        p = pid(i)
        api.propose_image_change(p, b"x", confidence=0.9, model_version="m", source_image_fingerprint=server.puzzles[p]["imageFingerprint"], tags=["artwork_crop"])
    page = api.change_requests_page(source="image_pipeline", tag="artwork_crop", limit=2)
    assert len(page["items"]) == 2 and page["nextCursor"]
    assert len(list(api.iter_change_requests(source="image_pipeline", tag="artwork_crop", limit=2))) == 3
    cid = page["items"][0]["id"]
    api.approve_change_request(cid, ["image"], "auto: confidence 0.900", record_version="rv")
    assert server.requests[-1]["json"] == {"selectedFields": ["image"], "decisionNote": "auto: confidence 0.900", "recordVersion": "rv"}
    api.reject_change_request(page["items"][1]["id"], "Not the picture", decision_note="checked")
    assert server.requests[-1]["json"] == {"rejectionReason": "Not the picture", "decisionNote": "checked"}


def test_uuid_before_is_an_exclusive_cursor_that_keeps_the_id():
    p = pid(7)
    assert uuid_before(p) < p and uuid_before(p) == pid(6)


def test_endpoint_probe(api):
    assert api.endpoint_exists("/internal-api/puzzle-images")
    assert not api.endpoint_exists("/internal-api/puzzle-difficulty-labels")
