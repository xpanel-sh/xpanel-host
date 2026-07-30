#!/usr/bin/env bash
set -Eeuo pipefail

PHAR_URL="https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar"
CHECKSUM_URL="${PHAR_URL}.sha512"
temporary="$(mktemp -d)"
trap 'rm -rf -- "$temporary"' EXIT

curl --fail --location --silent --show-error "$PHAR_URL" -o "$temporary/wp-cli.phar"
curl --fail --location --silent --show-error "$CHECKSUM_URL" -o "$temporary/wp-cli.phar.sha512"
expected="$(awk 'NR == 1 { print $1 }' "$temporary/wp-cli.phar.sha512")"
[[ "$expected" =~ ^[a-fA-F0-9]{128}$ ]] || { echo "Invalid WP-CLI checksum response." >&2; exit 1; }
actual="$(sha512sum "$temporary/wp-cli.phar" | cut -d' ' -f1)"
[[ "${actual,,}" == "${expected,,}" ]] || { echo "WP-CLI checksum verification failed." >&2; exit 1; }
php "$temporary/wp-cli.phar" --info >/dev/null
install -o root -g root -m 0755 "$temporary/wp-cli.phar" /usr/local/bin/wp
/usr/local/bin/wp --info >/dev/null
