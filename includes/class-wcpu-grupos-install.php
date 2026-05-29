<?php
/**
 * WCPU_Grupos_Install — Tablas para Grupos de Envío.
 */
defined( 'ABSPATH' ) || exit;

class WCPU_Grupos_Install {

    const DB_VERSION     = '1.0';
    const DB_VERSION_KEY = 'wcpu_grupos_db_version';

    public static function run(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE {$wpdb->prefix}wcpu_grupos (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre      VARCHAR(120) NOT NULL,
            descripcion VARCHAR(255) NOT NULL DEFAULT '',
            enabled     TINYINT(1)  NOT NULL DEFAULT 1,
            created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}wcpu_grupo_distritos (
            id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
            grupo_id  INT UNSIGNED NOT NULL,
            dep_code  VARCHAR(10)  NOT NULL,
            prov_code VARCHAR(20)  NOT NULL,
            dist_code VARCHAR(30)  NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_grupo_dist (grupo_id, dist_code),
            KEY idx_grupo (grupo_id),
            KEY idx_dist  (dist_code)
        ) {$charset};" );

        /* Agregar columna grupo_id a wcpu_shipping_rates si no existe */
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}wcpu_shipping_rates LIKE 'grupo_id'" );
        if ( empty( $cols ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}wcpu_shipping_rates ADD COLUMN grupo_id INT UNSIGNED NULL DEFAULT NULL AFTER dist_code" );
        }

        update_option( self::DB_VERSION_KEY, self::DB_VERSION );
    }

    public static function needs_update(): bool {
        return get_option( self::DB_VERSION_KEY, '0' ) !== self::DB_VERSION;
    }
}
