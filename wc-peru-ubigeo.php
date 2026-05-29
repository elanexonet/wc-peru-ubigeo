<?php
/**
 * Plugin Name:       WC Peru Ubigeo
 * Plugin URI:        https://elanexo.digital
 * Description:       Ubigeo peruano completo para WooCommerce: Depto→Prov→Dist gestionable desde el admin, Boleta/Factura con SUNAT/RENIEC (ApisPeru), tarifas de envío por ubigeo. Compatible con Bloques de WordPress.
 * Version:           4.0.1
 * Author:            Greg / El Anexo Digital
 * License:           GPL-2.0+
 * Text Domain:       wc-peru-ubigeo
 * Requires PHP:      7.4
 * WC requires at least: 7.6
 * WC tested up to:   9.9
 */
defined( 'ABSPATH' ) || exit;

define( 'WCPU_VERSION', '4.0.1' );
define( 'WCPU_DIR',     plugin_dir_path( __FILE__ ) );
define( 'WCPU_URL',     plugin_dir_url( __FILE__ ) );
define( 'WCPU_FILE',    __FILE__ );

add_action( 'before_woocommerce_init', function () {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables',  WCPU_FILE, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WCPU_FILE, true );
    }
} );

register_activation_hook( WCPU_FILE, function () {
    require_once WCPU_DIR . 'includes/class-wcpu-install.php';
    require_once WCPU_DIR . 'includes/class-wcpu-grupos-install.php';
    WCPU_Install::run();
    WCPU_Grupos_Install::run();
} );

add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>WC Peru Ubigeo requiere WooCommerce activo.</p></div>';
        } );
        return;
    }

    require_once WCPU_DIR . 'includes/class-wcpu-install.php';
    require_once WCPU_DIR . 'includes/class-wcpu-data.php';
    require_once WCPU_DIR . 'includes/class-wcpu-apisperu.php';
    require_once WCPU_DIR . 'includes/class-wcpu-ajax.php';
    require_once WCPU_DIR . 'includes/class-wcpu-shipping.php';
    require_once WCPU_DIR . 'includes/class-wcpu-grupos-install.php';
    require_once WCPU_DIR . 'includes/class-wcpu-grupos-admin.php';
    require_once WCPU_DIR . 'includes/class-wcpu-checkout-fields.php';
    require_once WCPU_DIR . 'includes/class-wcpu-ubigeo-admin.php';
    require_once WCPU_DIR . 'includes/class-wcpu-admin.php';

    WCPU_Ajax::init();
    WCPU_Checkout_Fields::init();
    WCPU_Ubigeo_Admin::init();
    WCPU_Admin::init();
    WCPU_Grupos_Admin::init();

    add_action( 'woocommerce_shipping_init', function () {
        require_once WCPU_DIR . 'includes/class-wcpu-shipping-method.php';
    } );
    add_filter( 'woocommerce_shipping_methods', function ( $methods ) {
        $methods['wcpu_ubigeo'] = 'WCPU_Shipping_Method';
        return $methods;
    } );

    /* Inyectar códigos de provincia/distrito en el paquete de envío */
    add_filter( 'woocommerce_cart_shipping_packages', function ( $packages ) {
        if ( ! WC()->session ) return $packages;
        $prov_code = (string) WC()->session->get( 'wcpu_prov_code', '' );
        $dist_code = (string) WC()->session->get( 'wcpu_dist_code', '' );
        if ( ! $prov_code ) return $packages;
        foreach ( $packages as &$pkg ) {
            $pkg['destination']['wcpu_prov_code'] = $prov_code;
            $pkg['destination']['wcpu_dist_code'] = $dist_code;
        }
        return $packages;
    } );

    if ( WCPU_Install::needs_update() )  WCPU_Install::run();
    if ( WCPU_Grupos_Install::needs_update() ) WCPU_Grupos_Install::run();
}, 20 );


/**
 * Fix Culqi v4.0.0 — "WooCommerce Blocks is not initialized or wc/store is not available"
 *
 * El plugin de Culqi v4.0.0 tiene un bug: su gateway.js ejecuta en línea 113:
 *   const store = window.wc?.data?.select('wc/store')
 * antes de que WC Blocks haya registrado ese store.
 *
 * Solución: inyectar un script inline ANTES del de Culqi que garantiza que
 * window.wc.data existe y tiene un select() seguro.
 * Cuando WC Blocks cargue después, sobreescribe el stub con la versión real.
 */
add_action( 'wp_head', function () {
    if ( ! is_checkout() ) return;
    ?>
    <script id="wcpu-culqi-compat">
    /* Garantizar que window.wc.data existe antes de que Culqi lo use */
    (function() {
        window.wc = window.wc || {};
        /* Si wc.data ya existe (WC Blocks cargó primero), no hacer nada */
        if (window.wc.data && typeof window.wc.data.select === 'function') return;
        /* Stub seguro: select() devuelve null en lugar de lanzar error */
        window.wc.data = window.wc.data || {
            select: function(store) {
                /* Cuando el store real esté disponible, usarlo */
                if (window._wc_data_real && typeof window._wc_data_real.select === 'function') {
                    return window._wc_data_real.select(store);
                }
                return null;
            },
            dispatch: function(store) {
                if (window._wc_data_real && typeof window._wc_data_real.dispatch === 'function') {
                    return window._wc_data_real.dispatch(store);
                }
                return {};
            },
            subscribe: function(fn) {
                if (window._wc_data_real && typeof window._wc_data_real.subscribe === 'function') {
                    return window._wc_data_real.subscribe(fn);
                }
                return function(){};
            }
        };
        /* Cuando wp.data esté listo, guardar referencia real y parchear wc.data */
        var patchInterval = setInterval(function() {
            if (window.wp && window.wp.data && typeof window.wp.data.select === 'function') {
                clearInterval(patchInterval);
                window._wc_data_real = window.wp.data;
                /* Solo reemplazar si seguimos usando el stub */
                if (!window.wc.data._isReal) {
                    window.wc.data = window.wp.data;
                }
            }
        }, 50);
        setTimeout(function(){ clearInterval(patchInterval); }, 10000);
    })();
    </script>
    <?php
}, 1 ); /* Prioridad 1 = antes de cualquier otro script en el <head> */
