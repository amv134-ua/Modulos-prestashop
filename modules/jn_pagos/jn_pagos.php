<?php
/**
 * Métodos de pago — Repuestos Juanito.
 * Bloque con las formas de pago aceptadas, para la ficha de producto y/o
 * el carrito. Sustituye al cuadro que pinta ps_checkout, que solo muestra
 * PayPal, y permite enseñar también tarjeta, Bizum y transferencia.
 *
 * Todo se puede apagar desde los ajustes del módulo.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Jn_Pagos extends Module
{
    const CONF_ACTIVE = 'JN_PAGOS_ACTIVE';
    const CONF_TITLE = 'JN_PAGOS_TITLE';
    const CONF_WHERE = 'JN_PAGOS_WHERE';     // product | cart | both
    const CONF_METHODS = 'JN_PAGOS_METHODS'; // slugs separados por comas

    /** Métodos disponibles: slug => [etiqueta, icono de reserva, archivo de logo] */
    const METHODS = [
        'paypal' => ['PayPal', 'account_balance_wallet', 'paypal.svg'],
        'paylater' => ['Paga a plazos', 'schedule', 'paylater.svg'],
        'card' => ['Tarjeta', 'credit_card', 'card.svg'],
        'visa' => ['Visa', 'credit_card', 'visa.svg'],
        'mastercard' => ['Mastercard', 'credit_card', 'mastercard.svg'],
        'bizum' => ['Bizum', 'smartphone', 'bizum.svg'],
        'wire' => ['Transferencia', 'account_balance', 'wire.svg'],
    ];

    public function __construct()
    {
        $this->name = 'jn_pagos';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'Repuestos Juanito';
        $this->need_instance = 0;
        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = 'Métodos de pago aceptados';
        $this->description = 'Bloque con las formas de pago (PayPal, a plazos, tarjeta, Bizum, transferencia) en la ficha de producto y el carrito.';
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('displayProductAdditionalInfo')
            && $this->registerHook('displayReassurance')
            && $this->registerHook('actionFrontControllerSetMedia')
            && Configuration::updateValue(self::CONF_ACTIVE, 1)
            && Configuration::updateValue(self::CONF_TITLE, 'Pago 100% seguro')
            && Configuration::updateValue(self::CONF_WHERE, 'product')
            && Configuration::updateValue(self::CONF_METHODS, 'paypal,paylater,card,bizum,wire');
    }

    public function uninstall()
    {
        foreach ([self::CONF_ACTIVE, self::CONF_TITLE, self::CONF_WHERE, self::CONF_METHODS] as $k) {
            Configuration::deleteByName($k);
        }

        return parent::uninstall();
    }

    /** Slugs elegidos, en el orden en que se pintarán */
    private function selectedMethods()
    {
        $raw = (string) Configuration::get(self::CONF_METHODS);
        $dir = _PS_MODULE_DIR_ . $this->name . '/views/img/';
        $url = $this->_path . 'views/img/';
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $raw))) as $slug) {
            if (!isset(self::METHODS[$slug])) {
                continue;
            }
            [$label, $icon, $file] = self::METHODS[$slug];
            // solo se usa el logo si el archivo está de verdad; si no, icono + texto
            $out[$slug] = [
                'label' => $label,
                'icon' => $icon,
                'img' => ($file && is_file($dir . $file)) ? $url . $file : '',
            ];
        }

        return $out;
    }

    private function isOn()
    {
        return (int) Configuration::get(self::CONF_ACTIVE) && $this->selectedMethods();
    }

    public function getContent()
    {
        $out = '';

        if (Tools::isSubmit('submitJnPagos')) {
            $methods = (array) Tools::getValue('methods', []);
            $methods = array_values(array_intersect(array_keys(self::METHODS), $methods));

            Configuration::updateValue(self::CONF_ACTIVE, (int) Tools::getValue(self::CONF_ACTIVE));
            Configuration::updateValue(self::CONF_TITLE, trim((string) Tools::getValue(self::CONF_TITLE)));
            $where = in_array(Tools::getValue(self::CONF_WHERE), ['product', 'cart', 'both'], true)
                ? Tools::getValue(self::CONF_WHERE) : 'product';
            Configuration::updateValue(self::CONF_WHERE, $where);
            Configuration::updateValue(self::CONF_METHODS, implode(',', $methods));

            $out .= $methods
                ? $this->displayConfirmation('Ajustes guardados.')
                : $this->displayWarning('No has marcado ninguna forma de pago, así que el bloque no se mostrará.');
        }

        $active = (int) Configuration::get(self::CONF_ACTIVE);
        $title = (string) Configuration::get(self::CONF_TITLE);
        $where = (string) Configuration::get(self::CONF_WHERE);
        $chosen = array_keys($this->selectedMethods());
        $h = function ($v) {
            return htmlspecialchars((string) $v, ENT_QUOTES);
        };

        $estado = $this->isOn()
            ? '<span style="background:#3ba33b;color:#fff;padding:3px 9px;border-radius:3px;font-size:12px">SE ESTÁ MOSTRANDO</span>'
            : '<span style="background:#999;color:#fff;padding:3px 9px;border-radius:3px;font-size:12px">no se muestra</span>';

        $checks = '';
        $dirImg = _PS_MODULE_DIR_ . $this->name . '/views/img/';
        foreach (self::METHODS as $slug => $m) {
            $tieneLogo = $m[2] && is_file($dirImg . $m[2]);
            $checks .= '<label style="display:block;margin:0 0 7px;font-weight:400">
                <input type="checkbox" name="methods[]" value="' . $slug . '"'
                . (in_array($slug, $chosen, true) ? ' checked' : '') . '>
                &nbsp;<i class="material-icons" style="font-size:17px;vertical-align:-4px;color:#777">'
                . $m[1] . '</i> ' . $h($m[0])
                . ($tieneLogo
                    ? ' <span style="color:#3ba33b;font-size:12px">— con logo</span>'
                    : ' <span style="color:#b0b0b0;font-size:12px">— sin logo (' . $h($m[2]) . '), saldrá con icono</span>')
                . '</label>';
        }

        return $out . '
        <div class="panel">
          <h3><i class="icon icon-credit-card"></i> Métodos de pago aceptados &nbsp; ' . $estado . '</h3>
          <form method="post" class="form-horizontal">

            <div class="form-group">
              <label class="control-label col-lg-3">¿Mostrar el bloque?</label>
              <div class="col-lg-4">
                <select name="' . self::CONF_ACTIVE . '" class="form-control fixed-width-lg">
                  <option value="1"' . ($active ? ' selected' : '') . '>Sí</option>
                  <option value="0"' . ($active ? '' : ' selected') . '>No</option>
                </select>
              </div>
            </div>

            <div class="form-group">
              <label class="control-label col-lg-3">Título</label>
              <div class="col-lg-5">
                <input type="text" name="' . self::CONF_TITLE . '" class="form-control" value="' . $h($title) . '">
              </div>
            </div>

            <div class="form-group">
              <label class="control-label col-lg-3">¿Dónde aparece?</label>
              <div class="col-lg-4">
                <select name="' . self::CONF_WHERE . '" class="form-control">
                  <option value="product"' . ($where === 'product' ? ' selected' : '') . '>Solo en la ficha de producto</option>
                  <option value="cart"' . ($where === 'cart' ? ' selected' : '') . '>Solo en el carrito</option>
                  <option value="both"' . ($where === 'both' ? ' selected' : '') . '>En los dos sitios</option>
                </select>
              </div>
            </div>

            <div class="form-group">
              <label class="control-label col-lg-3">Formas de pago a mostrar</label>
              <div class="col-lg-5">
                ' . $checks . '
                <p class="help-block">Marca solo las que aceptes de verdad. El orden en la web es el de esta lista.</p>
              </div>
            </div>

            <div class="panel-footer">
              <button type="submit" name="submitJnPagos" class="btn btn-default pull-right">
                <i class="process-icon-save"></i> Guardar
              </button>
            </div>
          </form>

          <div class="alert alert-info" style="margin:0">
            <strong>Para que no salgan dos bloques:</strong> apaga el de PayPal en
            <em>Módulos &rarr; PayPal &rarr; Configurar</em>, en la opción de mostrar los
            logos en la página de producto. Ese solo enseña PayPal; este enseña todas tus formas de pago.
          </div>
        </div>';
    }

    public function hookActionFrontControllerSetMedia()
    {
        if (!$this->isOn()) {
            return;
        }
        $this->context->controller->registerStylesheet(
            'module-jn-pagos',
            'modules/' . $this->name . '/views/css/front.css',
            ['media' => 'all', 'priority' => 200]
        );
    }

    private function render()
    {
        $this->context->smarty->assign([
            'jn_pagos_title' => (string) Configuration::get(self::CONF_TITLE),
            'jn_pagos_methods' => $this->selectedMethods(),
        ]);

        return $this->fetch('module:jn_pagos/views/templates/hook/block.tpl');
    }

    /** Ficha de producto: bajo el bloque de compra */
    public function hookDisplayProductAdditionalInfo($params)
    {
        if (!$this->isOn() || !in_array(Configuration::get(self::CONF_WHERE), ['product', 'both'], true)) {
            return '';
        }

        return $this->render();
    }

    /** Carrito y proceso de pago */
    public function hookDisplayReassurance($params)
    {
        if (!$this->isOn() || !in_array(Configuration::get(self::CONF_WHERE), ['cart', 'both'], true)) {
            return '';
        }
        // en la ficha ya lo pinta el otro hook: aquí solo fuera de ella
        if ($this->context->controller && $this->context->controller->php_self === 'product') {
            return '';
        }

        return $this->render();
    }
}
