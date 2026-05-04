#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="$(cat "${ROOT_DIR}/VERSION")"
OUT_DIR="${ROOT_DIR}/dist"
PLUGIN_DIR_NAME="ar-design-order-review-guard"
ZIP_PATH="${OUT_DIR}/${PLUGIN_DIR_NAME}-${VERSION}.zip"

mkdir -p "${OUT_DIR}"
rm -f "${ZIP_PATH}"

(
  cd "${ROOT_DIR}/.."
  zip -r "${ZIP_PATH}" "${PLUGIN_DIR_NAME}" \
    -x "${PLUGIN_DIR_NAME}/.git/*" \
    -x "${PLUGIN_DIR_NAME}/dist/*"
)

echo "Built: ${ZIP_PATH}"
