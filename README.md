# WC Peru Ubigeo

**Plugin de WordPress / WooCommerce**
Agrega los campos de **Departamento → Provincia → Distrito** del Perú (fuente: INEI/RENIEC) al formulario de checkout y a "Mi Cuenta".

---

## Características

- ✅ 25 Departamentos completos
- ✅ Todas las Provincias por departamento
- ✅ Distritos cargados dinámicamente vía AJAX (sin recargar la página)
- ✅ Selects encadenados (cascading dropdowns)
- ✅ Compatibilidad con WooCommerce HPOS (High-Performance Order Storage)
- ✅ Los datos se guardan como meta del pedido y del cliente
- ✅ Columna "Ubigeo" en la lista de pedidos del admin
- ✅ Visualización de ubigeo en el detalle del pedido (admin)
- ✅ Página de configuración en WooCommerce > Peru Ubigeo
- ✅ Sin dependencias externas

---

## Instalación

1. Sube la carpeta `wc-peru-ubigeo` a `/wp-content/plugins/`
2. Activa el plugin en **Plugins > Plugins instalados**
3. Asegúrate de que WooCommerce esté instalado y activo
4. ¡Listo! Los campos aparecerán automáticamente en el checkout

---

## Estructura de archivos

```
wc-peru-ubigeo/
├── wc-peru-ubigeo.php          ← Archivo principal del plugin
├── README.md
├── includes/
│   ├── class-wcpu-data.php     ← Datos de ubigeo (departamentos, provincias, distritos)
│   ├── class-wcpu-fields.php   ← Integración con campos de WooCommerce
│   ├── class-wcpu-ajax.php     ← Endpoints AJAX para carga dinámica
│   └── class-wcpu-admin.php    ← Mejoras en el panel de administración
└── assets/
    ├── js/
    │   └── wcpu-checkout.js    ← Lógica de selects encadenados
    └── css/
        └── wcpu.css            ← Estilos
```

---

## Cómo funciona

1. En el checkout, el campo **Estado/Región** de WC es reemplazado por tres selects: Departamento, Provincia y Distrito.
2. Al seleccionar un Departamento, se hace una petición AJAX al servidor para obtener sus provincias.
3. Al seleccionar una Provincia, se obtienen los distritos correspondientes.
4. Al completar el pedido, los códigos de ubigeo se guardan como `_billing_departamento`, `_billing_provincia`, `_billing_distrito` (y equivalentes de shipping) en el `post_meta` del pedido.

---

## Agregar más distritos

El archivo `includes/class-wcpu-data.php` contiene el método `all_distritos()`. Para agregar distritos de una provincia que aún no tenga datos, agrega una entrada con el código de 4 dígitos de la provincia como clave:

```php
'1506' => [ // Huaral (Lima)
    '150601' => 'Huaral',
    '150602' => 'Atavillos Alto',
    // ...
],
```

---

## Meta keys guardadas

| Meta key                   | Descripción                          |
|----------------------------|--------------------------------------|
| `_billing_departamento`    | Código de 2 dígitos del departamento |
| `_billing_provincia`       | Código de 4 dígitos de la provincia  |
| `_billing_distrito`        | Código de 6 dígitos del distrito     |
| `_shipping_departamento`   | (ídem, para envío)                   |
| `_shipping_provincia`      | (ídem, para envío)                   |
| `_shipping_distrito`       | (ídem, para envío)                   |

---

## Requisitos

- WordPress 5.8+
- WooCommerce 6.0+
- PHP 7.4+

---

## Autor

Desarrollado por **Greg / El Anexo Digital**  
https://elanexo.digital
