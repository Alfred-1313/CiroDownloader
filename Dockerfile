FROM node:22-bookworm-slim AS node
FROM php:8.3-cli-bookworm
COPY --from=node /usr/local/bin/node /usr/local/bin/node
RUN apt-get update && apt-get install -y --no-install-recommends \
    ffmpeg python3 python3-venv libcurl4-openssl-dev libsqlite3-dev libonig-dev ca-certificates coreutils \
    && docker-php-ext-install curl pdo_sqlite mbstring \
    && python3 -m venv /opt/yt \
    && /opt/yt/bin/pip install --no-cache-dir 'yt-dlp[default]==2026.10.26' \
    && rm -rf /var/lib/apt/lists/* \
    && useradd -u 10001 -m zero
WORKDIR /app
COPY src/ src/
COPY bin/ bin/
COPY resources/ resources/
COPY VERSION CHANGELOG.json ./
# Git checkouts created by the installer may inherit umask 077. Normalize the
# code permissions in the image before dropping privileges to the runtime user.
RUN chmod -R u=rwX,go=rX /app/src /app/bin /app/resources /app/VERSION /app/CHANGELOG.json \
    && mkdir /app/storage && chown zero:zero /app/storage
USER zero
CMD ["php", "bin/bot.php"]
