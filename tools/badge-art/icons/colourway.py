"""Set-colour colourways of an icon for one-off (single-tier) badges: the house -msp file with ONLY its accent layer
re-tinted (copied from the lab's SB specials.py `set_icon`; IK1's tier-tint method): the `-msp-acc` gradient gets
illus.wash(set icon colour), `-msp-acc-pale` the set's pale pair, and the accent layer's bevel shade (#e45f62, the
house deep coral) the set's deep tone. Face and outline layers are untouched. Coral returns the -msp file unchanged.
"""
import re

from .illus import shift, wash

HOUSE_DEEP = "#e45f62"      # illus.TIERS["msp"]["deep"]: the bevel-shade colour inside the accent layer


def _restop(raw, gid, stops):
    st = "".join(f'<stop offset="{o:g}" stop-color="{c}"/>' for o, c in stops)
    return re.sub(r'(<linearGradient id="%s"[^>]*>).*?(</linearGradient>)' % re.escape(gid),
                  lambda m: m.group(1) + st + m.group(2), raw, flags=re.S)


def set_colourway(msp_svg, icon_name, set_key, colour):
    """msp_svg = <icon>-msp.svg text; colour = frames.f1s.SETS[set_key] (needs 'icon' and 'pale')."""
    if set_key == "coral":
        return msp_svg
    raw = _restop(msp_svg, f"{icon_name}-msp-acc", wash(colour["icon"]))
    raw = _restop(raw, f"{icon_name}-msp-acc-pale", [(0, colour["pale"][0]), (1, colour["pale"][1])])
    m = re.search(r'(<g id="accent">)(.*?)(</g>)', raw, flags=re.S)
    if m:
        deep = shift(colour["icon"], -10.0, 1.12)
        raw = raw[:m.start(2)] + m.group(2).replace(HOUSE_DEEP, deep) + raw[m.end(2):]
    return raw
