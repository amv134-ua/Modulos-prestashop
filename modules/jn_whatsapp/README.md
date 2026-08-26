# jn_whatsapp

**Botón flotante de WhatsApp** para PrestaShop 8.

Botón fijo en la esquina inferior derecha que abre una conversación de WhatsApp
con la tienda. En las fichas de producto, **añade automáticamente el artículo al
mensaje**.

`PrestaShop 8.0+` · `PHP 8.1` · **206 líneas** · v1.0.0

---

## El detalle que lo hace útil

Un botón de WhatsApp genérico deja al cliente escribiendo *"hola, quería
preguntar por unas llantas"*, y la tienda tiene que averiguar cuáles.

Cuando el visitante está en una ficha de producto, este módulo precarga el
mensaje con el nombre y el enlace del artículo:

```
Hola, tengo una consulta sobre vuestras llantas.

Producto: Llanta BOLA B1 18x8.5 Negro Brillo
https://…/123-bola-b1.html
```

La conversación empieza con el contexto ya puesto. Para el cliente es un clic;
para la tienda, la diferencia entre poder responder o tener que preguntar.

```php
if ((int) Configuration::get(self::CONF_PRODUCT_CTX)
    && $this->context->controller->php_self === 'product') {
    $idProduct = (int) Tools::getValue('id_product');
    if ($idProduct) {
        $name = Product::getProductName($idProduct, null, $this->context->language->id);
        if ($name) {
            $message .= "\n\nProducto: " . $name . "\n"
                . $this->context->link->getProductLink($idProduct);
        }
    }
}
```

## Configuración

| Opción | Descripción |
|---|---|
| Número | Con prefijo de país; los demás caracteres se descartan al guardar |
| Mensaje precargado | Texto base de la conversación |
| Añadir el producto | Sí / No — activa el comportamiento de arriba |

El número se normaliza al guardarlo (`preg_replace('/\D+/', '', …)`), así que da
igual si se escribe con espacios, guiones o `+`. Si queda vacío, el botón no se
pinta: sin número no hay enlace válido.

## Implementación

- Enlace estándar `https://wa.me/<número>?text=<mensaje>`, con el texto pasado
  por `rawurlencode()`.
- Se renderiza en `displayBeforeBodyClosingTag`, fuera del flujo del documento,
  para no afectar a la maquetación de ninguna página.
- CSS propio con **animación de pulso** para atraer la atención sin resultar
  intrusivo.
- Sin dependencias: ni jQuery ni el SDK de WhatsApp.
