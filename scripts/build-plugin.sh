#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="ar-design-order-review-guard"
VERSION="$(tr -d '[:space:]' < "$ROOT_DIR/VERSION")"
BUILD_DIR="$ROOT_DIR/build"
STAGING_DIR="$BUILD_DIR/$SLUG"
ZIP_FILE="$BUILD_DIR/$SLUG-$VERSION.zip"

rm -rf "$STAGING_DIR"
mkdir -p "$STAGING_DIR" "$BUILD_DIR"

RSYNC_ARGS=(-a --delete)
if [[ -f "$ROOT_DIR/.distignore" ]]; then
    while IFS= read -r line || [[ -n "$line" ]]; do
        [[ -z "$line" || "$line" == \#* ]] && continue
        RSYNC_ARGS+=("--exclude=$line")
    done < "$ROOT_DIR/.distignore"
fi

rsync "${RSYNC_ARGS[@]}" "$ROOT_DIR/" "$STAGING_DIR/"
rm -f "$ZIP_FILE"
(cd "$BUILD_DIR" && zip -rq "$(basename "$ZIP_FILE")" "$SLUG")

echo "Created package: $ZIP_FILE"
