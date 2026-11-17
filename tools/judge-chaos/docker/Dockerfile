# The judge-chaos runner and proxy (tools/judge-chaos/README.md): Python with the WebDriver client, the proxy's HTTP
# library, Postgres for the checks and Pillow for the camera videos. Built locally (`chaos.sh build`), never pushed.
FROM python:3.15-alpine
RUN pip install --no-cache-dir selenium==4.52.0 aiohttp==3.14.5 'psycopg[binary]==3.3.9' pillow==13.0.0
WORKDIR /chaos
