<?php
/**
 * WCPU_Grupos_Admin — Panel de Grupos de Envío con Drag & Drop.
 */
defined( 'ABSPATH' ) || exit;

class WCPU_Grupos_Admin {

    public static function init() {
        add_action( 'admin_menu',                       array( __CLASS__, 'add_menu' ) );
        add_action( 'admin_post_wcpu_save_grupo',       array( __CLASS__, 'save_grupo' ) );
        add_action( 'admin_post_wcpu_delete_grupo',     array( __CLASS__, 'delete_grupo' ) );
        add_action( 'admin_post_wcpu_toggle_grupo',     array( __CLASS__, 'toggle_grupo' ) );
        add_action( 'wp_ajax_wcpu_get_distritos_grupo', array( __CLASS__, 'ajax_get_distritos' ) );
        add_action( 'wp_ajax_wcpu_get_grupo_members',   array( __CLASS__, 'ajax_get_members' ) );
    }

    public static function add_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Grupos de Envío', 'wc-peru-ubigeo' ),
            __( 'Grupos Envío', 'wc-peru-ubigeo' ),
            'manage_woocommerce',
            'wcpu-grupos',
            array( __CLASS__, 'render_page' )
        );
    }

    /* ══════════════════════════════════════════════════════════════
     *  RENDER PÁGINA
     * ══════════════════════════════════════════════════════════════ */
    public static function render_page() {
        global $wpdb;

        $editing = absint( $_GET['edit'] ?? 0 );
        $msg     = sanitize_text_field( $_GET['msg'] ?? '' );
        $msgs    = array(
            'saved'   => '✓ Grupo guardado correctamente.',
            'deleted' => '✓ Grupo eliminado.',
            'toggled' => '✓ Estado actualizado.',
        );

        $grupos = $wpdb->get_results(
            "SELECT g.*, COUNT(gd.id) as total
             FROM {$wpdb->prefix}wcpu_grupos g
             LEFT JOIN {$wpdb->prefix}wcpu_grupo_distritos gd ON g.id = gd.grupo_id
             GROUP BY g.id ORDER BY g.nombre",
            ARRAY_A
        );

        $edit_data    = null;
        $edit_members = array();
        if ( $editing ) {
            $edit_data = $wpdb->get_row(
                $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wcpu_grupos WHERE id=%d", $editing ),
                ARRAY_A
            );
            if ( $edit_data ) {
                $edit_members = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT gd.dist_code, gd.prov_code, gd.dep_code,
                                d.nombre as dist_nombre, p.nombre as prov_nombre, dep.nombre as dep_nombre
                         FROM {$wpdb->prefix}wcpu_grupo_distritos gd
                         LEFT JOIN {$wpdb->prefix}wcpu_distritos d ON d.code = gd.dist_code
                         LEFT JOIN {$wpdb->prefix}wcpu_provincias p ON p.code = gd.prov_code
                         LEFT JOIN {$wpdb->prefix}wcpu_departamentos dep ON dep.code = gd.dep_code
                         WHERE gd.grupo_id = %d
                         ORDER BY dep.nombre, p.nombre, d.nombre",
                        $editing
                    ),
                    ARRAY_A
                );
            }
        }

        $deps  = WCPU_Data::get_departamentos();
        $nonce = wp_create_nonce( 'wcpu_grupos_nonce' );
        $ajax  = admin_url( 'admin-ajax.php' );
        ?>
        <div class="wrap">
            <h1>📦 <?php esc_html_e( 'Grupos de Envío', 'wc-peru-ubigeo' ); ?></h1>

            <?php if ( $msg && isset( $msgs[ $msg ] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msgs[ $msg ] ); ?></p></div>
            <?php endif; ?>

            <div class="wcpu-grupos-layout">

                <!-- ══ FORMULARIO ══ -->
                <div class="wcpu-card" style="min-width:340px;max-width:420px;flex-shrink:0">

                    <h2><?php echo $edit_data ? '✏️ Editar Grupo' : '➕ Nuevo Grupo'; ?></h2>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wcpu-grupo-form">
                        <input type="hidden" name="action"   value="wcpu_save_grupo">
                        <input type="hidden" name="grupo_id" value="<?php echo $editing; ?>">
                        <?php wp_nonce_field( 'wcpu_grupos_nonce' ); ?>

                        <!-- Nombre y descripción -->
                        <p>
                            <label><strong>Nombre del grupo *</strong><br>
                            <input type="text" name="nombre" class="regular-text"
                                   value="<?php echo esc_attr( $edit_data['nombre'] ?? '' ); ?>"
                                   placeholder="ej: Lima Norte" required></label>
                        </p>
                        <p>
                            <label>Descripción (opcional)<br>
                            <input type="text" name="descripcion" class="regular-text"
                                   value="<?php echo esc_attr( $edit_data['descripcion'] ?? '' ); ?>"
                                   placeholder="Nota interna"></label>
                        </p>

                        <hr>
                        <h3>🗺 Distritos del grupo</h3>
                        <p class="description">
                            Filtra por Departamento y Provincia, luego <strong>arrastra</strong>
                            los distritos a la zona del grupo. No se permiten duplicados.
                        </p>

                        <!-- Filtros -->
                        <div style="display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap">
                            <select id="wcpu-g-dep" style="flex:1;min-width:140px">
                                <option value="">— Departamento —</option>
                                <?php foreach ( $deps as $code => $name ) : ?>
                                    <option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="wcpu-g-prov" style="flex:1;min-width:140px" disabled>
                                <option value="">— Provincia —</option>
                            </select>
                        </div>

                        <!-- Buscador rápido -->
                        <input type="text" id="wcpu-g-search" placeholder="🔍 Buscar distrito..." style="width:100%;margin-bottom:8px;box-sizing:border-box">

                        <!-- Zona de drag & drop -->
                        <div class="wcpu-dnd-layout">
                            <!-- Lista disponibles -->
                            <div class="wcpu-dnd-col">
                                <div class="wcpu-dnd-header">
                                    Disponibles
                                    <button type="button" id="wcpu-add-all" class="wcpu-mini-btn" title="Agregar todos al grupo">➕ Todos</button>
                                </div>
                                <ul id="wcpu-list-available" class="wcpu-dnd-list wcpu-drop-zone"
                                    data-zone="available">
                                    <li class="wcpu-dnd-empty">Selecciona Departamento y Provincia</li>
                                </ul>
                            </div>

                            <!-- Flecha central -->
                            <div style="display:flex;flex-direction:column;justify-content:center;align-items:center;gap:8px;padding:0 4px">
                                <button type="button" id="wcpu-move-right" class="wcpu-arrow-btn" title="Mover seleccionados al grupo">▶</button>
                                <button type="button" id="wcpu-move-left"  class="wcpu-arrow-btn" title="Quitar seleccionados del grupo">◀</button>
                            </div>

                            <!-- Lista del grupo -->
                            <div class="wcpu-dnd-col">
                                <div class="wcpu-dnd-header">
                                    En el grupo
                                    <button type="button" id="wcpu-remove-all" class="wcpu-mini-btn wcpu-mini-btn-danger" title="Quitar todos">✕ Todos</button>
                                </div>
                                <ul id="wcpu-list-grupo" class="wcpu-dnd-list wcpu-drop-zone"
                                    data-zone="grupo">
                                    <?php if ( empty( $edit_members ) ) : ?>
                                        <li class="wcpu-dnd-empty" id="wcpu-grupo-empty">Arrastra distritos aquí</li>
                                    <?php else : ?>
                                        <?php foreach ( $edit_members as $m ) : ?>
                                            <li class="wcpu-dnd-item wcpu-in-grupo"
                                                draggable="true"
                                                data-code="<?php echo esc_attr( $m['dist_code'] ); ?>"
                                                data-prov="<?php echo esc_attr( $m['prov_code'] ); ?>"
                                                data-dep="<?php echo esc_attr( $m['dep_code'] ); ?>"
                                                data-label="<?php echo esc_attr( $m['dist_nombre'] ); ?>">
                                                <span class="wcpu-drag-handle">⠿</span>
                                                <span class="wcpu-item-name"><?php echo esc_html( $m['dist_nombre'] ); ?></span>
                                                <span class="wcpu-item-ctx"><?php echo esc_html( $m['dep_nombre'] . ' / ' . $m['prov_nombre'] ); ?></span>
                                                <button type="button" class="wcpu-remove-item" title="Quitar">✕</button>
                                            </li>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>

                        <!-- Inputs hidden generados por JS -->
                        <div id="wcpu-hidden-inputs">
                            <?php foreach ( $edit_members as $m ) : ?>
                                <input type="hidden" name="distritos[]" value="<?php echo esc_attr( $m['dist_code'] . '|' . $m['prov_code'] . '|' . $m['dep_code'] ); ?>">
                            <?php endforeach; ?>
                        </div>

                        <p style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap">
                            <button type="submit" class="button button-primary">💾 Guardar Grupo</button>
                            <?php if ( $editing ) : ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcpu-grupos' ) ); ?>" class="button">✕ Cancelar</a>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>

                <!-- ══ TABLA DE GRUPOS ══ -->
                <div class="wcpu-card" style="flex:1;overflow-x:auto">
                    <h2>Grupos existentes (<?php echo count( $grupos ); ?>)</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Descripción</th>
                                <th width="80" style="text-align:center">Distritos</th>
                                <th width="80" style="text-align:center">Estado</th>
                                <th width="160">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ( empty( $grupos ) ) : ?>
                            <tr><td colspan="5" style="text-align:center;padding:20px;color:#999">
                                Sin grupos. Crea uno usando el formulario.
                            </td></tr>
                        <?php else : ?>
                            <?php foreach ( $grupos as $g ) :
                                $toggle_url = wp_nonce_url( admin_url( "admin-post.php?action=wcpu_toggle_grupo&grupo_id={$g['id']}" ), 'wcpu_grupos_nonce' );
                                $delete_url = wp_nonce_url( admin_url( "admin-post.php?action=wcpu_delete_grupo&grupo_id={$g['id']}" ), 'wcpu_grupos_nonce' );
                                $edit_url   = admin_url( "admin.php?page=wcpu-grupos&edit={$g['id']}" );
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html( $g['nombre'] ); ?></strong></td>
                                <td><?php echo esc_html( $g['descripcion'] ?: '—' ); ?></td>
                                <td style="text-align:center">
                                    <span class="wcpu-badge"><?php echo (int) $g['total']; ?></span>
                                </td>
                                <td style="text-align:center">
                                    <a href="<?php echo esc_url( $toggle_url ); ?>" title="<?php echo $g['enabled'] ? 'Desactivar' : 'Activar'; ?>">
                                        <?php echo $g['enabled'] ? '✅' : '⛔'; ?>
                                    </a>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">✏️ Editar</a>
                                    <a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small"
                                       onclick="return confirm('¿Eliminar el grupo «<?php echo esc_js( $g['nombre'] ); ?>»?\nLas tarifas asociadas perderán el grupo.')">🗑️</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>

                    <?php if ( ! empty( $grupos ) ) : ?>
                    <p style="margin-top:12px;font-size:13px;color:#666">
                        💡 Para usar un grupo en una tarifa de envío, ve a
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcpu-shipping' ) ); ?>">Envío Ubigeo</a>
                        y selecciona el grupo en el campo correspondiente.
                    </p>
                    <?php endif; ?>
                </div>

            </div><!-- .wcpu-grupos-layout -->
        </div>

        <style>
        /* ── Layout ── */
        .wcpu-grupos-layout { display:flex; gap:20px; margin-top:20px; align-items:flex-start; flex-wrap:wrap; }
        .wcpu-card { background:#fff; border:1px solid #ccd0d4; border-radius:4px; padding:20px; }

        /* ── Drag & Drop ── */
        .wcpu-dnd-layout { display:flex; gap:0; align-items:stretch; min-height:260px; }
        .wcpu-dnd-col    { flex:1; display:flex; flex-direction:column; min-width:0; }
        .wcpu-dnd-header {
            display:flex; align-items:center; justify-content:space-between;
            background:#f0f0f1; border:1px solid #c3c4c7; border-bottom:none;
            padding:6px 10px; font-size:12px; font-weight:600; color:#3c434a;
        }
        .wcpu-dnd-list {
            flex:1; margin:0; padding:4px;
            border:1px solid #c3c4c7; border-radius:0 0 3px 3px;
            overflow-y:auto; max-height:300px; min-height:120px;
            list-style:none; background:#fff;
            transition:background .15s;
        }
        .wcpu-dnd-list.wcpu-drag-over { background:#f0f7ff; border-color:#2271b1; }

        .wcpu-dnd-item {
            display:flex; align-items:center; gap:6px;
            padding:6px 8px; margin:2px 0;
            background:#fff; border:1px solid #ddd; border-radius:3px;
            cursor:grab; user-select:none; font-size:13px;
            transition:background .1s, border-color .1s, opacity .1s;
        }
        .wcpu-dnd-item:hover    { background:#f6f7f7; border-color:#999; }
        .wcpu-dnd-item.selected { background:#e8f0fe; border-color:#2271b1; }
        .wcpu-dnd-item.dragging { opacity:.4; }

        .wcpu-drag-handle { color:#aaa; font-size:16px; cursor:grab; flex-shrink:0; }
        .wcpu-item-name   { font-weight:500; flex:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .wcpu-item-ctx    { font-size:11px; color:#666; white-space:nowrap; }
        .wcpu-remove-item {
            background:none; border:none; color:#aaa; cursor:pointer;
            font-size:14px; padding:0 2px; line-height:1; flex-shrink:0;
        }
        .wcpu-remove-item:hover { color:#cc0000; }

        .wcpu-dnd-empty { color:#aaa; font-size:12px; text-align:center; padding:20px 8px; font-style:italic; }

        /* Items en la lista del grupo (color diferente) */
        #wcpu-list-grupo .wcpu-dnd-item { background:#f8fff8; border-color:#cce5cc; }
        #wcpu-list-grupo .wcpu-dnd-item:hover { background:#edfaed; }
        #wcpu-list-grupo .wcpu-dnd-item.selected { background:#d4edda; border-color:#28a745; }

        /* ── Botones ── */
        .wcpu-arrow-btn {
            background:#f0f0f1; border:1px solid #c3c4c7; border-radius:3px;
            padding:6px 10px; cursor:pointer; font-size:16px; line-height:1;
            transition:background .15s;
        }
        .wcpu-arrow-btn:hover { background:#2271b1; color:#fff; border-color:#2271b1; }
        .wcpu-mini-btn {
            background:none; border:none; cursor:pointer; font-size:11px;
            color:#2271b1; padding:0 2px;
        }
        .wcpu-mini-btn-danger { color:#cc0000; }
        .wcpu-mini-btn:hover  { text-decoration:underline; }

        /* ── Badge ── */
        .wcpu-badge {
            display:inline-block; background:#2271b1; color:#fff;
            border-radius:10px; padding:1px 8px; font-size:11px; font-weight:600;
        }

        @media(max-width:900px){ .wcpu-grupos-layout{ flex-direction:column; } }
        </style>

        <script>
        (function($){
            var AJAX     = '<?php echo esc_js( $ajax ); ?>';
            var NONCE    = '<?php echo esc_js( wp_create_nonce( 'wcpu_nonce' ) ); ?>';

            /* ── Cargar provincias al cambiar departamento ── */
            $('#wcpu-g-dep').on('change', function(){
                var dep = $(this).val();
                var $prov = $('#wcpu-g-prov');
                $prov.prop('disabled', true).html('<option value="">Cargando...</option>');
                $('#wcpu-list-available').html('<li class="wcpu-dnd-empty">Selecciona Provincia</li>');
                if (!dep) { $prov.html('<option value="">— Provincia —</option>').prop('disabled',true); return; }
                $.post(AJAX, { action:'wcpu_get_provincias', nonce:NONCE, dep_code:dep }, function(r){
                    $prov.html('<option value="">— Provincia —</option>');
                    if(r.success) $.each(r.data, function(k,v){ $prov.append('<option value="'+k+'">'+v+'</option>'); });
                    $prov.prop('disabled', false);
                });
            });

            /* ── Cargar distritos al cambiar provincia ── */
            $('#wcpu-g-prov').on('change', function(){
                var prov = $(this).val();
                var dep  = $('#wcpu-g-dep').val();
                var $list = $('#wcpu-list-available');
                if (!prov) { $list.html('<li class="wcpu-dnd-empty">Selecciona Provincia</li>'); return; }
                $list.html('<li class="wcpu-dnd-empty">Cargando...</li>');
                $.post(AJAX, { action:'wcpu_get_distritos', nonce:NONCE, prov_code:prov }, function(r){
                    $list.empty();
                    if(r.success && Object.keys(r.data).length > 0) {
                        $.each(r.data, function(code, name){
                            /* Omitir si ya está en el grupo */
                            if( isInGrupo(code) ) return;
                            $list.append( makeItem(code, prov, dep, name, false) );
                        });
                        if($list.children('.wcpu-dnd-item').length === 0){
                            $list.html('<li class="wcpu-dnd-empty">Todos los distritos de esta provincia ya están en el grupo</li>');
                        }
                    } else {
                        $list.html('<li class="wcpu-dnd-empty">Sin distritos</li>');
                    }
                    applySearch();
                });
            });

            /* ── Filtro de búsqueda ── */
            $('#wcpu-g-search').on('input', function(){ applySearch(); });
            function applySearch(){
                var q = $('#wcpu-g-search').val().toLowerCase().trim();
                $('#wcpu-list-available .wcpu-dnd-item').each(function(){
                    var name = $(this).data('label').toLowerCase();
                    $(this).toggle( !q || name.includes(q) );
                });
            }

            /* ── Crear elemento de lista ── */
            function makeItem(code, prov, dep, name, inGrupo){
                var $li = $('<li>')
                    .addClass('wcpu-dnd-item')
                    .attr('draggable', 'true')
                    .attr('data-code',  code)
                    .attr('data-prov',  prov)
                    .attr('data-dep',   dep)
                    .attr('data-label', name);

                var provLabel = $('#wcpu-g-prov option[value="'+prov+'"]').text();
                var depLabel  = $('#wcpu-g-dep  option[value="'+dep+'"]').text();
                var ctx = (depLabel && provLabel) ? depLabel+' / '+provLabel : '';

                $li.html(
                    '<span class="wcpu-drag-handle">⠿</span>' +
                    '<span class="wcpu-item-name">'+escHtml(name)+'</span>' +
                    (ctx ? '<span class="wcpu-item-ctx">'+escHtml(ctx)+'</span>' : '') +
                    '<button type="button" class="wcpu-remove-item" title="Quitar">✕</button>'
                );

                /* Eventos drag */
                bindDragEvents($li[0]);
                return $li;
            }

            function escHtml(s){ return $('<span>').text(s).html(); }

            /* ── Verificar si un código ya está en el grupo ── */
            function isInGrupo(code){
                return $('#wcpu-list-grupo').find('[data-code="'+code+'"]').length > 0;
            }

            /* ── Actualizar inputs hidden ── */
            function syncHiddenInputs(){
                var $container = $('#wcpu-hidden-inputs').empty();
                $('#wcpu-list-grupo .wcpu-dnd-item').each(function(){
                    var val = $(this).data('code')+'|'+$(this).data('prov')+'|'+$(this).data('dep');
                    $container.append( $('<input>').attr({type:'hidden', name:'distritos[]', value:val}) );
                });
                /* Actualizar badge contador */
                var count = $('#wcpu-list-grupo .wcpu-dnd-item').length;
                $('#wcpu-grupo-count').text(count);
                /* Mostrar/ocultar empty */
                if(count === 0){
                    if(!$('#wcpu-list-grupo .wcpu-dnd-empty').length)
                        $('#wcpu-list-grupo').append('<li class="wcpu-dnd-empty" id="wcpu-grupo-empty">Arrastra distritos aquí</li>');
                } else {
                    $('#wcpu-grupo-empty').remove();
                }
            }

            /* ── Mover ítem al grupo ── */
            function moveToGrupo($item){
                var code = $item.data('code');
                if(isInGrupo(code)) return; /* Anti-duplicado */
                var $clone = $item.clone(false);
                bindDragEvents($clone[0]);
                $('#wcpu-grupo-empty').remove();
                $('#wcpu-list-grupo').append($clone);
                $item.remove();
                syncHiddenInputs();
            }

            /* ── Mover ítem a disponibles ── */
            function moveToAvailable($item){
                $item.remove();
                syncHiddenInputs();
                /* Recargar disponibles si la provincia coincide */
                var prov = $('#wcpu-g-prov').val();
                if(prov) $('#wcpu-g-prov').trigger('change');
            }

            /* ── Botón ▶ — mover seleccionados al grupo ── */
            $('#wcpu-move-right').on('click', function(){
                $('#wcpu-list-available .wcpu-dnd-item.selected').each(function(){
                    moveToGrupo($(this));
                });
            });

            /* ── Botón ◀ — quitar seleccionados del grupo ── */
            $('#wcpu-move-left').on('click', function(){
                $('#wcpu-list-grupo .wcpu-dnd-item.selected').each(function(){
                    moveToAvailable($(this));
                });
            });

            /* ── Botón ➕ Todos ── */
            $('#wcpu-add-all').on('click', function(){
                $('#wcpu-list-available .wcpu-dnd-item:visible').each(function(){
                    moveToGrupo($(this));
                });
            });

            /* ── Botón ✕ Todos ── */
            $('#wcpu-remove-all').on('click', function(){
                if(!confirm('¿Quitar todos los distritos del grupo?')) return;
                $('#wcpu-list-grupo .wcpu-dnd-item').each(function(){
                    $(this).remove();
                });
                syncHiddenInputs();
                var prov=$('#wcpu-g-prov').val(); if(prov) $('#wcpu-g-prov').trigger('change');
            });

            /* ── Click para seleccionar (toggle) ── */
            $(document).on('click', '.wcpu-dnd-item', function(e){
                if($(e.target).hasClass('wcpu-remove-item')) return;
                $(this).toggleClass('selected');
            });

            /* ── Botón ✕ individual ── */
            $(document).on('click', '.wcpu-remove-item', function(){
                var $item = $(this).closest('.wcpu-dnd-item');
                var zone  = $item.closest('.wcpu-dnd-list').data('zone');
                if(zone === 'grupo') moveToAvailable($item);
                else $item.remove();
                syncHiddenInputs();
            });

            /* ══ DRAG & DROP ══════════════════════════════════════════ */
            var dragSrc = null;

            function bindDragEvents(el){
                el.addEventListener('dragstart', function(e){
                    dragSrc = el;
                    el.classList.add('dragging');
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', el.dataset.code);
                });
                el.addEventListener('dragend', function(){
                    el.classList.remove('dragging');
                    document.querySelectorAll('.wcpu-drop-zone').forEach(function(z){
                        z.classList.remove('wcpu-drag-over');
                    });
                    dragSrc = null;
                });
            }

            /* Eventos sobre las zonas de drop */
            document.querySelectorAll('.wcpu-drop-zone').forEach(function(zone){
                zone.addEventListener('dragover', function(e){
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    zone.classList.add('wcpu-drag-over');
                });
                zone.addEventListener('dragleave', function(e){
                    if(!zone.contains(e.relatedTarget)) zone.classList.remove('wcpu-drag-over');
                });
                zone.addEventListener('drop', function(e){
                    e.preventDefault();
                    zone.classList.remove('wcpu-drag-over');
                    if(!dragSrc) return;

                    var targetZone = zone.dataset.zone;
                    var srcZone    = dragSrc.closest('.wcpu-dnd-list').dataset.zone;

                    if(targetZone === 'grupo' && srcZone === 'available'){
                        /* Disponible → Grupo */
                        moveToGrupo($(dragSrc));
                    } else if(targetZone === 'available' && srcZone === 'grupo'){
                        /* Grupo → Disponible */
                        moveToAvailable($(dragSrc));
                    } else if(targetZone === 'grupo' && srcZone === 'grupo'){
                        /* Reordenar dentro del grupo */
                        var $target = $(e.target).closest('.wcpu-dnd-item');
                        if($target.length && $target[0] !== dragSrc){
                            var rect = $target[0].getBoundingClientRect();
                            var mid  = rect.top + rect.height / 2;
                            if(e.clientY < mid){
                                $target.before(dragSrc);
                            } else {
                                $target.after(dragSrc);
                            }
                            syncHiddenInputs();
                        }
                    }
                });
            });

            /* Activar eventos drag en items ya existentes (modo edición) */
            document.querySelectorAll('.wcpu-dnd-item').forEach(function(el){
                bindDragEvents(el);
            });

            /* ── Sincronizar al cargar ── */
            syncHiddenInputs();

        })(jQuery);
        </script>
        <?php
    }

    /* ══════════════════════════════════════════════════════════════
     *  CRUD
     * ══════════════════════════════════════════════════════════════ */
    public static function save_grupo() {
        check_admin_referer( 'wcpu_grupos_nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Sin permisos.' );

        global $wpdb;
        $grupo_id   = absint( $_POST['grupo_id'] ?? 0 );
        $nombre     = sanitize_text_field( $_POST['nombre']      ?? '' );
        $descripcion= sanitize_text_field( $_POST['descripcion'] ?? '' );
        $distritos  = isset( $_POST['distritos'] ) ? (array) $_POST['distritos'] : array();

        if ( empty( $nombre ) ) {
            wp_redirect( admin_url( 'admin.php?page=wcpu-grupos&msg=error' ) );
            exit;
        }

        $data = array( 'nombre' => $nombre, 'descripcion' => $descripcion );

        if ( $grupo_id ) {
            $wpdb->update( "{$wpdb->prefix}wcpu_grupos", $data, array( 'id' => $grupo_id ) );
        } else {
            $wpdb->insert( "{$wpdb->prefix}wcpu_grupos", array_merge( $data, array( 'enabled' => 1 ) ) );
            $grupo_id = (int) $wpdb->insert_id;
        }

        /* Sincronizar distritos */
        $wpdb->delete( "{$wpdb->prefix}wcpu_grupo_distritos", array( 'grupo_id' => $grupo_id ) );
        $seen = array();
        foreach ( $distritos as $entry ) {
            $parts = explode( '|', sanitize_text_field( $entry ) );
            if ( count( $parts ) < 3 ) continue;
            list( $dist_code, $prov_code, $dep_code ) = $parts;
            if ( isset( $seen[ $dist_code ] ) ) continue; /* Anti-duplicado server-side */
            $seen[ $dist_code ] = true;
            $wpdb->insert( "{$wpdb->prefix}wcpu_grupo_distritos", array(
                'grupo_id'  => $grupo_id,
                'dep_code'  => sanitize_text_field( $dep_code ),
                'prov_code' => sanitize_text_field( $prov_code ),
                'dist_code' => sanitize_text_field( $dist_code ),
            ) );
        }

        wp_redirect( admin_url( "admin.php?page=wcpu-grupos&msg=saved&edit={$grupo_id}" ) );
        exit;
    }

    public static function delete_grupo() {
        check_admin_referer( 'wcpu_grupos_nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Sin permisos.' );

        global $wpdb;
        $id = absint( $_GET['grupo_id'] ?? 0 );
        $wpdb->delete( "{$wpdb->prefix}wcpu_grupo_distritos", array( 'grupo_id' => $id ) );
        /* Desasociar tarifas */
        $wpdb->update( "{$wpdb->prefix}wcpu_shipping_rates", array( 'grupo_id' => null ), array( 'grupo_id' => $id ) );
        $wpdb->delete( "{$wpdb->prefix}wcpu_grupos", array( 'id' => $id ) );
        wp_redirect( admin_url( 'admin.php?page=wcpu-grupos&msg=deleted' ) );
        exit;
    }

    public static function toggle_grupo() {
        check_admin_referer( 'wcpu_grupos_nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Sin permisos.' );

        global $wpdb;
        $id      = absint( $_GET['grupo_id'] ?? 0 );
        $current = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT enabled FROM {$wpdb->prefix}wcpu_grupos WHERE id=%d", $id
        ) );
        $wpdb->update( "{$wpdb->prefix}wcpu_grupos", array( 'enabled' => $current ? 0 : 1 ), array( 'id' => $id ) );
        wp_redirect( admin_url( 'admin.php?page=wcpu-grupos&msg=toggled' ) );
        exit;
    }

    /* AJAX helpers para el formulario */
    public static function ajax_get_distritos() {
        check_ajax_referer( 'wcpu_nonce', 'nonce' );
        $prov = sanitize_text_field( $_POST['prov_code'] ?? '' );
        wp_send_json_success( WCPU_Data::get_distritos( $prov ) );
    }

    public static function ajax_get_members() {
        check_ajax_referer( 'wcpu_nonce', 'nonce' );
        global $wpdb;
        $id   = absint( $_POST['grupo_id'] ?? 0 );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT dist_code FROM {$wpdb->prefix}wcpu_grupo_distritos WHERE grupo_id=%d", $id
        ), ARRAY_A );
        $codes = array_column( $rows, 'dist_code' );
        wp_send_json_success( $codes );
    }

    /* ── Obtener grupos activos para uso en tarifas y shipping method ── */
    public static function get_all_active() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}wcpu_grupos WHERE enabled=1 ORDER BY nombre",
            ARRAY_A
        );
    }

    public static function get_grupo_for_dist( string $dist_code ): ?int {
        global $wpdb;
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT gd.grupo_id FROM {$wpdb->prefix}wcpu_grupo_distritos gd
             INNER JOIN {$wpdb->prefix}wcpu_grupos g ON g.id = gd.grupo_id
             WHERE gd.dist_code = %s AND g.enabled = 1 LIMIT 1",
            $dist_code
        ) );
        return $id ? (int) $id : null;
    }
}
