# Simplio3D Integration

**Contributors:** Simplio3D  
**Tags:** woocommerce, 3d configurator, product customization, iframe  
**Requires at least:** 5.0  
**Tested up to:** 6.8  
**Stable tag:** 1.0.0  
**License:** GPLv2 or later  
**License URI:** https://github.com/digitalartflow/simplio3d-woo-plugin/blob/main/LICENSE

---

## Description

Simplio3D Integration allows you to embed your Simplio3D configurator inside WordPress/WooCommerce pages and link the customized product directly into the WooCommerce cart.

This plugin is ideal if you sell configurable or customizable products (like furniture, jewelry, or manufacturing components) and need to pass dynamic configuration data from Simplio3D into WooCommerce.

---

## Installation

1. Upload the plugin folder to `/wp-content/plugins/simplio3d-integration/` or install it via **WordPress Admin → Plugins → Add New → Upload Plugin**.
2. Activate the plugin from the **Plugins** screen in WordPress.
3. Add the shortcode to any page or product:

   ```php
   [simplio3d_configurator
   url="https://app.simplio3d.com/configurator/share/UNIQUE_SHARE_URL"
   product_id="280"
   height="850px"
   width="100%"]
