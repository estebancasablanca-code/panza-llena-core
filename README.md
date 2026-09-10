# Panza Llena Core

Plugin propio de Panza Llena para gestionar accesos institucionales, reglas de catálogo y calendario, carrito agrupado, checkout y entregas sobre WooCommerce.

## Dependencias

- WordPress 6.9 o superior.
- PHP 7.4 o superior.
- WooCommerce activo. Es la única dependencia obligatoria del núcleo.
- Elementor es opcional para la lógica. Si falta, los pedidos, accesos y entregas continúan funcionando, aunque las páginas construidas con sus plantillas pueden verse incompletas.

## Catálogos y acceso

El plugin reconoce cuatro tipos de pedido: Colegios, ITEO Personal, ITEO Pacientes y Particular. Los accesos institucionales pueden combinar su catálogo con productos Particulares. Las autorizaciones se vuelven a validar en el servidor al agregar, modificar y finalizar el pedido.

Las páginas institucionales deben conservar estos slugs:

- `/colegios/`
- `/iteo-personal/`
- `/iteo-pacientes/`
- `/tienda/` para Particulares

El calendario persiste la fecha exacta elegida y admite pedidos hasta las 22:00 del día anterior a la entrega. Los cuatro catálogos comparten la misma ventana de lunes a sábado; un día sin productos publicados no se muestra.

## Caché

Las páginas de pedido dependen del horario, el código de acceso y la sesión de WooCommerce. El plugin envía `Cache-Control: no-store` y define `DONOTCACHEPAGE` en esas solicitudes.

Una caché de página completa del servidor o CDN puede responder antes de que WordPress cargue el plugin. En el hosting deben excluirse como mínimo:

- `/`
- `/colegios/*`
- `/iteo-personal/*`
- `/iteo-pacientes/*`
- `/particulares/*`
- `/tienda/*`
- `/producto/*`
- `/carrito/*`
- `/finalizar-compra/*`
- `/mi-cuenta/*`
- `/wp-json/pllc/*`

También se debe omitir la caché cuando exista alguna de estas cookies:

- `pllc_access`
- `woocommerce_items_in_cart`
- `woocommerce_cart_hash`
- `wp_woocommerce_session_*`
- `wordpress_logged_in_*`

Después de cambiar reglas, estilos o plantillas se debe vaciar la caché del hosting/CDN además de actualizar el navegador.

## Verificación

El workflow `PHP checks` se ejecuta en cada pull request y en cada `push` a `main`. Comprueba sintaxis PHP y JavaScript, autorización, calendario, entregas, estado del frontend, checkout, comunicaciones, orden del carrito, estilos e infraestructura.

Desde una copia local del repositorio se puede ejecutar:

```bash
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/order-rules.php
php tests/delivery-rules.php
php tests/frontend-state.php
php tests/checkout-notes.php
php tests/order-communications.php
php tests/ui-regressions.php
php tests/cart-order.php
php tests/access-rules.php
php tests/infrastructure.php
node --check assets/js/pllc-frontend.js
node --check assets/js/pllc-tour.js
node tests/mini-cart-dom.js
```

Antes de publicar también se prueba manualmente un pedido de cada modalidad, un carrito mixto, el límite de las 22:00, los correos y el panel de Entregas.

## Correos

Los correos conservan la envoltura y la tabla de productos de WooCommerce. El resumen de entrega utiliza tablas de presentación, anchos explícitos, colores de respaldo y estilos inline para mantener compatibilidad con Outlook clásico y online, Gmail y Apple Mail. No depende de Flexbox, Grid, variables CSS ni JavaScript.

Los SKU internos no se muestran. Cada producto conserva nombre, fecha completa, Almuerzo/Cena cuando corresponda, imagen, cantidad e importe aplicable. La variante de texto plano contiene la misma información sin etiquetas HTML.

## Publicación y rollback

1. Crear una rama desde `main` y cambiar una sola área funcional por vez.
2. Abrir un pull request y esperar que `PHP checks` termine correctamente.
3. Integrar a `main`. Si el hosting está conectado a esa rama, comprobar que finalice su despliegue automático.
4. Vaciar cachés externas y hacer una prueba breve de compra.

Antes de cambios de base de datos se realiza una copia de seguridad de WordPress. Si una versión falla, se revierte mediante un nuevo commit que deshaga el merge o se vuelve a desplegar el commit anterior conocido; no se eliminan pedidos ni tablas para retroceder código.

## Estructura y mantenimiento

- `class-pllc-order-rules.php`: autorización, normalización, fechas y cortes.
- `class-pllc-cart.php`: mutaciones consistentes e idempotentes del carrito.
- `class-pllc-deliveries.php`: proyección logística y reconciliación con pedidos.
- `class-pllc-frontend-assets.php`: estado canónico enviado al navegador.
- `class-pllc-checkout.php` y `class-pllc-order-details.php`: checkout y comunicaciones.
- `class-pllc-cache-control.php`: límites de caché pública y sesiones privadas.
- `panza-llena-core.php`: manifiesto y arranque de módulos.

El refactor será progresivo: primero pruebas de regresión, después extracción de responsabilidades pequeñas. No se deben renombrar o eliminar clases CSS utilizadas por Elementor sin revisar las plantillas guardadas en la base de datos.
