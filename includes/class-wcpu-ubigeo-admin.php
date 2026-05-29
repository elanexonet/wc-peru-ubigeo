<?php
/**
 * WCPU_Ubigeo_Admin — Panel CRUD de Departamentos, Provincias y Distritos.
 */
defined( 'ABSPATH' ) || exit;

class WCPU_Ubigeo_Admin {

    public static function init() {
        add_action( 'admin_menu',                  array( __CLASS__, 'add_menu' ) );
        add_action( 'admin_post_wcpu_save_dep',    array( __CLASS__, 'save_dep' ) );
        add_action( 'admin_post_wcpu_delete_dep',  array( __CLASS__, 'delete_dep' ) );
        add_action( 'admin_post_wcpu_save_prov',   array( __CLASS__, 'save_prov' ) );
        add_action( 'admin_post_wcpu_delete_prov', array( __CLASS__, 'delete_prov' ) );
        add_action( 'admin_post_wcpu_save_dist',   array( __CLASS__, 'save_dist' ) );
        add_action( 'admin_post_wcpu_delete_dist', array( __CLASS__, 'delete_dist' ) );
        add_action( 'wp_ajax_wcpu_import_ubigeo',  array( __CLASS__, 'ajax_import' ) );
    }

    public static function add_menu() {
        add_submenu_page(
            'woocommerce',
            'Gestión de Ubigeo',
            'Ubigeo',
            'manage_woocommerce',
            'wcpu-ubigeo',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        global $wpdb;
        $sel_dep  = sanitize_text_field( $_GET['dep']  ?? '' );
        $sel_prov = sanitize_text_field( $_GET['prov'] ?? '' );
        $msg      = sanitize_text_field( $_GET['msg']  ?? '' );

        $deps  = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wcpu_departamentos ORDER BY orden,nombre", ARRAY_A );
        $provs = $sel_dep  ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wcpu_provincias WHERE dep_code=%s ORDER BY orden,nombre", $sel_dep ), ARRAY_A ) : array();
        $dists = $sel_prov ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wcpu_distritos WHERE prov_code=%s ORDER BY orden,nombre", $sel_prov ), ARRAY_A ) : array();

        $dep_names  = array_column( $deps,  'nombre', 'code' );
        $prov_names = array_column( $provs, 'nombre', 'code' );

        $msgs = array(
            'dep_saved' => '✓ Departamento guardado.', 'dep_deleted' => '✓ Departamento eliminado.',
            'prov_saved'=> '✓ Provincia guardada.',    'prov_deleted'=> '✓ Provincia eliminada.',
            'dist_saved'=> '✓ Distrito guardado.',     'dist_deleted'=> '✓ Distrito eliminado.',
            'imported'  => '✓ Importación completada.',
        );
        $base = admin_url( 'admin.php?page=wcpu-ubigeo' );
        ?>
        <div class="wrap">
            <h1>🗺 Gestión de Ubigeo</h1>

            <?php if ( $msg && isset( $msgs[$msg] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($msgs[$msg]); ?></p></div>
            <?php endif; ?>

            <p class="wcpu-bread">
                <a href="<?php echo esc_url($base); ?>">📋 Departamentos</a>
                <?php if ( $sel_dep ) echo ' → <a href="'.esc_url($base.'&dep='.$sel_dep).'">'.esc_html($dep_names[$sel_dep]??$sel_dep).'</a>'; ?>
                <?php if ( $sel_prov ) echo ' → '.esc_html($prov_names[$sel_prov]??$sel_prov); ?>
            </p>

            <div class="wcpu-layout">

                <!-- FORMULARIO -->
                <div class="wcpu-card" style="min-width:300px;max-width:360px">
                    <?php if (!$sel_dep): ?>
                    <h2 id="form-title">Agregar Departamento</h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="wcpu_save_dep">
                        <?php wp_nonce_field('wcpu_ubigeo_nonce'); ?>
                        <input type="hidden" name="dep_id" id="dep_id" value="">
                        <p><label>Código WC *<br><input type="text" name="dep_code" id="dep_code" class="regular-text" maxlength="10" placeholder="LMA, ARE, CAL…" required></label>
                        <span class="description">Código ISO WooCommerce</span></p>
                        <p><label>Nombre *<br><input type="text" name="dep_nombre" id="dep_nombre" class="regular-text" required></label></p>
                        <p><label>Orden<br><input type="number" name="dep_orden" id="dep_orden" value="0" style="width:80px"></label></p>
                        <p><button type="submit" class="button button-primary" id="dep_btn">➕ Guardar</button>
                           <button type="button" class="button" id="dep_cancel" style="display:none">✕ Cancelar</button></p>
                    </form>

                    <?php elseif(!$sel_prov): ?>
                    <h2 id="form-title">Agregar Provincia</h2>
                    <p><em><?php echo esc_html($dep_names[$sel_dep]??$sel_dep); ?></em></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="wcpu_save_prov">
                        <?php wp_nonce_field('wcpu_ubigeo_nonce'); ?>
                        <input type="hidden" name="dep_code" value="<?php echo esc_attr($sel_dep); ?>">
                        <input type="hidden" name="prov_id" id="prov_id" value="">
                        <p><label>Nombre *<br><input type="text" name="prov_nombre" id="prov_nombre" class="regular-text" required></label></p>
                        <p><label>Orden<br><input type="number" name="prov_orden" id="prov_orden" value="0" style="width:80px"></label></p>
                        <p><button type="submit" class="button button-primary" id="prov_btn">➕ Guardar</button>
                           <button type="button" class="button" id="prov_cancel" style="display:none">✕ Cancelar</button></p>
                    </form>

                    <?php else: ?>
                    <h2 id="form-title">Agregar Distrito</h2>
                    <p><em><?php echo esc_html($prov_names[$sel_prov]??$sel_prov); ?></em></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="wcpu_save_dist">
                        <?php wp_nonce_field('wcpu_ubigeo_nonce'); ?>
                        <input type="hidden" name="dep_code"  value="<?php echo esc_attr($sel_dep); ?>">
                        <input type="hidden" name="prov_code" value="<?php echo esc_attr($sel_prov); ?>">
                        <input type="hidden" name="dist_id" id="dist_id" value="">
                        <p><label>Nombre *<br><input type="text" name="dist_nombre" id="dist_nombre" class="regular-text" required></label></p>
                        <p><label>Orden<br><input type="number" name="dist_orden" id="dist_orden" value="0" style="width:80px"></label></p>
                        <p><button type="submit" class="button button-primary" id="dist_btn">➕ Guardar</button>
                           <button type="button" class="button" id="dist_cancel" style="display:none">✕ Cancelar</button></p>
                    </form>
                    <?php endif; ?>

                    <hr>
                    <h3>📥 Importación masiva</h3>
                    <p class="description">
                        <strong>JSON:</strong> <code>[{"dep":"LMA","prov":"Lima","dist":"Miraflores"}]</code><br>
                        <strong>CSV:</strong> <code>dep_code,prov_nombre,dist_nombre</code>
                    </p>
                    <textarea id="import-data" rows="5" style="width:100%;font-family:monospace;font-size:11px"></textarea>
                    <p>
                        <select id="import-fmt"><option value="json">JSON</option><option value="csv">CSV</option></select>
                        <button type="button" class="button" id="import-btn"
                            data-nonce="<?php echo esc_attr(wp_create_nonce('wcpu_import_nonce')); ?>">📥 Importar</button>
                        <span id="import-msg" style="margin-left:8px;font-size:13px"></span>
                    </p>
                </div>

                <!-- TABLA -->
                <div class="wcpu-card" style="flex:1;overflow-x:auto">
                    <?php if (!$sel_dep): ?>
                    <h2>Departamentos (<?php echo count($deps); ?>)</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead><tr><th width="80">Código</th><th>Nombre</th><th width="60">Orden</th><th width="100">Provincias</th><th width="200">Acciones</th></tr></thead>
                        <tbody>
                        <?php foreach($deps as $d):
                            $np = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wcpu_provincias WHERE dep_code=%s",$d['code']));
                            $url_prov = $base.'&dep='.$d['code'];
                            $url_del  = wp_nonce_url(admin_url("admin-post.php?action=wcpu_delete_dep&dep_id={$d['id']}"),'wcpu_ubigeo_nonce');
                        ?>
                        <tr data-id="<?php echo $d['id']; ?>" data-code="<?php echo esc_attr($d['code']); ?>" data-nombre="<?php echo esc_attr($d['nombre']); ?>" data-orden="<?php echo $d['orden']; ?>">
                            <td><code><?php echo esc_html($d['code']); ?></code></td>
                            <td><?php echo esc_html($d['nombre']); ?></td>
                            <td><?php echo $d['orden']; ?></td>
                            <td><a href="<?php echo esc_url($url_prov); ?>"><?php echo $np; ?></a></td>
                            <td>
                                <button class="button button-small wcpu-edit" data-type="dep">✏️</button>
                                <a href="<?php echo esc_url($url_del); ?>" class="button button-small" onclick="return confirm('¿Eliminar departamento y todos sus datos?')">🗑️</a>
                                <a href="<?php echo esc_url($url_prov); ?>" class="button button-small">📂 Provincias</a>
                            </td>
                        </tr>
                        <?php endforeach; if(empty($deps)): ?>
                            <tr><td colspan="5" style="text-align:center;padding:20px;color:#999">Sin datos. El plugin pre-carga los datos al activarse.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>

                    <?php elseif(!$sel_prov): ?>
                    <h2>Provincias — <?php echo esc_html($dep_names[$sel_dep]??$sel_dep); ?> (<?php echo count($provs); ?>)</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead><tr><th width="120">Código</th><th>Nombre</th><th width="60">Orden</th><th width="90">Distritos</th><th width="180">Acciones</th></tr></thead>
                        <tbody>
                        <?php foreach($provs as $p):
                            $nd = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wcpu_distritos WHERE prov_code=%s",$p['code']));
                            $url_dist = $base.'&dep='.$sel_dep.'&prov='.$p['code'];
                            $url_del  = wp_nonce_url(admin_url("admin-post.php?action=wcpu_delete_prov&prov_id={$p['id']}&dep={$sel_dep}"),'wcpu_ubigeo_nonce');
                        ?>
                        <tr data-id="<?php echo $p['id']; ?>" data-code="<?php echo esc_attr($p['code']); ?>" data-nombre="<?php echo esc_attr($p['nombre']); ?>" data-orden="<?php echo $p['orden']; ?>">
                            <td><code><?php echo esc_html($p['code']); ?></code></td>
                            <td><?php echo esc_html($p['nombre']); ?></td>
                            <td><?php echo $p['orden']; ?></td>
                            <td><a href="<?php echo esc_url($url_dist); ?>"><?php echo $nd; ?></a></td>
                            <td>
                                <button class="button button-small wcpu-edit" data-type="prov">✏️</button>
                                <a href="<?php echo esc_url($url_del); ?>" class="button button-small" onclick="return confirm('¿Eliminar provincia y sus distritos?')">🗑️</a>
                                <a href="<?php echo esc_url($url_dist); ?>" class="button button-small">📂 Distritos</a>
                            </td>
                        </tr>
                        <?php endforeach; if(empty($provs)): ?>
                            <tr><td colspan="5" style="text-align:center;padding:20px;color:#999">Sin provincias.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>

                    <?php else: ?>
                    <h2>Distritos — <?php echo esc_html($prov_names[$sel_prov]??$sel_prov); ?> (<?php echo count($dists); ?>)</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead><tr><th width="140">Código</th><th>Nombre</th><th width="60">Orden</th><th width="100">Acciones</th></tr></thead>
                        <tbody>
                        <?php foreach($dists as $d):
                            $url_del = wp_nonce_url(admin_url("admin-post.php?action=wcpu_delete_dist&dist_id={$d['id']}&dep={$sel_dep}&prov={$sel_prov}"),'wcpu_ubigeo_nonce');
                        ?>
                        <tr data-id="<?php echo $d['id']; ?>" data-nombre="<?php echo esc_attr($d['nombre']); ?>" data-orden="<?php echo $d['orden']; ?>">
                            <td><code><?php echo esc_html($d['code']); ?></code></td>
                            <td><?php echo esc_html($d['nombre']); ?></td>
                            <td><?php echo $d['orden']; ?></td>
                            <td>
                                <button class="button button-small wcpu-edit" data-type="dist">✏️</button>
                                <a href="<?php echo esc_url($url_del); ?>" class="button button-small" onclick="return confirm('¿Eliminar distrito?')">🗑️</a>
                            </td>
                        </tr>
                        <?php endforeach; if(empty($dists)): ?>
                            <tr><td colspan="4" style="text-align:center;padding:20px;color:#999">Sin distritos.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>

            </div><!-- .wcpu-layout -->
        </div>

        <style>
        .wcpu-layout{display:flex;gap:20px;margin-top:16px;align-items:flex-start;flex-wrap:wrap}
        .wcpu-card{background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px}
        .wcpu-bread{font-size:14px;margin:8px 0}
        .wcpu-bread a{text-decoration:none}
        @media(max-width:900px){.wcpu-card:first-child{max-width:100%;min-width:100%!important}}
        </style>

        <script>
        jQuery(function($){
            /* Editar en línea */
            $(document).on('click','.wcpu-edit',function(){
                var t=$(this).data('type'), $tr=$(this).closest('tr');
                if(t==='dep'){
                    $('#dep_id').val($tr.data('id'));
                    $('#dep_code').val($tr.data('code'));
                    $('#dep_nombre').val($tr.data('nombre'));
                    $('#dep_orden').val($tr.data('orden'));
                    $('#dep_btn').text('💾 Actualizar');
                    $('#dep_cancel').show();
                } else if(t==='prov'){
                    $('#prov_id').val($tr.data('id'));
                    $('#prov_nombre').val($tr.data('nombre'));
                    $('#prov_orden').val($tr.data('orden'));
                    $('#prov_btn').text('💾 Actualizar');
                    $('#prov_cancel').show();
                } else {
                    $('#dist_id').val($tr.data('id'));
                    $('#dist_nombre').val($tr.data('nombre'));
                    $('#dist_orden').val($tr.data('orden'));
                    $('#dist_btn').text('💾 Actualizar');
                    $('#dist_cancel').show();
                }
                $('html,body').animate({scrollTop:0},200);
            });
            $('#dep_cancel').on('click',function(){ $('#dep_id,#dep_code,#dep_nombre').val(''); $('#dep_orden').val(0); $('#dep_btn').text('➕ Guardar'); $(this).hide(); });
            $('#prov_cancel').on('click',function(){ $('#prov_id,#prov_nombre').val(''); $('#prov_orden').val(0); $('#prov_btn').text('➕ Guardar'); $(this).hide(); });
            $('#dist_cancel').on('click',function(){ $('#dist_id,#dist_nombre').val(''); $('#dist_orden').val(0); $('#dist_btn').text('➕ Guardar'); $(this).hide(); });

            /* Importación */
            $('#import-btn').on('click',function(){
                var raw=$('#import-data').val().trim(), fmt=$('#import-fmt').val();
                if(!raw){ $('#import-msg').text('Pega los datos primero.'); return; }
                var $b=$(this).prop('disabled',true).text('Importando...');
                $.post(ajaxurl,{action:'wcpu_import_ubigeo',nonce:$(this).data('nonce'),data:raw,format:fmt},function(r){
                    $b.prop('disabled',false).text('📥 Importar');
                    if(r.success){ $('#import-msg').css('color','green').text('✓ '+r.data.imported+' registro(s).'); setTimeout(function(){location.reload()},1500); }
                    else { $('#import-msg').css('color','red').text('✗ '+(r.data||'Error')); }
                });
            });
        });
        </script>
        <?php
    }

    /* CRUD handlers (iguales a la versión anterior, simplificados) */
    public static function save_dep() {
        check_admin_referer('wcpu_ubigeo_nonce');
        if(!current_user_can('manage_woocommerce')) wp_die('Sin permisos.');
        global $wpdb;
        $id=$wpdb->prefix.'wcpu_departamentos'; $data=array('code'=>strtoupper(sanitize_text_field($_POST['dep_code']??'')),'nombre'=>sanitize_text_field($_POST['dep_nombre']??''),'orden'=>absint($_POST['dep_orden']??0));
        $rid=absint($_POST['dep_id']??0); $rid?$wpdb->update($id,$data,array('id'=>$rid)):$wpdb->insert($id,$data);
        wp_redirect(admin_url('admin.php?page=wcpu-ubigeo&msg=dep_saved')); exit;
    }
    public static function delete_dep() {
        check_admin_referer('wcpu_ubigeo_nonce');
        if(!current_user_can('manage_woocommerce')) wp_die('Sin permisos.');
        global $wpdb; $id=absint($_GET['dep_id']??0);
        $dep=$wpdb->get_row($wpdb->prepare("SELECT code FROM {$wpdb->prefix}wcpu_departamentos WHERE id=%d",$id));
        if($dep){ $provs=$wpdb->get_col($wpdb->prepare("SELECT code FROM {$wpdb->prefix}wcpu_provincias WHERE dep_code=%s",$dep->code)); foreach($provs as $pc) $wpdb->delete("{$wpdb->prefix}wcpu_distritos",array('prov_code'=>$pc)); $wpdb->delete("{$wpdb->prefix}wcpu_provincias",array('dep_code'=>$dep->code)); $wpdb->delete("{$wpdb->prefix}wcpu_departamentos",array('id'=>$id)); }
        wp_redirect(admin_url('admin.php?page=wcpu-ubigeo&msg=dep_deleted')); exit;
    }
    public static function save_prov() {
        check_admin_referer('wcpu_ubigeo_nonce');
        if(!current_user_can('manage_woocommerce')) wp_die('Sin permisos.');
        global $wpdb; $table=$wpdb->prefix.'wcpu_provincias';
        $id=absint($_POST['prov_id']??0); $dep=strtoupper(sanitize_text_field($_POST['dep_code']??'')); $nombre=sanitize_text_field($_POST['prov_nombre']??''); $orden=absint($_POST['prov_orden']??0);
        if($id){ $wpdb->update($table,array('nombre'=>$nombre,'orden'=>$orden),array('id'=>$id)); } else { $max=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE dep_code=%s",$dep))+1; $wpdb->insert($table,array('code'=>WCPU_Install::gen_prov_code($dep,$max),'dep_code'=>$dep,'nombre'=>$nombre,'orden'=>$orden)); }
        wp_redirect(admin_url("admin.php?page=wcpu-ubigeo&dep={$dep}&msg=prov_saved")); exit;
    }
    public static function delete_prov() {
        check_admin_referer('wcpu_ubigeo_nonce');
        if(!current_user_can('manage_woocommerce')) wp_die('Sin permisos.');
        global $wpdb; $id=absint($_GET['prov_id']??0); $dep=sanitize_text_field($_GET['dep']??'');
        $prov=$wpdb->get_row($wpdb->prepare("SELECT code FROM {$wpdb->prefix}wcpu_provincias WHERE id=%d",$id));
        if($prov){ $wpdb->delete("{$wpdb->prefix}wcpu_distritos",array('prov_code'=>$prov->code)); $wpdb->delete("{$wpdb->prefix}wcpu_provincias",array('id'=>$id)); }
        wp_redirect(admin_url("admin.php?page=wcpu-ubigeo&dep={$dep}&msg=prov_deleted")); exit;
    }
    public static function save_dist() {
        check_admin_referer('wcpu_ubigeo_nonce');
        if(!current_user_can('manage_woocommerce')) wp_die('Sin permisos.');
        global $wpdb; $table=$wpdb->prefix.'wcpu_distritos';
        $id=absint($_POST['dist_id']??0); $dep=strtoupper(sanitize_text_field($_POST['dep_code']??'')); $prov=sanitize_text_field($_POST['prov_code']??''); $nombre=sanitize_text_field($_POST['dist_nombre']??''); $orden=absint($_POST['dist_orden']??0);
        if($id){ $wpdb->update($table,array('nombre'=>$nombre,'orden'=>$orden),array('id'=>$id)); } else { $max=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE prov_code=%s",$prov))+1; $wpdb->insert($table,array('code'=>WCPU_Install::gen_dist_code($prov,$max),'prov_code'=>$prov,'dep_code'=>$dep,'nombre'=>$nombre,'orden'=>$orden)); }
        wp_redirect(admin_url("admin.php?page=wcpu-ubigeo&dep={$dep}&prov={$prov}&msg=dist_saved")); exit;
    }
    public static function delete_dist() {
        check_admin_referer('wcpu_ubigeo_nonce');
        if(!current_user_can('manage_woocommerce')) wp_die('Sin permisos.');
        global $wpdb; $id=absint($_GET['dist_id']??0); $dep=sanitize_text_field($_GET['dep']??''); $prov=sanitize_text_field($_GET['prov']??'');
        $wpdb->delete("{$wpdb->prefix}wcpu_distritos",array('id'=>$id));
        wp_redirect(admin_url("admin.php?page=wcpu-ubigeo&dep={$dep}&prov={$prov}&msg=dist_deleted")); exit;
    }
    public static function ajax_import() {
        check_ajax_referer('wcpu_import_nonce','nonce');
        if(!current_user_can('manage_woocommerce')) wp_send_json_error('Sin permisos.');
        global $wpdb; $raw=stripslashes($_POST['data']??''); $format=sanitize_text_field($_POST['format']??'json'); $items=array();
        if($format==='csv'){ foreach(array_filter(array_map('trim',explode("\n",$raw))) as $line){ if(stripos($line,'dep')===0) continue; $p=str_getcsv($line); if(count($p)>=3) $items[]=array('dep'=>trim($p[0]),'prov'=>trim($p[1]),'dist'=>trim($p[2])); } }
        else { $items=json_decode($raw,true); if(!is_array($items)) wp_send_json_error('JSON inválido.'); }
        $imported=0; $cache=array();
        foreach($items as $item){
            $dep=strtoupper(sanitize_text_field($item['dep']??'')); $pn=sanitize_text_field($item['prov']??''); $dn=sanitize_text_field($item['dist']??'');
            if(!$dep||!$pn) continue;
            if(!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}wcpu_departamentos WHERE code=%s",$dep))) continue;
            $ck=$dep.'|'.$pn;
            if(!isset($cache[$ck])){ $pc=$wpdb->get_var($wpdb->prepare("SELECT code FROM {$wpdb->prefix}wcpu_provincias WHERE dep_code=%s AND nombre=%s",$dep,$pn)); if(!$pc){ $m=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wcpu_provincias WHERE dep_code=%s",$dep))+1; $pc=WCPU_Install::gen_prov_code($dep,$m); $wpdb->insert("{$wpdb->prefix}wcpu_provincias",array('code'=>$pc,'dep_code'=>$dep,'nombre'=>$pn,'orden'=>$m)); } $cache[$ck]=$pc; }
            $pc=$cache[$ck];
            if($dn){ $ex=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}wcpu_distritos WHERE prov_code=%s AND nombre=%s",$pc,$dn)); if(!$ex){ $m=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wcpu_distritos WHERE prov_code=%s",$pc))+1; $dc=WCPU_Install::gen_dist_code($pc,$m); $wpdb->insert("{$wpdb->prefix}wcpu_distritos",array('code'=>$dc,'prov_code'=>$pc,'dep_code'=>$dep,'nombre'=>$dn,'orden'=>$m)); $imported++; } } else $imported++;
        }
        wp_send_json_success(array('imported'=>$imported));
    }
}
