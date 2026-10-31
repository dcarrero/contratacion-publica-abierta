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

# Cliente de PostgreSQL 17 para las copias de seguridad (db:backup).
# Del repositorio oficial PGDG: el de Debian trae una versión anterior y pg_dump se niega
# a volcar de un servidor más nuevo que él.
RUN apt-get update \
    && apt-get install -y --no-install-recommends gnupg lsb-release \
    && echo "deb http://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
    && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor -o /usr/share/keyrings/postgresql.gpg \
    && sed -i 's|deb http|deb [signed-by=/usr/share/keyrings/postgresql.gpg] http|' /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client-17 \
    && rm -rf /var/lib/apt/lists/*

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

# PHP config: más memoria para comandos de sync + opcache/JIT también en CLI
RUN { \
      echo "memory_limit=1024M"; \
      echo "max_execution_time=0"; \
    } > /usr/local/etc/php/conf.d/memory.ini \
 && { \
      echo "opcache.enable=1"; \
      echo "opcache.enable_cli=1"; \
      echo "opcache.memory_consumption=256"; \
      echo "opcache.interned_strings_buffer=32"; \
      echo "opcache.max_accelerated_files=20000"; \
      echo "opcache.validate_timestamps=1"; \
      echo "opcache.revalidate_freq=2"; \
      echo "opcache.jit=tracing"; \
      echo "opcache.jit_buffer_size=64M"; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# Caddyfile
COPY Caddyfile /etc/frankenphp/Caddyfile

# Entrypoint: limpiar config cache del host antes de arrancar
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /app

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
