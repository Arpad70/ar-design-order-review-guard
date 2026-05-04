# Changelog

## 0.2.5
- Fixed fatal error on secure delete for WooCommerce versions where `wc_delete_order()` is unavailable.
- Secure delete now uses compatibility fallback chain: `wc_delete_order()` -> `$order->delete(true)` -> `wp_delete_post(..., true)`.

## 0.2.4
- Fixed Secure Bin delete UX: secret password field is now shown directly in order detail (meta box + inline panel).
- Secure delete now purges reporting/KPI traces (`ard_audit_log`, `ard_order_processing`, `ard_order_archive`, `ard_order_flags`) for deleted order.
- Added retained deletion audit entry with actor, timestamp, order value/currency and product summary JSON (`product_ids`, item quantities).
- Added GitHub updater integration (`Update URI` + runtime updater registration) for WP update detection from GitHub Releases.

## 0.2.3
- Added GitHub Actions CI workflow for lint + ZIP build artifact.
- Added GitHub Actions release workflow that builds ZIP and attaches it to GitHub Release on tag `v*`.
- Fixed `scripts/build-plugin.sh` so it works directly from repository root in CI/GitHub.
