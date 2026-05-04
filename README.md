# AR Design Order Review Guard

## Účel
Nahradit automatické rušení nezaplacených objednávek bezpečným mezistavem:

- stav objednávky: `manual-review` (label: **Manuální kontrola**)
- objednávka čeká na ruční zpracování
- uvolní se rezervace skladu
- v tomto stavu se blokuje automatické odečtení skladu

## Co plugin dělá
1. Registruje nový Woo status `wc-manual-review`.
2. Vypíná defaultní Woo akci `wc_cancel_unpaid_orders`.
3. Každých 10 minut kontroluje staré nezaplacené objednávky (`pending`, `on-hold`, `failed`).
4. Pokud jsou starší než limit, přesune je do `manual-review`.
5. Po přesunu uvolní rezervaci skladu (`wc_release_stock_for_order`, pokud je dostupné).
6. V `manual-review` vrací `false` na `woocommerce_can_reduce_order_stock`.

## Konfigurační body
V souboru `ar-design-order-review-guard.php`:

- `STALE_MINUTES` - po kolika minutách přesunout objednávku do manuální kontroly (default `45`).
- `CRON_RECURRENCE` - interval cron běhu (default 10 minut).

## Poznámky
- Stav je záměrně určen pro ruční validaci rizikových/fake objednávek.
- Tým má v adminu bulk akci i rychlé tlačítko pro převod objednávky do `Manuální kontrola`.

## Anti-fake scoring (nově)
Do `Manuální kontroly` se objednávka přesune jen pokud dosáhne risk skóre.

### Default threshold
- `ar_design_order_review_guard_risk_threshold` (default: `5`)

### Default signály
- vyšší hodnota objednávky (default limit 250)
- guest checkout
- free e-mail doména
- rizikovější platební metoda (`cod`, `bacs`)
- chybějící IP
- podezřelý billing name pattern (`test`, `asdf`, `qwerty`, ...)
- neúplná adresa
- neobvykle vysoký počet položek

### Filtry
- `ar_design_order_review_guard_high_total_limit`
- `ar_design_order_review_guard_free_email_domains`
- `ar_design_order_review_guard_risky_payment_methods`
- `ar_design_order_review_guard_calculated_risk`
- `ar_design_order_review_guard_risk_threshold`
- `ar_design_order_review_guard_move_all_stale_unpaid` (default `false`)

Pokud chceš vrátit staré chování (přesun všech starších nezaplacených), nastav filtr `ar_design_order_review_guard_move_all_stale_unpaid` na `true`.

## Produkční strict profil (nastaveno)
Aktuální defaulty pro tento e-shop:

- `risk_threshold`: `4`
- `high_total_limit`: `120.0`
- `risky_payment_methods`: `cod`, `bacs`, `cheque`
- rozšířený seznam free email domén: `gmail.com`, `yahoo.com`, `hotmail.com`, `outlook.com`, `seznam.cz`, `email.cz`, `centrum.cz`, `post.cz`, `zoznam.sk`, `azet.sk`

Tento profil je nastaven přísněji, aby starší nezaplacené objednávky s rizikovými znaky šly rychleji do manuální kontroly a neblokovaly sklad.

## Ultra strict noční režim (nastaveno)
Noční okno: `22:00-06:00` (WP timezone).

V noci se automaticky použije přísnější profil:
- `risk_threshold`: `3`
- `high_total_limit`: `80.0`
- +1 bod do risk skóre za noční režim

### Filtry pro noční režim
- `ar_design_order_review_guard_night_start_hour` (default `22`)
- `ar_design_order_review_guard_night_end_hour` (default `6`)

Režim funguje automaticky bez dalších zásahů.

## Admin report (den vs. noc)
V menu WooCommerce je nová stránka:
- `WooCommerce -> AR Review Guard`

Obsah:
- celkový počet objednávek ve stavu `Manuální kontrola`
- rozpad na noční (`22:00-06:00`) a denní (`06:00-22:00`)
- trend za posledních 14 dní (denně: night/day/total)

## Release checklist
- Povinný post-release checklist je v souboru `RELEASE_CHECKLIST.md`.
- Před každým vydáním musí být checklist kompletně projitý a potvrzený.
