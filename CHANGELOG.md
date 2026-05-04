# Changelog

## 0.2.7
- Added bulk action on orders list to move selected orders into `Manuální kontrola`.
- Bulk action now works on both classic orders screen and HPOS orders screen.
- Added admin success notice with number of moved orders.

## 0.2.6
- Added admin overview table with recent Secure Bin operations.
- New section in `WooCommerce -> AR Review Guard`: event type, order ID, actor, timestamp and short detail.

## 0.2.5
- Fixed fatal error on secure delete for WooCommerce versions where `wc_delete_order()` is unavailable.
- Secure delete now uses compatibility fallback chain: `wc_delete_order()` -> `$order->delete(true)` -> `wp_delete_post(..., true)`.
