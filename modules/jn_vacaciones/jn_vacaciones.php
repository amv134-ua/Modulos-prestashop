<?php
/**
 * Aviso de vacaciones — Repuestos Juanito.
 * Ventana emergente que informa de que la tienda está de vacaciones hasta
 * una fecha configurable. Se cierra con la X de arriba a la derecha.
 * Aparece cada vez que el visitante ENTRA en la web, no al navegar por ella.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Jn_Vacaciones extends Module
{
    const CONF_ACTIVE = 'JN_VAC_ACTIVE';
    const CONF_DATE = 'JN_VAC_DATE';
    const CONF_TITLE = 'JN_VAC_TITLE';
    const CONF_TEXT = 'JN_VAC_TEXT';
    const CONF_MODE = 'JN_VAC_MODE';

    /** Modos de aparición */
    const MODE_ENTRY = 'entry';     // cada vez que entra desde fuera
    const MODE_SESSION = 'session'; // una vez por sesión del navegador

    private static $months = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    public function __construct()
    {
        $this->name = 'jn_vacaciones';
        $this->tab = 'front_office_features';
        $this->version = '1.2.0';
        $this->author = 'Repuestos Juanito';
        $this->need_instance = 0;
        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = 'Aviso de vacaciones';
        $this->description = 'Ventana emergente que avisa de que la tienda está de vacaciones hasta una fecha concreta.';
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('displayBeforeBodyClosingTag')
            && $this->registerHook('actionFrontControllerSetMedia')
            && Configuration::updateValue(self::CONF_ACTIVE, 0)
            && Configuration::updateValue(self::CONF_DATE, date('Y-m-d', strtotime('+15 days')))
            && Configuration::updateValue(self::CONF_TITLE, 'Estamos de vacaciones')
            && Configuration::updateValue(self::CONF_TEXT, 'Puedes seguir haciendo tu pedido con normalidad, pero no lo prepararemos ni lo enviaremos hasta nuestra vuelta. Gracias por tu paciencia.')
            && Configuration::updateValue(self::CONF_MODE, self::MODE_ENTRY);
    }

    public function uninstall()
    {
        foreach ([self::CONF_ACTIVE, self::CONF_DATE, self::CONF_TITLE, self::CONF_TEXT, self::CONF_MODE] as $k) {
            Configuration::deleteByName($k);
        }

        return parent::uninstall();
    }

    /**
     * Fecha en castellano: "15 de agosto de 2026".
     */
    private function prettyDate($ymd)
    {
        $ts = strtotime((string) $ymd);
        if (!$ts) {
            return '';
        }

        return (int) date('j', $ts) . ' de ' . self::$months[(int) date('n', $ts)] . ' de ' . date('Y', $ts);
    }

    /**
     * ¿Debe mostrarse hoy? Activo y con la fecha aún por llegar.
     */
    private function shouldShow()
    {
        if (!(int) Configuration::get(self::CONF_ACTIVE)) {
            return false;
        }
        $ts = strtotime((string) Configuration::get(self::CONF_DATE));
        if (!$ts) {
            return false;
        }

        // se sigue mostrando durante todo el día indicado
        return strtotime(date('Y-m-d')) <= strtotime(date('Y-m-d', $ts));
    }

    public function getContent()
    {
        $out = '';

        if (Tools::isSubmit('submitJnVac')) {
            $date = trim((string) Tools::getValue(self::CONF_DATE));
            $errors = [];

            if ($date !== '' && !strtotime($date)) {
                $errors[] = 'La fecha no es válida. Usa el selector de fecha.';
            }
            if ((int) Tools::getValue(self::CONF_ACTIVE) && $date === '') {
                $errors[] = 'Para activar el aviso tienes que indicar la fecha de vuelta.';
            }

            if ($errors) {
                $out .= $this->displayError(implode('<br>', $errors));
            } else {
                Configuration::updateValue(self::CONF_ACTIVE, (int) Tools::getValue(self::CONF_ACTIVE));
                Configuration::updateValue(self::CONF_DATE, $date ? date('Y-m-d', strtotime($date)) : '');
                Configuration::updateValue(self::CONF_TITLE, (string) Tools::getValue(self::CONF_TITLE));
                Configuration::updateValue(self::CONF_TEXT, (string) Tools::getValue(self::CONF_TEXT));
                $mode = Tools::getValue(self::CONF_MODE) === self::MODE_SESSION ? self::MODE_SESSION : self::MODE_ENTRY;
                Configuration::updateValue(self::CONF_MODE, $mode);
                $out .= $this->displayConfirmation('Ajustes guardados.');
            }
        }

        $active = (int) Configuration::get(self::CONF_ACTIVE);
        $date = (string) Configuration::get(self::CONF_DATE);
        $title = (string) Configuration::get(self::CONF_TITLE);
        $text = (string) Configuration::get(self::CONF_TEXT);
        $mode = (string) Configuration::get(self::CONF_MODE);

        $expired = '';
        if ($active && $date && strtotime(date('Y-m-d')) > strtotime($date)) {
            $expired = $this->displayWarning(
                'El aviso está activado pero la fecha (' . $this->prettyDate($date) . ') ya ha pasado, '
                . 'así que NO se está mostrando. Cambia la fecha o desactívalo.'
            );
        }

        $estado = $this->shouldShow()
            ? '<span style="background:#3ba33b;color:#fff;padding:3px 9px;border-radius:3px;font-size:12px">SE ESTÁ MOSTRANDO</span>'
            : '<span style="background:#999;color:#fff;padding:3px 9px;border-radius:3px;font-size:12px">no se muestra</span>';

        $h = function ($v) {
            return htmlspecialchars((string) $v, ENT_QUOTES);
        };

        // enlace de vista previa: la portada con ?jnvac=1
        $previewUrl = '';
        try {
            $base = $this->context->link->getPageLink('index', true);
            $previewUrl = $base . (strpos($base, '?') === false ? '?' : '&') . 'jnvac=1';
        } catch (Exception $e) {
            $previewUrl = __PS_BASE_URI__ . '?jnvac=1';
        }

        return $out . $expired . '
        <div class="panel">
          <h3><i class="icon icon-suitcase"></i> Aviso de vacaciones &nbsp; ' . $estado . '</h3>
          <form method="post" class="form-horizontal">

            <div class="form-group">
              <label class="control-label col-lg-3">¿Mostrar el aviso?</label>
              <div class="col-lg-4">
                <select name="' . self::CONF_ACTIVE . '" class="form-control fixed-width-lg">
                  <option value="1"' . ($active ? ' selected' : '') . '>Sí — estamos de vacaciones</option>
                  <option value="0"' . ($active ? '' : ' selected') . '>No — funcionamiento normal</option>
                </select>
                <p class="help-block">Ponlo en <strong>Sí</strong> cuando te vayas y en <strong>No</strong> al volver.</p>
              </div>
            </div>

            <div class="form-group">
              <label class="control-label col-lg-3">Volvemos el día</label>
              <div class="col-lg-4">
                <input type="date" name="' . self::CONF_DATE . '" class="form-control fixed-width-lg" value="' . $h($date) . '">
                <p class="help-block">
                  ' . ($date ? 'En la ventana pondrá: <strong>hasta el ' . $h($this->prettyDate($date)) . '</strong>.<br>' : '') . '
                  El aviso <strong>se oculta solo</strong> cuando pasa esta fecha, aunque se te olvide desactivarlo.
                </p>
              </div>
            </div>

            <div class="form-group">
              <label class="control-label col-lg-3">Título</label>
              <div class="col-lg-6">
                <input type="text" name="' . self::CONF_TITLE . '" class="form-control" value="' . $h($title) . '">
              </div>
            </div>

            <div class="form-group">
              <label class="control-label col-lg-3">Texto</label>
              <div class="col-lg-6">
                <textarea name="' . self::CONF_TEXT . '" class="form-control" rows="3">' . $h($text) . '</textarea>
                <p class="help-block">La frase «Volvemos el ' . $h($this->prettyDate($date ?: date('Y-m-d'))) . '» se añade sola, no la escribas aquí.</p>
              </div>
            </div>

            <div class="form-group">
              <label class="control-label col-lg-3">¿Cuándo aparece?</label>
              <div class="col-lg-6">
                <select name="' . self::CONF_MODE . '" class="form-control">
                  <option value="' . self::MODE_ENTRY . '"' . ($mode !== self::MODE_SESSION ? ' selected' : '') . '>Cada vez que entran en la web (recomendado)</option>
                  <option value="' . self::MODE_SESSION . '"' . ($mode === self::MODE_SESSION ? ' selected' : '') . '>Una sola vez por sesión del navegador</option>
                </select>
                <p class="help-block">
                  <strong>Cada vez que entran:</strong> sale cuando llegan desde Google, desde un enlace o
                  escribiendo la dirección. NO sale mientras navegan por la tienda. Si lo cierran, se van y
                  vuelven a entrar, les vuelve a salir.<br>
                  <strong>Una vez por sesión:</strong> sale una única vez y no vuelve hasta que cierran
                  el navegador. Menos insistente.
                </p>
              </div>
            </div>

            <div class="form-group">
              <label class="control-label col-lg-3">Ver cómo queda</label>
              <div class="col-lg-6">
                <a href="' . $h($previewUrl) . '" class="btn btn-default" target="_blank">
                  <i class="icon-external-link"></i> Abrir la tienda con el aviso a la vista
                </a>
                <p class="help-block">
                  Añadiendo <strong>?jnvac=1</strong> a cualquier dirección de la tienda, el aviso
                  se muestra siempre, sin importar el modo elegido ni si ya lo cerraste antes.
                  Úsalo para comprobarlo; a tus clientes les seguirá saliendo con la regla normal.
                </p>
              </div>
            </div>

            <div class="panel-footer">
              <button type="submit" name="submitJnVac" class="btn btn-default pull-right">
                <i class="process-icon-save"></i> Guardar
              </button>
            </div>
          </form>
        </div>';
    }

    public function hookActionFrontControllerSetMedia()
    {
        if (!$this->shouldShow()) {
            return;
        }
        $this->context->controller->registerStylesheet(
            'module-jn-vacaciones',
            'modules/' . $this->name . '/views/css/front.css',
            ['media' => 'all', 'priority' => 200]
        );
        $this->context->controller->registerJavascript(
            'module-jn-vacaciones',
            'modules/' . $this->name . '/views/js/front.js',
            ['position' => 'bottom', 'priority' => 200]
        );
    }

    public function hookDisplayBeforeBodyClosingTag()
    {
        if (!$this->shouldShow()) {
            return '';
        }

        $this->context->smarty->assign([
            'jn_vac_title' => (string) Configuration::get(self::CONF_TITLE),
            'jn_vac_text' => (string) Configuration::get(self::CONF_TEXT),
            'jn_vac_date' => $this->prettyDate(Configuration::get(self::CONF_DATE)),
            'jn_vac_mode' => Configuration::get(self::CONF_MODE) === self::MODE_SESSION
                ? self::MODE_SESSION
                : self::MODE_ENTRY,
        ]);

        return $this->fetch('module:jn_vacaciones/views/templates/hook/popup.tpl');
    }
}
