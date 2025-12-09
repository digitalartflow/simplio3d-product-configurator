=== Simplio3D Product Configurator ===
Contributors: digitalartflow
Tags: woocommerce, 3d configurator, product customization, iframe
Requires at least: 5.0
Tested up to: 6.9
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Simplio3D Product Configurator embeds your Simplio3D configurator and sends configured products to the WooCommerce cart.

== Description ==

This plugin is ideal if you sell configurable or customizable products (like furniture, jewelry, or manufacturing components) and need to pass dynamic configuration data from Simplio3D into WooCommerce.

== Installation ==
1. Upload the plugin folder to `/wp-content/plugins/simplio3d-product-configurator/` or install it via WordPress Admin > Plugins > Add New > Upload Plugin.
2. Activate the plugin from the 'Plugins' screen in WordPress.
3. Add the shortcode to any page or product:

[simplio3d_configurator
url="https://app.simplio3d.com/configurator/share/UNIQUE_SHARE_URL"
product_id="280"
height="850px"
width="100%"]


4. Replace `UNIQUE_SHARE_URL` with the share URL generated from your Simplio3D configurator.
5. Replace `product_id` with the WooCommerce product ID where the order should be linked.

== Usage ==
- You can mix regular WooCommerce products and Simplio3D-configured products in the same store.
- The shortcode will embed your configurator in an iframe and handle “Add to Cart” communication automatically.

== Frequently Asked Questions ==

= How do I get the share URL? =
Log in to your Simplio3D admin and generate a "Share URL" for the configurator.

= Where do I find the WooCommerce product_id? =
Go to **WooCommerce → Products**, hover over a product, and you’ll see the ID.

= Can I use both WooCommerce native products and Simplio3D? =
Yes. Normal WooCommerce products work as usual, and Simplio3D products are added using the shortcode.

= Does this plugin handle pricing? =
Yes. The configurator can send the final calculated price into WooCommerce’s cart. You can also rely on WooCommerce product price if you prefer.

== Screenshots ==
1. Example shortcode in a WordPress page.
2. Configurator embedded inside a WooCommerce product page.
3. Configured product added to WooCommerce cart.

== Changelog ==
= 1.0 =
* Initial release with shortcode support and WooCommerce cart integration.

== Upgrade Notice ==
= 1.0 =
Initial stable release.
