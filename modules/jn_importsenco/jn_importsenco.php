<?php
/**
 * Importación CSV — SENCO — Repuestos Juanito.
 *
 * Formato del proveedor Senco (delimitador ';', columnas en español).
 * Un producto por MODELO, con combinaciones Diámetro × Ancho × Anclaje × Color.
 * El ET va en la DESCRIPCIÓN (no es combinación: no afecta al precio y el
 * cliente lo concreta con nosotros según su vehículo).
 *
 * - Precio  : neto × 4 × 1,21 × 1,25   (hoja "Precios llantas", columnas A→D)
 * - Stock   : (Stock + Stock Fábrica) ÷ 4   (venta en juegos de 4)
 * - Imagen  : una por color (columna "Foto Predeterminada")
 * - Solo importa marcas que YA tienen categoría en la tienda.
 * - Productos que YA existen (por referencia MARCA-MODELO): se RESPETA su
 *   nombre y su descripción; solo se actualizan precio, stock y combinaciones.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Jn_ImportSenco extends Module
{
    /**
     * Las 7 marcas que se importan (decisión del usuario: el resto no se vende
     * lo suficiente y se descarta).
     *
     * La categoría NO se crea ni se toca: se BUSCA POR NOMBRE dentro de
     * "Llantas Marca". Así funciona con los ids que tenga cada tienda y
     * sobrevive a que se renombren o se recreen. Si no se encuentra la
     * categoría de una marca, esa marca se salta y se avisa en el informe.
     *
     * 'alias' = otros nombres con los que puede estar dada de alta.
     */
    const BRANDS = [
        'FONDMETAL'    => ['label' => 'Fondmetal',    'alias' => ['FONDMETAL']],
        'GMP'          => ['label' => 'GMP Italia',   'alias' => ['GMP ITALIA', 'GMP']],
        'MILLE MIGLIA' => ['label' => 'Mille Miglia', 'alias' => ['MILLE MIGLIA', 'MILLEMIGLIA']],
        'MIM'          => ['label' => 'MIM',          'alias' => ['MIM']],
        'MAK'          => ['label' => 'MAK',          'alias' => ['MAK']],
        'MOMO'         => ['label' => 'Momo',         'alias' => ['MOMO']],
        'ROMAC'        => ['label' => 'Romac',        'alias' => ['ROMAC']],
    ];

    /** categoría padre "Llantas Marca" */
    const PARENT_CAT = 13;

    /** categorías por diámetro: 15"→83 … 20"→252 */
    const DIAM_CATS = ['15' => 83, '16' => 84, '17' => 85, '18' => 86, '19' => 87, '20' => 252];

    /* Precio: PVP (IVA incl.) = neto × 4 × 1,21 × 1,25 ; en BD se guarda sin IVA */
    const PRICE_UNITS = 4;      // juego de 4 llantas
    const PRICE_VAT = 1.21;
    const PRICE_MARGIN = 1.25;
    /** el PVP final (con IVA) se redondea a la DECENA más cercana: 1034,45 -> 1030 · 1036,30 -> 1040 */
    const PRICE_ROUND_TO = 10;

    private $cache = [];

    public function __construct()
    {
        $this->name = 'jn_importsenco';
        $this->tab = 'administration';
        $this->version = '1.2.0';
        $this->author = 'Repuestos Juanito';
        $this->need_instance = 0;
        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = 'Importación CSV — Senco';
        $this->description = 'Importa el catálogo de Senco: un producto por modelo con combinaciones Diámetro × Ancho × Anclaje × Color, precio calculado, stock en juegos de 4 y una foto por color.';
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        return parent::install();
    }

    /* ==================== ADMIN ==================== */

    public function getContent()
    {
        $out = '';
        if (Tools::isSubmit('submitJnSenco') && isset($_FILES['jn_csv']) && is_uploaded_file($_FILES['jn_csv']['tmp_name'])) {
            $dir = _PS_MODULE_DIR_ . $this->name . '/var/';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $base = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) $_FILES['jn_csv']['name']);
            $dest = $dir . date('Ymd-His') . '-' . $base;
            if (!move_uploaded_file($_FILES['jn_csv']['tmp_name'], $dest)) {
                $out .= $this->displayError('No se pudo guardar el archivo subido.');
            } else {
                @set_time_limit(1800);
                $selected = [];
                foreach (array_keys(self::BRANDS) as $b) {
                    if (Tools::getValue('brand_' . md5($b))) {
                        $selected[] = $b;
                    }
                }
                try {
                    if (!$selected) {
                        throw new RuntimeException('No has marcado ninguna marca.');
                    }
                    $out .= $this->renderReport($this->importCsv($dest, $selected));
                } catch (Throwable $e) {
                    $out .= $this->displayError('Error durante la importación: ' . htmlspecialchars($e->getMessage()));
                }
            }
        }

        $brands = implode(', ', array_column(self::BRANDS, 'label'));

        return $out . '
        <div class="panel">
          <h3><i class="icon icon-upload"></i> Importación CSV — Senco</h3>
          <p>Sube el CSV de Senco (separado por <code>;</code>, con columnas Marca, Modelo, Anclaje,
             Diametro, Ancho, Et, Acabado, Neto, Stock, Stock Fabrica, Foto Predeterminada…).</p>
          <p><b>Solo se importan estas 7 marcas:</b> ' . $brands . '. El resto de marcas
             del archivo se ignoran (no se venden lo suficiente). La categoría de cada marca
             se busca por su nombre dentro de <i>Llantas Marca</i>; el módulo <b>no crea ni
             modifica categorías</b>.</p>
          <p><b>Un producto por modelo</b>, con combinaciones
             <b>Diámetro × Ancho × Anclaje × Color</b>. El <b>ET va en la descripción</b>
             (no cambia el precio; se concreta con el cliente según su vehículo).</p>
          <p><b>Precio:</b> <code>neto × 4 × 1,21 × 1,25</code>, <b>redondeado a la decena</b>
             (1.034,45&nbsp;€ &rarr; 1.030&nbsp;€ · 1.036,30&nbsp;€ &rarr; 1.040&nbsp;€).<br>
             <b>Stock:</b> <code>(Stock + Stock Fábrica) ÷ 4</code>, en juegos de 4. El ET no es una
             opción a elegir, pero los juegos se cuentan <b>por cada ET por separado</b> (no se puede
             montar un juego mezclando ET). <b>Sin stock NO se puede comprar.</b><br>
             <b>Fotos:</b> una por color.</p>
          <p class="alert alert-info"><b>Productos que ya existen</b> (localizados por la referencia
             <code>MARCA-MODELO</code>): se <b>respeta su nombre y su descripción actuales</b>; solo se
             actualizan precio, stock, combinaciones y fotos que falten. Los productos nuevos se crean
             con nombre y descripción generados automáticamente.</p>
          <form method="post" enctype="multipart/form-data">
            <div class="form-group">
              <label>Archivo CSV</label>
              <input type="file" name="jn_csv" accept=".csv" required>
            </div>
            <div class="form-group">
              <label>Marcas a importar</label>
              <div style="border:1px solid #ddd;border-radius:4px;padding:12px 14px;max-width:420px;background:#fafafa">
                ' . $this->renderBrandChecks() . '
              </div>
              <p class="help-block">Vienen todas marcadas. <b>Desmarca las que no quieras importar</b>
                 en esta pasada. Para la primera prueba, deja solo una.</p>
            </div>
            <button type="submit" name="submitJnSenco" class="btn btn-primary">
              <i class="process-icon-upload"></i> Importar
            </button>
          </form>
        </div>';
    }

    private function renderReport(array $rep)
    {
        $rows = '';
        foreach ($rep['models'] as $m) {
            $rows .= '<tr>
              <td>' . htmlspecialchars($m['brand'] . ' ' . $m['model']) . '</td>
              <td>' . ($m['action'] === 'created'
                    ? '<span class="label label-success">NUEVO</span>'
                    : '<span class="label label-info">ACTUALIZADO</span><br><small>nombre y descripción respetados</small>') . '</td>
              <td>#' . (int) $m['id_product'] . '</td>
              <td>' . (int) $m['combinations'] . '</td>
              <td>' . (int) $m['sets_total'] . '</td>
              <td>' . ($m['price_from']
                    ? number_format($m['price_from'], 2, ',', '.') . ' € – ' . number_format($m['price_to'], 2, ',', '.') . ' €'
                    : '<span class="text-muted">—</span>') . '</td>
              <td>' . (int) $m['images_added']
                    . ((int) $m['images_failed'] ? ' <span class="text-danger">(' . (int) $m['images_failed'] . ' fallidas)</span>' : '') . '</td>
            </tr>';
        }

        return '<div class="panel">
          <h3><i class="icon icon-check"></i> Importación completada
            <small>(' . (int) $rep['rows'] . ' filas leídas, ' . (int) $rep['skipped'] . ' ignoradas, '
              . $rep['seconds'] . ' s)</small></h3>
          <table class="table">
            <thead><tr><th>Modelo</th><th>Acción</th><th>Producto</th><th>Combinaciones</th>
                   <th>Juegos en stock</th><th>PVP (IVA incl.)</th><th>Fotos</th></tr></thead>
            <tbody>' . $rows . '</tbody>
          </table></div>';
    }

    /**
     * Busca la categoría de una marca DENTRO de "Llantas Marca", por nombre.
     * No crea ni modifica nada: si no existe, devuelve 0 y la marca se salta.
     */
    private function brandCategory($brand)
    {
        $k = 'cat:' . $brand;
        if (isset($this->cache[$k])) {
            return $this->cache[$k];
        }
        $names = self::BRANDS[$brand]['alias'] ?? [$brand];
        $db = Db::getInstance();
        $id = 0;
        foreach ($names as $n) {
            // TRIM porque algunas categorías tienen espacios de más al final ("ROMAC ")
            $id = (int) $db->getValue('SELECT c.id_category FROM ' . _DB_PREFIX_ . 'category c
                JOIN ' . _DB_PREFIX_ . 'category_lang cl ON cl.id_category=c.id_category AND cl.id_lang=1
                WHERE c.id_parent=' . (int) self::PARENT_CAT . "
                  AND UPPER(TRIM(cl.name))='" . pSQL(mb_strtoupper(trim($n))) . "'");
            if ($id) {
                break;
            }
        }
        if (!$id) { // último intento: que empiece por el nombre de la marca
            $id = (int) $db->getValue('SELECT c.id_category FROM ' . _DB_PREFIX_ . 'category c
                JOIN ' . _DB_PREFIX_ . 'category_lang cl ON cl.id_category=c.id_category AND cl.id_lang=1
                WHERE c.id_parent=' . (int) self::PARENT_CAT . "
                  AND UPPER(TRIM(cl.name)) LIKE '" . pSQL(mb_strtoupper(trim($brand))) . "%'");
        }

        return $this->cache[$k] = $id;
    }

    /** las 7 casillas del formulario, marcadas por defecto */
    private function renderBrandChecks()
    {
        $posted = Tools::isSubmit('submitJnSenco');
        $out = '';
        foreach (self::BRANDS as $brand => $info) {
            $id = 'brand_' . md5($brand);
            $cat = $this->brandCategory($brand);
            $checked = $posted ? (bool) Tools::getValue($id) : true;
            $warn = $cat ? '' : ' <span class="text-danger">(no encuentro su categoría — se saltará)</span>';
            $out .= '<div class="checkbox" style="margin:5px 0">
                <label style="font-weight:normal">
                  <input type="checkbox" name="' . $id . '" value="1"'
                    . ($checked ? ' checked' : '') . ($cat ? '' : ' disabled') . '>
                  <b>' . htmlspecialchars($info['label']) . '</b>
                  <small class="text-muted"> — ' . htmlspecialchars($brand) . '</small>' . $warn . '
                </label></div>';
        }

        return $out;
    }

    /* ==================== IMPORTACIÓN ==================== */

    public function importCsv($path, array $brands = null)
    {
        $t0 = microtime(true);
        $fh = fopen($path, 'r');
        if (!$fh) {
            throw new RuntimeException('No se puede abrir ' . $path);
        }
        $header = fgetcsv($fh, 0, ';');
        if ($header && isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }
        $idx = [];
        foreach ((array) $header as $i => $h) {
            $idx[mb_strtoupper(trim((string) $h, " \t\"'"))] = $i;
        }
        foreach (['MARCA', 'MODELO', 'ANCLAJE', 'DIAMETRO', 'ANCHO', 'ACABADO', 'NETO', 'STOCK'] as $req) {
            if (!isset($idx[$req])) {
                throw new RuntimeException('Falta la columna ' . $req . ' en el CSV');
            }
        }

        $models = [];
        $rows = 0;
        $skipped = 0;
        while (($r = fgetcsv($fh, 0, ';')) !== false) {
            if (count($r) < 5) {
                continue;
            }
            ++$rows;
            $g = function ($k) use ($r, $idx) { return isset($idx[$k]) ? trim((string) ($r[$idx[$k]] ?? '')) : ''; };

            $brand = mb_strtoupper($g('MARCA'));
            $model = $g('MODELO');
            if (!isset(self::BRANDS[$brand]) || $model === ''
                || ($brands !== null && !in_array($brand, $brands, true))
                || !$this->brandCategory($brand)) {
                ++$skipped;
                continue;
            }
            $diam = $this->numStr($g('DIAMETRO'));
            $width = $this->numStr($g('ANCHO'));
            $pcd = mb_strtoupper($g('ANCLAJE'));
            if ($diam === '' || $width === '' || $pcd === '') {
                ++$skipped;
                continue;
            }
            $color = $this->translateFinish($g('ACABADO'));
            $et = $this->numStr($g('ET'));
            $cost = $this->num($g('NETO'));
            $stock = (int) $this->num($g('STOCK')) + (int) $this->num($g('STOCK FABRICA'));
            $img = $g('FOTO PREDETERMINADA');

            $key = $brand . '|' . $model;
            if (!isset($models[$key])) {
                $models[$key] = [
                    'brand' => $brand, 'model' => $model,
                    'combos' => [], 'costs' => [], 'colors' => [], 'pcds' => [],
                    'etBySize' => [], 'colorImages' => [], 'extra' => [],
                ];
            }
            $m = &$models[$key];
            $ck = $color . '|' . $diam . '|' . $width . '|' . $pcd;
            /* El ET no es una combinación, pero el stock NO se puede sumar sin más:
               un juego son 4 llantas DEL MISMO ET (no se pueden mezclar). Por eso
               guardamos el stock POR ET y luego sumamos JUEGOS, no llantas.
               Ej.: ET40 con 2 llantas + ET45 con 2 -> 0 juegos (no 1). */
            $etKey = $et !== '' ? $et : '-';
            $m['combos'][$ck][$etKey] = ($m['combos'][$ck][$etKey] ?? 0) + $stock;
            if ($cost > 0) {
                $m['costs'][$ck] = isset($m['costs'][$ck]) ? min($m['costs'][$ck], $cost) : $cost;
            }
            $m['colors'][$color] = true;
            $m['pcds'][$pcd] = true;
            if ($et !== '') {
                $m['etBySize'][$diam][$width][$pcd][$et] = true;
            }
            if ($img !== '' && preg_match('#^https?://#i', $img)) {
                $m['colorImages'][$color][$img] = ($m['colorImages'][$color][$img] ?? 0) + 1;
            }
            foreach (['BUJE' => 'buje', 'INDICE CARGA' => 'carga', 'PESO' => 'peso', 'CONTRUCCION' => 'construccion'] as $col => $k) {
                $v = $g($col);
                if ($v !== '') {
                    $m['extra'][$k][$v] = true;
                }
            }
            unset($m);
        }
        fclose($fh);

        $result = [];
        foreach ($models as $m) {
            $result[] = $this->importModel($m);
        }

        return [
            'rows' => $rows, 'skipped' => $skipped,
            'seconds' => round(microtime(true) - $t0, 1),
            'models' => $result,
        ];
    }

    private function importModel(array $m)
    {
        $db = Db::getInstance();
        $brandPretty = $this->prettyBrand($m['brand']);
        $reference = $m['brand'] . '-' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $m['model']));

        /* ---- atributos ---- */
        $gDiam = $this->groupId('Pulgadas');
        $gWidth = $this->groupId('Ancho');
        $gPcd = $this->groupId('Anclaje');
        $gColor = $this->groupId('Color');

        $diams = $widths = $pcds = $colors = [];
        foreach (array_keys($m['combos']) as $ck) {
            [$c, $d, $w, $p] = explode('|', $ck);
            $diams[$d] = $diams[$d] ?? $this->attrId($gDiam, $d . '"', [$d . "''"]);
            $widths[$w] = $widths[$w] ?? $this->attrId($gWidth, $w . '"');
            $pcds[$p] = $pcds[$p] ?? $this->attrId($gPcd, strtolower($p));
            $colors[$c] = $colors[$c] ?? $this->attrId($gColor, $c);
        }

        /* ---- precios ---- */
        $priceByCk = [];
        foreach ($m['costs'] as $ck => $cost) {
            $priceByCk[$ck] = $this->finalPriceExclTax($cost);
        }
        $basePrice = $priceByCk ? round(min($priceByCk), 6) : 0.0;

        /* ---- producto: crear o localizar ---- */
        $idProduct = (int) $db->getValue("SELECT id_product FROM " . _DB_PREFIX_ . "product
            WHERE reference='" . pSQL($reference) . "'");
        $action = $idProduct ? 'updated' : 'created';

        if (!$idProduct) {
            $p = new Product();
            $p->reference = $reference;
            $p->id_manufacturer = $this->manufacturerId($brandPretty);
            $p->id_category_default = $this->brandCategory($m['brand']);
            $p->name = [1 => $this->buildName($brandPretty, $m)];
            $p->link_rewrite = [1 => Tools::str2url('kit-4x-' . $brandPretty . '-' . $m['model'])];
            $p->description = [1 => $this->buildDescription($brandPretty, $m)];
            $p->description_short = [1 => '<p>Juego de 4 llantas ' . $brandPretty . ' ' . $m['model'] . '.</p>'];
            $p->price = $basePrice;
            $p->id_tax_rules_group = $this->taxRulesGroup();
            $p->active = 1;
            $p->state = 1;
            $p->condition = 'new';
            $p->show_price = 1;
            $p->minimal_quantity = 1;
            $p->out_of_stock = 0;
            $p->add();
            $idProduct = (int) $p->id;
        } else {
            /* YA EXISTE: se respetan nombre y descripción (los tenéis puestos a mano).
               Solo se refresca el precio base. */
            $db->execute('UPDATE ' . _DB_PREFIX_ . 'product SET out_of_stock=0 WHERE id_product=' . $idProduct);
            if ($basePrice > 0) {
                $db->execute('UPDATE ' . _DB_PREFIX_ . 'product SET price=' . (float) $basePrice
                    . ' WHERE id_product=' . $idProduct);
                $db->execute('UPDATE ' . _DB_PREFIX_ . 'product_shop SET price=' . (float) $basePrice
                    . " WHERE id_product=$idProduct AND id_shop=1");
            }
        }

        /* categorías: la de la marca + las de diámetro que existan */
        $cats = [$this->brandCategory($m['brand'])];
        foreach (array_keys($diams) as $d) {
            $di = (string) (int) (float) $d;
            if (isset(self::DIAM_CATS[$di])) {
                $cats[] = self::DIAM_CATS[$di];
            }
        }
        (new Product($idProduct))->addToCategories($cats);

        /* ---- combinaciones: reconstruir ---- */
        $db->execute('DELETE pac FROM ' . _DB_PREFIX_ . 'product_attribute_combination pac
            JOIN ' . _DB_PREFIX_ . 'product_attribute pa ON pa.id_product_attribute=pac.id_product_attribute
            WHERE pa.id_product=' . $idProduct);
        $db->execute('DELETE FROM ' . _DB_PREFIX_ . "product_attribute_shop WHERE id_product=$idProduct");
        $db->execute('DELETE FROM ' . _DB_PREFIX_ . "product_attribute WHERE id_product=$idProduct");
        $db->execute('DELETE FROM ' . _DB_PREFIX_ . "stock_available WHERE id_product=$idProduct AND id_product_attribute>0");

        $pac = [];
        $totalSets = 0;
        $first = $default = 0;   // $default = la MÁS BARATA con stock (la que ve el cliente)
        $defaultPrice = null;
        $cheapest = 0;           // la más barata aunque no tenga stock, por si ninguna lo tuviera
        $cheapestPrice = null;
        $n = 0;
        $paByColor = [];
        foreach ($m['combos'] as $ck => $byEt) {
            [$c, $d, $w, $p] = explode('|', $ck);
            // juegos = suma de juegos completos de CADA ET (no se mezclan ET)
            $sets = 0;
            foreach ((array) $byEt as $wheelsEt) {
                $sets += intdiv((int) $wheelsEt, 4);
            }
            $totalSets += $sets;
            $impact = isset($priceByCk[$ck]) ? round($priceByCk[$ck] - $basePrice, 6) : 0.0;
            $db->execute('INSERT INTO ' . _DB_PREFIX_ . 'product_attribute
                (id_product, reference, supplier_reference, ean13, upc, mpn, isbn, wholesale_price, price,
                 ecotax, weight, unit_price_impact, default_on, minimal_quantity, low_stock_alert, available_date)
                VALUES (' . $idProduct . ", '', '', '', '', '', '', 0, " . (float) $impact . ", 0, 0, 0, NULL, 1, 0, '0000-00-00')");
            $idPa = (int) $db->Insert_ID();
            if (!$first) {
                $first = $idPa;
            }
            /* combinación por defecto = la MÁS BARATA (con stock si la hay),
               para que el precio "desde" y el de la ficha sean el más bajo */
            $precioCk = isset($priceByCk[$ck]) ? (float) $priceByCk[$ck] : 0.0;
            if ($cheapestPrice === null || $precioCk < $cheapestPrice) {
                $cheapestPrice = $precioCk;
                $cheapest = $idPa;
            }
            if ($sets > 0 && ($defaultPrice === null || $precioCk < $defaultPrice)) {
                $defaultPrice = $precioCk;
                $default = $idPa;
            }
            $paByColor[$c][] = $idPa;
            $db->execute('INSERT INTO ' . _DB_PREFIX_ . "product_attribute_shop
                (id_product, id_product_attribute, id_shop, wholesale_price, price, ecotax, weight,
                 unit_price_impact, default_on, minimal_quantity, low_stock_alert, available_date)
                VALUES ($idProduct, $idPa, 1, 0, " . (float) $impact . ", 0, 0, 0, NULL, 1, 0, '0000-00-00')");
            foreach ([$colors[$c], $diams[$d], $widths[$w], $pcds[$p]] as $ia) {
                $pac[] = '(' . (int) $ia . ',' . $idPa . ')';
            }
            $db->execute('INSERT INTO ' . _DB_PREFIX_ . "stock_available
                (id_product, id_product_attribute, id_shop, id_shop_group, quantity, physical_quantity,
                 reserved_quantity, depends_on_stock, out_of_stock, location)
                VALUES ($idProduct, $idPa, 1, 0, $sets, $sets, 0, 0, 0, '')");  // out_of_stock=0 -> sin stock NO se puede comprar
            if (count($pac) >= 2000) {
                $db->execute('INSERT INTO ' . _DB_PREFIX_ . 'product_attribute_combination (id_attribute, id_product_attribute) VALUES ' . implode(',', $pac));
                $pac = [];
            }
            ++$n;
        }
        if ($pac) {
            $db->execute('INSERT INTO ' . _DB_PREFIX_ . 'product_attribute_combination (id_attribute, id_product_attribute) VALUES ' . implode(',', $pac));
        }

        /* preferencia: la más barata CON stock -> la más barata aunque no tenga
           -> la primera insertada */
        if (!$default) {
            $default = $cheapest ?: $first;
        }
        if ($default) {
            $db->execute('UPDATE ' . _DB_PREFIX_ . "product_attribute SET default_on=1 WHERE id_product_attribute=$default");
            $db->execute('UPDATE ' . _DB_PREFIX_ . "product_attribute_shop SET default_on=1 WHERE id_product_attribute=$default");
            $db->execute('UPDATE ' . _DB_PREFIX_ . "product SET cache_default_attribute=$default WHERE id_product=$idProduct");
            $db->execute('UPDATE ' . _DB_PREFIX_ . "product_shop SET cache_default_attribute=$default WHERE id_product=$idProduct");
        }
        $agg = (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . "stock_available
            WHERE id_product=$idProduct AND id_product_attribute=0");
        if ($agg) {
            $db->execute('UPDATE ' . _DB_PREFIX_ . "stock_available SET quantity=$totalSets, physical_quantity=$totalSets,
                out_of_stock=0 WHERE id_product=$idProduct AND id_product_attribute=0");
        } else {
            $db->execute('INSERT INTO ' . _DB_PREFIX_ . "stock_available
                (id_product, id_product_attribute, id_shop, id_shop_group, quantity, physical_quantity,
                 reserved_quantity, depends_on_stock, out_of_stock, location)
                VALUES ($idProduct, 0, 1, 0, $totalSets, $totalSets, 0, 0, 0, '')");
        }

        /* ---- imágenes: una por color ---- */
        $img = ['added' => 0, 'failed' => 0];
        if (!empty($m['colorImages'])) {
            try {
                $img = $this->importColorImages($idProduct, $m['colorImages'], $paByColor);
            } catch (Throwable $e) {
                $img['failed'] = count($m['colorImages']);
            }
        }

        if (Module::isEnabled('ps_facetedsearch')) {
            $fs = Module::getInstanceByName('ps_facetedsearch');
            try {
                if (method_exists($fs, 'indexAttributes')) {
                    $fs->indexAttributes($idProduct);
                }
                if (method_exists($fs, 'indexProductPrices')) {
                    $fs->indexProductPrices($idProduct);
                }
            } catch (Throwable $e) { /* no crítico */
            }
        }

        return [
            'brand' => $brandPretty, 'model' => $m['model'],
            'id_product' => $idProduct, 'action' => $action,
            'combinations' => $n, 'sets_total' => $totalSets,
            'price_from' => $basePrice > 0 ? round($basePrice * self::PRICE_VAT, 2) : 0,
            'price_to' => $priceByCk ? round(max($priceByCk) * self::PRICE_VAT, 2) : 0,
            'images_added' => $img['added'], 'images_failed' => $img['failed'],
        ];
    }

    /* ==================== NOMBRE Y DESCRIPCIÓN ==================== */

    private function buildName($brandPretty, array $m)
    {
        $ds = [];
        foreach (array_keys($m['combos']) as $ck) {
            $ds[explode('|', $ck)[1]] = true;
        }
        $ds = array_keys($ds);
        usort($ds, function ($a, $b) { return (float) $a <=> (float) $b; });
        $str = implode('', array_map(function ($d) { return $d . '"'; }, $ds));
        $colorWord = count($m['colors']) > 1 ? 'colores' : mb_strtolower((string) array_key_first($m['colors']));

        return 'Kit 4x ' . $brandPretty . ' ' . $m['model'] . ' ' . $str . ' ' . $colorWord;
    }

    private function buildDescription($brandPretty, array $m)
    {
        $h = '<p><strong>Juego de 4 llantas ' . $brandPretty . ' ' . $m['model'] . '.</strong></p>';
        $h .= '<p>El precio indicado incluye 4 llantas, tapas, tornillería y arillos si son necesarios para su aplicación. Portes incluidos en península y Baleares, resto consultar.</p>';

        $ds = array_keys($m['etBySize']);
        usort($ds, function ($a, $b) { return (float) $a <=> (float) $b; });

        $h .= '<p><strong>Medidas, anclajes y ET disponibles:</strong></p>';
        foreach ($ds as $d) {
            $ws = array_keys($m['etBySize'][$d]);
            usort($ws, function ($a, $b) { return (float) $a <=> (float) $b; });
            $h .= '<p><strong>' . $d . '"</strong></p><ul>';
            foreach ($ws as $w) {
                foreach ($m['etBySize'][$d][$w] as $pcd => $ets) {
                    $e = array_keys($ets);
                    usort($e, function ($a, $b) { return (float) $a <=> (float) $b; });
                    $h .= '<li>' . $w . 'x' . $d . ' ' . strtolower($pcd) . ' — ET '
                        . (count($e) > 1 ? implode(', ', $e) : reset($e)) . '</li>';
                }
            }
            $h .= '</ul>';
        }

        $cs = array_keys($m['colors']);
        sort($cs, SORT_NATURAL | SORT_FLAG_CASE);
        $h .= '<p><strong>Colores disponibles:</strong> ' . $this->joinEs($cs) . '.</p>';

        if (!empty($m['extra']['buje'])) {
            $b = array_keys($m['extra']['buje']);
            sort($b, SORT_NATURAL);
            $h .= '<p><strong>Centraje (buje):</strong> ' . implode(' · ', $b) . ' mm.</p>';
        }
        if (!empty($m['extra']['construccion'])) {
            $h .= '<p><strong>Construcción:</strong> ' . $this->joinEs(array_map('ucfirst', array_map('strtolower', array_keys($m['extra']['construccion'])))) . '.</p>';
        }

        $h .= '<p>El ET exacto depende de tu vehículo. Imprescindible que nos comuniques en comentarios tu modelo de vehículo, añadir todos los detalles posibles, año, motorización, etc... en caso de dudas nuestro departamento técnico se pondrá en contacto.</p>';
        $h .= '<p>No olvides incluir al carrito tus neumáticos si los necesitaras. Al comprar las llantas te regalamos los montajes, equilibrados y válvulas; lo recibirás todo listo para montar al coche.</p>';

        return $h;
    }

    /* ==================== IMÁGENES ==================== */

    private function importColorImages($idProduct, array $colorImages, array $paByColor)
    {
        $db = Db::getInstance();
        $added = $failed = 0;
        $existing = [];
        foreach ((array) $db->executeS('SELECT i.id_image, il.legend FROM ' . _DB_PREFIX_ . 'image i
            JOIN ' . _DB_PREFIX_ . 'image_lang il ON il.id_image=i.id_image AND il.id_lang=1
            WHERE i.id_product=' . (int) $idProduct) as $row) {
            $existing[mb_strtolower(trim((string) $row['legend']))] = (int) $row['id_image'];
        }
        $hasCover = (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'image
            WHERE id_product=' . (int) $idProduct . ' AND cover=1');

        foreach ($colorImages as $color => $urls) {
            $k = mb_strtolower(trim($color));
            if (isset($existing[$k])) {
                $this->linkImage($existing[$k], $paByColor[$color] ?? []);
                continue;
            }
            arsort($urls);
            $url = (string) array_key_first($urls);
            $tmp = tempnam(sys_get_temp_dir(), 'jnsen');
            if (!$this->download($url, $tmp) || !@getimagesize($tmp)) {
                @unlink($tmp);
                ++$failed;
                continue;
            }
            $image = new Image();
            $image->id_product = (int) $idProduct;
            $image->position = Image::getHighestPosition($idProduct) + 1;
            $image->cover = $hasCover ? 0 : 1;
            $image->legend = [1 => $color];
            if (!$image->add()) {
                @unlink($tmp);
                ++$failed;
                continue;
            }
            $image->associateTo(1);
            $hasCover = 1;
            if (!$this->makeThumbs($image, $tmp)) {
                $image->delete();
                @unlink($tmp);
                ++$failed;
                continue;
            }
            @unlink($tmp);
            ++$added;
            $this->linkImage((int) $image->id, $paByColor[$color] ?? []);
        }

        return ['added' => $added, 'failed' => $failed];
    }

    private function download($url, $dest)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; RepuestosJuanito/1.0)',
        ]);
        $d = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($d === false || $code !== 200 || strlen((string) $d) < 1024) {
            return false;
        }

        return (bool) @file_put_contents($dest, $d);
    }

    private function makeThumbs(Image $image, $tmp)
    {
        if (!$image->createImgFolder()) {
            return false;
        }
        if (!ImageManager::resize($tmp, _PS_PROD_IMG_DIR_ . $image->getExistingImgPath() . '.' . $image->image_format)) {
            return false;
        }
        foreach (ImageType::getImagesTypes('products') as $t) {
            ImageManager::resize($tmp,
                _PS_PROD_IMG_DIR_ . $image->getExistingImgPath() . '-' . stripslashes($t['name']) . '.' . $image->image_format,
                (int) $t['width'], (int) $t['height'], $image->image_format);
        }

        return true;
    }

    private function linkImage($idImage, array $idsPa)
    {
        if (!$idsPa) {
            return;
        }
        $db = Db::getInstance();
        $v = [];
        foreach ($idsPa as $id) {
            $v[] = '(' . (int) $id . ',' . (int) $idImage . ')';
            if (count($v) >= 2000) {
                $db->execute('INSERT IGNORE INTO ' . _DB_PREFIX_ . 'product_attribute_image (id_product_attribute, id_image) VALUES ' . implode(',', $v));
                $v = [];
            }
        }
        if ($v) {
            $db->execute('INSERT IGNORE INTO ' . _DB_PREFIX_ . 'product_attribute_image (id_product_attribute, id_image) VALUES ' . implode(',', $v));
        }
    }

    /* ==================== HELPERS ==================== */

    /**
     * PVP final (IVA incluido) redondeado a la decena:
     *   neto x 4 x 1,21 x 1,25  ->  redondeo a múltiplo de 10
     * Ej.: 1034,45 -> 1030 · 1036,30 -> 1040
     */
    private function finalPriceWithTax($costNet)
    {
        $pvp = $costNet * self::PRICE_UNITS * self::PRICE_VAT * self::PRICE_MARGIN;

        return round($pvp / self::PRICE_ROUND_TO) * self::PRICE_ROUND_TO;
    }

    /** el mismo PVP pero SIN IVA, que es como lo guarda PrestaShop */
    private function finalPriceExclTax($costNet)
    {
        return $this->finalPriceWithTax($costNet) / self::PRICE_VAT;
    }

    /** "67,80000" -> 67.8 ; "1.234,5" -> 1234.5 */
    private function num($raw)
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return 0.0;
        }
        if (strpos($s, ',') !== false) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        }
        preg_match('/-?\d+(?:\.\d+)?/', $s, $mm);

        return isset($mm[0]) ? (float) $mm[0] : 0.0;
    }

    /** normaliza un número a texto corto: "6.50" -> "6.5" ; "19" -> "19" */
    private function numStr($raw)
    {
        $v = $this->num($raw);
        if ($v == 0.0 && trim((string) $raw) === '') {
            return '';
        }
        $s = rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');

        return $s === '' ? '0' : $s;
    }

    /** acabados del proveedor (inglés) -> castellano */
    public function translateFinish($en)
    {
        $base = [
            'BLACK' => 'Negro', 'SILVER' => 'Plata', 'GOLD' => 'Dorado', 'BRONZE' => 'Bronce',
            'GUNMETAL' => 'Antracita', 'ANTHRACITE' => 'Antracita', 'TITANIUM' => 'Titanio',
            'WHITE' => 'Blanco', 'GREY' => 'Gris', 'GRAY' => 'Gris', 'BRAUN' => 'Marrón',
            'BLUE' => 'Azul', 'RED' => 'Rojo', 'GREEN' => 'Verde', 'CHROME' => 'Cromo',
            'HYPER' => 'Hyper', 'DARK' => 'Oscuro', 'LIGHT' => 'Claro', 'FACE' => 'frente',
            'LIP' => 'borde', 'SIDE' => 'lateral',
        ];
        $fin = ['GLOSS' => 'brillo', 'GLOSSY' => 'brillo', 'MATT' => 'mate', 'MATTE' => 'mate',
            'SATIN' => 'satinado', 'POLISHED' => 'pulido', 'MACHINED' => 'diamantado', 'W/' => 'con'];

        $out = [];
        $suffix = '';
        foreach (preg_split('/\s+/', mb_strtoupper(trim((string) $en))) as $t) {
            if ($t === '') {
                continue;
            }
            if (isset($fin[$t])) {
                if ($t === 'W/') {
                    $out[] = 'con';
                } else {
                    $suffix = $fin[$t];
                }
            } elseif (isset($base[$t])) {
                $out[] = $base[$t];
            } else {
                $out[] = Tools::ucfirst(mb_strtolower($t));
            }
        }
        $name = trim(implode(' ', $out) . ($suffix !== '' ? ' ' . $suffix : ''));

        return $name !== '' ? $name : trim((string) $en);
    }

    private function joinEs(array $items)
    {
        $items = array_values($items);
        if (count($items) <= 1) {
            return (string) reset($items);
        }
        $last = array_pop($items);

        return implode(', ', $items) . ' y ' . $last;
    }

    private function prettyBrand($b)
    {
        $map = ['GMP' => 'GMP Italia', 'FOX' => 'Fox', 'MAK' => 'MAK', 'MOMO' => 'Momo',
            'MONACO' => 'Monaco', 'ROMAC' => 'Romac', 'FONDMETAL' => 'Fondmetal'];
        $u = mb_strtoupper($b);

        return $map[$u] ?? Tools::ucfirst(mb_strtolower($b));
    }

    private function taxRulesGroup()
    {
        if (isset($this->cache['trg'])) {
            return $this->cache['trg'];
        }
        $id = (int) Configuration::get('PS_TAX_RULES_GROUP');
        if (!$id) {
            $id = (int) Db::getInstance()->getValue('SELECT id_tax_rules_group FROM ' . _DB_PREFIX_ . 'tax_rules_group WHERE active=1 ORDER BY id_tax_rules_group');
        }

        return $this->cache['trg'] = $id;
    }

    private function manufacturerId($name)
    {
        $k = 'm:' . mb_strtoupper($name);
        if (isset($this->cache[$k])) {
            return $this->cache[$k];
        }
        $id = (int) Db::getInstance()->getValue('SELECT id_manufacturer FROM ' . _DB_PREFIX_ . "manufacturer
            WHERE UPPER(name)='" . pSQL(mb_strtoupper($name)) . "'");
        if (!$id) {
            $man = new Manufacturer();
            $man->name = $name;
            $man->active = 1;
            $man->add();
            $id = (int) $man->id;
        }

        return $this->cache[$k] = $id;
    }

    private function groupId($name)
    {
        $k = 'g:' . mb_strtolower($name);
        if (isset($this->cache[$k])) {
            return $this->cache[$k];
        }
        $id = (int) Db::getInstance()->getValue('SELECT ag.id_attribute_group FROM ' . _DB_PREFIX_ . 'attribute_group ag
            JOIN ' . _DB_PREFIX_ . 'attribute_group_lang agl ON agl.id_attribute_group=ag.id_attribute_group AND agl.id_lang=1
            WHERE LOWER(agl.name)=\'' . pSQL(mb_strtolower($name)) . '\'');
        if (!$id) {
            $g = new AttributeGroup();
            $g->group_type = 'select';
            $g->name = [1 => $name];
            $g->public_name = [1 => $name];
            $g->add();
            $id = (int) $g->id;
        }

        return $this->cache[$k] = $id;
    }

    private function attrId($gid, $name, array $alts = [])
    {
        $k = 'a:' . $gid . ':' . mb_strtolower($name);
        if (isset($this->cache[$k])) {
            return $this->cache[$k];
        }
        $db = Db::getInstance();
        $find = function ($n) use ($db, $gid) {
            return (int) $db->getValue('SELECT a.id_attribute FROM ' . _DB_PREFIX_ . 'attribute a
                JOIN ' . _DB_PREFIX_ . 'attribute_lang al ON al.id_attribute=a.id_attribute AND al.id_lang=1
                WHERE a.id_attribute_group=' . (int) $gid . ' AND LOWER(al.name)=\'' . pSQL(mb_strtolower($n)) . '\'');
        };
        $id = $find($name);
        foreach ($alts as $alt) {
            if ($id) {
                break;
            }
            $id = $find($alt);
        }
        if (!$id) {
            $a = new ProductAttribute();
            $a->id_attribute_group = (int) $gid;
            $a->name = [1 => $name];
            $a->add();
            $id = (int) $a->id;
        }

        return $this->cache[$k] = $id;
    }
}
