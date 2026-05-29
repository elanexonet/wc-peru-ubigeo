<?php
/**
 * WCPU_ApisPeru — Integración con apisperu.com
 *
 * Endpoints usados:
 *  - RUC: https://dniruc.apisperu.com/api/v1/ruc/{ruc}?token={token}
 *  - DNI: https://dniruc.apisperu.com/api/v1/dni/{dni}?token={token}
 *
 * La API key se configura en WooCommerce > Ajustes > (tab) Peru Ubigeo.
 */
defined( 'ABSPATH' ) || exit;

class WCPU_ApisPeru {

    const OPTION_KEY = 'wcpu_apisperu_token';
    const RUC_URL    = 'https://dniruc.apisperu.com/api/v1/ruc/%s?token=%s';
    const DNI_URL    = 'https://dniruc.apisperu.com/api/v1/dni/%s?token=%s';
    const CACHE_TTL  = DAY_IN_SECONDS * 7;   // cache 7 días para evitar hits innecesarios

    public static function get_token(): string {
        return (string) get_option( self::OPTION_KEY, '' );
    }

    /* ──────────────────────────────────────────────────────────────
     *  Consultar RUC
     * ────────────────────────────────────────────────────────────── */
    public static function get_ruc( string $ruc ): array {
        if ( ! preg_match( '/^\d{11}$/', $ruc ) ) {
            return [ 'success' => false, 'message' => 'RUC debe tener 11 dígitos.' ];
        }

        $cache_key = 'wcpu_ruc_' . $ruc;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $token = self::get_token();
        if ( ! $token ) {
            return [ 'success' => false, 'message' => 'Token de ApisPeru no configurado.' ];
        }

        $url      = sprintf( self::RUC_URL, $ruc, $token );
        $response = wp_remote_get( $url, [ 'timeout' => 10 ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body ) || isset( $body['message'] ) ) {
            return [ 'success' => false, 'message' => $body['message'] ?? 'Error en la consulta.' ];
        }

        $result = [
            'success'         => true,
            'ruc'             => $body['ruc']                     ?? $ruc,
            'razon_social'    => $body['razonSocial']             ?? '',
            'estado'          => $body['estadoContribuyente']     ?? '',
            'condicion'       => $body['condicionContribuyente']  ?? '',
            'direccion'       => $body['direccion']               ?? '',
            'ubigeo'          => $body['ubigeo']                  ?? '',
            'departamento'    => $body['departamento']            ?? '',
            'provincia'       => $body['provincia']               ?? '',
            'distrito'        => $body['distrito']                ?? '',
            'tipo_contribuyente' => $body['tipoContribuyente']    ?? '',
        ];

        set_transient( $cache_key, $result, self::CACHE_TTL );
        return $result;
    }

    /* ──────────────────────────────────────────────────────────────
     *  Consultar DNI
     * ────────────────────────────────────────────────────────────── */
    public static function get_dni( string $dni ): array {
        if ( ! preg_match( '/^\d{8}$/', $dni ) ) {
            return [ 'success' => false, 'message' => 'DNI debe tener 8 dígitos.' ];
        }

        $cache_key = 'wcpu_dni_' . $dni;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $token = self::get_token();
        if ( ! $token ) {
            return [ 'success' => false, 'message' => 'Token de ApisPeru no configurado.' ];
        }

        $url      = sprintf( self::DNI_URL, $dni, $token );
        $response = wp_remote_get( $url, [ 'timeout' => 10 ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body ) || isset( $body['message'] ) ) {
            return [ 'success' => false, 'message' => $body['message'] ?? 'DNI no encontrado.' ];
        }

        $nombre_completo = trim(
            ( $body['apellidoPaterno'] ?? '' ) . ' ' .
            ( $body['apellidoMaterno'] ?? '' ) . ', ' .
            ( $body['nombres'] ?? '' )
        );

        $result = [
            'success'          => true,
            'dni'              => $body['dni']             ?? $dni,
            'nombres'          => $body['nombres']         ?? '',
            'apellido_paterno' => $body['apellidoPaterno'] ?? '',
            'apellido_materno' => $body['apellidoMaterno'] ?? '',
            'nombre_completo'  => $nombre_completo,
        ];

        set_transient( $cache_key, $result, self::CACHE_TTL );
        return $result;
    }
}
