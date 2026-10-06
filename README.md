# Módulos PrestaShop 8 — Repuestos Juanito

Colección de **módulos desarrollados desde cero** para una tienda online de
llantas de aleación en producción, con catálogo y clientes reales.

No son ejercicios de práctica: cada módulo resuelve un problema concreto que
apareció durante la migración de la tienda desde PrestaShop 1.6 a PrestaShop 8,
y todos están funcionando en producción.

**Stack:** PHP 8.1 · PrestaShop 8.2 · MySQL · Smarty · JavaScript (sin
dependencias externas) · CSS

---

## Los módulos

| Módulo | Qué hace |
| :--- | :--- |
| [`jn_import2forgebola`](modules/jn_import2forgebola) | Importador de catálogo de proveedor: agrupa 3.000 filas de CSV en productos con combinaciones, calcula precios por fórmula y genera descripciones |
| [`jn_importsenco`](modules/jn_importsenco) | Segundo importador, para un proveedor con formato distinto; actualiza precio y stock **sin pisar** el trabajo editorial |
| [`jn_vacaciones`](modules/jn_vacaciones) | Aviso emergente de cierre por vacaciones, con detección de entrada a la web y fecha de caducidad automática |
| [`jn_pagos`](modules/jn_pagos) | Bloque de métodos de pago aceptados, con degradación elegante de los logotipos |
| [`jn_whatsapp`](modules/jn_whatsapp) | Botón flotante de WhatsApp que precarga el producto en el mensaje |

Cada carpeta tiene su propio README con el detalle técnico.

---

## Lo más destacable

**Dos importadores de catálogo.** Son el núcleo del proyecto.
Convierten las tarifas de dos proveedores —formatos, idiomas y monedas
distintos— en productos publicables: agrupación en combinaciones, cálculo de
precio con márgenes por tramos y redondeo comercial, conversión de unidades a
juegos de 4, descripciones redactadas automáticamente e imágenes asociadas por
color. Reimportar **actualiza** en lugar de duplicar, y respeta el contenido
editado a mano.

**Trabajo de front-end con criterio.** El botón de WhatsApp que añade el
producto al mensaje, o el bloque de pagos que se enseña entero aunque falte un
logotipo, son decisiones pensadas desde lo que necesita el cliente de la tienda,
no desde lo que es cómodo de programar.

**Depuración de casos no evidentes.** El README de
[`jn_vacaciones`](modules/jn_vacaciones) documenta un fallo que solo se
manifestaba en la carga real de la página y no al probar en consola: el hook
`displayBeforeBodyClosingTag` inserta el HTML *después* del `<script>` del tema.

---

## Notas

- Arquitectura estándar de PrestaShop 8: clase que extiende `Module`, registro
  de hooks, plantillas Smarty y formularios de configuración en el back-office.
- **Sin dependencias externas**: no usan Composer, jQuery ni frameworks de CSS.
- Todo lo configurable se ajusta desde el back-office, sin tocar código.
- Comentarios y textos de interfaz en español, por ser el idioma del cliente.
- Este repositorio contiene únicamente el código. Se han excluido a propósito
  los catálogos de proveedores y cualquier dato de negocio.

## Licencia

MIT — ver [LICENSE](LICENSE).
