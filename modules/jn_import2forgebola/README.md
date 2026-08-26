# jn_import2forgebola

**Importador de catálogo de proveedor (2Forge / BOLA)** para PrestaShop 8.

Convierte el CSV de un proveedor mayorista británico (formato TWG, unos 3.000
registros) en productos publicables: un producto por modelo de llanta, con todas
sus combinaciones, precio calculado, descripción redactada e imágenes asociadas.

`PrestaShop 8.0+` · `PHP 8.1` · **1.021 líneas** · v3.0.0

---

## El problema

El proveedor entrega una fila por cada variante física de llanta. La tienda no
vende variantes sueltas: vende **juegos de 4**, agrupados por modelo, y con
precio en euros calculado a partir de un coste en libras.

Traducir eso a mano son horas de trabajo por cada actualización de tarifa, y el
proveedor la actualiza a menudo.

## Qué hace

### Agrupación en productos con combinaciones

Las filas del proveedor se agrupan por modelo y se generan las combinaciones
**Color × Pulgadas × Ancho**. Los grupos de atributos y sus valores se crean si
no existen, buscándolos primero por nombre para no duplicarlos.

### Conversión a juegos de 4

- **Stock:** el del proveedor dividido entre 4 (no puedes vender un juego si no
  tienes las cuatro llantas).
- **Precio:** el coste unitario se multiplica por 4 antes de aplicar la fórmula.

### Cálculo del precio de venta

El PVP no es un margen fijo: se calcula encadenando conversión de divisa,
suplementos, IVA y un **margen variable por tramos de coste**.

```php
const PRICE_FIXED_ADD  = 60;     // suplemento sobre el coste de las 4 llantas
const PRICE_F1         = 1.07;
const PRICE_F2         = 1.2;    // cambio libra -> euro
const PRICE_VAT        = 1.21;
const PRICE_FIXED_END  = 30;
const PRICE_ROUND_TO   = 10;     // redondeo comercial a la decena

const PRICE_BRACKET_1  = 150;    // £ por llanta
const PRICE_BRACKET_2  = 250;
const PRICE_MULT_1     = 1.173;  // margen para el tramo bajo
const PRICE_MULT_2     = 1.2;
const PRICE_MULT_3     = 1.25;   // margen para el tramo alto
```

El redondeo final es a la decena más cercana, por estética comercial:
`1.893,52 € → 1.890 €` y `1.896 € → 1.900 €`.

### Descripción autogenerada

`buildDescription()` redacta la ficha en castellano a partir de los atributos:
rango de ET, anclajes (PCD) disponibles, acabados... incluyendo la construcción
correcta de enumeraciones en español (`joinEs()`: *"15, 16 y 17 pulgadas"*).

### Imágenes por color

Descarga la imagen de cada color, genera las miniaturas de PrestaShop y
**asocia cada imagen únicamente a las combinaciones de ese color**, de modo que
la foto cambia al seleccionar el acabado.

### Combinación por defecto

Se marca como predeterminada **la combinación más barata que tenga stock**, para
que el precio que ve el cliente en el listado sea el de entrada.

### Traducción de colores

`translateColour()` normaliza los nombres del proveedor (en inglés, con
variaciones y erratas) a los del catálogo español.

---

## Uso

Se maneja desde el back-office (`Módulos → Configurar`): se sube el CSV y el
módulo devuelve un **informe de la importación** con productos creados,
actualizados, atributos nuevos y filas descartadas con su motivo.

## Notas de implementación

- Los productos existentes se localizan por **referencia** (`MARCA-MODELO`), de
  modo que reimportar actualiza en vez de duplicar.
- Las categorías de marca se buscan **por nombre** dentro de la categoría padre,
  no por id fijo: así el módulo sobrevive a que se renombren o se recreen.
- Sin dependencias externas: el CSV se procesa con las funciones nativas de PHP.

> Los ficheros CSV del proveedor no se incluyen en este repositorio por contener
> precios de coste confidenciales.
