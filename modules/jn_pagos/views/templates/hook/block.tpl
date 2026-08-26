{**
 * Bloque de formas de pago — Repuestos Juanito
 * Cada método lleva su marca (SVG en views/img/) cuando existe, y el
 * icono genérico cuando no. El nombre se muestra siempre, para que se
 * entienda aunque el logo no cargue.
 *}
{if $jn_pagos_methods}
  <section class="jn-pagos">
    {if $jn_pagos_title}
      <p class="jn-pagos__title">
        <i class="material-icons" aria-hidden="true">lock</i>{$jn_pagos_title|escape:'html':'UTF-8'}
      </p>
    {/if}
    <ul class="jn-pagos__list">
      {foreach from=$jn_pagos_methods key=slug item=m}
        <li class="jn-pagos__item jn-pagos__item--{$slug|escape:'html':'UTF-8'}">
          {if $m.img}
            <img class="jn-pagos__logo" src="{$m.img|escape:'html':'UTF-8'}" alt="" aria-hidden="true" loading="lazy">
          {else}
            <i class="material-icons" aria-hidden="true">{$m.icon|escape:'html':'UTF-8'}</i>
          {/if}
          <span>{$m.label|escape:'html':'UTF-8'}</span>
        </li>
      {/foreach}
    </ul>
  </section>
{/if}
