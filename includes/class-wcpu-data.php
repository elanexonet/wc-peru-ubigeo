<?php
/**
 * WCPU_Data — Lee los datos de ubigeo desde la base de datos.
 * Los datos se pre-cargan al activar el plugin y se gestionan desde el admin.
 */
defined( 'ABSPATH' ) || exit;

class WCPU_Data {

    public static function get_departamentos(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT code, nombre FROM {$wpdb->prefix}wcpu_departamentos ORDER BY orden, nombre",
            ARRAY_A
        );
        $out = array();
        foreach ( $rows as $r ) {
            $out[ $r['code'] ] = $r['nombre'];
        }
        return $out;
    }

    public static function get_provincias( string $dep_code ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT code, nombre FROM {$wpdb->prefix}wcpu_provincias
                 WHERE dep_code = %s ORDER BY orden, nombre",
                strtoupper( $dep_code )
            ),
            ARRAY_A
        );
        $out = array();
        foreach ( $rows as $r ) {
            $out[ $r['code'] ] = $r['nombre'];
        }
        return $out;
    }

    public static function get_distritos( string $prov_code ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT code, nombre FROM {$wpdb->prefix}wcpu_distritos
                 WHERE prov_code = %s ORDER BY orden, nombre",
                $prov_code
            ),
            ARRAY_A
        );
        $out = array();
        foreach ( $rows as $r ) {
            $out[ $r['code'] ] = $r['nombre'];
        }
        return $out;
    }
}
