# Changelog

## 0.2.9
- Improved Secure Bin archivation idempotency for already-archived orders.
- Before insert, plugin now checks whether order already exists in Secure Bin.
- If archived record exists, plugin compares old/new snapshot payload.
- If new payload is materially richer, snapshot is updated.
- If no material difference is found, archivation is treated as complete and deletion continues.

## 0.2.8
- Added manual-review lifecycle flags to prevent re-queuing already manually reviewed and returned orders.
- New order meta flags:
- `_ardrg_manual_review_seen`
- `_ardrg_manual_review_returned`
- Cron now skips orders marked as previously returned from manual review.
