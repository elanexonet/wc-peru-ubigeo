<?php
/**
 * WCPU_Admin — Panel de administración.
 */
defined( 'ABSPATH' ) || exit;

class WCPU_Admin {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
        WCPU_Shipping::init();

        /* Guardar el token */
        add_action( 'admin_post_wcpu_save_settings', array( __CLASS__, 'handle_save_settings' ) );

        /* Recargar datos de ubigeo */
        add_action( 'admin_post_wcpu_reseed', array( __CLASS__, 'handle_reseed' ) );

        /* Columnas en lista de pedidos */
        add_filter( 'manage_woocommerce_page_wc-orders_columns',      array( __CLASS__, 'add_columns' ) );
        add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
        add_filter( 'manage_edit-shop_order_columns',                  array( __CLASS__, 'add_columns' ) );
        add_action( 'manage_shop_order_posts_custom_column',           array( __CLASS__, 'render_column_classic' ), 10, 2 );
    }

    /* ── Menú ── */
    public static function add_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Peru Ubigeo — Configuración', 'wc-peru-ubigeo' ),
            __( 'Peru Ubigeo', 'wc-peru-ubigeo' ),
            'manage_woocommerce',
            'wcpu-settings',
            array( __CLASS__, 'render_settings_page' )
        );
    }

    /* ── Recargar datos ubigeo ── */
    public static function handle_reseed() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Sin permisos.' );
        check_admin_referer( 'wcpu_reseed' );
        WCPU_Install::force_reseed();
        wp_redirect( admin_url( 'admin.php?page=wcpu-settings&reseeded=1' ) );
        exit;
    }

    /* ── Guardar token (admin-post.php) ── */
    public static function handle_save_settings() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Sin permisos.' );
        }
        check_admin_referer( 'wcpu_save_settings' );

        $token = isset( $_POST[ WCPU_ApisPeru::OPTION_KEY ] )
            ? sanitize_text_field( wp_unslash( $_POST[ WCPU_ApisPeru::OPTION_KEY ] ) )
            : '';

        update_option( WCPU_ApisPeru::OPTION_KEY, $token );

        wp_redirect( admin_url( 'admin.php?page=wcpu-settings&saved=1' ) );
        exit;
    }

    /* ── Página de configuración completa ── */
    public static function render_settings_page() {
        global $wpdb;
        $rates    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wcpu_shipping_rates" );
        $token    = WCPU_ApisPeru::get_token();
        $saved    = isset( $_GET['saved'] );
        ?>
        <div class="wrap">
            <h1>🇵🇪 WC Peru Ubigeo <span style="font-size:14px;color:#666;font-weight:normal">v<?php echo esc_html( WCPU_VERSION ); ?></span></h1>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p>✓ Configuración guardada correctamente.</p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['reseeded'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>✓ Datos de ubigeo recargados correctamente.</p></div>
            <?php endif; ?>

            <div style="display:flex;gap:20px;flex-wrap:wrap;margin:20px 0">

                <!-- Estado módulos -->
                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px;min-width:200px">
                    <h3 style="margin-top:0">📦 Ubigeo</h3>
                    <p style="margin:0">26 Departamentos<br>Provincias · Distritos<br><span style="color:green">✓ Activo</span></p>
                </div>
                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px;min-width:200px">
                    <h3 style="margin-top:0">🚚 Envío por Ubigeo</h3>
                    <p style="margin:0"><?php echo (int) $rates; ?> tarifa(s)<br>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcpu-shipping' ) ); ?>">Gestionar tarifas →</a></p>
                </div>
                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px;min-width:200px">
                    <h3 style="margin-top:0">🧾 ApisPeru</h3>
                    <p style="margin:0">
                    <?php if ( $token ) : ?>
                        <span style="color:green">✓ Token configurado</span>
                    <?php else : ?>
                        <span style="color:#b35a00">⚠ Sin token</span>
                    <?php endif; ?>
                    </p>
                </div>

                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px;min-width:200px">
                    <h3 style="margin-top:0">🔄 Datos Ubigeo</h3>
                    <p style="margin:0 0 10px">Dept., Provincias y Distritos</p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="wcpu_reseed">
                        <?php wp_nonce_field( 'wcpu_reseed' ); ?>
                        <button type="submit" class="button button-secondary"
                            onclick="return confirm('¿Recargar todos los datos de ubigeo? Los datos personalizados se perderán.')">
                            🔄 Recargar datos
                        </button>
                    </form>
                </div>

            </div>

            <!-- Formulario de configuración -->
            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:24px;max-width:560px">
                <h2 style="margin-top:0">⚙️ Configuración</h2>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="wcpu_save_settings">
                    <?php wp_nonce_field( 'wcpu_save_settings' ); ?>

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="<?php echo esc_attr( WCPU_ApisPeru::OPTION_KEY ); ?>">
                                        Token ApisPeru
                                    </label>
                                </th>
                                <td>
                                    <input
                                        type="password"
                                        id="<?php echo esc_attr( WCPU_ApisPeru::OPTION_KEY ); ?>"
                                        name="<?php echo esc_attr( WCPU_ApisPeru::OPTION_KEY ); ?>"
                                        value="<?php echo esc_attr( $token ); ?>"
                                        class="regular-text"
                                        autocomplete="new-password"
                                        placeholder="Tu token de apis.net.pe"
                                    >
                                    <p class="description">
                                        Obtén tu token en <a href="https://apis.net.pe" target="_blank">apis.net.pe</a>.
                                        Necesario para consultar RUC en SUNAT y DNI en RENIEC en tiempo real.
                                    </p>
                                    <?php if ( $token ) : ?>
                                        <p style="margin-top:6px">
                                            <button type="button" class="button button-small"
                                                onclick="
                                                    var f=document.getElementById('<?php echo esc_js( WCPU_ApisPeru::OPTION_KEY ); ?>');
                                                    f.type=f.type==='password'?'text':'password';
                                                    this.textContent=f.type==='password'?'👁 Mostrar':'🙈 Ocultar';
                                                ">👁 Mostrar</button>
                                        </p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <?php submit_button( '💾 Guardar configuración' ); ?>
                </form>
            </div>

        </div>
        <?php
    }

    /* ── Columnas en lista de pedidos ── */
    public static function add_columns( $columns ) {
        $new = array();
        foreach ( $columns as $k => $v ) {
            $new[ $k ] = $v;
            if ( 'billing_address' === $k ) {
                $new['wcpu_comp'] = __( 'Comprobante', 'wc-peru-ubigeo' );
            }
        }
        return $new;
    }

    public static function render_column( $column, $order ) {
        if ( 'wcpu_comp' !== $column ) return;
        self::render_comp_cell( $order );
    }

    public static function render_column_classic( $column, $post_id ) {
        if ( 'wcpu_comp' !== $column ) return;
        $order = wc_get_order( $post_id );
        if ( $order ) self::render_comp_cell( $order );
    }

    private static function render_comp_cell( $order ) {
        $tipo = $order->get_meta( '_wc_additional_fields_wcpu/tipo_comprobante' );
        if ( ! $tipo ) return;
        $icon = 'factura' === $tipo ? '🏢' : '🧾';
        $doc  = 'factura' === $tipo
            ? $order->get_meta( '_wc_additional_fields_wcpu/ruc' )
            : $order->get_meta( '_wc_additional_fields_wcpu/doc_numero' );
        echo '<small>' . $icon . ' ' . esc_html( strtoupper( $tipo ) );
        if ( $doc ) echo '<br>' . esc_html( $doc );
        echo '</small>';
    }
}
