"""MySpeedPuzzling image pipeline (docs/features/insights/image-pipeline.md).

Heavy libraries (torch, mlx, mlx-vlm, transformers, pyobjc) are imported lazily inside the stage functions, so the
pure-python parts and the tests run with the light dependency set.
"""

__version__ = "1.0.0"
USER_AGENT = f"puzzle-vision/{__version__}"
