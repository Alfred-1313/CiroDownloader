FROM node:22-bookworm-slim AS node
FROM rust:1.100.0-slim-bookworm AS apkeep
RUN apt-get update && apt-get install -y --no-install-recommends pkg-config libssl-dev ca-certificates \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /build
# Upstream release binaries require a newer glibc; compile for this image's Bookworm runtime.
ADD --checksum=sha256:6cbefbfe856cdac38d54f023e8def01479587d3bcd9d2f388a156b7669abe287 https://codeload.github.com/EFForg/apkeep/tar.gz/3356f137e34b50c3ae198d23d09dd2aab8799310 /tmp/apkeep.tar.gz
RUN tar -xzf /tmp/apkeep.tar.gz --strip-components=1 && cargo install --path . --locked --jobs 2 --root /out
FROM php:8.3-cli-bookworm
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=apkeep /out/bin/apkeep /usr/local/bin/apkeep
RUN apt-get update && apt-get install -y --no-install-recommends \
    ffmpeg python3 python3-venv libcurl4-openssl-dev libsqlite3-dev libonig-dev libzip-dev ca-certificates coreutils libssl3 libzstd1 \
    && docker-php-ext-install curl pdo_sqlite mbstring pcntl zip \
    && python3 -m venv /opt/yt \
    && /opt/yt/bin/pip install --no-cache-dir 'yt-dlp[default,curl-cffi]==2026.10.26' 'gallery-dl==1.32.19' \
    && rm -rf /var/lib/apt/lists/* \
    && useradd -u 10001 -m zero
WORKDIR /app
# Optional: lets yt-dlp ask a separate provider container for YouTube PO Tokens.
ARG YOUTUBE_POT_PLUGIN=0
RUN if [ "$YOUTUBE_POT_PLUGIN" = 1 ]; then /opt/yt/bin/pip install --no-cache-dir 'bgutil-ytdlp-pot-provider==2.0.2'; fi
COPY src/ src/
COPY bin/ bin/
COPY resources/ resources/
COPY VERSION CHANGELOG.json ./
# Git checkouts created by the installer may inherit umask 077. Normalize the
# code permissions in the image before dropping privileges to the runtime user.
RUN chmod -R u=rwX,go=rX /app/src /app/bin /app/resources /app/VERSION /app/CHANGELOG.json \
    && mkdir /app/storage /telegram-api && chown zero:zero /app/storage /telegram-api
USER zero
CMD ["php", "bin/bot.php"]

