<?php
/**
 * WCPU_Ajax — Endpoints AJAX del plugin.
 * Usa WCPU_Data que lee directamente desde la BD por dep_code/prov_code.
 */
defined( 'ABSPATH' ) || exit;

class WCPU_Ajax {

    public static function init() {
        $actions = array(
            'wcpu_get_provincias',
            'wcpu_get_distritos',
            'wcpu_consultar_ruc',
            'wcpu_consultar_dni',
            'wcpu_bulk_import',
        );
        foreach ( $actions as $a ) {
            add_action( 'wp_ajax_' . $a,        array( __CLASS__, $a ) );
            add_action( 'wp_ajax_nopriv_' . $a, array( __CLASS__, $a ) );
        }
    }

    /* ── Provincias: usa WCPU_Data que ya tiene la query correcta ── */
    public static function wcpu_get_provincias() {
        /* Verificación manual — check_ajax_referer falla para visitantes
         * si WC no inicializó su sesión aún */
        $nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
        if ( ! wp_verify_nonce( $nonce, 'wcpu_nonce' ) ) {
            wp_send_json_error( 'Nonce inválido' );
        }
        $dep_code = strtoupper( sanitize_text_field( wp_unslash( $_POST['dep_code'] ?? '' ) ) );
        $out = WCPU_Data::get_provincias( $dep_code );
        wp_send_json_success( $out );
    }

    /* ── Distritos: usa WCPU_Data ── */
    public static function wcpu_get_distritos() {
        $nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
        if ( ! wp_verify_nonce( $nonce, 'wcpu_nonce' ) ) {
            wp_send_json_error( 'Nonce inválido' );
        }
        $prov_code = sanitize_text_field( wp_unslash( $_POST['prov_code'] ?? '' ) );
        $out = WCPU_Data::get_distritos( $prov_code );
        wp_send_json_success( $out );
    }

    /* ── Consultar RUC ── */
    public static function wcpu_consultar_ruc() {
        $nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
        if ( ! wp_verify_nonce( $nonce, 'wcpu_nonce' ) ) { wp_send_json_error( 'Nonce inválido' ); }
        $ruc    = sanitize_text_field( wp_unslash( $_POST['ruc'] ?? '' ) );
        $result = WCPU_ApisPeru::get_ruc( $ruc );
        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }

    /* ── Consultar DNI ── */
    public static function wcpu_consultar_dni() {
        $nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
        if ( ! wp_verify_nonce( $nonce, 'wcpu_nonce' ) ) { wp_send_json_error( 'Nonce inválido' ); }
        $dni    = sanitize_text_field( wp_unslash( $_POST['dni'] ?? '' ) );
        $result = WCPU_ApisPeru::get_dni( $dni );
        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }

    /* ── Importación masiva de tarifas ── */
    public static function wcpu_bulk_import() {
        check_ajax_referer( 'wcpu_bulk_import', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Sin permisos.' );
        }
        $raw   = stripslashes( $_POST['rates'] ?? '' );
        $items = json_decode( $raw, true );
        if ( ! is_array( $items ) ) {
            wp_send_json_error( 'JSON inválido.' );
        }
        global $wpdb;
        $table    = $wpdb->prefix . 'wcpu_shipping_rates';
        $imported = 0;
        foreach ( $items as $item ) {
            $wpdb->insert( $table, array(
                'dep_code'   => sanitize_text_field( $item['dep_code']   ?? '' ),
                'prov_code'  => sanitize_text_field( $item['prov_code']  ?? '' ),
                'dist_code'  => sanitize_text_field( $item['dist_code']  ?? '' ),
                'label'      => sanitize_text_field( $item['label']      ?? '' ),
                'cost'       => (float) ( $item['cost']       ?? 0 ),
                'free_above' => (float) ( $item['free_above'] ?? 0 ),
                'notes'      => sanitize_text_field( $item['notes']      ?? '' ),
                'enabled'    => 1,
            ) );
            $imported++;
        }
        wp_send_json_success( array( 'imported' => $imported ) );
    }
}
