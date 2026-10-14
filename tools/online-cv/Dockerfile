# The CV worker of the online competitions (README.md here, docs/features/online-competitions/README.md §5.8):
# Python 3.12 + OpenCV (headless) + numpy, pinned like the bench. No public port - the app reaches it on the internal
# network. Runs as a non-root user and with a read-only file system: photos are read into memory, nothing is written.
#   docker build -t msp-online-cv tools/online-cv
FROM python:3.12.15-slim@sha256:02108f5d322dd89f1c9e552442c25acb0543dfdbc455693a5599624f20d9155d

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    # OpenCV refuses to decode a photo larger than this (server.py checks it again)
    OPENCV_IO_MAX_IMAGE_PIXELS=50000000 \
    # glibc's allocator: two shared arenas, and every buffer from 1 MiB up mapped on its own - a freed photo goes back
    # to the system at once instead of staying in the arena of the request thread that held it (tests/test_server.py
    # LoadTest: nine requests at once with 24 MP photos +2..15 MB over one check like this, +50..140 MB without)
    MALLOC_ARENA_MAX=2 \
    MALLOC_MMAP_THRESHOLD_=1048576

WORKDIR /app

COPY requirements.txt ./
RUN pip install --no-cache-dir --root-user-action=ignore -r requirements.txt

COPY completeness.py server.py ./

RUN groupadd --system --gid 10001 cv && useradd --system --uid 10001 --gid cv --no-create-home --shell /usr/sbin/nologin cv
USER 10001:10001

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD ["python", "-c", "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8000/health', timeout=2)"]

CMD ["python", "server.py"]
