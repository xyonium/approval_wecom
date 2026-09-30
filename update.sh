#!/bin/sh
# One-command updater for approval_wecom inside the Nextcloud container.
# Usage: sh /var/www/html/custom_apps/approval_wecom/update.sh
#
# Downloads the latest release from GitHub and overwrites in-place.

set -e

REPO="xyonium/approval_wecom"
DEST="/var/www/html/custom_apps"
TMP="/tmp/aw-update-$$.tgz"

echo "Fetching latest release tag..."
TAG=$(php -r '
$url = "https://api.github.com/repos/'"$REPO"'/releases/latest";
$ctx = stream_context_create(["http" => ["header" => "User-Agent: NC-updater"]]);
$json = file_get_contents($url, false, $ctx);
$data = json_decode($json, true);
echo $data["tag_name"] ?? "";
')

if [ -z "$TAG" ]; then
  echo "ERROR: could not determine latest release." >&2
  exit 1
fi

echo "Latest release: $TAG"
URL="https://github.com/$REPO/releases/download/$TAG/approval_wecom-${TAG#v}.tar.gz"

echo "Downloading $URL ..."
php -r 'copy($argv[1], $argv[2]) or exit(1);' "$URL" "$TMP"

echo "Extracting to $DEST ..."
tar xzf "$TMP" -C "$DEST"
chown -R www-data:www-data "$DEST/approval_wecom"
rm -f "$TMP"

echo "Done. approval_wecom updated to $TAG."
