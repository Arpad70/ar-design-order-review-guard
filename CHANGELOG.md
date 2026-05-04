# Changelog

## 0.2.3
- Added GitHub Actions CI workflow for lint + ZIP build artifact.
- Added GitHub Actions release workflow that builds ZIP and attaches it to GitHub Release on tag `v*`.
- Fixed `scripts/build-plugin.sh` so it works directly from repository root in CI/GitHub.

## 0.2.2
- Patch release to publish install hardening changes under a fresh Git tag.

## 0.2.1
- Unified minimum requirements with reporting plugin: WordPress 6.7 and PHP 8.0.
- Added install/runtime hardening baseline:
- bootstrap split and autoloader
- requirements gate with admin notice
- DB schema migrator with version checks
- explicit uninstall policy (data retained)
- release metadata files (`VERSION`, `CHANGELOG.md`) and build script

## 0.2.0
- Added bootstrap/runtime split with autoloader.
- Added requirements gate for WordPress, PHP and WooCommerce.
- Added schema migrator with DB version tracking (`ardrg_db_version`).
- Added plugin version tracking (`ardrg_version`).
- Added explicit uninstall policy (`uninstall.php`, data retained by default).
