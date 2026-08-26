{**
 * Aviso de vacaciones — Repuestos Juanito
 * Se muestra desde JS: nace oculto para que, si el JS fallara,
 * la tienda quede completamente normal.
 *}
<div class="jn-vac" id="jn-vac" data-mode="{$jn_vac_mode|escape:'html':'UTF-8'}" hidden>
  <div class="jn-vac__backdrop" data-jn-vac-close></div>

  <div class="jn-vac__box" role="dialog" aria-modal="true" aria-labelledby="jn-vac-title">

    <button type="button" class="jn-vac__x" data-jn-vac-close aria-label="Cerrar aviso">
      <span aria-hidden="true">&times;</span>
    </button>

    <div class="jn-vac__stripe" aria-hidden="true"></div>

    <div class="jn-vac__body">
      <div class="jn-vac__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="34" height="34" fill="none"
             stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <rect x="2" y="7" width="20" height="14" rx="2"></rect>
          <path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
        </svg>
      </div>

      <h2 class="jn-vac__title" id="jn-vac-title">{$jn_vac_title|escape:'html':'UTF-8'}</h2>

      {if $jn_vac_date}
        <p class="jn-vac__date">Volvemos el <strong>{$jn_vac_date|escape:'html':'UTF-8'}</strong></p>
      {/if}

      {if $jn_vac_text}
        <p class="jn-vac__text">{$jn_vac_text|escape:'html':'UTF-8'|nl2br nofilter}</p>
      {/if}

      <button type="button" class="jn-vac__btn" data-jn-vac-close>Entendido</button>
    </div>
  </div>
</div>
