<?php
/**
 * WCPU_Shipping — Panel de administración de tarifas de envío por ubigeo.
 * Subpágina: WooCommerce > Peru Ubigeo > Tarifas de Envío
 */
defined( 'ABSPATH' ) || exit;

class WCPU_Shipping {

    public static function init(): void {
        add_action( 'admin_menu',            [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_post_wcpu_save_rate',   [ __CLASS__, 'save_rate' ] );
        add_action( 'admin_post_wcpu_delete_rate', [ __CLASS__, 'delete_rate' ] );
        add_action( 'admin_post_wcpu_toggle_rate', [ __CLASS__, 'toggle_rate' ] );
        add_action( 'wp_ajax_wcpu_bulk_import',    [ __CLASS__, 'ajax_bulk_import' ] );
    }

    public static function add_menu(): void {
        add_submenu_page(
            'woocommerce',
            __( 'Tarifas de Envío Ubigeo', 'wc-peru-ubigeo' ),
            __( 'Envío Ubigeo', 'wc-peru-ubigeo' ),
            'manage_woocommerce',
            'wcpu-shipping',
            [ __CLASS__, 'render_page' ]
        );
    }

    /* ══════════════════════════════════════════════════════════════════
     *  RENDER PÁGINA PRINCIPAL
     * ══════════════════════════════════════════════════════════════════ */
    public static function render_page(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'wcpu_shipping_rates';

        /* Filtros de la URL */
        $filter_dep  = sanitize_text_field( $_GET['filter_dep']  ?? '' );
        $filter_prov = sanitize_text_field( $_GET['filter_prov'] ?? '' );

        /* Query con filtros */
        $where  = 'WHERE 1=1';
        $params = [];
        if ( $filter_dep && $filter_dep !== '*' ) {
            $where   .= ' AND dep_code = %s';
            $params[] = $filter_dep;
        }
        if ( $filter_prov ) {
            $where   .= ' AND prov_code = %s';
            $params[] = $filter_prov;
        }

        $sql   = "SELECT * FROM {$table} {$where} ORDER BY dep_code, prov_code, dist_code";
        $rates = $params
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) // phpcs:ignore
            : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore

        global $wpdb;
        $dep_rows = $wpdb->get_results( "SELECT code, nombre FROM {$wpdb->prefix}wcpu_departamentos ORDER BY orden,nombre", ARRAY_A );
        $deps = array( '*' => '🌎 Todo el Perú (catch-all)' );
        foreach ( $dep_rows as $r ) $deps[ $r['code'] ] = $r['nombre'];
        $nonce = wp_create_nonce( 'wcpu_shipping_nonce' );

        /* Mensaje de éxito/error */
        $msg = '';
        if ( isset( $_GET['saved'] ) )   $msg = '<div class="notice notice-success is-dismissible"><p>✓ Tarifa guardada correctamente.</p></div>';
        if ( isset( $_GET['deleted'] ) ) $msg = '<div class="notice notice-success is-dismissible"><p>✓ Tarifa eliminada.</p></div>';
        if ( isset( $_GET['error'] ) )   $msg = '<div class="notice notice-error is-dismissible"><p>✗ Error: ' . esc_html( urldecode( $_GET['error'] ) ) . '</p></div>';

        ?>
        <div class="wrap wcpu-shipping-wrap">
            <h1 class="wp-heading-inline">🚚 <?php esc_html_e( 'Tarifas de Envío por Ubigeo', 'wc-peru-ubigeo' ); ?></h1>
            <hr class="wp-header-end">
            <?php echo $msg; // phpcs:ignore ?>

            <div class="wcpu-shipping-layout">

                <!-- ══ FORMULARIO AGREGAR/EDITAR ══ -->
                <div class="wcpu-card wcpu-form-card">
                    <h2 id="wcpu-form-title"><?php esc_html_e( 'Nueva Tarifa', 'wc-peru-ubigeo' ); ?></h2>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wcpu-rate-form">
                        <input type="hidden" name="action" value="wcpu_save_rate">
                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
                        <input type="hidden" name="rate_id" id="rate_id" value="">

                        <p>
                            <label><?php esc_html_e( 'Departamento', 'wc-peru-ubigeo' ); ?> <span class="required">*</span></label>
                            <select name="dep_code" id="form_dep_code" required>
                                <?php foreach ( $deps as $code => $name ) : ?>
                                    <option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </p>

                        <p id="form_prov_row">
                            <label><?php esc_html_e( 'Provincia', 'wc-peru-ubigeo' ); ?> <small>(opcional — dejar vacío = aplica a todo el depto)</small></label>
                            <select name="prov_code" id="form_prov_code">
                                <option value=""><?php esc_html_e( '— Todas las provincias —', 'wc-peru-ubigeo' ); ?></option>
                            </select>
                        </p>

                        <p id="form_dist_row">
                            <label><?php esc_html_e( 'Distrito', 'wc-peru-ubigeo' ); ?> <small>(opcional)</small></label>
                            <select name="dist_code" id="form_dist_code">
                                <option value=""><?php esc_html_e( '— Todos los distritos —', 'wc-peru-ubigeo' ); ?></option>
                            </select>
                        </p>

                        <p>
                            <label><?php esc_html_e( 'O aplicar a un Grupo de distritos', 'wc-peru-ubigeo' ); ?>
                                <small>(<a href="<?php echo esc_url( admin_url('admin.php?page=wcpu-grupos') ); ?>" target="_blank">Gestionar grupos</a>)</small>
                            </label>
                            <select name="grupo_id" id="form_grupo_id">
                                <option value="">— Sin grupo —</option>
                                <?php
                                if ( class_exists('WCPU_Grupos_Admin') ) {
                                    foreach ( WCPU_Grupos_Admin::get_all_active() as $g ) {
                                        echo '<option value="'.esc_attr($g['id']).'">'.esc_html($g['nombre']).'</option>';
                                    }
                                }
                                ?>
                            </select>
                            <span class="description">Si seleccionas un grupo, se ignoran los campos Provincia/Distrito.</span>
                        </p>

                        <p>
                            <label><?php esc_html_e( 'Etiqueta (opcional)', 'wc-peru-ubigeo' ); ?></label>
                            <input type="text" name="label" id="form_label" placeholder="ej: Lima Metropolitana" style="width:100%">
                        </p>

                        <div class="wcpu-two-col">
                            <p>
                                <label><?php esc_html_e( 'Costo (S/)', 'wc-peru-ubigeo' ); ?> <span class="required">*</span></label>
                                <input type="number" name="cost" id="form_cost" min="0" step="0.01" required placeholder="0.00">
                            </p>
                            <p>
                                <label><?php esc_html_e( 'Gratis si supera (S/)', 'wc-peru-ubigeo' ); ?> <small>0 = desactivado</small></label>
                                <input type="number" name="free_above" id="form_free_above" min="0" step="0.01" placeholder="0.00" value="0">
                            </p>
                        </div>

                        <p>
                            <label><?php esc_html_e( 'Notas internas', 'wc-peru-ubigeo' ); ?></label>
                            <input type="text" name="notes" id="form_notes" style="width:100%" placeholder="Solo visible en el admin">
                        </p>

                        <p class="wcpu-form-actions">
                            <button type="submit" class="button button-primary">💾 <?php esc_html_e( 'Guardar Tarifa', 'wc-peru-ubigeo' ); ?></button>
                            <button type="button" class="button" id="wcpu-form-reset">✕ Cancelar</button>
                        </p>
                    </form>

                    <!-- Importación masiva -->
                    <hr>
                    <h3><?php esc_html_e( 'Importación masiva (JSON)', 'wc-peru-ubigeo' ); ?></h3>
                    <p><small><?php esc_html_e( 'Formato: array de objetos con dep_code, prov_code, dist_code, label, cost, free_above.', 'wc-peru-ubigeo' ); ?></small></p>
                    <textarea id="wcpu-bulk-json" rows="5" style="width:100%;font-family:monospace;font-size:12px" placeholder='[{"dep_code":"15","prov_code":"1501","dist_code":"","label":"Lima","cost":8,"free_above":150}]'></textarea>
                    <button type="button" class="button button-secondary" id="wcpu-bulk-import" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wcpu_bulk_import' ) ); ?>">
                        📥 <?php esc_html_e( 'Importar', 'wc-peru-ubigeo' ); ?>
                    </button>
                    <span id="wcpu-bulk-result" style="margin-left:8px"></span>
                </div>

                <!-- ══ TABLA DE TARIFAS ══ -->
                <div class="wcpu-card wcpu-table-card">
                    <!-- Filtros -->
                    <form method="get" class="wcpu-filters">
                        <input type="hidden" name="page" value="wcpu-shipping">
                        <select name="filter_dep" id="filter_dep" onchange="this.form.submit()">
                            <option value=""><?php esc_html_e( 'Todos los departamentos', 'wc-peru-ubigeo' ); ?></option>
                            <option value="*" <?php selected( $filter_dep, '*' ); ?>>🌎 Catch-all nacional</option>
                            <?php foreach ( $deps as $code => $name ) : ?>
                                <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $filter_dep, $code ); ?>>
                                    <?php echo esc_html( $name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ( $filter_dep && $filter_dep !== '*' ) : ?>
                            <select name="filter_prov" onchange="this.form.submit()">
                                <option value=""><?php esc_html_e( 'Todas las provincias', 'wc-peru-ubigeo' ); ?></option>
                                <?php
                        $prov_rows = $wpdb->get_results( $wpdb->prepare(
                            "SELECT p.code, p.nombre FROM {$wpdb->prefix}wcpu_provincias p
                             JOIN {$wpdb->prefix}wcpu_departamentos d ON d.id=p.dep_id
                             WHERE d.code=%s ORDER BY p.orden,p.nombre", $filter_dep
                        ), ARRAY_A );
                        foreach ( $prov_rows as $pr ) :
                            $pc = $pr['code']; $pn = $pr['nombre'];
                        ?>
                                    <option value="<?php echo esc_attr( $pc ); ?>" <?php selected( $filter_prov, $pc ); ?>><?php echo esc_html( $pn ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <span class="wcpu-rate-count"><?php echo count( $rates ); ?> tarifa(s)</span>
                    </form>

                    <table class="wp-list-table widefat fixed striped wcpu-rates-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Departamento', 'wc-peru-ubigeo' ); ?></th>
                                <th><?php esc_html_e( 'Provincia', 'wc-peru-ubigeo' ); ?></th>
                                <th><?php esc_html_e( 'Distrito', 'wc-peru-ubigeo' ); ?></th>
                                <th><?php esc_html_e( 'Etiqueta', 'wc-peru-ubigeo' ); ?></th>
                                <th style="text-align:right"><?php esc_html_e( 'Costo S/', 'wc-peru-ubigeo' ); ?></th>
                                <th style="text-align:right"><?php esc_html_e( 'Gratis desde S/', 'wc-peru-ubigeo' ); ?></th>
                                <th style="text-align:center"><?php esc_html_e( 'Estado', 'wc-peru-ubigeo' ); ?></th>
                                <th><?php esc_html_e( 'Acciones', 'wc-peru-ubigeo' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ( empty( $rates ) ) : ?>
                            <tr><td colspan="8" style="text-align:center;padding:20px;color:#999"><?php esc_html_e( 'No hay tarifas configuradas.', 'wc-peru-ubigeo' ); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ( $rates as $rate ) :
                                $dep_name  = ( '*' === $rate['dep_code'] ) ? '🌎 Todo el Perú' : ( $deps[ $rate['dep_code'] ] ?? $rate['dep_code'] );
                                /* Si tiene grupo, mostrar nombre del grupo */
                                if ( ! empty( $rate['grupo_id'] ) && class_exists('WCPU_Grupos_Admin') ) {
                                    global $wpdb;
                                    $gnom = $wpdb->get_var($wpdb->prepare("SELECT nombre FROM {$wpdb->prefix}wcpu_grupos WHERE id=%d", $rate['grupo_id']));
                                    if ($gnom) { $dep_name = '📦 Grupo: '.$gnom; }
                                }
                                $prov_name = '—';
                                if ( $rate['prov_code'] ) {
                                    $pn = $wpdb->get_var( $wpdb->prepare( "SELECT nombre FROM {$wpdb->prefix}wcpu_provincias WHERE code=%s", $rate['prov_code'] ) );
                                    $prov_name = $pn ?: $rate['prov_code'];
                                }
                                $dist_name = '—';
                                if ( $rate['dist_code'] ) {
                                    $dn = $wpdb->get_var( $wpdb->prepare( "SELECT nombre FROM {$wpdb->prefix}wcpu_distritos WHERE code=%s", $rate['dist_code'] ) );
                                    $dist_name = $dn ?: $rate['dist_code'];
                                }
                                $toggle_url = wp_nonce_url(
                                    admin_url( 'admin-post.php?action=wcpu_toggle_rate&rate_id=' . $rate['id'] ),
                                    'wcpu_shipping_nonce'
                                );
                                $delete_url = wp_nonce_url(
                                    admin_url( 'admin-post.php?action=wcpu_delete_rate&rate_id=' . $rate['id'] ),
                                    'wcpu_shipping_nonce'
                                );
                            ?>
                            <tr data-rate='<?php echo esc_attr( json_encode( $rate ) ); ?>'>
                                <td><?php echo esc_html( $dep_name ); ?></td>
                                <td><?php echo esc_html( $prov_name ); ?></td>
                                <td><?php echo esc_html( $dist_name ); ?></td>
                                <td><?php echo esc_html( $rate['label'] ?: '—' ); ?></td>
                                <td style="text-align:right"><strong>S/ <?php echo number_format( (float) $rate['cost'], 2 ); ?></strong></td>
                                <td style="text-align:right"><?php echo $rate['free_above'] > 0 ? 'S/ ' . number_format( (float)$rate['free_above'], 2 ) : '—'; ?></td>
                                <td style="text-align:center">
                                    <a href="<?php echo esc_url( $toggle_url ); ?>" class="wcpu-toggle <?php echo $rate['enabled'] ? 'enabled' : 'disabled'; ?>" title="<?php echo $rate['enabled'] ? 'Desactivar' : 'Activar'; ?>">
                                        <?php echo $rate['enabled'] ? '✅' : '⛔'; ?>
                                    </a>
                                </td>
                                <td>
                                    <a href="#" class="wcpu-edit-btn button button-small" data-id="<?php echo esc_attr( $rate['id'] ); ?>">✏️ Editar</a>
                                    <a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small wcpu-delete-btn" onclick="return confirm('¿Eliminar esta tarifa?')">🗑️</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div><!-- .wcpu-table-card -->

            </div><!-- .wcpu-shipping-layout -->
        </div>

        <style>
        .wcpu-shipping-layout { display:flex; gap:20px; margin-top:20px; align-items:flex-start; }
        .wcpu-card { background:#fff; border:1px solid #ccd0d4; border-radius:4px; padding:20px; }
        .wcpu-form-card { min-width:340px; max-width:380px; flex-shrink:0; }
        .wcpu-table-card { flex:1; overflow-x:auto; }
        .wcpu-form-card select,
        .wcpu-form-card input[type=text],
        .wcpu-form-card input[type=number] { width:100%; }
        .wcpu-two-col { display:flex; gap:12px; }
        .wcpu-two-col p { flex:1; }
        .wcpu-form-actions { display:flex; gap:8px; }
        .wcpu-filters { display:flex; gap:8px; align-items:center; margin-bottom:12px; flex-wrap:wrap; }
        .wcpu-filters select { min-width:180px; }
        .wcpu-rate-count { margin-left:auto; color:#666; font-size:13px; }
        .wcpu-rates-table td { vertical-align:middle; }
        .wcpu-toggle { text-decoration:none; font-size:18px; }
        .required { color:#cc0000; }
        @media (max-width:960px) { .wcpu-shipping-layout { flex-direction:column; } .wcpu-form-card { max-width:100%; } }
        </style>

        <script>
        (function($){
            var ajaxUrl      = '<?php echo esc_js( admin_url( "admin-ajax.php" ) ); ?>';
            var nonce        = '<?php echo esc_js( $nonce ); ?>';
            var wcpuNonce    = '<?php echo esc_js( wp_create_nonce( 'wcpu_nonce' ) ); ?>';
            var wcpuI18n = <?php echo json_encode([
                'select_prov' => '— Todas las provincias —',
                'select_dist' => '— Todos los distritos —',
            ]); ?>;

            /* ── Encadenado Depto → Prov → Dist en el formulario ── */
            $('#form_dep_code').on('change', function(){
                var dep = $(this).val();
                var $prov = $('#form_prov_code');
                var $dist = $('#form_dist_code');

                $prov.html('<option value="">'+wcpuI18n.select_prov+'</option>');
                $dist.html('<option value="">'+wcpuI18n.select_dist+'</option>');

                if (!dep || dep === '*') return;

                $.post(ajaxUrl, { action:'wcpu_get_provincias', nonce:wcpuNonce, dep_code:dep }, function(r){
                    if(r.success) $.each(r.data, function(k,v){ $prov.append('<option value="'+k+'">'+v+'</option>'); });
                });
            });

            $('#form_prov_code').on('change', function(){
                var prov = $(this).val();
                var $dist = $('#form_dist_code');
                $dist.html('<option value="">'+wcpuI18n.select_dist+'</option>');
                if (!prov) return;
                $.post(ajaxUrl, { action:'wcpu_get_distritos', nonce:wcpuNonce, prov_code:prov }, function(r){
                    if(r.success) $.each(r.data, function(k,v){ $dist.append('<option value="'+k+'">'+v+'</option>'); });
                });
            });

            /* ── Editar tarifa → cargar datos en el formulario ── */
            $(document).on('click', '.wcpu-edit-btn', function(e){
                e.preventDefault();
                var rate = $(this).closest('tr').data('rate');
                $('#wcpu-form-title').text('Editar Tarifa #' + rate.id);
                $('#rate_id').val(rate.id);
                $('#form_label').val(rate.label);
                $('#form_cost').val(rate.cost);
                $('#form_free_above').val(rate.free_above);
                $('#form_notes').val(rate.notes);

                /* Seleccionar departamento */
                $('#form_dep_code').val(rate.dep_code);

                var $prov = $('#form_prov_code');
                var $dist = $('#form_dist_code');

                /* Si hay departamento, cargar provincias primero */
                if (rate.dep_code && rate.dep_code !== '*') {
                    $prov.html('<option value="">Cargando...</option>');
                    $dist.html('<option value="">'+wcpuI18n.select_dist+'</option>');

                    $.post(ajaxUrl, { action:'wcpu_get_provincias', nonce:wcpuNonce, dep_code:rate.dep_code }, function(r){
                        $prov.html('<option value="">'+wcpuI18n.select_prov+'</option>');
                        if (r.success) {
                            $.each(r.data, function(k,v){
                                $prov.append('<option value="'+k+'">'+v+'</option>');
                            });
                        }
                        /* Una vez cargadas las provincias, seleccionar la correcta */
                        if (rate.prov_code) {
                            $prov.val(rate.prov_code);

                            /* Cargar distritos de esa provincia */
                            $dist.html('<option value="">Cargando...</option>');
                            $.post(ajaxUrl, { action:'wcpu_get_distritos', nonce:wcpuNonce, prov_code:rate.prov_code }, function(r2){
                                $dist.html('<option value="">'+wcpuI18n.select_dist+'</option>');
                                if (r2.success) {
                                    $.each(r2.data, function(k,v){
                                        $dist.append('<option value="'+k+'">'+v+'</option>');
                                    });
                                }
                                /* Seleccionar el distrito correcto */
                                if (rate.dist_code) $dist.val(rate.dist_code);
                            });
                        }
                    });
                } else {
                    $prov.html('<option value="">'+wcpuI18n.select_prov+'</option>');
                    $dist.html('<option value="">'+wcpuI18n.select_dist+'</option>');
                }

                $('html,body').animate({ scrollTop: $('#wcpu-rate-form').offset().top - 40 }, 300);
            });

            /* ── Reset formulario ── */
            $('#wcpu-form-reset').on('click', function(){
                $('#wcpu-form-title').text('Nueva Tarifa');
                $('#wcpu-rate-form')[0].reset();
                $('#rate_id').val('');
            });

            /* ── Importación masiva ── */
            $('#wcpu-bulk-import').on('click', function(){
                var json = $('#wcpu-bulk-json').val().trim();
                if (!json) return;
                var $btn = $(this).prop('disabled', true).text('Importando...');
                $.post(ajaxUrl, {
                    action: 'wcpu_bulk_import',
                    nonce: $(this).data('nonce'),
                    rates: json
                }, function(r){
                    $btn.prop('disabled', false).text('📥 Importar');
                    if(r.success){
                        $('#wcpu-bulk-result').css('color','green').text('✓ ' + r.data.imported + ' tarifa(s) importada(s).');
                        setTimeout(function(){ location.reload(); }, 1500);
                    } else {
                        $('#wcpu-bulk-result').css('color','red').text('✗ ' + r.data);
                    }
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    /* ══ GUARDAR / ACTUALIZAR TARIFA ═══════════════════════════════ */
    public static function save_rate(): void {
        check_admin_referer( 'wcpu_shipping_nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Sin permisos.' );

        global $wpdb;
        $table = $wpdb->prefix . 'wcpu_shipping_rates';

        $grupo_id  = absint( $_POST['grupo_id'] ?? 0 );
        $data = [
            'dep_code'   => sanitize_text_field( $_POST['dep_code']   ?? '' ),
            'prov_code'  => $grupo_id ? '' : sanitize_text_field( $_POST['prov_code']  ?? '' ),
            'dist_code'  => $grupo_id ? '' : sanitize_text_field( $_POST['dist_code']  ?? '' ),
            'grupo_id'   => $grupo_id ?: null,
            'label'      => sanitize_text_field( $_POST['label']      ?? '' ),
            'cost'       => (float) ( $_POST['cost']       ?? 0 ),
            'free_above' => (float) ( $_POST['free_above'] ?? 0 ),
            'notes'      => sanitize_text_field( $_POST['notes']      ?? '' ),
            'enabled'    => 1,
        ];

        $rate_id = absint( $_POST['rate_id'] ?? 0 );
        if ( $rate_id ) {
            $wpdb->update( $table, $data, [ 'id' => $rate_id ] );
        } else {
            $wpdb->insert( $table, $data );
        }

        wp_redirect( admin_url( 'admin.php?page=wcpu-shipping&saved=1' ) );
        exit;
    }

    /* ══ ELIMINAR TARIFA ═══════════════════════════════════════════ */
    public static function delete_rate(): void {
        check_admin_referer( 'wcpu_shipping_nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Sin permisos.' );

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'wcpu_shipping_rates', [ 'id' => absint( $_GET['rate_id'] ?? 0 ) ] );
        wp_redirect( admin_url( 'admin.php?page=wcpu-shipping&deleted=1' ) );
        exit;
    }

    /* ══ ACTIVAR / DESACTIVAR TARIFA ══════════════════════════════ */
    public static function toggle_rate(): void {
        check_admin_referer( 'wcpu_shipping_nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Sin permisos.' );

        global $wpdb;
        $table   = $wpdb->prefix . 'wcpu_shipping_rates';
        $rate_id = absint( $_GET['rate_id'] ?? 0 );
        $current = (int) $wpdb->get_var( $wpdb->prepare( "SELECT enabled FROM {$table} WHERE id=%d", $rate_id ) );
        $wpdb->update( $table, [ 'enabled' => $current ? 0 : 1 ], [ 'id' => $rate_id ] );
        wp_redirect( admin_url( 'admin.php?page=wcpu-shipping' ) );
        exit;
    }

    /* ══ IMPORTACIÓN MASIVA AJAX ═══════════════════════════════════ */
    public static function ajax_bulk_import(): void {
        check_ajax_referer( 'wcpu_bulk_import', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Sin permisos.' );

        $raw = stripslashes( $_POST['rates'] ?? '' );
        $items = json_decode( $raw, true );

        if ( ! is_array( $items ) ) {
            wp_send_json_error( 'JSON inválido.' );
        }

        global $wpdb;
        $table   = $wpdb->prefix . 'wcpu_shipping_rates';
        $imported = 0;

        foreach ( $items as $item ) {
            $wpdb->insert( $table, [
                'dep_code'   => sanitize_text_field( $item['dep_code']   ?? '' ),
                'prov_code'  => sanitize_text_field( $item['prov_code']  ?? '' ),
                'dist_code'  => sanitize_text_field( $item['dist_code']  ?? '' ),
                'label'      => sanitize_text_field( $item['label']      ?? '' ),
                'cost'       => (float) ( $item['cost']       ?? 0 ),
                'free_above' => (float) ( $item['free_above'] ?? 0 ),
                'notes'      => sanitize_text_field( $item['notes']      ?? '' ),
                'enabled'    => 1,
            ] );
            $imported++;
        }

        wp_send_json_success( [ 'imported' => $imported ] );
    }
}
