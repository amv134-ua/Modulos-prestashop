<?php
/**
 * Botón flotante de WhatsApp — Repuestos Juanito.
 * Botón fijo abajo a la derecha con enlace wa.me; en las fichas de producto
 * puede añadir automáticamente el nombre y la URL del producto al mensaje.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Jn_Whatsapp extends Module
{
    const CONF_PHONE = 'JN_WA_PHONE';
    const CONF_MESSAGE = 'JN_WA_MESSAGE';
    const CONF_PRODUCT_CTX = 'JN_WA_PRODUCT_CTX';

    public function __construct()
    {
        $this->name = 'jn_whatsapp';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'Repuestos Juanito';
        $this->need_instance = 0;
        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = 'Botón de WhatsApp';
        $this->description = 'Botón flotante de WhatsApp abajo a la derecha; en las fichas añade el producto al mensaje.';
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('displayBeforeBodyClosingTag')
            && $this->registerHook('actionFrontControllerSetMedia')
            && Configuration::updateValue(self::CONF_PHONE, '34659529976')
            && Configuration::updateValue(self::CONF_MESSAGE, 'Hola, tengo una consulta sobre vuestras llantas.')
            && Configuration::updateValue(self::CONF_PRODUCT_CTX, 1);
    }

    public function uninstall()
    {
        foreach ([self::CONF_PHONE, self::CONF_MESSAGE, self::CONF_PRODUCT_CTX] as $k) {
            Configuration::deleteByName($k);
        }

        return parent::uninstall();
    }

    public function getContent()
    {
        $out = '';
        if (Tools::isSubmit('submitJnWa')) {
            $phone = preg_replace('/\D+/', '', (string) Tools::getValue(self::CONF_PHONE));
            Configuration::updateValue(self::CONF_PHONE, $phone);
            Configuration::updateValue(self::CONF_MESSAGE, (string) Tools::getValue(self::CONF_MESSAGE));
            Configuration::updateValue(self::CONF_PRODUCT_CTX, (int) Tools::getValue(self::CONF_PRODUCT_CTX));
            $out .= $this->displayConfirmation('Ajustes guardados.');
        }
        $phone = Configuration::get(self::CONF_PHONE);
        $msg = Configuration::get(self::CONF_MESSAGE);
        $ctx = (int) Configuration::get(self::CONF_PRODUCT_CTX);

        return $out . '
        <div class="panel">
          <h3><i class="icon icon-whatsapp"></i> Botón de WhatsApp</h3>
          <form method="post" class="form-horizontal">
            <div class="form-group">
              <label class="control-label col-lg-3">Número (con prefijo de país, solo dígitos)</label>
              <div class="col-lg-4">
                <input type="text" name="' . self::CONF_PHONE . '" class="form-control"
                       value="' . htmlspecialchars($phone, ENT_QUOTES) . '" placeholder="34600111222">
                <p class="help-block">Ej.: 34600111222. Debe ser un número con WhatsApp
                (la app WhatsApp Business también admite fijos verificados).</p>
              </div>
            </div>
            <div class="form-group">
              <label class="control-label col-lg-3">Mensaje precargado</label>
              <div class="col-lg-6">
                <textarea name="' . self::CONF_MESSAGE . '" class="form-control" rows="2">'
                    . htmlspecialchars($msg, ENT_QUOTES) . '</textarea>
              </div>
            </div>
            <div class="form-group">
              <label class="control-label col-lg-3">Añadir el producto al mensaje</label>
              <div class="col-lg-4">
                <select name="' . self::CONF_PRODUCT_CTX . '" class="form-control fixed-width-lg">
                  <option value="1"' . ($ctx ? ' selected' : '') . '>Sí — en las fichas incluye nombre y enlace</option>
                  <option value="0"' . ($ctx ? '' : ' selected') . '>No — mensaje fijo siempre</option>
                </select>
              </div>
            </div>
            <div class="panel-footer">
              <button type="submit" name="submitJnWa" class="btn btn-default pull-right">
                <i class="process-icon-save"></i> Guardar
              </button>
            </div>
          </form>
        </div>';
    }

    public function hookActionFrontControllerSetMedia()
    {
        $this->context->controller->registerStylesheet(
            'module-jn-whatsapp',
            'modules/' . $this->name . '/views/css/front.css',
            ['media' => 'all', 'priority' => 200]
        );
    }

    public function hookDisplayBeforeBodyClosingTag()
    {
        $phone = preg_replace('/\D+/', '', (string) Configuration::get(self::CONF_PHONE));
        if (!$phone) {
            return '';
        }
        $message = (string) Configuration::get(self::CONF_MESSAGE);

        if ((int) Configuration::get(self::CONF_PRODUCT_CTX)
            && $this->context->controller
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

        $this->context->smarty->assign([
            'jn_wa_url' => 'https://wa.me/' . $phone . '?text=' . rawurlencode($message),
        ]);

        return $this->fetch('module:jn_whatsapp/views/templates/hook/button.tpl');
    }
}
