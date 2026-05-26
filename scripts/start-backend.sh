#!/bin/sh
set -eu

wait_for_tcp() {
  host="$1"
  port="$2"

  until php -r '
    $host = $argv[1];
    $port = (int) $argv[2];
    $socket = @fsockopen($host, $port, $errno, $errstr, 1);
    if ($socket) {
        fclose($socket);
        exit(0);
    }
    fwrite(STDERR, "Waiting for {$host}:{$port}...\n");
    exit(1);
  ' "$host" "$port"
  do
    sleep 1
  done
}

wait_for_tcp "${DB_HOST:-db}" "${DB_PORT:-5432}"

# Skip Composer auto-scripts here so the worker does not race on Symfony cache files.
composer install --no-interaction --no-scripts

php bin/console doctrine:migrations:migrate --no-interaction
exec php -S 0.0.0.0:8000 -t public public/index.php
