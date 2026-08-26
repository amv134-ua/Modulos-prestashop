# jn_pagos

**Bloque de métodos de pago aceptados** para PrestaShop 8.

Muestra las formas de pago que acepta la tienda —PayPal, pago a plazos, tarjeta,
Bizum y transferencia— con sus logotipos, en la ficha de producto y/o en el
carrito.

`PrestaShop 8.0+` · `PHP 8.1` · **356 líneas** · v1.0.0

---

## Por qué

El módulo oficial *PrestaShop Checkout* pinta un recuadro de confianza en la
ficha de producto, pero **solo anuncia PayPal**. Una tienda que además cobra por
tarjeta, Bizum y transferencia está desaprovechando ese espacio: el cliente que
no usa PayPal no ve ninguna forma de pago que reconozca.

Este módulo sustituye ese recuadro por uno que lista **todas** las formas de
pago reales de la tienda.

## Configuración

| Opción | Valores |
|---|---|
| Mostrar el bloque | Sí / No |
| Título | Texto libre (por defecto *"Pago 100% seguro"*) |
| Dónde aparece | Ficha de producto / Carrito / Ambos |
| Formas de pago | Casillas por método; el orden de la lista es el de la web |

El formulario indica, para cada método, si tiene logotipo disponible o si se
mostrará con icono, de modo que el administrador sabe qué va a ver el cliente
antes de guardar.

## Detalles de implementación

### Degradación elegante de los logotipos

Cada método declara etiqueta, icono de reserva y fichero de logotipo. El
logotipo **solo se usa si el archivo existe de verdad** en disco:

```php
$out[$slug] = [
    'label' => $label,
    'icon'  => $icon,
    'img'   => ($file && is_file($dir . $file)) ? $url . $file : '',
];
```

Si falta el SVG, se pinta el icono de Material Icons y el nombre. El bloque
nunca muestra un hueco roto, y añadir una marca nueva es dejar caer su SVG en
`views/img/`.

El nombre del método se escribe **siempre**, junto al logo, para que se entienda
aunque la imagen no cargue.

### Sin bloques duplicados

El módulo se engancha a dos hooks —`displayProductAdditionalInfo` para la ficha
y `displayReassurance` para el resto— y comprueba el controlador activo para no
pintarse dos veces en la misma página:

```php
// en la ficha ya lo pinta el otro hook: aquí solo fuera de ella
if ($this->context->controller->php_self === 'product') {
    return '';
}
```

### Estilo integrado con el tema

El CSS usa las **variables de color del tema** con valores de reserva, así que
el bloque hereda la identidad visual de la tienda sin duplicar la paleta:

```css
border: 1px solid var(--jn-line, #e5e8ec);
border-radius: var(--jn-radius, 8px);
background: var(--jn-surface, #f4f6f8);
```

Todas las fichas comparten `min-height` para que la fila quede alineada aunque
unos métodos lleven logotipo y otros icono.
