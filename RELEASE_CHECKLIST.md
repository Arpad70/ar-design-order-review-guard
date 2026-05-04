# RELEASE CHECKLIST

## 1. Version consistency
- Check plugin header `Version`.
- Check runtime version constant(s).
- Check `VERSION` file value.
- All three must match exactly (`X.Y.Z`).

## 2. Activation and fatal-free load
- Open plugin main admin page.
- Open critical subpages/screens.
- Check PHP error log after page loads.

## 3. Core workflow tests
- Move order to `Manuální kontrola`.
- Single Secure Bin delete (valid secret).
- Bulk Secure Bin delete.
- Verify redirect returns to orders list (with filters preserved).

## 4. Date/time correctness
- Verify UI shows local WordPress timezone.
- Verify date/time format follows WP settings (`date_format` + `time_format`).

## 5. Update path
- Verify Git tag format `vX.Y.Z`.
- Verify GitHub release exists.
- Verify ZIP asset exists and matches version.
- Verify WP update detection works.

## 6. Cron health
- Verify cron hook is scheduled (`ar_design_move_unpaid_to_manual_review`).
- Trigger `wp-cron.php` manually and confirm no errors.

## 7. Data/audit integrity
- After delete: order removed from WooCommerce.
- Reporting/KPI traces purged as intended.
- Audit entry retained (who/when/value/product summary).

## 8. Rollback readiness
- Keep previous stable ZIP available.
- Document rollback target release.

## 9. Sign-off
- Record tested environment (staging/production).
- Record tester name and timestamp.
- Confirm release approved for rollout.
