<?php
/**
 * WCPU_Shipping_Method — Método de envío por Ubigeo Perú.
 *
 * El Block Checkout guarda los campos adicionales en:
 *   WC()->customer->get_billing('wcpu/provincia') → nombre de provincia
 *   WC()->customer->get_billing('wcpu/distrito')  → nombre de distrito
 *
 * Para buscar la tarifa necesitamos los CÓDIGOS, no los nombres.
 * Los códigos se guardan en la sesión vía woocommerce_store_api_cart_update_order_from_request
 * o los buscamos en la BD por nombre.
 */
defined( 'ABSPATH' ) || exit;

class WCPU_Shipping_Method extends WC_Shipping_Method {

    public function __construct( $instance_id = 0 ) {
        $this->id                 = 'wcpu_ubigeo';
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = __( 'Envío por Ubigeo Perú', 'wc-peru-ubigeo' );
        $this->method_description = __( 'Calcula el costo de envío según Departamento, Provincia y Distrito.', 'wc-peru-ubigeo' );
        $this->supports           = array( 'shipping-zones', 'instance-settings' );
        $this->enabled            = 'yes';
        $this->init();
        $this->title = $this->get_option( 'title', __( 'Envío a domicilio', 'wc-peru-ubigeo' ) );
    }

    public function init() {
        $this->init_form_fields();
        $this->init_settings();
        add_action(
            'woocommerce_update_options_shipping_' . $this->id,
            array( $this, 'process_admin_options' )
        );
    }

    public function init_form_fields() {
        $this->instance_form_fields = array(
            'title' => array(
                'title'    => __( 'Título', 'wc-peru-ubigeo' ),
                'type'     => 'text',
                'default'  => __( 'Envío a domicilio', 'wc-peru-ubigeo' ),
                'desc_tip' => __( 'Nombre que verá el cliente en el checkout.', 'wc-peru-ubigeo' ),
            ),
            'fallback_cost' => array(
                'title'             => __( 'Costo por defecto (S/)', 'wc-peru-ubigeo' ),
                'type'              => 'number',
                'default'           => '15',
                'desc_tip'          => __( 'Se aplica si no hay tarifa configurada. 0 = no mostrar el método.', 'wc-peru-ubigeo' ),
                'custom_attributes' => array( 'min' => '0', 'step' => '0.01' ),
            ),
            'show_rate_label' => array(
                'title'   => __( 'Mostrar zona en el label', 'wc-peru-ubigeo' ),
                'type'    => 'checkbox',
                'label'   => __( 'Sí (ej: "Envío a domicilio — Lima")', 'wc-peru-ubigeo' ),
                'default' => 'yes',
            ),
        );
    }

    public function calculate_shipping( $package = array() ) {
        $destination = isset( $package['destination'] ) ? $package['destination'] : array();
        $country     = isset( $destination['country'] ) ? $destination['country'] : '';

        if ( 'PE' !== $country ) {
            return;
        }

        /* Departamento: viene del state nativo de WC */
        $dep_code = isset( $destination['state'] ) ? $destination['state'] : '';

        /* ═══════════════════════════════════════════════════════════════════════════════
         * FIX CRÍTICO: Intentar múltiples fuentes para obtener códigos de ubigeo
         * Esto es crucial para visitantes que pueden no tener sesión inicializada
         * ═══════════════════════════════════════════════════════════════════════════════ */
        $prov_code = '';
        $dist_code = '';

        /* 1️⃣ FUENTE PRIORITARIA: Sesión (para usuarios logueados y visitantes con sesión inicializada) */
        if ( WC()->session && WC()->session->has_session() ) {
            $prov_code = (string) WC()->session->get( 'wcpu_prov_code', '' );
            $dist_code = (string) WC()->session->get( 'wcpu_dist_code', '' );
        }

        /* 2️⃣ FALLBACK: Buscar en customer meta (para visitantes que aún no tienen sesión) */
        if ( ! $prov_code && WC()->customer ) {
            $prov_name = (string) WC()->customer->get_billing( 'wcpu/provincia' );
            $dist_name = (string) WC()->customer->get_billing( 'wcpu/distrito' );

            if ( $prov_name && $dep_code ) {
                $prov_code = self::find_prov_code_by_name( $dep_code, $prov_name );
            }
            if ( ! $dist_code && $dist_name && $prov_code ) {
                $dist_code = self::find_dist_code_by_name( $prov_code, $dist_name );
            }
        }

        /* 3️⃣ TERCERA OPCIÓN: Desde el package destination (inyectado vía filtro) */
        if ( ! $prov_code && isset( $destination['wcpu_prov_code'] ) ) {
            $prov_code = $destination['wcpu_prov_code'];
            $dist_code = isset( $destination['wcpu_dist_code'] ) ? $destination['wcpu_dist_code'] : '';
        }

        /* 🔍 Buscar tarifa en la BD */
        $rate = $this->find_rate( $dep_code, $prov_code, $dist_code );

        if ( null === $rate ) {
            $fallback = (float) $this->get_option( 'fallback_cost', 15 );
            if ( $fallback <= 0 ) return;
            $rate = array( 'label' => '', 'cost' => $fallback, 'free_above' => 0 );
        }

        $cart_total = WC()->cart ? WC()->cart->get_displayed_subtotal() : 0;
        $free_above = isset( $rate['free_above'] ) ? (float) $rate['free_above'] : 0;
        $cost       = ( $free_above > 0 && $cart_total >= $free_above ) ? 0 : (float) $rate['cost'];

        $title = $this->get_option( 'title', __( 'Envío a domicilio', 'wc-peru-ubigeo' ) );
        $label = $title;
        if ( 'yes' === $this->get_option( 'show_rate_label', 'yes' ) && ! empty( $rate['label'] ) ) {
            $label .= ' — ' . $rate['label'];
        }
        if ( 0.0 === $cost ) {
            $label .= ' (' . __( 'GRATIS', 'wc-peru-ubigeo' ) . ')';
        }

        $this->add_rate( array(
            'id'    => $this->get_rate_id(),
            'label' => $label,
            'cost'  => $cost,
        ) );
    }

    /* Buscar código de provincia por nombre */
    private static function find_prov_code_by_name( string $dep_code, string $prov_name ): string {
        global $wpdb;
        $code = $wpdb->get_var( $wpdb->prepare(
            "SELECT code FROM {$wpdb->prefix}wcpu_provincias
             WHERE dep_code = %s AND nombre = %s LIMIT 1",
            strtoupper( $dep_code ), $prov_name
        ) );
        return (string) $code;
    }

    /* Buscar código de distrito por nombre */
    private static function find_dist_code_by_name( string $prov_code, string $dist_name ): string {
        global $wpdb;
        $code = $wpdb->get_var( $wpdb->prepare(
            "SELECT code FROM {$wpdb->prefix}wcpu_distritos
             WHERE prov_code = %s AND nombre = %s LIMIT 1",
            $prov_code, $dist_name
        ) );
        return (string) $code;
    }

    private function find_rate( string $dep, string $prov, string $dist ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'wcpu_shipping_rates';

        $candidates = array();

        /* 1. Tarifa exacta por distrito */
        if ( $dep && $prov && $dist ) {
            $candidates[] = $wpdb->prepare(
                "SELECT * FROM {$table} WHERE dep_code=%s AND prov_code=%s AND dist_code=%s AND grupo_id IS NULL AND enabled=1 LIMIT 1",
                $dep, $prov, $dist
            );
        }

        /* 2. Tarifa por grupo (¿el distrito pertenece a un grupo con tarifa?) */
        if ( $dist && class_exists( 'WCPU_Grupos_Admin' ) ) {
            $grupo_id = WCPU_Grupos_Admin::get_grupo_for_dist( $dist );
            if ( $grupo_id ) {
                $candidates[] = $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE grupo_id=%d AND enabled=1 LIMIT 1",
                    $grupo_id
                );
            }
        }

        /* 3. Tarifa por provincia */
        if ( $dep && $prov ) {
            $candidates[] = $wpdb->prepare(
                "SELECT * FROM {$table} WHERE dep_code=%s AND prov_code=%s AND dist_code='' AND grupo_id IS NULL AND enabled=1 LIMIT 1",
                $dep, $prov
            );
        }

        /* 4. Tarifa por departamento */
        if ( $dep ) {
            $candidates[] = $wpdb->prepare(
                "SELECT * FROM {$table} WHERE dep_code=%s AND prov_code='' AND dist_code='' AND grupo_id IS NULL AND enabled=1 LIMIT 1",
                $dep
            );
        }

        /* 5. Catch-all nacional */
        $candidates[] = "SELECT * FROM {$table} WHERE dep_code='*' AND enabled=1 LIMIT 1";

        foreach ( $candidates as $sql ) {
            $row = $wpdb->get_row( $sql, ARRAY_A );
            if ( $row ) return $row;
        }
        return null;
    }
}
