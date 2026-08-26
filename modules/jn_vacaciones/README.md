# jn_vacaciones

**Aviso emergente de cierre por vacaciones** para PrestaShop 8.

Ventana modal que informa de que la tienda está de vacaciones hasta una fecha
concreta. El cliente puede seguir comprando; solo se le avisa de que el envío se
retrasará.

`PrestaShop 8.0+` · `PHP 8.1` · **605 líneas** · v1.2.0

---

## Configuración

Todo se ajusta desde el back-office, sin tocar código:

| Opción | Descripción |
|---|---|
| Activo | Interruptor de encendido |
| Fecha de vuelta | El aviso **se desactiva solo** al llegar la fecha |
| Título y texto | Contenido del mensaje |
| Modo de aparición | `entry` o `session` (ver abajo) |

La fecha se muestra al cliente redactada en castellano: `2026-08-15` se
convierte en *"15 de agosto de 2026"*.

## Los dos modos

```
entry    Aparece cada vez que el visitante ENTRA en la web desde fuera
         (Google, un enlace, la URL escrita a mano). NO reaparece mientras
         navega por la tienda.

session  Aparece una sola vez y no vuelve hasta que cierra el navegador.
```

El modo `entry` fue el requisito original y es el más interesante de
implementar: hay que distinguir "ha entrado" de "está navegando" sin
almacenamiento persistente. Se resuelve inspeccionando `document.referrer`:

```js
function navegacionInterna() {
  var ref = document.referrer;
  if (!ref) { return false; }                              // entrada directa
  var u = new URL(ref);
  if (u.host !== window.location.host) { return false; }   // viene de fuera
  if (/\/admin[0-9a-z]{4,}\//i.test(u.pathname)) { return false; }
  return true;
}
```

Venir del **panel de administración** cuenta como entrada externa, para que el
botón "Ver mi tienda" muestre el aviso y el administrador pueda comprobarlo.

## Vista previa

Añadiendo `?jnvac=1` a cualquier URL, el aviso se muestra **siempre**, ignorando
el modo configurado y sin registrar que se ha cerrado. Permite enseñárselo al
cliente sin activarlo para todo el mundo.

---

## El fallo que costó encontrar

La v1.0 no funcionaba. El JavaScript era correcto, la plantilla era correcta, y
aun así el popup no aparecía nunca.

La causa: el hook `displayBeforeBodyClosingTag` inserta el HTML **después** del
`<script>` del paquete combinado del tema. Cuando el script se ejecutaba, el div
todavía no existía en el documento y `getElementById` devolvía `null`:

```js
/* El div del aviso se pinta DESPUÉS de este script, así que hay que
   esperar a que la página esté montada para poder encontrarlo. */
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', arrancar);
} else {
  arrancar();
}
```

Lo engañoso del caso es que **las pruebas en consola funcionaban**: al pegar el
código a mano, el elemento ya estaba en la página. El fallo solo se daba en la
carga real, que es exactamente el escenario que no se estaba probando.

## Accesibilidad

- Cierre con la **X**, con el botón *Entendido*, con el **fondo oscuro** y con
  la tecla **Escape**.
- Al abrir, el foco pasa al botón de cierre; al cerrar, **vuelve al elemento que
  lo tenía antes**.
- Se bloquea el desplazamiento del fondo mientras el aviso está abierto.
- El acceso a `sessionStorage` va envuelto en `try/catch`: en navegación privada
  puede lanzar excepción, y un aviso de vacaciones nunca debe romper la tienda.
