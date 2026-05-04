# Changelog

## 0.2.8
- Added manual-review lifecycle flags to prevent re-queuing already manually reviewed and returned orders.
- New order meta flags:
- `_ardrg_manual_review_seen`
- `_ardrg_manual_review_returned`
- Cron now skips orders marked as previously returned from manual review.

## 0.2.7
- Added bulk action on orders list to move selected orders into `Manuální kontrola`.
- Bulk action now works on both classic orders screen and HPOS orders screen.
- Added admin success notice with number of moved orders.
