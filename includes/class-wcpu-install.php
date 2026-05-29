<?php
/**
 * WCPU_Install — Crea/actualiza las tablas de BD del plugin.
 *
 * Tablas:
 *  {prefix}wcpu_departamentos  — Departamentos (código WC nativo + nombre)
 *  {prefix}wcpu_provincias     — Provincias por departamento
 *  {prefix}wcpu_distritos      — Distritos por provincia
 *  {prefix}wcpu_shipping_rates — Tarifas de envío por ubigeo
 */
defined( 'ABSPATH' ) || exit;

class WCPU_Install {

    const DB_VERSION     = '5.0';
    const DB_VERSION_KEY = 'wcpu_db_version';

    public static function run(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        /* ── Departamentos ── */
        dbDelta( "CREATE TABLE {$wpdb->prefix}wcpu_departamentos (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            code       VARCHAR(10)  NOT NULL UNIQUE COMMENT 'Código WC/ISO (ej: LMA, ARE)',
            nombre     VARCHAR(120) NOT NULL,
            orden      INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_code (code)
        ) {$charset};" );

        /* ── Provincias ── */
        dbDelta( "CREATE TABLE {$wpdb->prefix}wcpu_provincias (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            code       VARCHAR(20)  NOT NULL UNIQUE COMMENT 'Código auto (ej: LMA-01)',
            dep_code   VARCHAR(10)  NOT NULL COMMENT 'FK a wcpu_departamentos.code',
            nombre     VARCHAR(120) NOT NULL,
            orden      INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_dep (dep_code),
            KEY idx_code (code)
        ) {$charset};" );

        /* ── Distritos ── */
        dbDelta( "CREATE TABLE {$wpdb->prefix}wcpu_distritos (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            code       VARCHAR(30)  NOT NULL UNIQUE COMMENT 'Código auto (ej: LMA-01-001)',
            prov_code  VARCHAR(20)  NOT NULL COMMENT 'FK a wcpu_provincias.code',
            dep_code   VARCHAR(10)  NOT NULL COMMENT 'Desnormalizado para queries rápidas',
            nombre     VARCHAR(120) NOT NULL,
            orden      INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_prov (prov_code),
            KEY idx_dep  (dep_code),
            KEY idx_code (code)
        ) {$charset};" );

        /* ── Tarifas de envío ── */
        dbDelta( "CREATE TABLE {$wpdb->prefix}wcpu_shipping_rates (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            dep_code     VARCHAR(10)  NOT NULL DEFAULT '',
            prov_code    VARCHAR(20)  NOT NULL DEFAULT '',
            dist_code    VARCHAR(30)  NOT NULL DEFAULT '',
            label        VARCHAR(120) NOT NULL DEFAULT '',
            cost         DECIMAL(10,2) NOT NULL DEFAULT '0.00',
            free_above   DECIMAL(10,2) NOT NULL DEFAULT '0.00',
            enabled      TINYINT(1)   NOT NULL DEFAULT 1,
            notes        VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY idx_ubigeo (dep_code, prov_code, dist_code)
        ) {$charset};" );

        /* Si venimos de una versión anterior, limpiar y re-insertar datos */
        $current_version = get_option( self::DB_VERSION_KEY, '0' );
        if ( $current_version !== self::DB_VERSION ) {
            /* Versión nueva: truncar y re-sembrar */
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wcpu_departamentos" );
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wcpu_provincias" );
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wcpu_distritos" );
        }

        update_option( self::DB_VERSION_KEY, self::DB_VERSION );

        /* Pre-cargar datos */
        self::seed_data();
    }

    public static function needs_update(): bool {
        return get_option( self::DB_VERSION_KEY, '0' ) !== self::DB_VERSION;
    }

    /**
     * Forzar re-carga de datos (borrar y reinsertar).
     * Llamar desde admin cuando los datos estén inconsistentes.
     */
    public static function force_reseed(): void {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wcpu_departamentos" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wcpu_provincias" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wcpu_distritos" );
        self::seed_data();
    }

    /* ══════════════════════════════════════════════════════════════
     *  SEED — Pre-cargar todos los departamentos, provincias y distritos
     * ══════════════════════════════════════════════════════════════ */
    private static function seed_data(): void {
        global $wpdb;

        /* Solo si no hay datos aún */
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wcpu_departamentos" );
        if ( $count > 0 ) return;

        $data = self::get_seed_data();

        $orden_dep = 1;
        foreach ( $data as $dep_code => $dep ) {
            $wpdb->replace( "{$wpdb->prefix}wcpu_departamentos", array(
                'code'   => $dep_code,
                'nombre' => $dep['nombre'],
                'orden'  => $orden_dep++,
            ) );

            $orden_prov = 1;
            foreach ( $dep['provincias'] as $prov_nombre => $distritos ) {
                $prov_code = self::gen_prov_code( $dep_code, $orden_prov );
                $wpdb->replace( "{$wpdb->prefix}wcpu_provincias", array(
                    'code'     => $prov_code,
                    'dep_code' => $dep_code,
                    'nombre'   => $prov_nombre,
                    'orden'    => $orden_prov++,
                ) );

                $orden_dist = 1;
                foreach ( $distritos as $dist_nombre ) {
                    $dist_code = self::gen_dist_code( $prov_code, $orden_dist );
                    $wpdb->replace( "{$wpdb->prefix}wcpu_distritos", array(
                        'code'      => $dist_code,
                        'prov_code' => $prov_code,
                        'dep_code'  => $dep_code,
                        'nombre'    => $dist_nombre,
                        'orden'     => $orden_dist++,
                    ) );
                }
            }
        }

        /* Tarifa nacional por defecto */
        $rate_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wcpu_shipping_rates" );
        if ( $rate_count === 0 ) {
            $wpdb->insert( "{$wpdb->prefix}wcpu_shipping_rates", array(
                'dep_code'   => '*',
                'prov_code'  => '',
                'dist_code'  => '',
                'label'      => 'Envío nacional',
                'cost'       => 15.00,
                'free_above' => 0.00,
                'enabled'    => 1,
                'notes'      => 'Tarifa por defecto para todo el Perú',
            ) );
        }
    }

    /* ── Generar código de provincia: DEP-01, DEP-02 ... ── */
    public static function gen_prov_code( string $dep_code, int $orden ): string {
        return $dep_code . '-' . str_pad( $orden, 2, '0', STR_PAD_LEFT );
    }

    /* ── Generar código de distrito: PROV-001, PROV-002 ... ── */
    public static function gen_dist_code( string $prov_code, int $orden ): string {
        return $prov_code . '-' . str_pad( $orden, 3, '0', STR_PAD_LEFT );
    }

    /* ══════════════════════════════════════════════════════════════
     *  DATOS INICIALES COMPLETOS
     * ══════════════════════════════════════════════════════════════ */
    private static function get_seed_data(): array {
        return array(
            'LMA' => array( 'nombre' => 'Lima Metropolitana', 'provincias' => array(
                'Lima' => array(
                    'Lima (Cercado)','Ancón','Ate','Barranco','Breña','Carabayllo',
                    'Chaclacayo','Chorrillos','Cieneguilla','Comas','El Agustino',
                    'Independencia','Jesús María','La Molina','La Victoria','Lince',
                    'Los Olivos','Lurigancho - Chosica','Lurín','Magdalena del Mar',
                    'Miraflores','Pachacámac','Pucusana','Pueblo Libre','Puente Piedra',
                    'Punta Hermosa','Punta Negra','Rímac','San Bartolo','San Borja',
                    'San Isidro','San Juan de Lurigancho','San Juan de Miraflores',
                    'San Luis','San Martín de Porres','San Miguel','Santa Anita',
                    'Santa María del Mar','Santa Rosa','Santiago de Surco','Surquillo',
                    'Villa El Salvador','Villa María del Triunfo',
                ),
            ) ),
            'LIM' => array( 'nombre' => 'Lima Provincias', 'provincias' => array(
                'Barranca'   => array('Barranca','Ámbar','Paramonga','Pativilca','Supe','Supe Puerto'),
                'Cajatambo'  => array('Cajatambo','Copa','Gorgor','Huancapón','Manas'),
                'Canta'      => array('Canta','Arahuay','Huamantanga','Huaros','Lachaqui','San Buenaventura','Santa Rosa de Quives'),
                'Cañete'     => array('San Vicente de Cañete','Asia','Calango','Cerro Azul','Chilca','Coayllo','Imperial','Lunahuaná','Mala','Nuevo Imperial','Pacarán','Quilmaná','San Antonio','San Luis','Santa Cruz de Flores','Zúñiga'),
                'Huaral'     => array('Huaral','Atavillos Alto','Atavillos Bajo','Aucallama','Chancay','Ihuarí','Lampián','Pacaraos','San Miguel de Acos','Santa Cruz de Andamarca','Sumbilca','Veintisiete de Noviembre'),
                'Huarochirí' => array('Matucana','Antioquia','Callahuanca','Carampoma','Chicla','Cuenca','Huachupampa','Huanza','Huarochirí','Lahuaytambo','Langa','Laraos','Mariatana','Ricardo Palma','San Andrés de Tupicocha','San Antonio','San Bartolomé','San Damián','San Juan de Iris','San Juan de Tantaranche','San Lorenzo de Quinti','San Mateo','San Mateo de Otao','San Pedro de Casta','San Pedro de Huancayre','Sangallaya','Santa Cruz de Cocachacra','Santa Eulalia','Santiago de Anchucaya','Santiago de Tuna','Santo Domingo de Los Olleros','Surco'),
                'Huaura'     => array('Huacho','Ambar','Caleta de Carquín','Checras','Hualmay','Huaura','Leoncio Prado','Paccho','Santa Leonor','Santa María','Sayán','Vegueta'),
                'Oyón'       => array('Oyón','Andajes','Caujul','Cochamarca','Navan','Pachangara'),
                'Yauyos'     => array('Yauyos','Alis','Allauca','Ayauca','Ayaviri','Azángaro','Cacra','Carania','Catahuasi','Chocos','Cochas','Colonia','Hongos','Huampará','Huancaya','Huangáscar','Huañec','Laraos','Lincha','Madean','Miraflores','Omas','Putinza','Quinches','Quinocay','San Joaquín','San Pedro de Pilas','Tanta','Tauripampa','Tomas','Tupe','Viñac','Vitis'),
            ) ),
            'CAL' => array( 'nombre' => 'Callao', 'provincias' => array(
                'Callao' => array('Callao','Bellavista','Carmen de La Legua Reynoso','La Perla','La Punta','Ventanilla','Mi Perú'),
            ) ),
            'ARE' => array( 'nombre' => 'Arequipa', 'provincias' => array(
                'Arequipa'    => array('Arequipa','Alto Selva Alegre','Cayma','Cerro Colorado','Characato','Chiguata','Jacobo Hunter','José Luis Bustamante y Rivero','La Joya','Mariano Melgar','Miraflores','Mollebaya','Paucarpata','Pocsi','Polobaya','Quequeña','Sabandia','Sachaca','San Juan de Siguas','San Juan de Tarucani','Santa Isabel de Siguas','Santa Rita de Siguas','Socabaya','Tiabaya','Uchumayo','Vitor','Yanahuara','Yarabamba','Yura'),
                'Camaná'      => array('Camaná','José María Quimper','Mariscal Cáceres','Nicolás de Piérola','Ocoña','Quilca','Samuel Pastor'),
                'Caravelí'    => array('Caravelí','Acarí','Atico','Atiquipa','Bella Unión','Cahuacho','Chala','Chaparra','Huanuhuanu','Jaqui','Lomas','Quicacha','Yauca'),
                'Castilla'    => array('Aplao','Andagua','Ayo','Chachas','Chilcaymarca','Choco','Huancarqui','Machaguay','Orcopampa','Pampacolca','Tipan','Uñon','Uraca','Viraco'),
                'Caylloma'    => array('Chivay','Achoma','Cabanaconde','Callalli','Caylloma','Coporaque','Huambo','Huanca','Ichupampa','Lari','Lluta','Maca','Madrigal','San Antonio de Chuca','Sibayo','Tapay','Tisco','Tuti','Yanque','Majes'),
                'Condesuyos'  => array('Chuquibamba','Andaray','Cayarani','Chichas','Iray','Río Grande','Salamanca','Yanaquihua'),
                'Islay'       => array('Mollendo','Cocachacra','Dean Valdivia','Islay','Mejía','Punta de Bombón'),
                'La Unión'    => array('Cotahuasi','Alca','Charcana','Huaynacotas','Pampamarca','Puyca','Quechualla','Sayla','Tauria','Tomepampa','Toro'),
            ) ),
            'CUS' => array( 'nombre' => 'Cusco', 'provincias' => array(
                'Cusco'         => array('Cusco','Ccorca','Poroy','San Jerónimo','San Sebastián','Santiago','Saylla','Wanchaq'),
                'Acomayo'       => array('Acomayo','Acopia','Acos','Mosoc Llacta','Pomacanchi','Rondocan','Sangarará'),
                'Anta'          => array('Anta','Ancahuasi','Cachimayo','Chinchaypujio','Huarocondo','Limatambo','Mollepata','Pucyura','Zurite'),
                'Calca'         => array('Calca','Coya','Lamay','Lares','Pisac','San Salvador','Taray','Yanatile'),
                'Canas'         => array('Yanaoca','Checca','Kunturkanki','Langui','Layo','Pampamarca','Quehue','Túpac Amaru'),
                'Canchis'       => array('Sicuani','Checacupe','Combapata','Maranganí','Pitumarca','San Pablo','San Pedro','Tinta'),
                'Chumbivilcas'  => array('Santo Tomás','Capacmarca','Chamaca','Colquemarca','Livitaca','Llusco','Quiñota','Velille'),
                'Espinar'       => array('Espinar','Condoroma','Coporaque','Ocoruro','Pallpata','Pichigua','Suyckutambo','Alto Pichigua'),
                'La Convención' => array('Santa Ana','Echarate','Huayopata','Maranura','Ocobamba','Quellouno','Kimbiri','Santa Teresa','Vilcabamba','Pichari','Inkawasi','Villa Kintiarina','Villa Virgen'),
                'Paruro'        => array('Paruro','Accha','Ccapi','Colcha','Huanoquite','Omacha','Paccaritambo','Pillpinto','Yaurisque'),
                'Paucartambo'   => array('Paucartambo','Caicay','Challabamba','Colquepata','Huancarani','Kosñipata'),
                'Quispicanchi'  => array('Urcos','Andahuaylillas','Camanti','Ccarhuayo','Ccatca','Cusipata','Huaro','Lucre','Marcapata','Ocongate','Oropesa','Quiquijana'),
                'Urubamba'      => array('Urubamba','Chinchero','Huayllabamba','Machupicchu','Maras','Ollantaytambo','Yucay'),
            ) ),
            'LAL' => array( 'nombre' => 'La Libertad', 'provincias' => array(
                'Trujillo'         => array('Trujillo','El Porvenir','Florencia de Mora','Huanchaco','La Esperanza','Laredo','Moche','Poroto','Salaverry','Simbal','Víctor Larco Herrera'),
                'Ascope'           => array('Ascope','Casa Grande','Chicama','Chocope','Magdalena de Cao','Paiján','Rázuri','Santiago de Cao'),
                'Bolívar'          => array('Bolívar','Bambamarca','Condormarca','Longotea','Uchumarca','Ucuncha'),
                'Chepén'           => array('Chepén','Pacanga','Pueblo Nuevo'),
                'Julcán'           => array('Julcán','Calamarca','Carabamba','Huaso'),
                'Otuzco'           => array('Otuzco','Agallpampa','Charat','Huaranchal','La Cuesta','Mache','Paranday','Salpo','Sinsicap','Usquil'),
                'Pacasmayo'        => array('San Pedro de Lloc','Guadalupe','Jequetepeque','Pacasmayo','San José'),
                'Pataz'            => array('Tayabamba','Buldibuyo','Chilia','Huancaspata','Huaylillas','Huayo','Ongón','Parcoy','Pataz','Pías','Santiago de Challas','Taurija','Urpay'),
                'Sánchez Carrión'  => array('Huamachuco','Chugay','Cochorco','Curgos','Marcabal','Sanagoran','Sarin','Sartimbamba'),
                'Santiago de Chuco'=> array('Santiago de Chuco','Angasmarca','Cachicadán','Mollebamba','Mollepata','Quiruvilca','Santa Cruz de Chuca','Sitabamba'),
                'Gran Chimú'       => array('Cascas','Lucma','Marmot','Sayapullo'),
                'Virú'             => array('Virú','Chao','Guadalupito'),
            ) ),
            'LAM' => array( 'nombre' => 'Lambayeque', 'provincias' => array(
                'Chiclayo'    => array('Chiclayo','Chongoyape','Eten','Eten Puerto','José Leonardo Ortiz','La Victoria','Lagunas','Monsefu','Nueva Arica','Oyotún','Picsi','Pimentel','Reque','Santa Rosa','Saña','Cayaltí','Pátapo','Pomalca','Pucalá','Tumán'),
                'Ferreñafe'   => array('Ferreñafe','Cañaris','Incahuasi','Manuel Antonio Mesones Muro','Pitipo','Pueblo Nuevo'),
                'Lambayeque'  => array('Lambayeque','Chóchope','Illimo','Jayanca','Mochumi','Mórrope','Motupe','Olmos','Pacora','Salas','San José','Túcume'),
            ) ),
            'PIU' => array( 'nombre' => 'Piura', 'provincias' => array(
                'Piura'       => array('Piura','Castilla','Catacaos','Cura Morí','El Tallán','La Arena','La Unión','Las Lomas','Tambogrande','Veintiseis de Octubre'),
                'Ayabaca'     => array('Ayabaca','Frias','Jilili','Lagunas','Montero','Pacaipampa','Paimas','Sapillica','Sicchez','Suyo'),
                'Huancabamba' => array('Huancabamba','Canchaque','El Carmen de La Frontera','Huarmaca','Lalaquiz','San Miguel del Faique','Sondor','Sondorillo'),
                'Morropón'    => array('Chulucanas','Buenos Aires','Chalaco','La Matanza','Morropón','Salitral','San Juan de Bigote','Santa Catalina de Mossa','Santo Domingo','Yamango'),
                'Paita'       => array('Paita','Amotape','Arenal','Colan','La Huaca','Tamarindo','Vichayal'),
                'Sullana'     => array('Sullana','Bellavista','Ignacio Escudero','Lancones','Marcavelica','Miguel Checa','Querecotillo','Salitral'),
                'Talara'      => array('Pariñas','El Alto','La Brea','Lobitos','Los Órganos','Mancora'),
                'Sechura'     => array('Sechura','Bellavista de La Unión','Bernal','Cristo Nos Valga','Vice','Rinconada Llicuar'),
            ) ),
            'PUN' => array( 'nombre' => 'Puno', 'provincias' => array(
                'Puno'                  => array('Puno','Acapí','Alto de la Alianza','Capachica','Chucuito','Coata','Huata','Mañazo','Paucarcolla','Pichacani','Platería','San Antonio','Tiquillaca','Vilque'),
                'Azángaro'              => array('Azángaro','Achaya','Arapa','Asillo','Caminaca','Chupa','José Domingo Choquehuanca','Muñani','Potoni','Saman','San Antón','San José','San Juan de Salinas','Santiago de Pupuja','Tirapata'),
                'Carabaya'              => array('Macusani','Ajoyani','Ayapata','Coasa','Corani','Crucero','Ituata','Ollachea','San Gabán','Usicayos'),
                'Chucuito'              => array('Juli','Desaguadero','Huacullani','Kelluyo','Pisacoma','Pomata','Zepita'),
                'El Collao'             => array('Ilave','Capazo','Pilcuyo','Santa Rosa','Conduriri'),
                'Huancané'              => array('Huancané','Cojata','Huatasani','Inchupalla','Pusi','Rosaspata','Taraco','Vilque Chico'),
                'Lampa'                 => array('Lampa','Cabanilla','Calapuja','Nicasio','Ocuviri','Palca','Paratía','Pucará','Santa Lucía','Vilavila'),
                'Melgar'                => array('Ayaviri','Antauta','Cupi','Llalli','Macari','Nuñoa','Orurillo','Santa Rosa','Umachiri'),
                'Moho'                  => array('Moho','Conima','Huayrapata','Tilali'),
                'San Antonio de Putina' => array('Putina','Ananea','Pedro Vilca Apaza','Quilcapuncu','Sina'),
                'San Román'             => array('Juliaca','Cabana','Cabanillas','Caracoto','San Miguel'),
                'Sandia'                => array('Sandia','Cuyocuyo','Limbani','Patambuco','Phara','Quiaca','San Juan del Oro','Yanahuaya','Alto Inambari'),
                'Yunguyo'               => array('Yunguyo','Anapia','Copani','Cuturapi','Ollaraya','Tinicachi','Unicachi'),
            ) ),
            'AYA' => array( 'nombre' => 'Ayacucho', 'provincias' => array(
                'Huamanga'       => array('Ayacucho','Acocro','Acos Vinchos','Carmen Alto','Chiara','Jesús Nazareno','Ocros','Pacaycasa','Quinua','San José de Ticllas','San Juan Bautista','Santiago de Pischa','Socos','Tambillo','Vinchos','Andrés Avelino Cáceres Dorregaray'),
                'Cangallo'       => array('Cangallo','Chuschi','Los Morochucos','María Parado de Bellido','Paras','Totos'),
                'Huanca Sancos'  => array('Sancos','Carapo','Sacsamarca','Santiago de Lucanamarca'),
                'Huanta'         => array('Huanta','Ayahuanco','Huamanguilla','Iguain','Luricocha','Santillana','Sivia','Llochegua','Canayre','Uchuraccay'),
                'La Mar'         => array('San Miguel','Anco','Ayna','Chilcas','Chungui','Luis Carranza','Santa Rosa','Tambo','Samugari','Anchihuay'),
                'Lucanas'        => array('Puquio','Aucara','Cabana','Carmen Salcedo','Chaviña','Chipao','Huac-Huas','Laramate','Leoncio Prado','Llauta','Lucanas','Ocaña','Otoca','Saisa','San Cristóbal','San Juan','San Pedro','San Pedro de Palco','Sancos','Santa Ana de Huaycahuacho','Santa Lucía'),
                'Parinacochas'   => array('Coracora','Chumpi','Coronel Castañeda','Pacapausa','Pullo','Puyusca','San Francisco de Ravacayco','Upahuacho'),
                'Páucar del Sara Sara' => array('Pausa','Colta','Corculla','Lampa','Marcabamba','Oyolo','Pararca','San Javier de Alpabamba','San José de Ushua','Sara Sara'),
                'Sucre'          => array('Querobamba','Belen','Chalcos','Chilcayoc','Huacaña','Morcolla','Paico','San Salvador de Quije','Santiago de Paucaray','Soras'),
                'Víctor Fajardo' => array('Huancapi','Alcamenca','Apongo','Asquipata','Canaria','Cayara','Colca','Huamanquiquia','Huancaraylla','Huaya','Sarhua','Vilcanchos'),
                'Vilcas Huamán'  => array('Vilcas Huamán','Accomarca','Carhuanca','Concepción','Huambalpa','Independencia','Saurama','Vischongo'),
            ) ),
            'CAJ' => array( 'nombre' => 'Cajamarca', 'provincias' => array(
                'Cajamarca'   => array('Cajamarca','Asunción','Chetilla','Cospan','Encañada','Jesús','Llacanora','Los Baños del Inca','Magdalena','Matara','Namora','San Juan'),
                'Cajabamba'   => array('Cajabamba','Cachachi','Condebamba','Sitacocha'),
                'Celendín'    => array('Celendín','Chumuch','Cortegana','Huasmin','Jorge Chávez','José Gálvez','Miguel Iglesias','Oxamarca','Sorochuco','Sucre','Utco','La Libertad de Pallan'),
                'Chota'       => array('Chota','Anguia','Chadin','Chiguirip','Chimban','Choropampa','Cochabamba','Conchan','Huambos','Lajas','Llama','Miracosta','Paccha','Pion','Querocoto','San Juan de Licupis','Tacabamba','Tocmoche','Chalamarca'),
                'Contumazá'   => array('Contumazá','Chilete','Cupisnique','Guzmango','San Benito','Santa Cruz de Toled','Tantarica','Yonán'),
                'Cutervo'     => array('Cutervo','Callayuc','Choros','Cujillo','La Ramada','Pimpingos','Querocotillo','San Andrés de Cutervo','San Juan de Cutervo','San Luis de Lucma','Santa Cruz','Santo Domingo de la Capilla','Santo Tomas','Socota','Toribio Casanova'),
                'Hualgayoc'   => array('Bambamarca','Chugur','Hualgayoc'),
                'Jaén'        => array('Jaén','Bellavista','Chontali','Colasay','Huabal','Las Pirias','Pomahuaca','Pucará','Sallique','San Felipe','San José del Alto','Santa Rosa'),
                'San Ignacio' => array('San Ignacio','Chirinos','Huarango','La Coipa','Namballe','San José de Lourdes','Tabaconas'),
                'San Marcos'  => array('Pedro Gálvez','Chancay','Eduardo Villanueva','Gregorio Pita','Ichocán','José Manuel Quiroz','José Sabogal'),
                'San Miguel'  => array('San Miguel','Bolívar','Calquis','Catilluc','El Prado','La Florida','Llapa','Nanchoc','Niepos','San Gregorio','San Silvestre de Cochan','Tongod','Unión Agua Blanca'),
                'San Pablo'   => array('San Pablo','Kuntur Wasi','Tumbaden','San Luis'),
                'Santa Cruz'  => array('Santa Cruz','Andabamba','Catache','Chancaybaños','La Esperanza','Ninabamba','Pulan','Saucepampa','Sexi','Uticyacu','Yauyucán'),
            ) ),
            'HUV' => array( 'nombre' => 'Huancavelica', 'provincias' => array(
                'Huancavelica' => array('Huancavelica','Acobambilla','Acoria','Conayca','Cuenca','Huachocolpa','Huayllahuara','Izcuchaca','Laria','Manta','Mariscal Cáceres','Moya','Nuevo Occoro','Palca','Pilchaca','Vilca','Yauli','Ascensión','Huando'),
                'Acobamba'     => array('Acobamba','Andabamba','Anta','Caja','Marcas','Paucara','Pomacocha','Rosario'),
                'Angaraes'     => array('Lircay','Anchonga','Callanmarca','Ccochaccasa','Chincho','Congalla','Huanca-Huanca','Huayllay Grande','Julcamarca','San Antonio de Antaparco','Santo Tomás de Pata','Secclla'),
                'Castrovirreyna' => array('Castrovirreyna','Arma','Aurahua','Capillas','Chupamarca','Cocas','Huachos','Huamatambo','Mollepampa','San Juan','Santa Ana','Ticrapo'),
                'Churcampa'    => array('Churcampa','Chinchihuasi','El Carmen','La Merced','Locroja','Paucarbamba','San Miguel de Mayocc','San Pedro de Coris','Zupay'),
                'Huaytará'     => array('Huaytará','Ayavi','Córdova','Huayacundo Arma','Laramarca','Ocoyo','Pilpichaca','Querco','Quito-Arma','San Antonio de Cusicancha','San Francisco de Sangayaico','San Isidro','Santiago de Chocorvos','Santiago de Quirahuara','Santo Domingo de Capillas','Tambo'),
                'Tayacaja'     => array('Pampas','Acraquia','Ahuaycha','Colcabamba','Daniel Hernández','Huachocolpa','Huaribamba','Ñahuimpuquio','Pazos','Quishuar','Salcabamba','Salcahuasi','San Marcos de Rocchac','Surcubamba','Tintay Puncu','Quichuas','Andaymarca','Roble','Pichos','Santiago de Tucuma'),
            ) ),
            'HUC' => array( 'nombre' => 'Huánuco', 'provincias' => array(
                'Huánuco'       => array('Huánuco','Amarilis','Kichki','Chinchao','Churubamba','Margos','Quisqui','San Francisco de Cayran','San Pedro de Chaulan','Santa María del Valle','Yarumayo','Pillco Marca','Yacus','San Pablo de Pillao'),
                'Ambo'          => array('Ambo','Cayna','Colpas','Conchamarca','Huacar','San Francisco','San Rafael','Tomay Kichwa'),
                'Dos de Mayo'   => array('La Unión','Chuquis','Marías','Pachas','Quivilla','Ripan','Shunqui','Sillapata','Yanas'),
                'Huacaybamba'   => array('Huacaybamba','Canchabamba','Cochabamba','Pinra'),
                'Huamalíes'     => array('Llata','Arancay','Chavín de Pariarca','Jacas Grande','Jircan','Miraflores','Monzón','Punchao','Puños','Singa','Tantamayo'),
                'Leoncio Prado' => array('Rupa-Rupa','Daniel Alomia Robles','Hermilio Valdizán','José Crespo y Castillo','Luyando','Mariano Damaso Beraun','Pucayacu','Castillo Grande','Pueblo Nuevo'),
                'Marañón'       => array('Huacrachuco','Cholon','San Buenaventura'),
                'Pachitea'      => array('Panao','Chaglla','Molino','Umari'),
                'Puerto Inca'   => array('Puerto Inca','Codo del Pozuzo','Honoria','Rayón','Yuyapichis'),
                'Lauricocha'    => array('Jesús','Baños','Jivia','Queropalca','Rondos','San Francisco de Asís','San Miguel de Cauri'),
                'Yarowilca'     => array('Chavinillo','Cahuac','Chacabamba','Aparicio Pomares','Jacas Chico','Obas','Pampamarca','Choras'),
            ) ),
            'ICA' => array( 'nombre' => 'Ica', 'provincias' => array(
                'Ica'    => array('Ica','La Tinguiña','Los Aquijes','Ocucaje','Pachacútec','Parcona','Pueblo Nuevo','Salas','San José de Los Molinos','San Juan Bautista','Santiago','Subtanjalla','Tate','Yauca del Rosario'),
                'Chincha'=> array('Chincha Alta','Alto Larán','Chavín','Chincha Baja','El Carmen','Grocio Prado','Pueblo Nuevo','San Juan de Yanac','San Pedro de Huacarpana','Sunampe','Tambo de Mora'),
                'Nazca'  => array('Nazca','Changuillo','El Ingenio','Marcona','Vista Alegre'),
                'Palpa'  => array('Palpa','Llipata','Río Grande','Santa Cruz','Tibillo'),
                'Pisco'  => array('Pisco','Huancano','Humay','Independencia','Paracas','San Andrés','San Clemente','Túpac Amaru Inca'),
            ) ),
            'JUN' => array( 'nombre' => 'Junín', 'provincias' => array(
                'Huancayo'    => array('Huancayo','Carhuacallanga','Chacapampa','Chicche','Chilca','Chongos Alto','Chupuro','Colca','El Tambo','Huacrapuquio','Hualhuas','Huancan','Huasicancha','Huayucachi','Ingenio','Pariahuanca','Pilcomayo','Pucará','Quichuay','Quilcas','San Agustín','San Jerónimo de Tunán','Saño','Sapallanga','Sicaya','Viques'),
                'Concepción'  => array('Concepción','Aco','Andamarca','Chambará','Cochas','Comas','Heroínas Toledo','Manzanares','Mariscal Castilla','Matahuasi','Mito','Nueve de Julio','Orcotuna','San José de Quero','Santa Rosa de Ocopa'),
                'Chanchamayo' => array('Chanchamayo','Perene','Pichanaqui','San Luis de Shuaro','San Ramón','Vitoc'),
                'Junín'       => array('Junín','Carhuamayo','Ondores','Ulcumayo'),
                'Satipo'      => array('Satipo','Coviriali','Llaylla','Mazamari','Pampa Hermosa','Pangoa','Río Negro','Río Tambo','Vizcatan del Ene'),
                'Tarma'       => array('Tarma','Acobamba','Huaricolca','Huasahuasi','La Unión','Palca','Palcamayo','San Pedro de Cajas','Tapo'),
                'Yauli'       => array('La Oroya','Chacapalpa','Huay-Huay','Marcapomacocha','Morococha','Paccha','Santa Bárbara de Carhuacayán','Santa Rosa de Sacco','Suitucancha','Yauli'),
                'Chupaca'     => array('Chupaca','Ahuac','Chongos Bajo','Huachac','Huamancaca Chico','San Juan de Iscos','San Juan de Jarpa','Tres de Diciembre','Yanacancha'),
            ) ),
            'AMA' => array( 'nombre' => 'Amazonas', 'provincias' => array(
                'Chachapoyas'          => array('Chachapoyas','Asunción','Balsas','Cheto','Chiliquín','Chuquibamba','Granada','Huancas','La Jalca','Leimebamba','Levanto','Magdalena','Mariscal Castilla','Molinopampa','Montevideo','Olleros','Quinjalca','San Francisco de Daguas','San Isidro de Maino','Soloco','Sonche'),
                'Bagua'                => array('Bagua','Aramango','Copallin','El Parco','Imaza','La Peca'),
                'Bongará'              => array('Jumbilla','Chisquilla','Churuja','Corosha','Cuispes','Florida','Jazán','Recta','San Carlos','Shipasbamba','Valera','Yambrasbamba'),
                'Condorcanqui'         => array('Nieva','El Cenepa','Río Santiago'),
                'Luya'                 => array('Lamud','Camporredondo','Cocabamba','Colcamar','Conila','Inguilpata','Longuita','Lonya Chico','Luya','Luya Viejo','María','Ocalli','Ocumal','Pisuquia','Providencia','San Cristóbal','San Francisco del Yeso','San Jerónimo','San Juan de Lopecancha','Santa Catalina','Santo Tomás','Tingo','Trita'),
                'Rodríguez de Mendoza' => array('San Nicolás','Chirimoto','Cochamal','Huambo','Limabamba','Longar','Mariscal Benavides','Milpuc','Omia','Santa Rosa','Totora','Vista Alegre'),
                'Utcubamba'            => array('Bagua Grande','Cajaruro','Cumba','El Milagro','Jamalca','Lonya Grande','Yamon'),
            ) ),
            'ANC' => array( 'nombre' => 'Áncash', 'provincias' => array(
                'Huaraz'                  => array('Huaraz','Cochabamba','Colcabamba','Huanchay','Independencia','Jangas','La Libertad','Llanganuco','Pampas Grande','Paucas','Pira','Tarica'),
                'Aija'                    => array('Aija','Coris','Huacllan','La Merced','Succha'),
                'Antonio Raymondi'        => array('Llamellín','Aczo','Chaccho','Chingas','Mirgas','San Juan de Rontoy'),
                'Asunción'                => array('Chacas','Acochaca'),
                'Bolognesi'               => array('Chiquián','Abelardo Pardo Lezameta','Antonio Raymondi','Aquia','Cajacay','Canis','Colquioc','Huallanca','Huasta','Huayllacayán','La Primavera','Mangas','Pacllón','San Miguel de Corpanqui','Ticllos'),
                'Carhuaz'                 => array('Carhuaz','Acopampa','Amashca','Anta','Ataquero','Marcará','Pamparak','Pariahuanca','San Miguel de Aco','Shilla','Tinco','Yungar'),
                'Carlos Fermín Fitzcarrald' => array('San Luis','San Nicolás','Yauya'),
                'Casma'                   => array('Casma','Buena Vista Alta','Comandante Noel','Yaután'),
                'Corongo'                 => array('Corongo','Aco','Bambas','Cusca','La Pampa','Pampas','Yanac'),
                'Huari'                   => array('Huari','Anra','Cajay','Chavin de Huantar','Huacachi','Huacchis','Huachis','Huantar','Masín','Paucas','Ponto','Rahuapampa','Rapayan','San Marcos','San Pedro de Chana','Uco'),
                'Huarmey'                 => array('Huarmey','Cochapeti','Culebras','Huayan','Malvas'),
                'Huaylas'                 => array('Caraz','Huallanca','Huata','Huaylas','Mato','Pamparomas','Pueblo Libre','Santa Cruz','Santo Toribio','Yuracmarca'),
                'Mariscal Luzuriaga'      => array('Piscobamba','Casca','Eleazar Guzmán Barrón','Fidel Olivas Escudero','Llama','Llumpa','Lucma','Musga'),
                'Ocros'                   => array('Ocros','Acas','Cajamarquilla','Carhuapampa','Cochas','Congas','Llipa','San Cristóbal de Rajan','San Pedro','Santiago de Chilcas'),
                'Pallasca'                => array('Cabana','Bolognesi','Conchucos','Huacaschuque','Huandoval','Lacabamba','Llapo','Pallasca','Pampas','Santa Rosa','Tauca'),
                'Pomabamba'               => array('Pomabamba','Huayllan','Parobamba','Quinuabamba'),
                'Recuay'                  => array('Recuay','Catac','Cotaparaco','Huayllapampa','Llacllin','Pampas Chico','Pampas Grande','Pativilca','Punta Callán','Tapacocha','Ticapampa'),
                'Santa'                   => array('Chimbote','Cáceres del Perú','Coishco','Macate','Moro','Nepeña','Samanco','Santa','Nuevo Chimbote'),
                'Sihuas'                  => array('Sihuas','Acobamba','Alfonso Ugarte','Cashapampa','Chingalpo','Huayllabamba','Quiches','Ragash','San Juan','Sicsibamba'),
                'Yungay'                  => array('Yungay','Cascapara','Mancos','Matacoto','Quillo','Ranrahirca','Shupluy','Yanama'),
            ) ),
            'APU' => array( 'nombre' => 'Apurímac', 'provincias' => array(
                'Abancay'     => array('Abancay','Chacoche','Circa','Curahuasi','Huanipaca','Lambrama','Pichirhua','San Pedro de Cachora','Tamburco'),
                'Andahuaylas' => array('Andahuaylas','Andarapa','Chiara','Huancarama','Huancaray','Kishuara','Pacobamba','Pacucha','Pampachiri','San Antonio de Cachi','Santa María de Chicmo','Talavera','Turpo','José María Arguedas','Pomacocha','San Jerónimo'),
                'Antabamba'   => array('Antabamba','El Oro','Huaquirca','Juan Espinoza Medrano','Oropesa','Pachaconas','Sabaino'),
                'Aymaraes'    => array('Chalhuanca','Capaya','Caraybamba','Chapimarca','Colcabamba','Cotaruse','Huayllo','Justo Apu Sahuaraura','Lucre','Pocohuanca','San Juan de Chacña','Sañayca','Soraya','Tapairihua','Tintay','Toraya','Yanaca'),
                'Cotabambas'  => array('Tambobamba','Cotabambas','Coyllurqui','Haquira','Mara','Challhuahuacho'),
                'Chincheros'  => array('Chincheros','Anco_Huallo','Cocharcas','Huaccana','Ocobamba','Ongoy','Uranmarca','Ranracancha'),
                'Grau'        => array('Chuquibambilla','Curasco','Gamarra','Huayllati','Mamara','Micaela Bastidas','Pataypampa','Progreso','San Antonio','Santa Rosa','Turpay','Vilcabamba','Virundo','Curpahuasi'),
            ) ),
            'MOQ' => array( 'nombre' => 'Moquegua', 'provincias' => array(
                'Mariscal Nieto'        => array('Moquegua','Carumas','Cuchumbaya','Samegua','San Cristóbal','Torata'),
                'General Sánchez Cerro' => array('Omate','Chojata','Coalaque','Ichuña','La Capilla','Lloque','Matalaque','Puquina','Quinistaquillas','Ubinas','Yunga'),
                'Ilo'                   => array('Ilo','El Algarrobal','Pacocha'),
            ) ),
            'PAS' => array( 'nombre' => 'Pasco', 'provincias' => array(
                'Pasco'                  => array('Chaupimarca','Huachon','Huariaca','Huayllay','Ninacaca','Pallanchacra','Paucartambo','San Francisco de Asís de Yarusyacán','Simón Bolívar','Ticlacayan','Tinyahuarco','Vicco','Yanacancha'),
                'Daniel Alcides Carrión' => array('Yanahuanca','Chacayan','Goyllarisquizga','Paucar','San Pedro de Pillao','Santa Ana de Tusi','Tapuc','Vilcabamba'),
                'Oxapampa'               => array('Oxapampa','Chontabamba','Huancabamba','Palcazú','Pozuzo','Puerto Bermúdez','Villa Rica','Constitución'),
            ) ),
            'SAM' => array( 'nombre' => 'San Martín', 'provincias' => array(
                'Moyobamba'       => array('Moyobamba','Calzada','Habana','Jepelacio','Soritor','Yantalo'),
                'Bellavista'      => array('Bellavista','Alto Biavo','Bajo Biavo','Huallaga','San Pablo','San Rafael'),
                'El Dorado'       => array('San José de Sisa','Agua Blanca','San Martín','Santa Rosa','Shatoja'),
                'Huallaga'        => array('Saposoa','Alto Saposoa','El Eslabón','Piscoyacu','Sacanche','Tingo de Saposoa'),
                'Lamas'           => array('Lamas','Alonso de Alvarado','Barranquita','Caynarachi','Cuñumbuqui','Pinto Recodo','Rumisapa','San Roque de Cumbaza','Shanao','Tabalosos','Zapatero'),
                'Mariscal Cáceres'=> array('Juanjuí','Campanilla','Huicungo','Pachiza','Pajarillo'),
                'Picota'          => array('Picota','Buenos Aires','Caspizapa','Pilluana','Pucacaca','San Cristóbal','San Hilarión','Shamboyacu','Tingo de Ponaza','Tres Unidos'),
                'Rioja'           => array('Rioja','Awajun','Elías Soplin Vargas','Nueva Cajamarca','Pardo Miguel','Posic','San Fernando','Yorongos','Yuracyacu'),
                'San Martín'      => array('Tarapoto','Alberto Leveau','Cacatachi','Chazuta','Chipurana','El Porvenir','Huimbayoc','Juan Guerra','La Banda de Shilcayo','Morales','Papaplaya','San Antonio','Sauce','Shapaja'),
                'Tocache'         => array('Tocache','Nuevo Progreso','Pólvora','Shunte','Uchiza'),
            ) ),
            'TAC' => array( 'nombre' => 'Tacna', 'provincias' => array(
                'Tacna'        => array('Tacna','Alto de la Alianza','Calana','Ciudad Nueva','Inclán','Pachía','Palca','Pocollay','Sama','Coronel Gregorio Albarracín Lanchipa','La Yarada-Los Palos'),
                'Candarave'    => array('Candarave','Cairani','Camilaca','Curibaya','Huanuara','Quilahuani'),
                'Jorge Basadre'=> array('Locumba','Ilabaya','Ite'),
                'Tarata'       => array('Tarata','Chucatamani','Estique','Estique-Pampa','Sitajara','Susapaya','Tarucachi','Ticaco'),
            ) ),
            'TUM' => array( 'nombre' => 'Tumbes', 'provincias' => array(
                'Tumbes'               => array('Tumbes','Corrales','La Cruz','Pampas de Hospital','San Jacinto','San Juan de la Virgen'),
                'Contralmirante Villar'=> array('Zorritos','Casitas','Canoas de Punta Sal'),
                'Zarumilla'            => array('Zarumilla','Aguas Verdes','Matapalo','Papayal'),
            ) ),
            'UCA' => array( 'nombre' => 'Ucayali', 'provincias' => array(
                'Coronel Portillo' => array('Callería','Campoverde','Iparía','Masisea','Yarinacocha','Nueva Requena','Manantay'),
                'Atalaya'          => array('Raymondi','Sepahua','Tahuanía','Yurúa'),
                'Padre Abad'       => array('Padre Abad','Irazola','Curimaná','Neshuya','Alexander Von Humboldt'),
                'Purús'            => array('Purús'),
            ) ),
            'LOR' => array( 'nombre' => 'Loreto', 'provincias' => array(
                'Maynas'                   => array('Iquitos','Alto Nanay','Fernando Lores','Indiana','Las Amazonas','Mazan','Napo','Punchana','Torres Causana','Belén','San Juan Bautista'),
                'Alto Amazonas'            => array('Yurimaguas','Balsapuerto','Jeberos','Lagunas','Santa Cruz','Teniente César López Rojas'),
                'Loreto'                   => array('Nauta','Parinari','Tigre','Trompeteros','Urarinas'),
                'Mariscal Ramón Castilla'  => array('Ramón Castilla','Pebas','San Pablo','Yavari'),
                'Requena'                  => array('Requena','Alto Tapiche','Capelo','Emilio San Martín','Maquia','Puinahua','Saquena','Soplin','Tapiche','Jenaro Herrera','Yaquerana'),
                'Ucayali'                  => array('Contamana','Inahuaya','Padre Márquez','Pampa Hermosa','Sarayacu','Vargas Guerra'),
                'Datem del Marañón'        => array('San Lorenzo','Barranca','Cahuapanas','Manseriche','Morona','Pastaza'),
                'Putumayo'                 => array('El Estrecho','Huapapa','Yaguas','Rosa Panduro','Teniente Manuel Clavero','Güeppi'),
            ) ),
            'MDD' => array( 'nombre' => 'Madre de Dios', 'provincias' => array(
                'Tambopata' => array('Tambopata','Inambari','Las Piedras','Laberinto'),
                'Manu'      => array('Manu','Fitzcarrald','Madre de Dios','Huepetuhe'),
                'Tahuamanu' => array('Iñapari','Iberia','Tahuamanu'),
            ) ),
        );
    }
}
