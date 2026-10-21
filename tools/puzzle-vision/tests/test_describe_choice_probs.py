"""`describe` 1.1.0: how sure the model was of every closed-list choice, read from the recorded top tokens."""

from puzzle_vision.stages import describe as ds

# A toy tokenizer: a token id is an index into this list, decoding joins the pieces.
PIECES = ['{"', 'style', '":"', 'photo', '"', '_aer', 'ial', 'water', 'colour', ',"', 'themes', '":["', 'animals', '","',
          'pets', '"]', 'collageGrid', '":', 'false', 'true', '}', 'birds']


def decode(ids):
    return "".join(PIECES[i] for i in ids)


def t(piece):
    return PIECES.index(piece)


def step(*choices):
    return [(t(p), q) for p, q in choices]


def answer_steps():
    """{"style":"photo","themes":["animals","pets"],"collageGrid":false} with a few near misses."""
    return [
        step(('{"', 1.0)), step(('style', 1.0)), step(('":"', 1.0)),
        step(('photo', 0.8), ('water', 0.15), ('colour', 0.01)),  # "colour" is no style - never listed
        step(('"', 0.9), ('_aer', 0.1)),                          # "photo" ends here, or goes on to photo_aerial
        step((',"', 1.0)), step(('themes', 1.0)), step(('":["', 1.0)),
        step(('animals', 0.6), ('birds', 0.3)), step(('","', 1.0)),
        step(('pets', 0.97)), step(('"]', 1.0)),
        step((',"', 1.0)), step(('collageGrid', 1.0)), step(('":', 1.0)),
        step(('false', 0.7), ('true', 0.3)), step(('}', 1.0)),
    ]


def test_the_chosen_value_carries_its_probability_and_the_values_it_nearly_chose():
    probs = ds.choice_probs(answer_steps(), decode)
    assert probs["style"]["p"] == 0.72  # 0.8 for "photo" × 0.9 for ending there
    assert probs["style"]["alt"] == {"photo_aerial": 0.08, "watercolour": 0.15}
    assert probs["themes.0"] == {"p": 0.6, "alt": {"birds": 0.3}}
    assert probs["themes.1"] == {"p": 0.97, "alt": {}}
    assert probs["collageGrid"] == {"p": 0.7, "alt": {"true": 0.3}}


def test_a_token_that_also_holds_the_opening_quote_is_compared_from_the_value_on():
    pieces = ['{"style":', '"photo', '"', '"water', 'colour', '}']
    dec = lambda ids: "".join(pieces[i] for i in ids)  # noqa: E731
    steps = [[(0, 1.0)], [(1, 0.7), (3, 0.3)], [(2, 1.0)], [(5, 1.0)]]
    assert ds.choice_probs(steps, dec)["style"] == {"p": 0.7, "alt": {"watercolour": 0.3}}


def test_the_payload_keeps_the_probabilities_beside_the_answer_but_parity_ignores_them():
    text = decode([s[0][0] for s in answer_steps()])
    assert text == '{"style":"photo","themes":["animals","pets"],"collageGrid":false}'
    probs = ds.choice_probs(answer_steps(), decode)
    assert {k for k, *_ in ds._value_spans(text)} == {"style", "themes.0", "themes.1", "collageGrid"}
    assert "choiceProbs" not in ds.PARITY_FIELDS and probs["style"]["p"] < 1


def test_the_recorder_passes_the_constrained_logits_through_and_keeps_the_top_tokens():
    import mlx.core as mx

    inner = lambda ids, logits: logits + mx.array([[0.0, -mx.inf, 0.0, 0.0]])  # noqa: E731 - token 1 not allowed
    rec = ds.ChoiceRecorder(inner, top_k=2)
    out = rec(mx.array([[5]]), mx.array([[2.0, 9.0, 1.0, 0.0]]))
    assert out.shape == (1, 4) and rec.steps[0][0][0] == 0 and abs(rec.steps[0][0][1] - 0.665) < 1e-2
    assert [i for i, _ in rec.steps[0]] == [0, 2]
