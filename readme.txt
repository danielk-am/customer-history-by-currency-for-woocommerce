=== Customer History by Currency for WooCommerce ===
Contributors: danielkam1
Tags: woocommerce, multi-currency, orders, customers, currency
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See what each customer has spent in each currency on the WooCommerce order screen, instead of one total that adds currencies together.

== Description ==

See what each customer has spent in each currency, right on the WooCommerce order screen.

= The problem =

The Customer history box on the edit order screen adds up a customer's orders without reading each order's currency.

On a store that trades in euros, a customer with orders of €41.90, €10.00 and £36.00 shows a total of €87.90. Euros and pounds are added as if they were one unit, then labelled with the store's symbol. The average order value is worked out from the same sum. No exchange rate makes either number right.

= What the plugin does =

It lists one total and one average per currency, each labelled with its currency code:

* €51.90 EUR (2 orders) and £36.00 GBP (1 order), instead of €87.90.
* Refunds come off the total of the refunded order's own currency.
* Currencies that share a symbol stay distinct: $40.00 AUD, $30.00 CAD and $20.00 USD.
* Nothing is converted. Each amount is shown as the customer paid it.

It is built to stay out of the way:

* It only acts when a customer has orders in another currency. For everyone else, the box is exactly what WooCommerce renders.
* It checks its own numbers. If its per-currency counts and totals do not add up to the count and total WooCommerce calculated, it leaves the box alone.
* It stands down if WooCommerce starts listing currencies itself.
* There are no settings. It stores nothing, adds no database tables and makes no external requests.

= Who it helps =

* Store owners who sell in more than one currency, whether through a multi-currency plugin or orders entered by hand.
* Support and finance staff who read this box before they refund, reply or flag an account.
* Agencies and developers who look after multi-currency stores.

= Requirements and limits =

* WooCommerce 10.8 or later, with High-Performance Order Storage switched on. On legacy order storage the plugin changes nothing.
* WooCommerce Analytics enabled. WooCommerce only shows the Customer history box when it is.
* Tested with WooCommerce 11.1.2.
* If your theme replaces WooCommerce's order/customer-history.php template, the plugin only acts when that template still prints the standard total and average.

= Want this in WooCommerce itself? =

This plugin exists so stores can have the fix now. If you would rather see it built in, add a thumbs up to [WooCommerce issue #43781](https://github.com/woocommerce/woocommerce/issues/43781) or add your voice on the [WooCommerce feature requests board](https://woocommerce.com/feature-requests/woocommerce/).

== Installation ==

1. Install and activate the plugin. WooCommerce must be active.
2. Open any order for a customer who has paid in more than one currency.
3. Find the Customer history box. Totals and averages are listed per currency.

There is nothing to configure.

== Frequently Asked Questions ==

= Does it convert currencies? =

No. Each total is shown in the currency the customer paid in. A converted lifetime total would move with exchange rates and would not match your books.

= Why does nothing change for some customers? =

The plugin only acts when a customer has orders in a currency other than your store currency. For a customer who has only paid in your store currency, the box stays exactly as WooCommerce renders it.

= Does it work with legacy order storage? =

No. It needs High-Performance Order Storage. On legacy order storage the plugin changes nothing.

= Does it change my orders or reports? =

No. It only changes what the Customer history box displays. It does not write to orders, Analytics or any setting.

= Which multi-currency plugins does it work with? =

It reads the currency saved on each order, so it does not depend on a particular multi-currency plugin. It has not been tested against specific multi-currency plugins yet.

== Screenshots ==

1. A customer with orders in euros and pounds: one total and one average per currency.
2. Three currencies that share the $ symbol, told apart by their codes.
3. WooCommerce alone, for comparison: euros and pounds added into one total.

== Changelog ==

= 1.0.0 =
* First release. Lists Customer history totals and averages per currency.
