#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="$(cat "${ROOT_DIR}/VERSION")"
OUT_DIR="${ROOT_DIR}/dist"
PLUGIN_DIR_NAME="ar-design-order-review-guard"
ZIP_PATH="${OUT_DIR}/${PLUGIN_DIR_NAME}-${VERSION}.zip"
TMP_DIR="$(mktemp -d)"
STAGE_DIR="${TMP_DIR}/${PLUGIN_DIR_NAME}"

cleanup() {
  rm -rf "${TMP_DIR}"
}
trap cleanup EXIT

mkdir -p "${OUT_DIR}" "${STAGE_DIR}"
rm -f "${ZIP_PATH}"

rsync -a \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='dist' \
  --exclude='.DS_Store' \
  "${ROOT_DIR}/" "${STAGE_DIR}/"

(
  cd "${TMP_DIR}"
  zip -r "${ZIP_PATH}" "${PLUGIN_DIR_NAME}"
)

echo "Built: ${ZIP_PATH}"
