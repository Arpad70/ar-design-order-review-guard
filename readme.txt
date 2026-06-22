=== AR Design Order Review Guard ===
Contributors: arpad70
Requires at least: 6.7
Tested up to: 6.9.4
Requires PHP: 8.0
Stable tag: 0.2.20
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.txt

Bezpecny mezistav pro manualni kontrolu WooCommerce objednavek bez automatickeho ruseni a bez nechtene rezervace skladu.

== Description ==

Plugin nahradi puvodni auto-cancel flow u nezaplacenych objednavek vlastnim mezistavem pro manualni kontrolu.
Obsahuje ochranu proti smazani objednavek, secure-bin archivaci a pomocne admin workflow akce.
Plugin deklaruje kompatibilitu s WooCommerce HPOS.

== Installation ==

1. Nahrajte plugin do adresare `/wp-content/plugins/`.
2. Aktivujte plugin v administraci WordPressu.
3. Zkontrolujte WooCommerce workflow statusy a navazujici interni procesy.

== Changelog ==

= 0.2.19 =
* Doplnen WordPress-standard `readme.txt`.
* Zachovana HPOS kompatibilita a secure-bin workflow.

= 0.2.0 =
* Zavedena secure-bin archivace a auditni logika.

= 0.1.0 =
* Prvni verze pluginu pro manual review guard workflow.
