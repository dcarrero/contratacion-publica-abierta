#!/bin/sh
# Limpiar config/route/view cache del host para evitar conflictos con env Docker
rm -f /app/bootstrap/cache/config.php
rm -f /app/bootstrap/cache/routes-v7.php
rm -f /app/bootstrap/cache/views.php

# Ejecutar el comando original de FrankenPHP
exec frankenphp run --config /etc/frankenphp/Caddyfile
