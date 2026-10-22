FROM php:8.2-cli-alpine

RUN apk add --no-cache \
        libsodium-dev \
        openssl \
        openssl-dev \
        oniguruma-dev \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        sodium \
        sockets \
    && rm -rf /var/cache/apk/*

RUN addgroup -S ghostmesh && adduser -S -G ghostmesh ghostmesh

WORKDIR /app

COPY . .

RUN chown -R ghostmesh:ghostmesh /app \
    && chmod +x /app/bin/websocket_relay_daemon.php \
    && chmod +x /app/bin/run_ghostmesh_simulation.php

USER ghostmesh

EXPOSE 9000

CMD ["php", "/app/bin/websocket_relay_daemon.php"]