FROM dunglas/frankenphp:php8.4

# Extensiones PHP necesarias
RUN install-php-extensions \
    pdo_mysql \
    pdo_pgsql \
    pdo_sqlite \
    intl \
    bcmath \
    zip \
    gd \
    pcntl \
    opcache

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Node 20 LTS via binario oficial
RUN ARCH=$(dpkg --print-architecture) \
    && if [ "$ARCH" = "arm64" ]; then NODE_ARCH="arm64"; else NODE_ARCH="x64"; fi \
    && curl -fsSL "https://nodejs.org/dist/v20.20.1/node-v20.20.1-linux-${NODE_ARCH}.tar.xz" \
       | tar -xJ -C /usr/local --strip-components=1 \
    && node --version && npm --version

# Self-signed certificate for local HTTPS
RUN mkdir -p /etc/ssl/private && \
    openssl req -x509 -nodes -days 3650 \
      -newkey rsa:2048 \
      -keyout /etc/ssl/private/selfsigned.key \
      -out /etc/ssl/certs/selfsigned.crt \
      -subj "/CN=localhost" \
      -addext "subjectAltName=DNS:localhost,IP:127.0.0.1"

# PHP config: más memoria para comandos de sync
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/memory.ini

# Caddyfile
COPY Caddyfile /etc/frankenphp/Caddyfile

# Entrypoint: limpiar config cache del host antes de arrancar
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /app

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
