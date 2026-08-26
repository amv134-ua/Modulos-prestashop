# jn_importsenco

**Importador de catálogo del proveedor Senco** para PrestaShop 8.

Segundo importador de la tienda, para un proveedor con un formato de fichero
completamente distinto al de [`jn_import2forgebola`](../jn_import2forgebola).

`PrestaShop 8.0+` · `PHP 8.1` · **900 líneas** · v1.2.0

---

## Por qué un segundo importador

Cada proveedor entrega su tarifa como quiere. Senco usa `;` como separador,
cabeceras en español, precios netos en euros y una dimensión más de
combinación. Compartir un único importador habría exigido una capa de
configuración más compleja que los dos módulos juntos.

| | 2Forge / BOLA | Senco |
|---|---|---|
| Separador | `,` | `;` |
| Idioma origen | inglés | español |
| Moneda | libras (con conversión) | euros netos |
| Combinaciones | Color × Pulgadas × Ancho | Diámetro × Ancho × Anclaje × Color |
| Tratamiento del ET | atributo | va en la descripción |

## Qué hace

### Combinaciones de cuatro dimensiones

Genera **Diámetro × Ancho × Anclaje × Color**. El **ET** (offset) no se modela
como combinación deliberadamente: no altera el precio y se concreta con el
cliente según su vehículo, así que se documenta en la descripción en lugar de
multiplicar el número de combinaciones.

### Precio y stock

```php
const PRICE_UNITS  = 4;      // se vende en juegos de 4
const PRICE_VAT    = 1.21;
const PRICE_MARGIN = 1.25;
const PRICE_ROUND_TO = 10;   // redondeo comercial a la decena
```

El stock se calcula sumando existencias propias y de fábrica, dividido entre 4.

### Filtrado por marca

Solo se importan **siete marcas** seleccionadas; el resto se descarta por no
tener salida suficiente. La lista es visible y editable desde el formulario de
configuración, con casillas por marca.

Las categorías **no se crean ni se modifican**: se buscan por nombre dentro de
la categoría padre. Si la categoría de una marca no existe, esa marca se salta y
queda anotada en el informe, en lugar de crear categorías huérfanas.

### Actualización no destructiva

Este es el detalle más importante del módulo. Cuando un producto **ya existe**
(localizado por referencia `MARCA-MODELO`):

- ✅ Se actualizan **precio, stock y combinaciones**
- ❌ **NO se tocan** el nombre ni la descripción

Así el trabajo editorial hecho a mano sobre un producto no se pierde en la
siguiente importación de tarifa.

### Imágenes y acabados

Una imagen por color, descargada, miniaturizada y asociada solo a las
combinaciones de ese acabado. `translateFinish()` normaliza los nombres de
acabado del proveedor a los del catálogo.

---

## Uso

Desde el back-office: se marcan las marcas a importar, se sube el CSV y el
módulo devuelve el informe con creados, actualizados y descartados.

> El fichero de tarifa del proveedor no se incluye en este repositorio.
