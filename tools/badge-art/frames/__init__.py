"""Frame code (only what the badges need): f1.py = the F1 seal ladder v2 tier bases (t1..t5), f1s.py = the F1S-A
ribbon seal one-off base (one per set colour), kit.py = the shared house-style drawing kit.
BASES is the one table freeze_bases.py exports: key -> (under, over, status, title, file stem)."""
from . import f1, f1s

BASES = {**f1.BASES, **f1s.BASES}
