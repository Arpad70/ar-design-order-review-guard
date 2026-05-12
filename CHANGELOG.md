# Changelog

## 0.2.18 - 2026-05-12
- opraven release workflow pre tag build, aby korektne zapisoval diagnostiku do `GITHUB_STEP_SUMMARY`
- nový release obchádza neúspešný tag `v0.2.17` a publikuje fix updateru aj čistenia ZIP assetov

## 0.2.17 - 2026-05-12
- opraven updater GitHub releasov, aby preferoval ZIP asset presne zodpovedajúci aktuálnej verzii tagu
- build pred vytvorením balíčka čistí staré ZIPy v `dist`, takže release už nepribaľuje staršie verzie pluginu

## 0.2.16 - 2026-05-12
- doplněno automatické znovunaplánování WP CRON hooku `ar_design_move_unpaid_to_manual_review`, pokud ve frontě chybí
- opraven stav, kdy přehled manuální kontroly ukazoval `nenaplánované`, protože se CRON po update pluginu sám neobnovil

## 0.2.15 - 2026-05-12
- zjednotený slovenský text v súhrne WP CRON pre prehľad manuálnej kontroly
- opravený lokalizovaný nadpis sekcie a fallback texty pre nespustený / nenaplánovaný CRON

## 0.2.14 - 2026-05-12
- opraven fatální pád pluginu při načítání adminu způsobený chybějícím `RollbackManager.php`
- rollback manager je nyní dostupný ve stejném namespace jako update infrastruktura

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
