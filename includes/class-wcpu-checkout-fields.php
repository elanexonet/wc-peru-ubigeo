<?php
/**
 * WCPU_Checkout_Fields v6.1 — Fix visitante: combobox state input
 */
defined( 'ABSPATH' ) || exit;

class WCPU_Checkout_Fields {

    public static function init() {
        add_action( 'woocommerce_init', array( __CLASS__, 'register_fields' ) );
        add_action( 'wp_footer',        array( __CLASS__, 'inject_assets' ) );
        add_action( 'woocommerce_admin_order_data_after_billing_address',
                    array( __CLASS__, 'render_admin' ) );
        add_filter( 'woocommerce_get_country_locale', array( __CLASS__, 'reorder_pe_locale' ) );
        add_action( 'wp_ajax_wcpu_set_ubigeo_codes',        array( __CLASS__, 'ajax_set_codes' ) );
        add_action( 'wp_ajax_nopriv_wcpu_set_ubigeo_codes', array( __CLASS__, 'ajax_set_codes' ) );
        add_action(
            'woocommerce_store_api_checkout_update_order_from_request',
            array( __CLASS__, 'store_api_update_order' ),
            10, 2
        );
        /* Forzar que el campo state de Perú sea siempre un select
         * (no combobox) para que funcione igual en modo visitante y logueado */
        add_filter( 'woocommerce_get_country_locale', array( __CLASS__, 'force_pe_state_select' ), 20 );
    }

    public static function force_pe_state_select( array $locale ): array {
        /* type = select fuerza el select nativo en lugar del combobox de texto */
        $locale['PE']['state']['type'] = 'select';
        return $locale;
    }

    public static function ajax_set_codes() {
        $nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
        if ( ! wp_verify_nonce( $nonce, 'wcpu_nonce' ) ) {
            wp_send_json_error( 'Nonce inválido' );
        }
        
        /* ═══════════════════════════════════════════════════════════════
         * FIX CRÍTICO: Asegurar que la sesión existe y está inicializada
         * Esto es crucial para visitantes (guest checkout)
         * ═══════════════════════════════════════════════════════════════ */
        if ( ! WC()->session ) {
            WC()->session = new WC_Session_Handler();
            WC()->session->init();
        } elseif ( ! WC()->session->has_session() ) {
            WC()->session->set_customer_session_cookie( true );
        }
        
        $prov_code = sanitize_text_field( wp_unslash( $_POST['prov_code'] ?? '' ) );
        $dist_code = sanitize_text_field( wp_unslash( $_POST['dist_code'] ?? '' ) );
        WC()->session->set( 'wcpu_prov_code', $prov_code );
        WC()->session->set( 'wcpu_dist_code', $dist_code );
        wp_send_json_success( array( 'prov_code' => $prov_code, 'dist_code' => $dist_code ) );
    }

    public static function reorder_pe_locale( array $locale ): array {
        $locale['PE']['postcode']['priority'] = 80;
        $locale['PE']['city']['priority']     = 72;
        $locale['PE']['phone']['priority']    = 90;
        $locale['PE']['state']['priority']    = 70;
        return $locale;
    }

    public static function store_api_update_order( $order, $request ) {
        if ( ! WC()->session ) return;
        $prov_code = (string) WC()->session->get( 'wcpu_prov_code', '' );
        $dist_code = (string) WC()->session->get( 'wcpu_dist_code', '' );
        if ( $prov_code ) $order->update_meta_data( '_wcpu_prov_code', $prov_code );
        if ( $dist_code ) $order->update_meta_data( '_wcpu_dist_code', $dist_code );
    }

    public static function register_fields() {
        if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) return;

        woocommerce_register_additional_checkout_field( array(
            'id'         => 'wcpu/provincia',
            'label'      => 'Provincia',
            'location'   => 'address',
            'type'       => 'text',
            'required'   => false,
            'attributes' => array( 'autocomplete' => 'off' ),
        ) );
        woocommerce_register_additional_checkout_field( array(
            'id'         => 'wcpu/tipo_comprobante',
            'label'      => 'Tipo de Comprobante',
            'location'   => 'contact',
            'type'       => 'select',
            'required'   => false,
            'options'    => array(
                array( 'value' => 'boleta',  'label' => 'Boleta de Venta' ),
                array( 'value' => 'factura', 'label' => 'Factura' ),
            ),
        ) );
        woocommerce_register_additional_checkout_field( array(
            'id'         => 'wcpu/tipo_doc',
            'label'      => 'Tipo de Documento',
            'location'   => 'contact',
            'type'       => 'select',
            'required'   => false,
            'options'    => array(
                array( 'value' => 'dni', 'label' => 'DNI' ),
                array( 'value' => 'ce',  'label' => 'Carnet de Extranjería' ),
            ),
        ) );
        woocommerce_register_additional_checkout_field( array(
            'id' => 'wcpu/doc_numero', 'label' => 'Número de Documento',
            'location' => 'contact', 'type' => 'text', 'required' => false,
        ) );
        woocommerce_register_additional_checkout_field( array(
            'id' => 'wcpu/doc_nombre', 'label' => 'Nombre completo',
            'location' => 'contact', 'type' => 'text', 'required' => false,
        ) );
        woocommerce_register_additional_checkout_field( array(
            'id' => 'wcpu/ruc', 'label' => 'RUC',
            'location' => 'contact', 'type' => 'text', 'required' => false,
        ) );
        woocommerce_register_additional_checkout_field( array(
            'id' => 'wcpu/razon_social', 'label' => 'Razón Social',
            'location' => 'contact', 'type' => 'text', 'required' => false,
        ) );
    }

    public static function render_admin( $order ) {
        $tipo = $order->get_meta( '_wc_additional_fields_wcpu/tipo_comprobante' );
        if ( ! $tipo ) return;
        $prov = $order->get_meta( '_wc_additional_fields_wcpu/provincia' );
        $dist = $order->get_billing_city();
        echo '<div style="background:#f9f9f9;border:1px solid #dde0e5;border-radius:6px;padding:12px;margin-top:10px;font-size:13px">';
        if ( $prov || $dist ) {
            echo '<strong>🗺 Ubigeo:</strong> ';
            if ( $prov ) echo esc_html( $prov );
            if ( $dist ) echo ' / ' . esc_html( $dist );
            echo '<br>';
        }
        echo '<strong>🧾 Comprobante:</strong> ' . esc_html( strtoupper( $tipo ) ) . '<br>';
        if ( 'boleta' === $tipo ) {
            $tdoc = $order->get_meta( '_wc_additional_fields_wcpu/tipo_doc' ) ?: 'DNI';
            $num  = $order->get_meta( '_wc_additional_fields_wcpu/doc_numero' );
            $nom  = $order->get_meta( '_wc_additional_fields_wcpu/doc_nombre' );
            echo '<strong>' . esc_html( strtoupper( $tdoc ) ) . ':</strong> ' . esc_html( $num ) . '<br>';
            if ( $nom ) echo '<strong>Nombre:</strong> ' . esc_html( $nom );
        } else {
            echo '<strong>RUC:</strong> ' . esc_html( $order->get_meta( '_wc_additional_fields_wcpu/ruc' ) ) . '<br>';
            echo '<strong>Razón Social:</strong> ' . esc_html( $order->get_meta( '_wc_additional_fields_wcpu/razon_social' ) );
        }
        echo '</div>';
    }

    public static function inject_assets() {
        if ( ! is_checkout() ) return;
        $ajax    = esc_js( admin_url( 'admin-ajax.php' ) );
        $nonce   = esc_js( wp_create_nonce( 'wcpu_nonce' ) );
        $has_api = class_exists('WCPU_ApisPeru') && WCPU_ApisPeru::get_token() ? 'true' : 'false';
        ?>
<style id="wcpu-styles">
.wc-block-components-address-form__country { display:none !important; }
#billing .wc-block-components-address-form,
#shipping .wc-block-components-address-form,
.wc-block-components-address-form { display:flex !important; flex-wrap:wrap !important; align-items:flex-start !important; }
.wc-block-components-address-form__country    { order:1;  width:100% !important; }
.wc-block-components-address-form__first_name { order:10; width:calc(50% - 8px) !important; }
.wc-block-components-address-form__last_name  { order:11; width:calc(50% - 8px) !important; }
.wc-block-components-address-form__company    { order:20; width:100% !important; }
.wc-block-components-address-form__address_1  { order:30; width:100% !important; }
.wc-block-components-address-form__address_2  { order:31; width:100% !important; }
.wc-block-components-address-form__state      { order:70 !important; width:100% !important; }
div[class*="-input"]:has([id*="wcpu-provincia"]),
div[class*="-input"]:has([id*="provincia"])    { order:71 !important; width:100% !important; }
.wc-block-components-address-form__city       { order:72 !important; width:100% !important; }
.wc-block-components-address-form__postcode   { order:80 !important; }
.wc-block-components-address-form__phone      { order:90 !important; width:100% !important; }
.wc-block-components-address-form__city { display:flex !important; flex-direction:column !important; }
.wc-block-components-address-form__city > input[type="text"],
.wc-block-components-address-form__city input#billing-city,
.wc-block-components-address-form__city input#shipping-city {
    position:absolute !important; opacity:0 !important;
    pointer-events:none !important; height:0 !important;
    width:0 !important; padding:0 !important; border:none !important;
}
.wc-block-components-address-form__city > label { display:none !important; }
.wc-block-components-address-form__city .wc-blocks-components-select__label { display:block !important; visibility:visible !important; }
div[class*="-input"]:has([id*="provincia"])              { display:none !important; }
body.wcpu-dep div[class*="-input"]:has([id*="provincia"]) { display:flex !important; flex-direction:column !important; }
.wc-block-components-address-form__wcpu-provincia > label { display:none !important; }
#contact .wc-block-components-address-form,
.wc-block-checkout__contact-fields .wc-block-components-address-form { flex-direction:column !important; }
#contact .wc-block-components-address-form > *,
.wc-block-checkout__contact-fields .wc-block-components-address-form > * { width:100% !important; }
div[class*="-input"]:has([id*="tipo_comprobante"]) select,
div[class*="-input"]:has([id*="tipo_comprobante"]) .wc-blocks-components-select { display:none !important; }
div[class*="-input"]:has([id*="tipo_comprobante"]) { margin-top:16px; padding-top:16px; border-top:1px solid #e5e7eb; }
div[class*="-input"]:has([id*="tipo_doc"]),
div[class*="-input"]:has([id*="doc_numero"]),
div[class*="-input"]:has([id*="doc_nombre"]),
div[class*="-input"]:has([id*="ruc"]),
div[class*="-input"]:has([id*="razon_social"]) { display:none !important; }
body.wcpu-boleta  div[class*="-input"]:has([id*="tipo_doc"])     { display:block !important; }
body.wcpu-boleta  div[class*="-input"]:has([id*="doc_numero"])   { display:block !important; }
body.wcpu-boleta  div[class*="-input"]:has([id*="doc_nombre"])   { display:block !important; }
body.wcpu-factura div[class*="-input"]:has([id*="ruc"])          { display:block !important; }
body.wcpu-factura div[class*="-input"]:has([id*="razon_social"]) { display:block !important; }
body.wcpu-boleta.wcpu-ce div[class*="-input"]:has([id*="doc_nombre"]) { display:none !important; }
#wcpu-btn-reniec { display:inline-flex !important; }
body.wcpu-ce #wcpu-btn-reniec { display:none !important; }
.wcpu-btns { display:flex; gap:10px; margin:10px 0 16px; width:100%; }
.wcpu-btn { flex:1; padding:14px 10px; border:1px solid var(--wp--preset--color--field,#767676); border-radius:var(--wp--custom--radius--md,8px); background:var(--wp--preset--color--base,#fff); font-size:14px; cursor:pointer; font-weight:500; transition:all 200ms; }
.wcpu-btn:hover { border-color:var(--wp--preset--color--accent,#0f63e9); color:var(--wp--preset--color--accent,#0f63e9); }
.wcpu-btn.wcpu-on { background:var(--wp--preset--color--accent,#0f63e9); border-color:var(--wp--preset--color--accent,#0f63e9); color:var(--wp--preset--color--base,#fff); font-weight:600; }
.wcpu-api-row { display:flex !important; gap:8px; align-items:stretch; width:100%; }
.wcpu-api-row input { flex:1; min-width:0; }
.wcpu-api-btn { padding:0 14px; height:3em; background:var(--wp--preset--color--accent,#0f63e9); color:#fff; border:none; border-radius:var(--wp--custom--radius--md,8px); font-size:var(--wp--preset--font-size--2-xs,0.746rem); cursor:pointer; font-weight:600; transition:opacity 200ms; }
.wcpu-api-btn:disabled { opacity:.5; cursor:wait; }
.wcpu-msg { display:block; font-size:var(--wp--preset--font-size--2-xs,0.746rem); margin-top:4px; min-height:16px; }
.wcpu-msg.ok   { color:#32873b; }
.wcpu-msg.err  { color:#cc0000; }
.wcpu-msg.warn { color:#7f783e; }
.wcpu-msg.spin { color:#707070; }
	.wc-block-components-address-form__state .wc-blocks-components-select:not(.wcpu-state-wrapper) {
    display: none !important;
}
	/* Oculta el campo input dentro del contenedor de estado */
.wc-block-components-address-form__state > input,
.wc-block-components-address-form__state > label {
    display: none !important;
}

/* Opcional: Si el input tiene un contenedor padre directo que quieres ocultar */
.wc-block-components-text-input.wc-block-components-address-form__state > input {
    display: none !important;
}
	.wc-blocks-components-select.wcpu-state-wrapper {
}
</style>

<script id="wcpu-script">
(function(){
    'use strict';
    var AJAX='<?php echo $ajax; ?>', NONCE='<?php echo $nonce; ?>', HAS_API=<?php echo $has_api; ?>;
    var tipo='boleta', tdoc='dni', depCode='';
    function qs(s,c){ return (c||document).querySelector(s); }
    function qsa(s,c){ return Array.from((c||document).querySelectorAll(s)); }
    function setReactVal(el,val){
        if(!el) return;
        try{ var s=Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype,'value').set; s.call(el,val); }catch(e){ el.value=val; }
        el.dispatchEvent(new Event('input',{bubbles:true}));
        el.dispatchEvent(new Event('change',{bubbles:true}));
    }
    function setReactSelect(el,val){
        if(!el) return;
        try{ var s=Object.getOwnPropertyDescriptor(window.HTMLSelectElement.prototype,'value').set; s.call(el,val); }catch(e){ el.value=val; }
        el.dispatchEvent(new Event('change',{bubbles:true}));
    }
    function setMsg(id,text,cls){ var el=qs('#'+id); if(!el) return; el.textContent=text; el.className='wcpu-msg'+(cls?' '+cls:''); }
    function activateWrapper(inp){
        if(!inp) return;
        var wrap=inp.closest('.wc-block-components-text-input')||inp.parentElement; if(!wrap) return;
        function check(){ wrap.classList.toggle('is-active',inp.value.trim().length>0); }
        inp.addEventListener('input',check); inp.addEventListener('change',check); check();
    }
    function ajax(action,params,cb){
        var fd=new FormData(); fd.append('action',action); fd.append('nonce',NONCE);
        Object.keys(params).forEach(function(k){ fd.append(k,params[k]); });
        fetch(AJAX,{method:'POST',body:fd}).then(function(r){ return r.json(); })
            .then(function(j){ cb(j.success?null:(j.data||'Error'),j.success?j.data:null); })
            .catch(function(e){ cb(e.message||'Error',null); });
    }
    
    /* ═══════════════════════════════════════════════════════════════════════════════
     * FIX CRÍTICO: sendCodesToSession mejorada
     * Ahora fuerza el recalcuado de envíos después de guardar en sesión
     * Esto es crucial para visitantes que usan WC Blocks
     * ═══════════════════════════════════════════════════════════════════════════════ */
    function sendCodesToSession(prov,dist){
        var fd=new FormData(); fd.append('action','wcpu_set_ubigeo_codes'); fd.append('nonce',NONCE);
        fd.append('prov_code',prov||''); fd.append('dist_code',dist||'');
        fetch(AJAX,{method:'POST',body:fd})
            .then(function(r){ return r.json(); })
            .then(function(j){
                if(j.success){
                    /* Forzar que WC Blocks recalcule los envíos */
                    if(window.wp&&window.wp.data&&window.wp.data.dispatch){
                        try{
                            var cartStore=window.wp.data.dispatch('wc/store/cart');
                            if(cartStore&&cartStore.updateCustomerData){
                                cartStore.updateCustomerData();
                            }
                        }catch(e){
                            console.debug('wcpu: No se pudo forzar actualización de envíos:', e);
                        }
                    }
                }
            })
            .catch(function(e){ console.debug('wcpu: Error guardando ubigeo en sesión:', e); });
    }
    
    function clearFormFields(target){
        if(target==='boleta'||target==='all'){ setReactVal(qs('[id*="doc_numero"]'),''); setReactVal(qs('[id*="doc_nombre"]'),''); setMsg('wcpu-dni-msg','',''); }
        if(target==='factura'||target==='all'){ setReactVal(qs('[id*="ruc"]'),''); setReactVal(qs('[id*="razon_social"]'),''); setMsg('wcpu-ruc-msg','',''); }
    }
    function makeSelect(placeholder,labelText){
        var outer=document.createElement('div'); outer.className='wc-blocks-components-select';
        var inner=document.createElement('div'); inner.className='wc-blocks-components-select__container';
        var lbl=document.createElement('label'); lbl.className='wc-blocks-components-select__label'; lbl.textContent=labelText;
        var sel=document.createElement('select'); sel.size=1; sel.className='wc-blocks-components-select__select wcpu-ubigeo-sel'; sel.setAttribute('aria-invalid','false');
        sel.innerHTML='<option value="">'+placeholder+'</option>';
        inner.appendChild(lbl); inner.appendChild(sel);
        inner.insertAdjacentHTML('beforeend','<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" width="24" height="24" class="wc-blocks-components-select__expand" aria-hidden="true" focusable="false"><path d="M17.5 11.6L12 16l-5.5-4.4.9-1.2L12 14l4.5-3.6 1 1.2z"></path></svg>');
        outer.appendChild(inner);
        return {outer:outer,sel:sel};
    }
    function fillSel(sel,data,placeholder,savedCode){
        sel.innerHTML='<option value="">'+placeholder+'</option>';
        var savedName='';
        Object.keys(data).forEach(function(code){ var o=document.createElement('option'); o.value=code; o.textContent=data[code]; if(code===savedCode){o.selected=true;savedName=data[code];} sel.appendChild(o); });
        return savedName;
    }
    /* ── Select visual de Departamento ────────────────────────────
     * Reemplaza el combobox nativo de WC Blocks con un select
     * igual que Provincia y Distrito para consistencia visual. ── */
    function buildDepSelect(){
        /* Buscar el contenedor del state field */
        var stateWrap = qs('.wc-block-components-address-form__state');
        if(!stateWrap) return;
        if(stateWrap.querySelector('.wcpu-dep-sel')) return; /* ya existe */

        /* Obtener los departamentos desde el select o input nativo */
        var deps = {};

        /* Si hay un select nativo (logueado), leer sus opciones */
        var nativeSel = stateWrap.querySelector('select');
        if(nativeSel && nativeSel.options.length > 1){
            Array.from(nativeSel.options).forEach(function(o){
                if(o.value) deps[o.value] = o.text;
            });
        }

        /* Si no hay select nativo, usar la lista hardcodeada de Perú */
        if(Object.keys(deps).length === 0){
            deps = {
                'AMA':'Amazonas','ANC':'Ancash','APU':'Apurímac','ARE':'Arequipa',
                'AYA':'Ayacucho','CAJ':'Cajamarca','CAL':'Callao','CUS':'Cusco',
                'HUV':'Huancavelica','HUC':'Huánuco','ICA':'Ica','JUN':'Junín',
                'LAL':'La Libertad','LAM':'Lambayeque','LMA':'Lima Metropolitana',
                'LIM':'Lima Provincias','LOR':'Loreto','MDD':'Madre de Dios',
                'MOQ':'Moquegua','PAS':'Pasco','PIU':'Piura','PUN':'Puno',
                'SAM':'San Martín','TAC':'Tacna','TUM':'Tumbes','UCA':'Ucayali'
            };
        }

        /* Crear select visual con estructura nativa Gravia */
        var outer = document.createElement('div');
        outer.className = 'wc-blocks-components-select wcpu-state-wrapper';
        var inner = document.createElement('div');
        inner.className = 'wc-blocks-components-select__container';
        var lbl = document.createElement('label');
        lbl.className = 'wc-blocks-components-select__label';
        lbl.textContent = 'Departamento';
        var sel = document.createElement('select');
        sel.size = 1;
        sel.className = 'wc-blocks-components-select__select wcpu-dep-sel';
        sel.setAttribute('aria-invalid','false');
        sel.innerHTML = '<option value="">— Selecciona Departamento —</option>';

        Object.keys(deps).forEach(function(code){
            var o = document.createElement('option');
            o.value = code; o.textContent = deps[code];
            sel.appendChild(o);
        });

        inner.appendChild(lbl); inner.appendChild(sel);
        inner.insertAdjacentHTML('beforeend',
            '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" width="24" height="24"'+
            ' class="wc-blocks-components-select__expand" aria-hidden="true" focusable="false">'+
            '<path d="M17.5 11.6L12 16l-5.5-4.4.9-1.2L12 14l4.5-3.6 1 1.2z"></path></svg>'
        );
        outer.appendChild(inner);
        stateWrap.appendChild(outer);

        /* Listener: al seleccionar departamento → actualizar el input/select nativo de WC */
        sel.addEventListener('change', function(){
            var code = this.value;
            /* Actualizar el select nativo si existe (logueado) */
            var nSel = stateWrap.querySelector('select:not(.wcpu-dep-sel)');
            if(nSel) {
                var setter = Object.getOwnPropertyDescriptor(window.HTMLSelectElement.prototype,'value').set;
                setter.call(nSel, code);
                nSel.dispatchEvent(new Event('change',{bubbles:true}));
            }
            /* Actualizar el input combobox si existe (visitante) */
            var nInp = stateWrap.querySelector('input[type="text"]');
            if(nInp) {
                var setter2 = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype,'value').set;
                setter2.call(nInp, code);
                nInp.dispatchEvent(new Event('input',{bubbles:true}));
                nInp.dispatchEvent(new Event('change',{bubbles:true}));
            }
        });
    }

    function buildProvSelect(dep,savedCode){
        var input=qs('[id$="-wcpu-provincia"]')||qs('[name$="wcpu/provincia"]'); if(!input) return;
        var wrap=input.closest('.wc-block-components-text-input')||input.parentElement; if(!wrap) return;
        var sel=wrap.querySelector('.wcpu-ubigeo-sel');
        if(!sel){
            input.style.display='none';
            var s=makeSelect('','Provincia'); wrap.appendChild(s.outer); sel=s.sel;
            sel.addEventListener('change',function(){
                var code=this.value, name=this.options[this.selectedIndex]?this.options[this.selectedIndex].text:'';
                setReactVal(input,name); input.dataset.code=code;
                resetDist(); document.body.classList.remove('wcpu-prov');
                if(!code) return;
                sendCodesToSession(code,''); buildDistSelect(code,'');
            });
        }
        ajax('wcpu_get_provincias',{dep_code:dep},function(err,data){
            if(err||!data) return;
            var savedName=fillSel(sel,data,'',savedCode||'');
            document.body.classList.add('wcpu-dep');
            if(savedCode&&savedName){ setReactVal(input,savedName); input.dataset.code=savedCode; document.body.classList.add('wcpu-prov'); }
        });
    }
    function buildDistSelect(prov,savedCode){
        var cityInput=qs('#billing-city')||qs('#shipping-city')||qs('[id$="-city"]'); if(!cityInput) return;
        var cityWrap=cityInput.closest('.wc-block-components-address-form__city'); if(!cityWrap) return;
        var sel=cityWrap.querySelector('.wcpu-ubigeo-sel');
        if(!sel){
            var s=makeSelect('','Distrito'); cityWrap.appendChild(s.outer); sel=s.sel;
            sel.addEventListener('change',function(){
                var code=this.value, name=this.options[this.selectedIndex]?this.options[this.selectedIndex].text:'';
                setReactVal(cityInput,name); cityInput.dataset.distCode=code;
                var pi=qs('[id$="-wcpu-provincia"]')||qs('[name$="wcpu/provincia"]');
                sendCodesToSession(pi?(pi.dataset.code||''):'',code);
            });
        }
        ajax('wcpu_get_distritos',{prov_code:prov},function(err,data){
            if(err||!data) return;
            var savedName=fillSel(sel,data,'',savedCode||'');
            if(savedCode&&savedName){ setReactVal(cityInput,savedName); cityInput.dataset.distCode=savedCode; }
        });
    }
    function resetDist(){
        var city=qs('#billing-city')||qs('#shipping-city')||qs('[id$="-city"]');
        if(city){ setReactVal(city,''); city.dataset.distCode=''; }
        var ds=qs('.wc-block-components-address-form__city .wcpu-ubigeo-sel');
        if(ds) ds.innerHTML='<option value=""></option>';
    }

    /* ══ initDepListener — ÚNICO CAMBIO vs v6.0:
     * Soporte para input[type=text] combobox en modo visitante.
     * En modo logueado WC usa <select id="billing-state">.
     * En modo visitante WC usa <input id="shipping-state"> (combobox).
     * El código ISO (LMA, ARE…) llega en el .value del input. ══ */
    var ultimoDep='';

    function isStateEl(el){
        return el&&el.id&&(
            el.id==='billing-state'||
            el.id==='shipping-state'||
            el.id.endsWith('-state')
        );
    }

    /* Leer el código ISO del elemento de departamento.
     * Solo acepta valores cortos tipo LMA/ARE (2-6 letras mayúsculas).
     * Ignora texto largo mientras el usuario escribe en el combobox. */
    function getDepCode(el){
        if(!el) return '';
        var v=el.value||'';
        return (v.length>=2&&v.length<=6&&/^[A-Z]+$/.test(v))?v:'';
    }

    function initDepListener(){
        if(document.body.dataset.wcpuGlobalHook){
            checkDepChange();
            return;
        }
        document.body.dataset.wcpuGlobalHook='true';

        /* mousedown: limpiar React state antes del cambio (para usuarios logueados) */
        document.body.addEventListener('mousedown',function(e){
            if(isStateEl(e.target)){
                limpiarUbigeo();
                ultimoDep=''; depCode='';
            }
        },true);

        /* Eventos DOM estándar */
        ['change','input','blur'].forEach(function(ev){
            document.body.addEventListener(ev,function(e){
                if(!isStateEl(e.target)) return;
                setTimeout(checkDepChange, 300);
            },true);
        });

        /* wp.data.subscribe — la forma correcta de detectar cambios en WC Blocks store.
         * Funciona tanto para logueados como visitantes ya que React actualiza el store
         * siempre que el usuario selecciona una opción válida del combobox. */
        try{
            if(window.wp&&window.wp.data){
                window.wp.data.subscribe(function(){
                    setTimeout(checkDepChange, 50);
                });
            }
        }catch(e){}

        /* Estado inicial */
        checkDepChange();
    }

    function limpiarUbigeo(){
        var pi=qs('[id$="-wcpu-provincia"]')||qs('[name$="wcpu/provincia"]');
        var city=qs('#billing-city')||qs('#shipping-city')||qs('[id$="-city"]');
        if(pi){ setReactVal(pi,''); pi.dataset.code=''; }
        if(city){ setReactVal(city,''); city.dataset.distCode=''; }
        var ps=pi?pi.closest('.wc-block-components-text-input'):null;
        var psel=ps?ps.querySelector('.wcpu-ubigeo-sel'):null;
        if(psel) psel.innerHTML='<option value=""></option>';
        var ds=qs('.wc-block-components-address-form__city .wcpu-ubigeo-sel');
        if(ds) ds.innerHTML='<option value=""></option>';
    }

    /* Leer el código de departamento desde todas las fuentes */
    function readDepFromDOM(){
        /* 1. Select nativo (logueado con select) */
        var sel=qs('select#billing-state')||qs('select#shipping-state');
        if(sel&&sel.value) return sel.value;

        /* 2. wp.data store — fuente más confiable para visitantes con combobox */
        try{
            if(window.wp&&window.wp.data){
                var store=window.wp.data.select('wc/store/cart');
                if(store&&store.getCustomerData){
                    var addr=store.getCustomerData();
                    var s=(addr.shippingAddress&&addr.shippingAddress.state)||
                          (addr.billingAddress&&addr.billingAddress.state)||'';
                    if(s&&s.length<=6) return s;
                }
            }
        }catch(e){}

        /* 3. Input visible como fallback */
        var combo=qs('#billing-state')||qs('#shipping-state')||qs('[id$="-state"]');
        if(combo&&combo.value&&combo.value.length<=6) return combo.value;

        return '';
    }

    function checkDepChange(){
        var v=readDepFromDOM();
        if(v&&v!==ultimoDep) procesarDep(v);
    }

    function procesarDep(v){
        ultimoDep=v; depCode=v;
        limpiarUbigeo();
        document.body.classList.add('wcpu-dep');
        document.body.classList.remove('wcpu-prov');
        buildProvSelect(v,'');
    }

    function buildComprobanteBtn(){
        var sel=qs('[id*="tipo_comprobante"]'); if(!sel) return;
        var wrap=sel.closest('div[class*="-input"]')||sel.parentElement;
        if(!wrap||qs('.wcpu-btns',wrap)) return;
        var div=document.createElement('div'); div.className='wcpu-btns';
        div.innerHTML='<button type="button" class="wcpu-btn" data-val="boleta">🧾 Boleta de Venta</button><button type="button" class="wcpu-btn" data-val="factura">🏢 Factura</button>';
        wrap.appendChild(div);
        div.addEventListener('click',function(e){
            var btn=e.target.closest('.wcpu-btn[data-val]'); if(!btn) return;
            var v=btn.getAttribute('data-val');
            if(v!==tipo) clearFormFields(v==='factura'?'boleta':'factura');
            setReactSelect(sel,v); setTipo(v);
        });
        setTipo(sel.value||'boleta');
    }
    function setTipo(v){
        tipo=v;
        var sel=qs('[id*="tipo_comprobante"]'), wrap=sel&&(sel.closest('div[class*="-input"]')||sel.parentElement);
        if(wrap) qsa('.wcpu-btn',wrap).forEach(function(b){ b.classList.toggle('wcpu-on',b.getAttribute('data-val')===v); });
        document.body.classList.toggle('wcpu-boleta',v==='boleta');
        document.body.classList.toggle('wcpu-factura',v==='factura');
    }
    function monitorTipoDocSelect(){
        var sel=qs('[id*="tipo_doc"]'); if(!sel||sel.dataset.wcpuMonitored) return;
        sel.dataset.wcpuMonitored='true';
        sel.addEventListener('change',function(){ setTipoDoc(this.value||'dni'); setReactVal(qs('[id*="doc_numero"]'),''); setReactVal(qs('[id*="doc_nombre"]'),''); });
        setTipoDoc(sel.value||'dni');
    }
    function setTipoDoc(v){
        tdoc=v;
        document.body.classList.toggle('wcpu-dni',v==='dni'); document.body.classList.toggle('wcpu-ce',v==='ce');
        var inp=qs('[id*="doc_numero"]'), lbl=qs('label[for*="doc_numero"]');
        if(inp) inp.maxLength=v==='dni'?8:12;
        if(lbl) lbl.textContent=v==='dni'?'Número de DNI *':'N.º Carnet de Extranjería *';
    }
    function buildDniBtn(){
        var inp=qs('[id*="doc_numero"]'), wrap=inp&&(inp.closest('div[class*="-input"]')||inp.parentElement);
        if(!wrap||qs('#wcpu-btn-reniec',wrap)) return;
        activateWrapper(inp);
        inp.addEventListener('input',function(){ var clean=tdoc==='dni'?this.value.replace(/\D/g,'').slice(0,8):this.value.slice(0,12); if(this.value!==clean) this.value=clean; });
        var row=document.createElement('div'); row.className='wcpu-api-row';
        inp.parentNode.insertBefore(row,inp); row.appendChild(inp);
        var msgEl=document.createElement('span'); msgEl.id='wcpu-dni-msg'; msgEl.className='wcpu-msg'; wrap.appendChild(msgEl);
        if(HAS_API){
            var btn=document.createElement('button'); btn.type='button'; btn.id='wcpu-btn-reniec'; btn.className='wcpu-api-btn'; btn.textContent='🔍 RENIEC'; row.appendChild(btn);
            function doDni(){
                if(tdoc!=='dni') return;
                var dni=inp.value.trim();
                if(!dni){setMsg('wcpu-dni-msg','Ingresa el número de DNI','err');return;}
                if(!/^\d{8}$/.test(dni)){setMsg('wcpu-dni-msg','El DNI debe tener 8 dígitos','err');return;}
                setMsg('wcpu-dni-msg','⏳ Consultando RENIEC...','spin'); btn.disabled=true;
                ajax('wcpu_consultar_dni',{dni:dni},function(err,data){
                    btn.disabled=false;
                    if(err){setMsg('wcpu-dni-msg','Verificar número ingresado','err');return;}
                    var nombre=data.nombre_completo||data.nombre||'';
                    var nomInp=qs('[id*="doc_nombre"]');
                    if(nomInp&&nombre){ var nw=nomInp.closest('div[class*="-input"]')||nomInp.parentElement; if(nw) nw.classList.add('is-active'); setReactVal(nomInp,nombre); }
                    setMsg('wcpu-dni-msg','✓ DNI Encontrado!','ok');
                });
            }
            btn.addEventListener('click',doDni);
            inp.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();doDni();}});
        }
    }
    function buildRucBtn(){
        var inp=qs('#contact-wcpu-ruc')||qs('[id$="-wcpu-ruc"]'), wrap=inp&&(inp.closest('div[class*="-input"]')||inp.parentElement);
        if(!wrap||qs('#wcpu-btn-sunat',wrap)) return;
        activateWrapper(inp);
        inp.addEventListener('input',function(){ var clean=this.value.replace(/\D/g,'').slice(0,11); if(this.value!==clean) this.value=clean; });
        var row=document.createElement('div'); row.className='wcpu-api-row';
        inp.parentNode.insertBefore(row,inp); row.appendChild(inp);
        var btn=document.createElement('button'); btn.type='button'; btn.id='wcpu-btn-sunat'; btn.className='wcpu-api-btn'; btn.textContent='🔍 SUNAT'; row.appendChild(btn);
        var msgEl=document.createElement('span'); msgEl.id='wcpu-ruc-msg'; msgEl.className='wcpu-msg'; wrap.appendChild(msgEl);
        function doRuc(){
            var ruc=inp.value.trim();
            if(!ruc){setMsg('wcpu-ruc-msg','Ingresa el número de RUC','err');return;}
            if(!/^\d{11}$/.test(ruc)){setMsg('wcpu-ruc-msg','El RUC debe tener 11 dígitos','err');return;}
            setMsg('wcpu-ruc-msg','⏳ Consultando SUNAT...','spin'); btn.disabled=true;
            ajax('wcpu_consultar_ruc',{ruc:ruc},function(err,data){
                btn.disabled=false;
                if(err){setMsg('wcpu-ruc-msg','Verificar número ingresado','err');return;}
                var rsInp=qs('[id*="razon_social"]');
                if(rsInp&&data.razon_social){ var rw=rsInp.closest('div[class*="-input"]')||rsInp.parentElement; if(rw) rw.classList.add('is-active'); setReactVal(rsInp,data.razon_social); }
                var activo=(data.estado||'').toUpperCase()==='ACTIVO';
                setMsg('wcpu-ruc-msg','✓ RUC Encontrado!'+((!activo&&data.estado)?' — '+data.estado:''),activo?'ok':'warn');
            });
        }
        btn.addEventListener('click',doRuc);
        inp.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();doRuc();}});
    }
    document.body.classList.add('wcpu-boleta','wcpu-dni');
    setInterval(function(){
        buildDepSelect();
        initDepListener();
        buildComprobanteBtn();
        monitorTipoDocSelect();
        buildDniBtn();
        buildRucBtn();
    },500);
})();
</script>
        <?php
    }
}
