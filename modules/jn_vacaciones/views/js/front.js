/**
 * Aviso de vacaciones — Repuestos Juanito · v1.2.0
 *
 * IMPORTANTE (v1.2.0): PrestaShop coloca el <script> del paquete combinado
 * ANTES del div del aviso. Si buscásemos el elemento nada más cargar, no
 * existiría todavía y no pasaría nada. Por eso esperamos a que la página
 * esté montada antes de buscarlo.
 *
 * MODOS (se eligen en los ajustes del módulo):
 *
 *   entry   — aparece cada vez que el visitante ENTRA en la web desde fuera
 *             (Google, un enlace, la dirección escrita a mano, o desde tu
 *             propio panel de administración). No aparece mientras navega
 *             por la tienda.
 *
 *   session — aparece una sola vez y no vuelve hasta que cierra el navegador.
 *
 * VISTA PREVIA:
 *   Añadiendo  ?jnvac=1  a cualquier URL de la tienda, el aviso se muestra
 *   siempre, ignorando ambos modos y sin recordar que lo cerraste.
 */
(function () {
  'use strict';

  var KEY = 'jnVacCerrado';

  /* ---- ¿nos han pedido vista previa? ---------------------------- */
  function vistaPrevia() {
    return /[?&]jnvac=1(&|$)/.test(window.location.search);
  }

  /* ---- ¿venimos de otra página del propio front de la tienda? ----
     Ojo: venir del PANEL DE ADMINISTRACIÓN cuenta como entrar desde
     fuera, para que "Ver mi tienda" también lo muestre.              */
  function navegacionInterna() {
    var ref = document.referrer;
    if (!ref) { return false; }                 // sin referente = ha entrado directo
    try {
      var u = new URL(ref);
      if (u.host !== window.location.host) { return false; }   // otro dominio
      if (/\/admin[0-9a-z]{4,}\//i.test(u.pathname)) { return false; }
      return true;
    } catch (e) {
      return false;
    }
  }

  function arrancar() {
    var box = document.getElementById('jn-vac');
    if (!box) { return; }

    var mode = box.getAttribute('data-mode') === 'session' ? 'session' : 'entry';
    var lastFocused = null;

    /* almacenamiento; en incógnito puede fallar: nunca romper por esto */
    function marcarCerrado() {
      if (mode !== 'session') { return; }
      try { window.sessionStorage.setItem(KEY, '1'); } catch (e) { /* da igual */ }
    }
    function yaCerrado() {
      if (mode !== 'session') { return false; }
      try { return window.sessionStorage.getItem(KEY) === '1'; } catch (e) { return false; }
    }

    function debeMostrarse() {
      if (vistaPrevia()) { return true; }       // ?jnvac=1 manda siempre
      if (mode === 'session') { return !yaCerrado(); }
      return !navegacionInterna();
    }

    function abrir() {
      lastFocused = document.activeElement;
      box.hidden = false;
      box.style.display = 'flex';               // por si algún estilo del tema estorba
      document.body.style.overflow = 'hidden';  // que no scrollee el fondo
      var x = box.querySelector('.jn-vac__x');
      if (x) { x.focus(); }
      document.addEventListener('keydown', alPulsarTecla);
    }

    function cerrar() {
      box.hidden = true;
      box.style.display = '';
      document.body.style.overflow = '';
      if (!vistaPrevia()) { marcarCerrado(); }  // en vista previa no se recuerda
      document.removeEventListener('keydown', alPulsarTecla);
      if (lastFocused && typeof lastFocused.focus === 'function') { lastFocused.focus(); }
    }

    function alPulsarTecla(e) {
      if (e.key === 'Escape' || e.key === 'Esc') { cerrar(); }
    }

    /* la X, el botón "Entendido" y el fondo oscuro cierran */
    var cierres = box.querySelectorAll('[data-jn-vac-close]');
    for (var i = 0; i < cierres.length; i++) {
      cierres[i].addEventListener('click', cerrar);
    }

    if (debeMostrarse()) {
      window.setTimeout(abrir, vistaPrevia() ? 150 : 700);
    }
  }

  /* El div del aviso se pinta DESPUÉS de este script, así que hay que
     esperar a que la página esté montada para poder encontrarlo. */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', arrancar);
  } else {
    arrancar();
  }
})();
