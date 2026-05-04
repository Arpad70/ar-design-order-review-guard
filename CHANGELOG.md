# Changelog

## 0.2.13 - 2026-05-04
- v přehledu manuální kontroly přidán popisek s posledním dokončeným během WP CRON
- v závorce se zobrazuje další plánované spuštění CRON hooku `ar_design_move_unpaid_to_manual_review`
- časové údaje jsou ve formátu dle nastavení WordPressu

## 0.2.0
- Added bootstrap/runtime split with autoloader.
- Added requirements gate for WordPress, PHP and WooCommerce.
- Added schema migrator with DB version tracking (`ardrg_db_version`).
- Added plugin version tracking (`ardrg_version`).
- Added explicit uninstall policy (`uninstall.php`, data retained by default).

## 0.2.12
- zaveden standardizovaný post-release checklist v souboru `RELEASE_CHECKLIST.md`
- checklist je nově povinná součást release procesu
