<?php
/*
Plugin Name: Simplio3D Integration
Plugin URI: https://simplio3d.com/
Description: Receives configurator data from a Simplio3D iframe using postMessage and adds products to the WooCommerce cart with description, thumbnail, config ID, and custom pricing. Provides a shortcode for embedding the iframe.
Version: 1.0.0
Author: Simplio3D
Author URI: https://simplio3d.com/
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.4
Text Domain: simplio3d-integration
*/


if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Simplio3D_Woo_Integration_Enhanced {
    const VERSION       = '0.2.0';
    const NONCE_ACTION  = 'simplio3d_add_to_cart';
    const NONCE_NAME    = 'nonce';

    public function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        add_action( 'wp_ajax_simplio3d_add_to_cart', [ $this, 'ajax_add_to_cart' ] );
        add_action( 'wp_ajax_nopriv_simplio3d_add_to_cart', [ $this, 'ajax_add_to_cart' ] );
        // add_action('woocommerce_after_cart_item_name', [$this, 'print_thumb_after_name'], 5, 2);
        add_action('wp_head', function(){
            echo '<style>.wc-item-meta img{display:block;margin-top:6px;}</style>';
        });

        add_filter( 'woocommerce_get_item_data', [ $this, 'display_item_meta' ], 10, 2 );
        add_filter( 'woocommerce_cart_item_thumbnail', [ $this, 'cart_item_thumbnail' ], 999, 3 );
        add_filter('woocommerce_get_cart_item_from_session', [$this, 'restore_from_session'], 10, 3);
        add_filter('woocommerce_cart_item_name', [$this, 'prepend_thumb_to_name'], 10, 3);

        // Ensure custom price is applied reliably
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'apply_custom_prices' ], 20 );

        // Persist meta to order items
        add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'persist_order_item_meta' ], 10, 3 );

        // Shortcode for embedding the iframe
        add_shortcode( 'simplio3d_configurator', [ $this, 'shortcode_configurator' ] );

        add_filter( 'woocommerce_order_item_name', function( $name, $item ) {
            error_log('Blocks cart item has thumbnail: ' . $item);
            $thumb = $item->get_meta( '_simplio3d_thumbnail', true );
            if ( ! $thumb ) return $name;
            return '<img src="'.esc_url($thumb).'" alt="" style="max-width:60px;height:auto;margin-right:8px;vertical-align:middle;" />' . $name;
        }, 10, 2);

        add_filter( 'woocommerce_store_api_cart_item', function( $response, $cart_item ) {
            // Only affect items coming from Simplio3D
            if ( empty( $cart_item['simplio3d_thumbnail'] ) ) {
                return $response; // regular Woo products stay as-is
            }

            if ( ! empty( $cart_item['simplio3d_thumbnail'] ) ) {
                error_log('Blocks cart item has thumbnail: ' . $cart_item['simplio3d_thumbnail']);
            }

            $url = $cart_item['simplio3d_thumbnail'];

            if ( isset( $response['images'][0] ) ) {
                $response['images'][0]['src']       = $url;
                $response['images'][0]['thumbnail'] = $url;
                $response['images'][0]['srcset']    = '';
                $response['images'][0]['sizes']     = '';
            } else {
                $response['images'] = [[
                    'id'        => 0,
                    'src'       => $url,
                    'thumbnail' => $url,
                    'srcset'    => '',
                    'sizes'     => '',
                    'name'      => 'Snapshot',
                    'alt'       => '',
                ]];
            }

            return $response;
        }, 10, 2 );

    }

    public function enqueue_scripts() {
        if ( is_admin() || ! class_exists( 'WooCommerce' ) ) { return; }

        wp_register_script(
            'simplio3d-woo',
            plugins_url( 'assets/js/simplio3d-woo.js', __FILE__ ),
            [ 'jquery' ],
            self::VERSION,
            true
        );

        $data = [
            'ajax_url'         => admin_url( 'admin-ajax.php' ),
            self::NONCE_NAME   => wp_create_nonce( self::NONCE_ACTION ),
            // Restrict the iframe origins that are allowed to message the shop
            'allowed_origins'  => apply_filters( 'simplio3d_allowed_origins', [] ),
        ];
        wp_localize_script( 'simplio3d-woo', 'Simplio3DWoo', $data );
        wp_enqueue_script( 'simplio3d-woo' );
    }

    public function shortcode_configurator( $atts ) {
        $atts = shortcode_atts( [
            'url'        => '',
            'product_id' => '',
            'height'     => '700',
            'width'      => '100%',
        ], $atts, 'simplio3d_configurator' );

        if ( empty( $atts['url'] ) ) {
            return '<div style="color:#b00">Simplio3D: Please provide the iframe URL via url="...".</div>';
        }

        $url = esc_url( $atts['url'] );
        $pid = esc_attr( $atts['product_id'] );
        $h   = esc_attr( $atts['height'] );
        $w   = esc_attr( $atts['width'] );

        ob_start();
        ?>
        <div class="simplio3d-wrapper" data-product-id="<?php echo $pid; ?>">
            <iframe class="simplio3d-iframe" src="<?php echo $url; ?>"
                    style="width:<?php echo $w; ?>;height:<?php echo $h; ?>;border:0;"
                    allow="clipboard-write; fullscreen"></iframe>
        </div>
        <?php
        return ob_get_clean();
    }

    public function ajax_add_to_cart() {
        if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
            wp_send_json_error( [ 'message' => 'WooCommerce not available' ], 400 );
        }

        check_ajax_referer( self::NONCE_ACTION, self::NONCE_NAME );

        $product_id  = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $quantity    = isset($_POST['quantity']) ? max(1, absint($_POST['quantity'])) : 1;
        $description = isset($_POST['description']) ? wp_kses_post( wp_unslash($_POST['description']) ) : '';
        $config_id   = isset($_POST['config_id']) ? sanitize_text_field( wp_unslash($_POST['config_id']) ) : '';
        $price_raw   = isset($_POST['price']) ? wc_format_decimal( wp_unslash($_POST['price']) ) : '';
        $orderurl = isset($_POST['orderurl']) ? wp_kses_post( wp_unslash($_POST['orderurl']) ) : '';

        // Thumbnail can be a URL or a data URL. If data URL, store to uploads and use URL.
        $thumbnail = '';
        if ( isset($_POST['thumbnail']) ) {
            $raw = wp_unslash($_POST['thumbnail']);
            if ( strpos($raw, 'data:image/') === 0 ) {
                $thumbnail = $this->save_data_url_image( $raw );
            } else {
                $thumbnail = esc_url_raw( $raw );
            }
        }

        $print_maps = [];

        if (isset($_POST['printmaps'])) {
            $raw = wp_unslash($_POST['printmaps']);

            if (is_array($raw)) {
                // Already an array (e.g., form fields printmaps[]=...&printmaps[]=...)
                $arr = $raw;

            } else {
                // It's a string; normalize
                $str = trim((string) $raw);

                if ($str === '' || $str === '[]') {
                    $arr = [];
                } else {
                    // Try JSON first
                    $decoded = json_decode($str, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $arr = $decoded;
                    } else {
                        // Fallback: comma-separated
                        $arr = array_map('trim', explode(',', $str));
                    }
                }
            }

            // Sanitize URLs & drop empties
            $print_maps = array_values(
                array_filter(
                    array_map('esc_url_raw', (array) $arr)
                )
            );
        }

        $snap_shots = [];

        if (isset($_POST['snapshots'])) {
            $raw = wp_unslash($_POST['snapshots']);

            if (is_array($raw)) {
                // Already an array (e.g., form fields snapshots[]=...&snapshots[]=...)
                $arr = $raw;

            } else {
                // It's a string; normalize
                $str = trim((string) $raw);

                if ($str === '' || $str === '[]') {
                    $arr = [];
                } else {
                    // Try JSON first
                    $decoded = json_decode($str, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $arr = $decoded;
                    } else {
                        // Fallback: comma-separated
                        $arr = array_map('trim', explode(',', $str));
                    }
                }
            }

            // Sanitize URLs & drop empties
            $snap_shots = array_values(
                array_filter(
                    array_map('esc_url_raw', (array) $arr)
                )
            );
        }



        if ( ! $product_id ) {
            wp_send_json_error( [ 'message' => 'Missing product_id' ], 422 );
        }

        $cart_item_data = [
            'simplio3d_description' => $description,
            'simplio3d_thumbnail'   => $thumbnail,
            'simplio3d_config_id'   => $config_id,
            'simplio3d_orderurl'   => $orderurl,
            'simplio3d_print_maps'  => $print_maps,
            'simplio3d_snap_shots'  => $snap_shots,
            // Prevent merging different designs of the same product
            'simplio3d_unique_key'  => md5( $description . '|' . $thumbnail . '|' . microtime(true) ),
        ];

        if ( $price_raw !== '' ) {
            $cart_item_data['simplio3d_price'] = (float) $price_raw;
        }

        $added = WC()->cart->add_to_cart( $product_id, $quantity, 0, [], $cart_item_data );

        if ( ! $added ) {
            wp_send_json_error( [ 'message' => 'Failed to add to cart' ], 500 );
        }

        // Keep totals/cart hash fresh
        WC()->cart->calculate_totals();

        wp_send_json_success( [
            'cart_hash'  => WC()->cart->get_cart_hash(),
            'cart_count' => WC()->cart->get_cart_contents_count(),
        ] );
    }

    public function print_thumb_after_name( $cart_item, $cart_item_key ) {
        if ( empty( $cart_item['simplio3d_thumbnail'] ) ) {
            return;
        }
        $src = $cart_item['simplio3d_thumbnail'];

        // Render a small snapshot under the product title.
        if ( strpos($src, 'data:image/') === 0 ) {
            echo '<div class="simplio3d-thumb" style="margin-top:6px;"><img src="' . $src . '" alt="" style="max-width:80px;height:auto;" /></div>';
        } else {
            echo '<div class="simplio3d-thumb" style="margin-top:6px;"><img src="' . esc_url($src) . '" alt="" style="max-width:80px;height:auto;" /></div>';
        }
    }


    public function apply_custom_prices( $cart ) {
        if ( is_admin() && ! defined('DOING_AJAX') ) { return; }
        if ( empty( $cart ) || ! isset( $cart->cart_contents ) ) { return; }

        foreach ( $cart->get_cart() as $item ) {
            if ( isset( $item['simplio3d_price'] ) && is_numeric( $item['simplio3d_price'] ) ) {
                $item['data']->set_price( (float) $item['simplio3d_price'] );
            }
        }
    }

    public function display_item_meta( $item_data, $cart_item ) {
        $desc = isset($cart_item['simplio3d_description']) ? trim((string) $cart_item['simplio3d_description']) : '';

        if ($desc !== '') {
            $desc = preg_replace('/\s*•\s*/', ' • ', $desc);     // normalize bullets
            $parts = array_filter(array_map('trim', explode('•', $desc))); // split by bullet

            $html  = '<ul class="simplio3d-customization" style="margin:6px 0 0;padding-left:1.1em;">';
            foreach ($parts as $p) {
                $html .= '<li>' . esc_html($p) . '</li>';
            }
            $html .= '</ul>';

            $plain = implode("\n", $parts);

            // Some themes use 'value', others use 'display'. We provide both.
            $item_data[] = [
                'name'    => __( 'Customization', 'simplio3d' ),
                'value'   => $plain,         // Blocks cart will show this (no HTML)
                'display' => $html,          // Classic cart/checkout will show this
            ];
        }

        // if ( ! empty( $cart_item['simplio3d_thumbnail'] ) ) {
        //     $src = $cart_item['simplio3d_thumbnail'];

        //     // Build safe HTML (allow data: or normal URL)
        //     $img_html = ( strpos( $src, 'data:image/' ) === 0 )
        //         ? '<img src="' . $src . '" alt="" style="max-width:120px;height:auto;border:1px solid #eee;border-radius:4px;" />'
        //         : '<img src="' . esc_url( $src ) . '" alt="" style="max-width:120px;height:auto;border:1px solid #eee;border-radius:4px;" />';

        //     // Some themes only use 'value', some use 'display' — cover both
        //     $item_data[] = [
        //         'name'    => __( 'Snapshot', 'simplio3d' ),
        //         'key'     => __( 'Snapshot', 'simplio3d' ),   // legacy compatibility
        //         'value'   => $src,                               // avoid printing the raw URL
        //         'display' => wp_kses_post( $img_html ),        // render the image
        //     ];
        // }
        
        /*
        if ( ! empty( $cart_item['simplio3d_config_id'] ) ) {
            $item_data[] = [
                'name'  => __( 'Config ID', 'simplio3d' ),
                'value' => esc_html( $cart_item['simplio3d_config_id'] ),
            ];
        }*/
        return $item_data;
    }

    public function cart_item_thumbnail( $thumbnail, $cart_item, $cart_item_key ) {
        // if ( ! empty( $cart_item['simplio3d_thumbnail'] ) ) {
        //     return '<img src="' . esc_url( $cart_item['simplio3d_thumbnail'] ) . '" alt="" style="max-width:60px;height:auto;" />';
        // }
        // return $thumbnail;

        if ( empty( $cart_item['simplio3d_thumbnail'] ) ) {
            return $thumbnail;
        }

        $src = $cart_item['simplio3d_thumbnail'];

        // If it's a data URL, echo it directly (esc_url would strip data:)
        if ( strpos( $src, 'data:image/' ) === 0 ) {
            return '<img src="' . $src . '" alt="" style="max-width:60px;height:auto;" />';
        }

        // Normal URL path
        return '<img src="' . esc_url( $src ) . '" alt="" style="max-width:60px;height:auto;" />';
    }

    public function persist_order_item_meta( $item, $cart_item_key, $values ) {
        if ( ! empty( $values['simplio3d_description'] ) ) {
            $item->add_meta_data( 'Customization', wp_kses_post( $values['simplio3d_description'] ), true );
        }
        if ( ! empty( $values['simplio3d_config_id'] ) ) {
            $item->add_meta_data( 'Config ID', sanitize_text_field( $values['simplio3d_config_id'] ), true );
        }
        if ( ! empty( $values['simplio3d_orderurl'] ) ) {
            $item->add_meta_data( 'Simplio3D order link', sanitize_text_field( $values['simplio3d_orderurl'] ), true );
        }
        if ( ! empty( $values['simplio3d_thumbnail'] ) ) {
            // $item->add_meta_data( 'Snapshot URL', esc_url_raw( $values['simplio3d_thumbnail'] ), true );
            $item->add_meta_data( '_simplio3d_thumbnail', esc_url_raw( $values['simplio3d_thumbnail'] ), true );
        }
        if ( ! empty( $values['simplio3d_print_maps'] ) ) {
            // Store as JSON on the order line item
            $item->add_meta_data(
                'Print Map URLs',
                wp_json_encode( array_values( (array) $values['simplio3d_print_maps'] ) ),
                true
            );
        }

        if ( ! empty( $values['simplio3d_snap_shots'] ) ) {
            // Store as JSON on the order line item
            $item->add_meta_data(
                'Print Snap Shots 360 Views',
                wp_json_encode( array_values( (array) $values['simplio3d_snap_shots'] ) ),
                true
            );
        }
    }

    private function save_data_url_image( $data_url ) {
        if ( strpos( $data_url, 'data:image/' ) !== 0 ) { return ''; }
        $parts = explode( ',', $data_url, 2 );
        if ( count( $parts ) !== 2 ) { return ''; }
        $meta = $parts[0];
        $b64  = $parts[1];

        if ( strpos( $meta, ';base64' ) === false ) { return ''; }

        if ( ! preg_match( '#data:image/([a-zA-Z0-9]+)#', $meta, $m ) ) {
            return '';
        }
        $ext = strtolower( $m[1] );
        $bin = base64_decode( $b64 );
        if ( ! $bin ) { return ''; }

        if ( ! function_exists( 'wp_upload_bits' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $filename = 'simplio3d-' . wp_generate_uuid4() . '.' . preg_replace( '/[^a-z0-9]+/i', '', $ext );
        $upload   = wp_upload_bits( $filename, null, $bin );

        if ( ! empty( $upload['error'] ) ) {
            return '';
        }
        return $upload['url'];
    }

    public function restore_from_session( $cart_item, $values, $cart_item_key ) {

        // echo "<pre>";
        // error_log('Blocks cart item has thumbnail: ' . print_r($cart_item));

        // Carry over our custom fields
        foreach (['simplio3d_thumbnail','simplio3d_description','simplio3d_config_id','simplio3d_orderurl','simplio3d_price','simplio3d_print_maps', 'simplio3d_snap_shots'] as $k) {
            if ( isset($values[$k]) ) {
                $cart_item[$k] = $values[$k];
            }
        }

        // Re-apply custom price if present
        if ( isset($cart_item['simplio3d_price']) && is_numeric($cart_item['simplio3d_price']) ) {
            $cart_item['data']->set_price( (float) $cart_item['simplio3d_price'] );
        }

        return $cart_item;
    }

    public function prepend_thumb_to_name( $name, $cart_item, $cart_item_key ) {
        if ( empty($cart_item['simplio3d_thumbnail']) ) return $name;
        $src = $cart_item['simplio3d_thumbnail'];
        $img = (strpos($src,'data:image/')===0)
            ? '<img src="'.$src.'" alt="" style="max-width:60px;height:auto;margin-right:8px;vertical-align:middle;" />'
            : '<img src="'.esc_url($src).'" alt="" style="max-width:60px;height:auto;margin-right:8px;vertical-align:middle;" />';
        return $img . $name;
    }
}

new Simplio3D_Woo_Integration_Enhanced();
