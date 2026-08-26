<?php
/**
 * Importación CSV — 2Forge / BOLA (formato TWG) — Repuestos Juanito.
 *
 * Un producto por MODELO del CSV ("Kit 4x {Marca} {Modelo} …"), con
 * combinaciones dinámicas Color × Pulgadas × Ancho creadas por
 * inserción masiva (miles de combinaciones sin despeinarse), descripción
 * generada a partir de los datos (ET agrupado por pulgada y anchura, colores,
 * anclajes) y stock por combinación = stock del CSV ÷ 4 (venta en juegos).
 * v2: precios desde COST PRICE del CSV y una imagen por color.
 * v3 (jul 2026): el ANCLAJE deja de ser combinación (va en la descripción por pulgada):
 *     93% menos de combinaciones => fichas rápidas. Stock = máximo entre anclajes.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Jn_Import2ForgeBola extends Module
{
    /** cat. por diámetro: 15"→83 … 20"→252 */
    const DIAM_CATS = ['15' => 83, '16' => 84, '17' => 85, '18' => 86, '19' => 87, '20' => 252];

    /** categoría padre donde cuelgan las de marca */
    const PARENT_CAT = 13;

    /** nombre de la categoría de cada marca (se busca por nombre, no por id,
        para que siga funcionando si algún día las recreas) */
    const BRAND_CATS = [
        '2FORGE' => '2FORGE WHEELS',
        'BOLA' => 'BOLA WHEELS',
    ];

    /* --- Fórmula de precio (hoja "Precios llantas", columnas P/Q/R) ---
       total compra = (((coste_libras x 4) + 60) x 1,07 x 1,2 x 1,21) + 30
       PVP (IVA incl.) = total compra x multiplicador según coste por llanta:
         hasta £150 -> 1,173 · hasta £250 -> 1,2 · por encima -> 1,25          */
    const PRICE_FIXED_ADD = 60;      // suplemento sobre el coste de las 4 llantas
    const PRICE_F1 = 1.07;           // factor 1
    const PRICE_F2 = 1.2;            // factor 2 (cambio libra->euro)
    const PRICE_VAT = 1.21;          // IVA 21%
    const PRICE_FIXED_END = 30;      // suplemento final
    const PRICE_ROUND_TO = 10;       // redondeo del PVP a la decena (1893,52 -> 1890 / 1896 -> 1900)
    const PRICE_BRACKET_1 = 150;     // £ por llanta
    const PRICE_BRACKET_2 = 250;
    const PRICE_MULT_1 = 1.173;
    const PRICE_MULT_2 = 1.2;
    const PRICE_MULT_3 = 1.25;

    private $attrCache = [];

    public function __construct()
    {
        $this->name = 'jn_import2forgebola';
        $this->tab = 'administration';
        $this->version = '3.0.0';
        $this->author = 'Repuestos Juanito';
        $this->need_instance = 0;
        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = 'Importación CSV — 2Forge / BOLA';
        $this->description = 'Importa el catálogo del proveedor (formato TWG): un producto por modelo con combinaciones Color × Pulgadas × Ancho, descripción autogenerada y stock en juegos de 4.';
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
        if (Tools::isSubmit('submitJnImport') && isset($_FILES['jn_csv']) && is_uploaded_file($_FILES['jn_csv']['tmp_name'])) {
            $dir = _PS_MODULE_DIR_ . $this->name . '/var/';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $base = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) $_FILES['jn_csv']['name']);
            $dest = $dir . date('Ymd-His') . '-' . $base;
            if (!move_uploaded_file($_FILES['jn_csv']['tmp_name'], $dest)) {
                $out .= $this->displayError('No se pudo guardar el archivo subido.');
            } else {
                @set_time_limit(900);
                try {
                    $report = $this->importCsv($dest);
                    $out .= $this->renderReport($report);
                } catch (Throwable $e) {
                    $out .= $this->displayError('Error durante la importación: ' . htmlspecialchars($e->getMessage()));
                }
            }
        }

        return $out . '
        <div class="panel">
          <h3><i class="icon icon-upload"></i> Importación CSV — 2Forge / BOLA (formato TWG)</h3>
          <p>Sube el CSV del proveedor (columnas BRAND, MODEL, COLOUR, SIZE, PCD, OFFSET, STOCK…).
             Se crea <b>un producto por modelo</b>; si el producto ya existe (por referencia
             MARCA-MODELO) se <b>actualiza</b>: descripción regenerada y combinaciones/stock
             reconstruidos. El stock del CSV son llantas sueltas: se divide entre 4
             (venta en juegos) y con menos de 4 no se puede comprar.</p>
          <p><b>Precios:</b> se calculan solos desde la columna <code>COST PRICE</code> del CSV:
             <code>(((coste&nbsp;×&nbsp;4)&nbsp;+&nbsp;60)&nbsp;×&nbsp;1,07&nbsp;×&nbsp;1,2&nbsp;×&nbsp;1,21)&nbsp;+&nbsp;30</code>,
             multiplicado por <b>1,173</b> (hasta £150/llanta), <b>1,2</b> (hasta £250) o <b>1,25</b> (más de £250).
             El producto queda con el precio de la combinación más barata y cada combinación suma su diferencia.</p>
          <p><b>Fotos:</b> se descarga <b>una imagen por color</b> del modelo (no una por medida) y se asocia
             a las combinaciones de ese color. Si el color ya tenía foto, no se vuelve a descargar.</p>
          <p><b>Anclaje (v3):</b> ya <b>no</b> es una opción a elegir en la ficha. Al ser llanta forjada se
             taladra al anclaje del vehículo del cliente, así que los anclajes se listan <b>en la descripción,
             agrupados por pulgada</b>. Con esto las combinaciones bajan ~93% (p.ej. ZF8: 2.331 &rarr; 168) y
             la ficha carga rápido. El <b>stock</b> de cada combinación es el <b>máximo</b> entre anclajes
             (comprobado en el CSV: el stock no cambia entre anclajes de la misma llanta).</p>
          <p class="help-block">Junto al informe se genera un CSV con los colores traducidos al castellano.</p>
          <form method="post" enctype="multipart/form-data">
            <div class="form-group">
              <input type="file" name="jn_csv" accept=".csv" required>
            </div>
            <button type="submit" name="submitJnImport" class="btn btn-primary">
              <i class="process-icon-upload"></i> Importar
            </button>
          </form>
        </div>';
    }

    private function renderReport(array $report)
    {
        $rows = '';
        foreach ($report['models'] as $m) {
            $rows .= '<tr>
              <td>' . htmlspecialchars($m['brand'] . ' ' . $m['model']) . '</td>
              <td>' . ($m['action'] === 'created' ? '<span class="label label-success">NUEVO</span>' : '<span class="label label-info">ACTUALIZADO</span>') . '</td>
              <td><a href="' . htmlspecialchars($m['edit_link']) . '" target="_blank">#' . (int) $m['id_product'] . '</a></td>
              <td>' . (int) $m['combinations'] . '</td>
              <td>' . (int) $m['sets_total'] . '</td>
              <td>' . ($m['price_from']
                    ? number_format($m['price_from'], 2, ',', '.') . ' € – ' . number_format($m['price_to'], 2, ',', '.') . ' €'
                    : '<span class="text-muted">sin precio</span>') . '</td>
              <td>' . (int) $m['images_added']
                    . ((int) $m['images_failed'] ? ' <span class="text-danger">(' . (int) $m['images_failed'] . ' fallidas)</span>' : '') . '</td>
              <td>' . htmlspecialchars(implode(', ', $m['attrs_created']) ?: '—') . '</td>
            </tr>';
        }
        $csvLink = $report['translated_csv_url']
            ? '<p><a class="btn btn-default" href="' . htmlspecialchars($report['translated_csv_url']) . '" download>
                 <i class="icon icon-download"></i> Descargar CSV con colores traducidos</a></p>'
            : '';

        return '<div class="panel">
          <h3><i class="icon icon-check"></i> Importación completada
              <small>(' . (int) $report['rows'] . ' filas, ' . (int) $report['skipped'] . ' descartadas, '
                . $report['seconds'] . ' s)</small></h3>
          <table class="table">
            <thead><tr><th>Modelo</th><th>Acción</th><th>Producto</th><th>Combinaciones</th>
                   <th>Juegos en stock</th><th>PVP (IVA incl.)</th><th>Fotos</th><th>Atributos creados</th></tr></thead>
            <tbody>' . $rows . '</tbody>
          </table>' . $csvLink . '</div>';
    }

    /* ==================== IMPORTACIÓN ==================== */

    /**
     * @return array{rows:int,skipped:int,seconds:float,models:array,translated_csv_url:?string}
     */
    public function importCsv($path)
    {
        $t0 = microtime(true);
        $fh = fopen($path, 'r');
        if (!$fh) {
            throw new RuntimeException('No se puede abrir ' . $path);
        }
        $header = fgetcsv($fh);
        if ($header && isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]); // BOM
        }
        $idx = [];
        foreach ($header as $i => $h) {
            $idx[strtoupper(trim($h, " \t\"'"))] = $i;
        }
        foreach (['BRAND', 'MODEL', 'COLOUR', 'SIZE', 'PCD', 'OFFSET', 'STOCK'] as $req) {
            if (!isset($idx[$req])) {
                throw new RuntimeException('Falta la columna ' . $req . ' en el CSV');
            }
        }

        $models = [];
        $rows = 0;
        $skipped = 0;
        $translated = [];
        $translated[] = $header;
        $colIdx = $idx['COLOUR'];

        while (($r = fgetcsv($fh)) !== false) {
            if (count($r) < 2) {
                continue;
            }
            ++$rows;
            $g = function ($k) use ($r, $idx) { return trim((string) ($r[$idx[$k]] ?? '')); };
            $brand = $g('BRAND');
            $model = $g('MODEL');
            $colourEn = $g('COLOUR');
            $size = strtoupper($g('SIZE'));
            $pcd = strtoupper($g('PCD'));
            $et = $g('OFFSET');
            $stock = (int) preg_replace('/\D+/', '', $g('STOCK'));
            // v2: coste por llanta en libras e imagen del color
            $cost = isset($idx['COST PRICE']) ? $this->parseMoney($g('COST PRICE')) : null;
            $image = isset($idx['IMAGE']) ? $g('IMAGE') : '';

            $colourEs = $this->translateColour($colourEn);
            $r[$colIdx] = $colourEs;
            $translated[] = $r;

            if ($brand === '' || $model === '' || $colourEn === '' || strpos($size, 'X') === false || $pcd === '') {
                ++$skipped;
                continue;
            }
            [$width, $diam] = explode('X', $size, 2);
            $width = rtrim(trim($width), '"');
            $diam = rtrim(trim($diam), '"');
            if (!is_numeric($width) || !is_numeric($diam)) {
                ++$skipped;
                continue;
            }
            $key = $brand . '|' . $model;
            if (!isset($models[$key])) {
                $models[$key] = [
                    'brand' => $brand, 'model' => $model,
                    'combos' => [], 'etByDW' => [], 'colors' => [], 'pcds' => [],
                    'colorDiam' => [],
                    'costs' => [],       // v2: coste (£) por combinación
                    'colorImages' => [], // v2: url de imagen por color (con frecuencia)
                    'pcdByDiam' => [],   // v3: anclajes disponibles por pulgada
                ];
            }
            $m = &$models[$key];
            /* v3: la combinación YA NO incluye el anclaje (la llanta es forjada y se
               taladra a medida). Clave = color|pulgadas|ancho. El anclaje pasa a la
               descripción, agrupado por pulgada. */
            $ck = $colourEs . '|' . $diam . '|' . $width;
            // v3: stock = MÁXIMO entre anclajes, no suma. Verificado sobre el CSV:
            // el stock no varía entre anclajes de la misma llanta; lo único que cambia
            // es 0 (anclaje no disponible) frente al stock real.
            $m['combos'][$ck] = isset($m['combos'][$ck]) ? max($m['combos'][$ck], $stock) : $stock;
            // v2: coste mínimo visto para esa combinación
            if ($cost !== null && $cost > 0) {
                $m['costs'][$ck] = isset($m['costs'][$ck]) ? min($m['costs'][$ck], $cost) : $cost;
            }
            // v2: una imagen por COLOR (nos quedamos con la más repetida, no una por medida)
            if ($image !== '' && preg_match('#^https?://#i', $image)) {
                $m['colorImages'][$colourEs][$image] = ($m['colorImages'][$colourEs][$image] ?? 0) + 1;
            }
            $m['etByDW'][$diam][$width][$et] = true;
            $m['colors'][$colourEs] = true;
            $m['pcds'][$pcd] = true;
            // v3: anclajes por pulgada (para la descripción)
            $m['pcdByDiam'][$diam][$pcd] = true;
            $m['colorDiam'][$colourEs . '|' . $diam] = true;
            unset($m);
        }
        fclose($fh);

        // CSV traducido
        $dir = _PS_MODULE_DIR_ . $this->name . '/var/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tName = preg_replace('/\.csv$/i', '', basename($path)) . '-es.csv';
        $tPath = $dir . $tName;
        $tf = fopen($tPath, 'w');
        foreach ($translated as $line) {
            fputcsv($tf, $line);
        }
        fclose($tf);
        $tUrl = __PS_BASE_URI__ . 'modules/' . $this->name . '/var/' . rawurlencode($tName);

        $result = [];
        foreach ($models as $m) {
            $result[] = $this->importModel($m);
        }

        return [
            'rows' => $rows,
            'skipped' => $skipped,
            'seconds' => round(microtime(true) - $t0, 1),
            'models' => $result,
            'translated_csv_url' => $tUrl,
        ];
    }

    private function importModel(array $m)
    {
        $db = Db::getInstance();
        $created = [];

        /* ---- atributos ---- */
        $gDiam = $this->groupId('Pulgadas');
        $gAncho = $this->groupId('Ancho', $created);
        $gColor = $this->groupId('Color');
        // v3: el anclaje ya NO es una combinación (va en la descripción por pulgada)

        $diams = [];
        $widths = [];
        foreach ($m['etByDW'] as $d => $ws) {
            $diams[$d] = $this->attrId($gDiam, $this->diamLabel($d), $created, [$d . "''"]);
            foreach ($ws as $w => $x) {
                $widths[$w] = $this->attrId($gAncho, $w . '"', $created);
            }
        }
        $colors = [];
        foreach (array_keys($m['colors']) as $c) {
            $colors[$c] = $this->attrId($gColor, $c, $created);
        }

        /* ---- precios (v2): base = combinación más barata; el resto va como impacto ---- */
        $priceByCk = [];
        foreach (array_keys($m['combos']) as $ck) {
            if (isset($m['costs'][$ck])) {
                $priceByCk[$ck] = $this->priceExclTax($m['costs'][$ck]);
            }
        }
        $basePrice = $priceByCk ? round(min($priceByCk), 6) : 0.0;

        /* ---- producto ---- */
        $brandPretty = $this->prettyBrand($m['brand']);
        $diamSorted = array_keys($m['etByDW']);
        usort($diamSorted, function ($a, $b) { return (float) $a <=> (float) $b; });
        $diamStr = implode('', array_map(function ($d) { return $d . '"'; }, $diamSorted));
        $colorWord = count($m['colors']) > 1 ? 'colores' : strtolower(array_key_first($m['colors']));
        $name = 'Kit 4x ' . $brandPretty . ' ' . $m['model'] . ' ' . $diamStr . ' ' . $colorWord;
        $summary = '<p>Kit 4 llantas ' . $brandPretty . ' ' . $m['model'] . '.</p>';
        $desc = $this->buildDescription($brandPretty, $m);
        $reference = strtoupper($m['brand'] . '-' . $m['model']);

        $idProduct = (int) $db->getValue("SELECT id_product FROM ps_product WHERE reference='" . pSQL($reference) . "'");
        $action = $idProduct ? 'updated' : 'created';

        if (!$idProduct) {
            $p = new Product();
            $p->reference = $reference;
            $p->id_manufacturer = $this->manufacturerId($m['brand']);
            // por defecto, la categoría de su marca (BOLA WHEELS / 2FORGE WHEELS)
            $p->id_category_default = $this->brandCategory($m['brand']) ?: self::PARENT_CAT;
            $p->name = [1 => $name];
            $p->link_rewrite = [1 => Tools::str2url('kit-4x-' . $brandPretty . '-' . $m['model'])];
            $p->description = [1 => $desc];
            $p->description_short = [1 => $summary];
            $p->price = $basePrice;
            $p->id_tax_rules_group = $this->defaultTaxRulesGroup();
            $p->active = 1;
            $p->state = 1;
            $p->condition = 'new';
            $p->show_price = 1;
            $p->minimal_quantity = 1;
            $p->out_of_stock = 0; // sin stock (juegos) => no comprable
            $p->add();
            $idProduct = (int) $p->id;
        } else {
            $db->execute("UPDATE ps_product SET id_manufacturer=" . $this->manufacturerId($m['brand'])
                . ', out_of_stock=0' . ($basePrice > 0 ? ', price=' . (float) $basePrice : '')
                . " WHERE id_product=$idProduct");
            if ($basePrice > 0) {
                $db->execute('UPDATE ps_product_shop SET price=' . (float) $basePrice
                    . " WHERE id_product=$idProduct AND id_shop=1");
            }
            $db->execute("UPDATE ps_product_lang SET
                name='" . pSQL($name) . "',
                description='" . pSQL($desc, true) . "',
                description_short='" . pSQL($summary, true) . "'
                WHERE id_product=$idProduct");
        }

        /* categorías: la 13 + la de la marca + las de diámetro que existan */
        $cats = [self::PARENT_CAT];
        if ($brandCat = $this->brandCategory($m['brand'])) {
            $cats[] = $brandCat;
        }
        foreach ($diamSorted as $d) {
            $dInt = (string) (int) (float) $d;
            if (isset(self::DIAM_CATS[$dInt])) {
                $cats[] = self::DIAM_CATS[$dInt];
            }
        }
        $prod = new Product($idProduct);
        $prod->addToCategories($cats);

        /* ---- combinaciones: borrar y reconstruir en bloque ---- */
        $db->execute("DELETE pac FROM ps_product_attribute_combination pac
            JOIN ps_product_attribute pa ON pa.id_product_attribute=pac.id_product_attribute
            WHERE pa.id_product=$idProduct");
        $db->execute("DELETE FROM ps_product_attribute_shop WHERE id_product=$idProduct");
        $db->execute("DELETE FROM ps_product_attribute WHERE id_product=$idProduct");
        $db->execute("DELETE FROM ps_stock_available WHERE id_product=$idProduct AND id_product_attribute>0");

        $pacValues = [];
        $totalSets = 0;
        $firstId = 0;
        $defaultId = 0;       // la MÁS BARATA con stock: es la que ve el cliente al abrir la ficha
        $defaultPrice = null;
        $cheapestId = 0;      // la más barata aunque no tenga stock, por si ninguna lo tuviera
        $cheapestPrice = null;
        $n = 0;
        $paByColor = [];   // v2: combinaciones por color, para asociarles su foto
        foreach ($m['combos'] as $ck => $wheelStock) {
            [$c, $d, $w] = explode('|', $ck);
            $sets = intdiv($wheelStock, 4);
            $totalSets += $sets;
            $impact = isset($priceByCk[$ck]) ? round($priceByCk[$ck] - $basePrice, 6) : 0.0;
            $db->execute('INSERT INTO ps_product_attribute
                (id_product, reference, supplier_reference, ean13, upc, mpn, isbn,
                 wholesale_price, price, ecotax, weight, unit_price_impact,
                 default_on, minimal_quantity, low_stock_alert, available_date)
                VALUES (' . $idProduct . ", '', '', '', '', '', '',
                 0, " . (float) $impact . ", 0, 0, 0, NULL, 1, 0, '0000-00-00')");
            $idPa = (int) $db->Insert_ID();
            if (!$firstId) {
                $firstId = $idPa;
            }
            /* combinación por defecto = la MÁS BARATA (con stock si la hay).
               Así el precio "desde" del listado y el que ve al abrir la ficha
               son el más bajo, en vez del primero que saliera del CSV. */
            $precioCk = isset($priceByCk[$ck]) ? (float) $priceByCk[$ck] : 0.0;
            if ($cheapestPrice === null || $precioCk < $cheapestPrice) {
                $cheapestPrice = $precioCk;
                $cheapestId = $idPa;
            }
            if ($sets > 0 && ($defaultPrice === null || $precioCk < $defaultPrice)) {
                $defaultPrice = $precioCk;
                $defaultId = $idPa;
            }
            $paByColor[$c][] = $idPa;
            $db->execute("INSERT INTO ps_product_attribute_shop
                (id_product, id_product_attribute, id_shop, wholesale_price, price, ecotax,
                 weight, unit_price_impact, default_on, minimal_quantity, low_stock_alert, available_date)
                VALUES ($idProduct, $idPa, 1, 0, " . (float) $impact . ", 0, 0, 0, NULL, 1, 0, '0000-00-00')");
            foreach ([$colors[$c], $diams[$d], $widths[$w]] as $idAttr) {
                $pacValues[] = '(' . (int) $idAttr . ',' . $idPa . ')';
            }
            $db->execute("INSERT INTO ps_stock_available
                (id_product, id_product_attribute, id_shop, id_shop_group, quantity,
                 physical_quantity, reserved_quantity, depends_on_stock, out_of_stock, location)
                VALUES ($idProduct, $idPa, 1, 0, $sets, $sets, 0, 0, 0, '')");
            if (count($pacValues) >= 2000) {
                $db->execute('INSERT INTO ps_product_attribute_combination (id_attribute, id_product_attribute) VALUES ' . implode(',', $pacValues));
                $pacValues = [];
            }
            ++$n;
        }
        if ($pacValues) {
            $db->execute('INSERT INTO ps_product_attribute_combination (id_attribute, id_product_attribute) VALUES ' . implode(',', $pacValues));
        }

        /* stock agregado del producto + combinación por defecto.
           Orden de preferencia: la más barata CON stock -> la más barata
           aunque no tenga -> la primera que se insertó. */
        if (!$defaultId) {
            $defaultId = $cheapestId ?: $firstId;
        }
        $db->execute("UPDATE ps_product_attribute SET default_on=1 WHERE id_product_attribute=$defaultId");
        $db->execute("UPDATE ps_product_attribute_shop SET default_on=1 WHERE id_product_attribute=$defaultId");
        $db->execute("UPDATE ps_product SET cache_default_attribute=$defaultId WHERE id_product=$idProduct");
        $db->execute("UPDATE ps_product_shop SET cache_default_attribute=$defaultId WHERE id_product=$idProduct");
        $agg = (int) $db->getValue("SELECT COUNT(*) FROM ps_stock_available WHERE id_product=$idProduct AND id_product_attribute=0");
        if ($agg) {
            $db->execute("UPDATE ps_stock_available SET quantity=$totalSets, physical_quantity=$totalSets, out_of_stock=0
                WHERE id_product=$idProduct AND id_product_attribute=0");
        } else {
            $db->execute("INSERT INTO ps_stock_available
                (id_product, id_product_attribute, id_shop, id_shop_group, quantity,
                 physical_quantity, reserved_quantity, depends_on_stock, out_of_stock, location)
                VALUES ($idProduct, 0, 1, 0, $totalSets, $totalSets, 0, 0, 0, '')");
        }

        /* ---- imágenes por color (v2) ---- */
        $img = ['added' => 0, 'failed' => 0];
        if (!empty($m['colorImages'])) {
            try {
                $img = $this->importColorImages($idProduct, $m['colorImages'], $paByColor);
            } catch (Throwable $e) {
                $img['failed'] = count($m['colorImages']);
            }
        }

        /* índices de facetas */
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

        // el enlace al panel puede fallar fuera del contexto admin (p.ej. por CLI):
        // no debe abortar la importación
        $editLink = '';
        try {
            $editLink = Context::getContext()->link->getAdminLink('AdminProducts', true, ['id_product' => $idProduct, 'updateproduct' => 1]);
        } catch (Throwable $e) {
            $editLink = '';
        }

        return [
            'brand' => $m['brand'],
            'model' => $m['model'],
            'id_product' => $idProduct,
            'action' => $action,
            'combinations' => $n,
            'sets_total' => $totalSets,
            'attrs_created' => $created,
            'price_from' => $basePrice > 0 ? round($basePrice * self::PRICE_VAT, 2) : 0,
            'price_to' => $priceByCk ? round(max($priceByCk) * self::PRICE_VAT, 2) : 0,
            'images_added' => $img['added'],
            'images_failed' => $img['failed'],
            'edit_link' => $editLink,
        ];
    }

    /* ==================== PRECIOS (v2) ==================== */

    /** "198.5 GBP" -> 198.5 */
    private function parseMoney($raw)
    {
        $s = str_replace(',', '', (string) $raw);
        if (preg_match('/(-?\d+(?:\.\d+)?)/', $s, $mm)) {
            return (float) $mm[1];
        }

        return null;
    }

    /**
     * Multiplicador de margen según el coste por llanta (£), tal y como está
     * en la hoja "Precios llantas": hasta 150 -> 1,173 · hasta 250 -> 1,2 · más -> 1,25.
     */
    private function marginMultiplier($costGbp)
    {
        if ($costGbp <= self::PRICE_BRACKET_1) {
            return self::PRICE_MULT_1;
        }
        if ($costGbp <= self::PRICE_BRACKET_2) {
            return self::PRICE_MULT_2;
        }

        return self::PRICE_MULT_3;
    }

    /**
     * PVP del juego de 4, IVA incluido, a partir del coste por llanta en libras:
     *   total compra = (((coste x 4) + 60) x 1,07 x 1,2 x 1,21) + 30
     *   PVP          = total compra x multiplicador de margen
     */
    private function pvpWithTax($costGbp)
    {
        $totalCompra = ((($costGbp * 4) + self::PRICE_FIXED_ADD) * self::PRICE_F1 * self::PRICE_F2 * self::PRICE_VAT)
            + self::PRICE_FIXED_END;

        $pvp = $totalCompra * $this->marginMultiplier($costGbp);

        // se redondea a la decena más cercana, igual que en el importador de Senco
        return round($pvp / self::PRICE_ROUND_TO) * self::PRICE_ROUND_TO;
    }

    /** PrestaShop guarda el precio SIN IVA (el 21% lo añade la regla de impuestos). */
    private function priceExclTax($costGbp)
    {
        return $this->pvpWithTax($costGbp) / self::PRICE_VAT;
    }

    /* ==================== IMÁGENES (v2) ==================== */

    /**
     * Una imagen por color: descarga la URL y la asocia a las combinaciones de ese color.
     * Idempotente: si el producto ya tiene una imagen con la leyenda del color, no la repite.
     *
     * @return array{added:int,failed:int}
     */
    private function importColorImages($idProduct, array $colorImages, array $paByColor)
    {
        $db = Db::getInstance();
        $added = 0;
        $failed = 0;

        // colores que ya tienen imagen en este producto (por leyenda)
        $existing = [];
        $rowsEx = $db->executeS('SELECT i.id_image, il.legend FROM ' . _DB_PREFIX_ . 'image i
            JOIN ' . _DB_PREFIX_ . 'image_lang il ON il.id_image=i.id_image AND il.id_lang=1
            WHERE i.id_product=' . (int) $idProduct);
        foreach ((array) $rowsEx as $row) {
            $existing[mb_strtolower(trim((string) $row['legend']))] = (int) $row['id_image'];
        }

        $hasCover = (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'image
            WHERE id_product=' . (int) $idProduct . ' AND cover=1');

        foreach ($colorImages as $color => $urls) {
            $key = mb_strtolower(trim($color));
            if (isset($existing[$key])) {
                $this->linkImageToCombinations($existing[$key], $paByColor[$color] ?? []);
                continue;
            }
            arsort($urls);                 // la más repetida = la medida más habitual
            $url = (string) array_key_first($urls);

            $tmp = tempnam(sys_get_temp_dir(), 'jnimg');
            if (!$this->downloadFile($url, $tmp)) {
                @unlink($tmp);
                ++$failed;
                continue;
            }
            if (!ImageManager::isCorrectImageFileExt($url) && !@getimagesize($tmp)) {
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

            if (!$this->generateImageFiles($image, $tmp)) {
                $image->delete();
                @unlink($tmp);
                ++$failed;
                continue;
            }
            @unlink($tmp);
            ++$added;

            $this->linkImageToCombinations((int) $image->id, $paByColor[$color] ?? []);
        }

        return ['added' => $added, 'failed' => $failed];
    }

    private function downloadFile($url, $dest)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; RepuestosJuanito/1.0)',
        ]);
        $data = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($data === false || $code !== 200 || strlen((string) $data) < 1024) {
            return false;
        }

        return (bool) @file_put_contents($dest, $data);
    }

    /** master + miniaturas de todos los formatos del tema */
    private function generateImageFiles(Image $image, $tmpFile)
    {
        if (!$image->createImgFolder()) {
            return false;
        }
        $target = _PS_PROD_IMG_DIR_ . $image->getExistingImgPath() . '.' . $image->image_format;
        if (!ImageManager::resize($tmpFile, $target)) {
            return false;
        }
        foreach (ImageType::getImagesTypes('products') as $t) {
            ImageManager::resize(
                $tmpFile,
                _PS_PROD_IMG_DIR_ . $image->getExistingImgPath() . '-' . stripslashes($t['name']) . '.' . $image->image_format,
                (int) $t['width'],
                (int) $t['height'],
                $image->image_format
            );
        }

        return true;
    }

    private function linkImageToCombinations($idImage, array $idsPa)
    {
        if (!$idsPa) {
            return;
        }
        $db = Db::getInstance();
        $vals = [];
        foreach ($idsPa as $idPa) {
            $vals[] = '(' . (int) $idPa . ',' . (int) $idImage . ')';
            if (count($vals) >= 2000) {
                $db->execute('INSERT IGNORE INTO ' . _DB_PREFIX_ . 'product_attribute_image
                    (id_product_attribute, id_image) VALUES ' . implode(',', $vals));
                $vals = [];
            }
        }
        if ($vals) {
            $db->execute('INSERT IGNORE INTO ' . _DB_PREFIX_ . 'product_attribute_image
                (id_product_attribute, id_image) VALUES ' . implode(',', $vals));
        }
    }

    /* ==================== DESCRIPCIÓN ==================== */

    private function buildDescription($brandPretty, array $m)
    {
        $diams = array_keys($m['etByDW']);
        usort($diams, function ($a, $b) { return (float) $a <=> (float) $b; });

        $h = '<p><strong>Juego de llantas ' . $brandPretty . ' ' . $m['model'] . '.</strong></p>';
        $h .= '<p>El precio indicado por pulgada incluye 4 llantas, tapas, tornillería y arillos si son necesarios para su aplicación. Portes incluidos en península y Baleares, resto consultar.</p>';
        $h .= '<p>Los formatos en concavidad y garganta varían dependiendo de anchuras, puedes ver foto real en el enlace a la web oficial.</p>';
        if (count($diams) > 1) {
            $h .= '<p>Llanta forjada disponible de ' . reset($diams) . '" a ' . end($diams) . '", en configuración a medida.</p>';
        } else {
            $h .= '<p>Llanta forjada disponible en ' . reset($diams) . '", en configuración a medida.</p>';
        }
        $h .= '<p><strong>Medidas, anchuras y rango de ET disponibles:</strong></p>';

        foreach ($diams as $d) {
            $byRange = [];
            $ws = array_keys($m['etByDW'][$d]);
            usort($ws, function ($a, $b) { return (float) $a <=> (float) $b; });
            foreach ($ws as $w) {
                $range = $this->etRange(array_keys($m['etByDW'][$d][$w]));
                $byRange[$range][] = $w;
            }
            if (count($byRange) === 1) {
                $range = array_key_first($byRange);
                $group = $byRange[$range];
                if (count($group) === 1) {
                    $h .= '<p><strong>' . $d . '"</strong> — solo ' . $group[0] . 'x' . $d . ' — ET ' . $range . '</p>';
                } else {
                    $h .= '<p><strong>' . $d . '"</strong> — anchuras ' . $this->joinEs($group) . ' — ET ' . $range . '</p>';
                }
            } else {
                $h .= '<p><strong>' . $d . '":</strong></p><ul>';
                foreach ($byRange as $range => $group) {
                    $labels = array_map(function ($w) use ($d) { return $w . 'x' . $d; }, $group);
                    $h .= '<li>' . $this->joinEs($labels) . ' — ET ' . $range . '</li>';
                }
                $h .= '</ul>';
            }
        }

        $cs = array_keys($m['colors']);
        sort($cs, SORT_NATURAL | SORT_FLAG_CASE);
        $todas = count($m['colorDiam']) === count($m['colors']) * count($diams);
        $h .= '<p><strong>Colores disponibles:</strong> ' . $this->joinEs($cs) . '. '
            . ($todas ? 'Todos los colores disponibles en todas las medidas.' : 'Disponibilidad de colores según medida.') . '</p>';

        /* v3: anclajes DETALLADOS POR PULGADA (ya no son una opción a elegir en la ficha:
           la llanta es forjada y se taladra al anclaje del vehículo del cliente). */
        if (!empty($m['pcdByDiam'])) {
            $sameForAll = true;
            $ref = null;
            foreach ($diams as $d) {
                $list = isset($m['pcdByDiam'][$d]) ? array_keys($m['pcdByDiam'][$d]) : [];
                sort($list);
                $sig = implode(',', $list);
                if ($ref === null) {
                    $ref = $sig;
                } elseif ($sig !== $ref) {
                    $sameForAll = false;
                    break;
                }
            }
            if ($sameForAll) {
                $h .= '<p><strong>Anclajes disponibles:</strong> '
                    . $this->pcdSentence(array_keys($m['pcds'])) . ' (en todas las medidas).</p>';
            } else {
                $h .= '<p><strong>Anclajes disponibles por medida:</strong></p><ul>';
                foreach ($diams as $d) {
                    if (empty($m['pcdByDiam'][$d])) {
                        continue;
                    }
                    $h .= '<li><strong>' . $d . '"</strong> — ' . $this->pcdSentence(array_keys($m['pcdByDiam'][$d])) . '</li>';
                }
                $h .= '</ul>';
            }
        }
        $h .= '<p>La llanta es forjada y se taladra al anclaje de tu vehículo, por eso el anclaje no se elige aquí: indícanos tu coche y nosotros lo fabricamos a medida.</p>';

        $h .= '<p>Imprescindible que nos comuniques en comentarios tu modelo de vehículo, añadir todos los detalles posibles, año, motorización, etc... en caso de dudas nuestro departamento técnico se pondrá en contacto.</p>';
        $h .= '<p>No olvides incluir al carrito tus neumáticos si los necesitaras. Al comprar las llantas te regalamos los montajes, equilibrados y válvulas; lo recibirás todo listo para montar al coche.</p>';

        return $h;
    }

    private function etRange(array $raws)
    {
        $mins = [];
        $maxs = [];
        foreach ($raws as $raw) {
            if (preg_match('/(-?\d+)\s*TO\s*(-?\d+)/i', $raw, $mm)) {
                $mins[] = (int) $mm[1];
                $maxs[] = (int) $mm[2];
            }
        }
        if ($mins) {
            return min($mins) . ' a ' . max($maxs);
        }

        return strtolower(implode(' / ', $raws));
    }

    private function pcdSentence(array $pcds)
    {
        $byBolts = [];
        foreach ($pcds as $p) {
            $parts = explode('X', strtoupper($p), 2);
            $byBolts[$parts[0]][] = strtolower($p);
        }
        ksort($byBolts);
        $chunks = [];
        foreach ($byBolts as $vals) {
            usort($vals, function ($a, $b) {
                return (float) substr($a, strpos($a, 'x') + 1) <=> (float) substr($b, strpos($b, 'x') + 1);
            });
            $chunks[] = count($vals) === 1 ? $vals[0] : 'de ' . reset($vals) . ' a ' . end($vals);
        }

        return implode(' y ', $chunks);
    }

    private function joinEs(array $items)
    {
        if (count($items) <= 1) {
            return (string) reset($items);
        }
        $last = array_pop($items);

        return implode(', ', $items) . ' y ' . $last;
    }

    /* ==================== TRADUCCIÓN DE COLORES ==================== */

    public function translateColour($en)
    {
        $base = [
            'BLACK' => 'Negro', 'BRONZE' => 'Bronce', 'GOLD' => 'Dorado',
            'GUNMETAL' => 'Antracita', 'SILVER' => 'Plata', 'WHITE' => 'Blanco',
            'GREY' => 'Gris', 'GRAY' => 'Gris', 'BLUE' => 'Azul', 'RED' => 'Rojo',
            'GREEN' => 'Verde', 'ORANGE' => 'Naranja', 'PURPLE' => 'Púrpura',
            'TITANIUM' => 'Titanio', 'CHROME' => 'Cromo', 'CANDY' => 'Candy',
        ];
        $finish = ['GLOSS' => 'brillo', 'MATT' => 'mate', 'MATTE' => 'mate',
            'SATIN' => 'satinado', 'POLISHED' => 'pulido'];

        $tokens = preg_split('/\s+/', strtoupper(trim($en)));
        $fin = '';
        $rest = [];
        foreach ($tokens as $t) {
            if (isset($finish[$t])) {
                $fin = $finish[$t];
            } else {
                $rest[] = isset($base[$t]) ? $base[$t] : Tools::ucfirst(strtolower($t));
            }
        }
        $name = trim(implode(' ', $rest) . ($fin !== '' ? ' ' . $fin : ''));

        return $name !== '' ? $name : trim($en);
    }

    /* ==================== HELPERS de atributos ==================== */

    private function groupId($name, ?array &$created = null)
    {
        $key = 'g:' . mb_strtolower($name);
        if (isset($this->attrCache[$key])) {
            return $this->attrCache[$key];
        }
        $id = (int) Db::getInstance()->getValue(
            'SELECT ag.id_attribute_group FROM ps_attribute_group ag
             JOIN ps_attribute_group_lang agl ON agl.id_attribute_group=ag.id_attribute_group AND agl.id_lang=1
             WHERE LOWER(agl.name)=\'' . pSQL(mb_strtolower($name)) . '\'');
        if (!$id) {
            $g = new AttributeGroup();
            $g->group_type = 'select';
            $g->name = [1 => $name];
            $g->public_name = [1 => $name];
            $g->add();
            $id = (int) $g->id;
            if ($created !== null) {
                $created[] = 'grupo ' . $name;
            }
        }

        return $this->attrCache[$key] = $id;
    }

    private function attrId($gid, $name, ?array &$created = null, array $regexCandidates = [])
    {
        $key = 'a:' . $gid . ':' . mb_strtolower($name);
        if (isset($this->attrCache[$key])) {
            return $this->attrCache[$key];
        }
        $db = Db::getInstance();
        $id = (int) $db->getValue(
            'SELECT a.id_attribute FROM ps_attribute a
             JOIN ps_attribute_lang al ON al.id_attribute=a.id_attribute AND al.id_lang=1
             WHERE a.id_attribute_group=' . (int) $gid . ' AND LOWER(al.name)=\'' . pSQL(mb_strtolower($name)) . '\'');
        if (!$id) {
            foreach ($regexCandidates as $alt) {
                $id = (int) $db->getValue(
                    'SELECT a.id_attribute FROM ps_attribute a
                     JOIN ps_attribute_lang al ON al.id_attribute=a.id_attribute AND al.id_lang=1
                     WHERE a.id_attribute_group=' . (int) $gid
                    . ' AND LOWER(al.name)=\'' . pSQL(mb_strtolower($alt)) . '\'');
                if ($id) {
                    break;
                }
            }
        }
        if (!$id) {
            $a = new ProductAttribute();
            $a->id_attribute_group = (int) $gid;
            $a->name = [1 => $name];
            $a->add();
            $id = (int) $a->id;
            if ($created !== null) {
                $created[] = $name;
            }
        }

        return $this->attrCache[$key] = $id;
    }

    private function diamLabel($d)
    {
        return $d . '"';
    }

    /**
     * Categoría de la marca (BOLA WHEELS / 2FORGE WHEELS), buscada por nombre
     * bajo la categoría padre. Devuelve 0 si no existe: en ese caso el producto
     * se queda solo en la 13, como hacía antes.
     */
    private function brandCategory($brand)
    {
        $k = 'bcat:' . strtoupper($brand);
        if (isset($this->attrCache[$k])) {
            return $this->attrCache[$k];
        }
        $nombre = self::BRAND_CATS[strtoupper($brand)] ?? ($brand . ' WHEELS');
        $db = Db::getInstance();
        // TRIM/UPPER por si la categoría tiene espacios de más o distinta caja
        $id = (int) $db->getValue('SELECT c.id_category FROM ' . _DB_PREFIX_ . 'category c
            JOIN ' . _DB_PREFIX_ . 'category_lang cl ON cl.id_category = c.id_category AND cl.id_lang = 1
            WHERE c.id_parent = ' . (int) self::PARENT_CAT . "
              AND UPPER(TRIM(cl.name)) = '" . pSQL(mb_strtoupper(trim($nombre))) . "'");
        if (!$id) {
            // último intento: que empiece por el nombre de la marca
            $id = (int) $db->getValue('SELECT c.id_category FROM ' . _DB_PREFIX_ . 'category c
                JOIN ' . _DB_PREFIX_ . 'category_lang cl ON cl.id_category = c.id_category AND cl.id_lang = 1
                WHERE c.id_parent = ' . (int) self::PARENT_CAT . "
                  AND UPPER(TRIM(cl.name)) LIKE '" . pSQL(mb_strtoupper(trim($brand))) . "%'");
        }

        return $this->attrCache[$k] = $id;
    }

    private function prettyBrand($brand)
    {
        $map = ['2FORGE' => '2Forge', 'BOLA' => 'BOLA'];
        $u = strtoupper($brand);

        return isset($map[$u]) ? $map[$u] : Tools::ucfirst(strtolower($brand));
    }

    /** grupo de impuestos por defecto de la tienda (IVA 21%) */
    private function defaultTaxRulesGroup()
    {
        if (isset($this->attrCache['trg'])) {
            return $this->attrCache['trg'];
        }
        $id = (int) Configuration::get('PS_TAX_RULES_GROUP');
        if (!$id) {
            $id = (int) Db::getInstance()->getValue(
                'SELECT id_tax_rules_group FROM ' . _DB_PREFIX_ . 'tax_rules_group WHERE active=1 ORDER BY id_tax_rules_group');
        }

        return $this->attrCache['trg'] = $id;
    }

    private function manufacturerId($brand)
    {
        $key = 'm:' . strtoupper($brand);
        if (isset($this->attrCache[$key])) {
            return $this->attrCache[$key];
        }
        $id = (int) Db::getInstance()->getValue(
            "SELECT id_manufacturer FROM ps_manufacturer WHERE UPPER(name)='" . pSQL(strtoupper($brand)) . "'");
        if (!$id) {
            $man = new Manufacturer();
            $man->name = $this->prettyBrand($brand);
            $man->active = 1;
            $man->add();
            $id = (int) $man->id;
        }

        return $this->attrCache[$key] = $id;
    }
}
