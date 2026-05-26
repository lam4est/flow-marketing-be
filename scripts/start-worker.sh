#!/bin/sh
set -eu

wait_for_file() {
  path="$1"

  until [ -f "$path" ]
  do
    echo "Waiting for $path..."
    sleep 1
  done
}

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

wait_for_file /app/vendor/autoload.php
wait_for_tcp backend 8000

exec php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M -vv
