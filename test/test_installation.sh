#!/bin/bash
set -x
set -e

if [ "$GITHUB_REPOSITORY" = "" ] ; then
    echo "GITHUB_REPOSITORY env variable not set" >&2
    exit 1
fi

tmpdir=$(mktemp -d)
cd "$tmpdir"

curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
php wp-cli.phar --info
chmod +x wp-cli.phar
./wp-cli.phar core download
./wp-cli.phar config create --force --dbname=testdb --dbuser="${DB_USER:-user}" --dbhost="${DB_HOST:-127.0.0.1}" --dbpass="${DB_PASS:-password}"
./wp-cli.phar core install --url=localhost --title=test --admin_user=admin --admin_email=example@example.com
./wp-cli.phar plugin install --activate "https://github.com/$GITHUB_REPOSITORY/archive/$GITHUB_SHA.zip"
