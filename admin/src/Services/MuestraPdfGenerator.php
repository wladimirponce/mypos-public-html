<?php
declare(strict_types=1);

namespace App\Services;

use DOMDocument;
use DOMNode;
use DOMXPath;
use Exception;

/**
 * Genera la representación impresa de un DTE como PDF real (TCPDF):
 * texto seleccionable (el validador del SII busca el RUT con puntos, el folio
 * y la leyenda "Timbre Electrónico SII" en el TEXTO del PDF) y timbre PDF417
 * nativo construido con los bytes ISO-8859-1 EXACTOS del TED firmado (un
 * timbre re-codificado en UTF-8 invalida la firma → "TED - Firma invalida").
 */
class MuestraPdfGenerator
{
    private const TIPO_NOMBRE = [
        33 => 'FACTURA ELECTRÓNICA',
        34 => 'FACTURA NO AFECTA O EXENTA ELECTRÓNICA',
        39 => 'BOLETA ELECTRÓNICA',
        41 => 'BOLETA NO AFECTA O EXENTA ELECTRÓNICA',
        43 => 'LIQUIDACIÓN FACTURA ELECTRÓNICA',
        46 => 'FACTURA DE COMPRA ELECTRÓNICA',
        52 => 'GUÍA DE DESPACHO ELECTRÓNICA',
        56 => 'NOTA DE DÉBITO ELECTRÓNICA',
        61 => 'NOTA DE CRÉDITO ELECTRÓNICA',
        110 => 'FACTURA DE EXPORTACIÓN ELECTRÓNICA',
        111 => 'NOTA DE DÉBITO DE EXPORTACIÓN ELECTRÓNICA',
        112 => 'NOTA DE CRÉDITO DE EXPORTACIÓN ELECTRÓNICA',
    ];

    private const IND_TRASLADO = [
        1 => 'Operación constituye venta',
        2 => 'Ventas por efectuar',
        3 => 'Consignaciones',
        4 => 'Entrega gratuita',
        5 => 'Traslados internos',
        6 => 'Otros traslados no venta',
        7 => 'Guía de devolución',
        8 => 'Traslado para exportación (no venta)',
        9 => 'Venta para exportación',
    ];

    private const TIPO_DESPACHO = [
        1 => 'Despacho por cuenta del receptor (cliente)',
        2 => 'Despacho por cuenta del emisor a instalaciones del cliente',
        3 => 'Despacho por cuenta del emisor a otras instalaciones',
    ];

    private const FORMATO_HOJA_SII = [215.9, 279.4]; // carta estándar (Letter)

    /** Tipos que requieren ejemplar CEDIBLE además del tributario */
    public const TIPOS_CEDIBLE = [33, 34, 43, 46, 52];

    private static function padBarcodePng(string $png, int $padX = 32, int $padY = 32): string
    {
        if (!function_exists('imagecreatefromstring')) {
            return $png;
        }

        $src = @imagecreatefromstring($png);
        if (!$src) {
            return $png;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $dst = imagecreatetruecolor($w + 2 * $padX, $h + 2 * $padY);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);
        imagecopy($dst, $src, $padX, $padY, 0, 0, $w, $h);

        ob_start();
        imagepng($dst);
        $padded = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        return $padded !== '' ? $padded : $png;
    }

    public static function formatRut(string $rut): string
    {
        $rut = trim($rut);
        if (!preg_match('/^(\d+)-([\dkK])$/', $rut, $m)) return $rut;
        return number_format((float)$m[1], 0, ',', '.') . '-' . $m[2];
    }

    private static function n($v, int $dec = 0): string
    {
        return number_format((float)$v, $dec, ',', '.');
    }

    private static function qty($v): string
    {
        $f = (float)$v;
        return ($f == (int)$f) ? self::n($f) : rtrim(rtrim(number_format($f, 3, ',', '.'), '0'), ',');
    }

    private static function unidadSii(string $unidad): string
    {
        $unidad = trim($unidad);
        $unidad = preg_replace('/^(?:S\.?\s*I\.?\s*I\.?|SII)\s*[-—:]*\s*/iu', '', $unidad) ?? $unidad;

        return trim($unidad);
    }

    /**
     * @param array  $dte  ['tipo','folio','xml' (UTF-8), 'raw' (ISO-8859-1), 'label']
     * @param array  $opts ['unidadSII','resolNum','resolFch','certBanner'=>bool]
     * @param string $copia 'TRIBUTARIA' | 'CEDIBLE'
     * @return string bytes del PDF
     */
    public function render(array $dte, array $opts, string $copia = 'TRIBUTARIA', string $formato = 'carta'): string
    {
        require_once __DIR__ . '/../../lib/tcpdf/tcpdf.php';

        $dom = new DOMDocument();
        if (!@$dom->loadXML($dte['xml'])) {
            throw new Exception("XML inválido para muestra T{$dte['tipo']}F{$dte['folio']}");
        }
        $xp  = new DOMXPath($dom);
        $xp->registerNamespace('s', 'http://www.sii.cl/SiiDte');
        $q   = fn(string $path, ?DOMNode $ctx = null) =>
            trim((string)$xp->evaluate("string($path)", $ctx ?? $dom->documentElement));

        $tipo  = (int)$dte['tipo'];
        $folio = (int)$dte['folio'];
        $copia = strtoupper(trim($copia));
        if (!in_array($copia, ['TRIBUTARIA', 'CEDIBLE'], true)) {
            throw new Exception("Copia inválida para muestra T{$tipo}F{$folio}: {$copia}");
        }
        if ($copia === 'CEDIBLE' && !in_array($tipo, self::TIPOS_CEDIBLE, true)) {
            throw new Exception("El DTE tipo {$tipo} no admite copia CEDIBLE");
        }

        // TED con los bytes ORIGINALES firmados (ISO-8859-1)
        $ted = '';
        if (!empty($dte['raw']) && preg_match('/<TED[\s\S]*?<\/TED>/', $dte['raw'], $m)) {
            $ted = $m[0];
        }
        if ($ted === '') {
            throw new Exception("No se encontró el TED en el XML de T{$tipo}F{$folio}");
        }

        // Formato térmico (80mm / 58mm): ticket angosto para boletas, mismo TED.
        // La carta sigue siendo el camino por defecto (facturas, copias cedibles).
        if (in_array($formato, ['80', '58'], true) && $copia !== 'CEDIBLE') {
            return $this->renderTermico($xp, $q, $tipo, $folio, $opts, $ted, $formato);
        }

        // ── Datos del documento ──────────────────────────────────────────────
        $emRut   = self::formatRut($q('//s:Encabezado/s:Emisor/s:RUTEmisor'));
        $emRzn   = $q('//s:Encabezado/s:Emisor/s:RznSoc');
        $emGiro  = $q('//s:Encabezado/s:Emisor/s:GiroEmis');
        $emDir   = $q('//s:Encabezado/s:Emisor/s:DirOrigen');
        $emCmna  = $q('//s:Encabezado/s:Emisor/s:CmnaOrigen');
        $emCiu   = $q('//s:Encabezado/s:Emisor/s:CiudadOrigen');

        $reRut   = self::formatRut($q('//s:Encabezado/s:Receptor/s:RUTRecep'));
        $reRzn   = $q('//s:Encabezado/s:Receptor/s:RznSocRecep');
        $reGiro  = $q('//s:Encabezado/s:Receptor/s:GiroRecep');
        $reDir   = $q('//s:Encabezado/s:Receptor/s:DirRecep');
        $reCmna  = $q('//s:Encabezado/s:Receptor/s:CmnaRecep');
        $reCiu   = $q('//s:Encabezado/s:Receptor/s:CiudadRecep');

        $fchEmis = $q('//s:Encabezado/s:IdDoc/s:FchEmis');
        $mntNeto = $q('//s:Encabezado/s:Totales/s:MntNeto');
        $mntExe  = $q('//s:Encabezado/s:Totales/s:MntExe');
        $iva     = $q('//s:Encabezado/s:Totales/s:IVA');
        $tasa    = $q('//s:Encabezado/s:Totales/s:TasaIVA') ?: '19';
        $mntTot  = $q('//s:Encabezado/s:Totales/s:MntTotal');

        $resolNum = (int)($opts['resolNum'] ?? 0);
        $resolAno = substr((string)($opts['resolFch'] ?? '2021-01-04'), 0, 4) ?: '2021';
        $unidad   = self::unidadSii((string)($opts['unidadSII'] ?? ''));
        $indTraslado = (int)$q('//s:Encabezado/s:IdDoc/s:IndTraslado');
        if ($copia === 'CEDIBLE' && $tipo === 52 && $indTraslado === 5) {
            throw new Exception('La guía de traslado interno no admite copia CEDIBLE');
        }

        // ── PDF ──────────────────────────────────────────────────────────────
        $pdf = new class('P', 'mm', self::FORMATO_HOJA_SII, true, 'UTF-8', false) extends \TCPDF {
            public function disableTcpdfLink(): void
            {
                $this->tcpdflink = false;
            }
        };
        $pdf->disableTcpdfLink();
        $pdf->SetCreator('MyPOS DTE');
        $pdf->SetTitle("Muestra T{$tipo} F{$folio} {$copia}");
        $pdf->SetMargins(12, 10, 12);
        $pdf->SetAutoPageBreak(true, 10);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();

        // Emisor (izquierda)
        $pdf->SetXY(12, 12);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->MultiCell(110, 5, $emRzn, 0, 'L');
        $pdf->SetFont('helvetica', '', 8);
        if ($emGiro !== '') $pdf->MultiCell(110, 3.6, 'Giro: ' . $emGiro, 0, 'L', false, 1, 12);
        $dirLine = trim($emDir . ($emCmna !== '' ? ', ' . $emCmna : '') . ($emCiu !== '' ? ' — ' . $emCiu : ''));
        if ($dirLine !== '') $pdf->MultiCell(110, 3.6, 'Casa Matriz: ' . $dirLine, 0, 'L', false, 1, 12);

        // Recuadro SII (derecha): RUT con puntos + tipo + folio — el revisor
        // exige estos tres datos dentro del recuadro rojo o negro.
        $bx = 128; $by = 12; $bw = 76; $bh = 27;
        $pdf->SetDrawColor(200, 0, 0);
        $pdf->SetLineWidth(0.7);
        $pdf->Rect($bx, $by, $bw, $bh);
        $pdf->SetTextColor(200, 0, 0);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetXY($bx, $by + 3);
        $pdf->Cell($bw, 5, 'R.U.T.: ' . $emRut, 0, 2, 'C');
        $pdf->SetFont('helvetica', 'B', 10);
        $nombreTipo = self::TIPO_NOMBRE[$tipo] ?? "DOCUMENTO TIPO $tipo";
        $pdf->SetXY($bx + 2, $by + 9);
        $pdf->MultiCell($bw - 4, 4.5, $nombreTipo, 0, 'C');
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetXY($bx, $by + $bh - 8);
        $pdf->Cell($bw, 5, 'N° ' . $folio, 0, 0, 'C');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetXY($bx, $by + $bh + 1.5);
        $pdf->Cell($bw, 4, 'S.I.I.' . ($unidad !== '' ? ' - ' . $unidad : ''), 0, 0, 'C');
        $pdf->SetDrawColor(120, 120, 120);
        $pdf->SetLineWidth(0.2);

        // Receptor
        $y = max(46.0, $pdf->GetY() + 6);
        $pdf->Rect(12, $y, 192, 18);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetXY(14, $y + 2);
        $pdf->Cell(120, 4, 'Señor(es): ' . $reRzn, 0, 0, 'L');
        $pdf->Cell(70, 4, 'R.U.T.: ' . $reRut, 0, 1, 'L');
        $pdf->SetX(14);
        $pdf->Cell(120, 4, 'Dirección: ' . trim($reDir . ($reCmna !== '' ? ', ' . $reCmna : '')), 0, 0, 'L');
        $pdf->Cell(70, 4, 'Fecha emisión: ' . $fchEmis, 0, 1, 'L');
        $pdf->SetX(14);
        $pdf->Cell(120, 4, 'Ciudad: ' . ($reCiu !== '' ? $reCiu : $reCmna), 0, 0, 'L');
        $pdf->Cell(70, 4, 'Giro: ' . $reGiro, 0, 1, 'L');
        $y = $y + 20;

        // Referencias
        $refs = $xp->query('//s:Referencia');
        if ($refs->length) {
            $pdf->SetXY(12, $y);
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->Cell(192, 4, 'Referencias:', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 8);
            foreach ($refs as $r) {
                $tpoRef = $q('.//s:TpoDocRef', $r);
                $folRef = $q('.//s:FolioRef', $r);
                $fchRef = $q('.//s:FchRef', $r);
                $rznRef = $q('.//s:RazonRef', $r);
                $codRef = $q('.//s:CodRef', $r);
                if (strtoupper($tpoRef) === 'SET' || strtoupper($codRef) === 'SET') {
                    $linea = 'Referencia certificación SII: SET';
                    if ($rznRef !== '') $linea .= ' - ' . $rznRef;
                } else {
                    $tipoRefNombre = self::TIPO_NOMBRE[(int)$tpoRef] ?? $tpoRef;
                    $linea = "Documento: {$tipoRefNombre}";
                    if ($folRef !== '') $linea .= "  Folio {$folRef}";
                    if ($fchRef !== '') $linea .= "  ({$fchRef})";
                    if ($codRef !== '') $linea .= "  Cód: {$codRef}";
                    if ($rznRef !== '') $linea .= "  — {$rznRef}";
                }
                $pdf->SetX(14);
                $pdf->Cell(190, 3.8, $linea, 0, 1, 'L');
            }
            $y = $pdf->GetY() + 2;
        }

        // Traslado (guías)
        if ($tipo === 52) {
            $indT = $indTraslado;
            $tDes = (int)$q('//s:Encabezado/s:IdDoc/s:TipoDespacho');
            $pdf->SetXY(12, $y);
            $pdf->SetFont('helvetica', '', 8);
            if ($indT) $pdf->Cell(192, 4, 'TIPO DE TRASLADO: ' . (self::IND_TRASLADO[$indT] ?? $indT), 0, 1, 'L');
            if ($tDes) { $pdf->SetX(12); $pdf->Cell(192, 4, 'TIPO DE DESPACHO: ' . (self::TIPO_DESPACHO[$tDes] ?? $tDes), 0, 1, 'L'); }
            $y = $pdf->GetY() + 2;
        }

        // Detalle. El manual de muestras exige el descuento por línea en MONTO
        // (el porcentaje es opcional adicional).
        $pdf->SetXY(12, $y);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell(8, 5, '#', 'B', 0, 'L');
        $pdf->Cell(76, 5, 'Descripción', 'B', 0, 'L');
        $pdf->Cell(16, 5, 'Cant.', 'B', 0, 'R');
        $pdf->Cell(24, 5, 'P. Unitario', 'B', 0, 'R');
        $pdf->Cell(12, 5, 'Dcto.%', 'B', 0, 'R');
        $pdf->Cell(24, 5, 'Dcto. $', 'B', 0, 'R');
        $pdf->Cell(32, 5, 'Total', 'B', 1, 'R');
        $pdf->SetFont('helvetica', '', 8);
        foreach ($xp->query('//s:Detalle') as $det) {
            $nl    = $q('.//s:NroLinDet', $det);
            $nm    = $q('.//s:NmbItem', $det);
            $cant  = $q('.//s:QtyItem', $det);
            $prc   = $q('.//s:PrcItem', $det);
            $dcto  = $q('.//s:DescuentoPct', $det);
            $dctoM = $q('.//s:DescuentoMonto', $det);
            $mont  = $q('.//s:MontoItem', $det);
            $exe   = $q('.//s:IndExe', $det);
            $unm   = $q('.//s:UnmdItem', $det);
            if ($exe === '1') $nm .= ' (EXENTO)';
            if ($unm !== '')  $cant = ($cant !== '' ? self::qty($cant) . ' ' . $unm : $unm);
            elseif ($cant !== '') $cant = self::qty($cant);
            // Monto del descuento: obligatorio si hay % y el XML no lo trae
            if ($dctoM === '' && $dcto !== '' && $prc !== '' && $cant !== '') {
                $bruto = (float)$q('.//s:QtyItem', $det) * (float)$prc;
                $dctoM = (string)round($bruto * (float)$dcto / 100);
            }
            $pdf->SetX(12);
            $pdf->Cell(8, 4.5, $nl, 0, 0, 'L');
            $pdf->Cell(76, 4.5, mb_substr($nm, 0, 50), 0, 0, 'L');
            $pdf->Cell(16, 4.5, $cant, 0, 0, 'R');
            $pdf->Cell(24, 4.5, $prc !== '' ? '$' . self::n($prc) : '', 0, 0, 'R');
            $pdf->Cell(12, 4.5, $dcto !== '' ? self::n($dcto) . '%' : '', 0, 0, 'R');
            $pdf->Cell(24, 4.5, $dctoM !== '' && (float)$dctoM > 0 ? '$' . self::n($dctoM) : '', 0, 0, 'R');
            $pdf->Cell(32, 4.5, '$' . self::n($mont), 0, 1, 'R');
        }
        $pdf->Line(12, $pdf->GetY() + 0.5, 204, $pdf->GetY() + 0.5);
        $y = $pdf->GetY() + 2;

        // Descuento global
        foreach ($xp->query('//s:DscRcgGlobal') as $dr) {
            $mov = $q('.//s:TpoMov', $dr);
            $tv  = $q('.//s:TpoValor', $dr);
            $val = $q('.//s:ValorDR', $dr);
            $lbl = $mov === 'R' ? 'Recargo global' : 'Descuento global';
            $valStr = $tv === '%' ? self::n($val) . '%' : '$' . self::n($val);
            $pdf->SetXY(12, $y);
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->Cell(192, 4, "$lbl sobre ítems afectos: $valStr", 0, 1, 'L');
            $y = $pdf->GetY();
        }

        // Totales (derecha)
        $ty = $y + 2;
        $pdf->SetFont('helvetica', '', 9);
        $rows = [];
        if ($mntNeto !== '' && (float)$mntNeto > 0) $rows[] = ['Monto Neto', $mntNeto];
        if ($mntExe !== ''  && (float)$mntExe  > 0) $rows[] = ['Monto Exento', $mntExe];
        if ($iva !== ''     && (float)$iva     > 0) $rows[] = ["IVA ({$tasa}%)", $iva];
        foreach ($rows as $r) {
            $pdf->SetXY(128, $ty);
            $pdf->Cell(40, 5, $r[0], 1, 0, 'L');
            $pdf->Cell(36, 5, '$' . self::n($r[1]), 1, 1, 'R');
            $ty += 5;
        }
        $pdf->SetXY(128, $ty);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(40, 6, 'TOTAL', 1, 0, 'L');
        $pdf->Cell(36, 6, '$' . self::n($mntTot), 1, 1, 'R');
        $y = max($y + 4, $ty + 10);

        // Acuse de recibo (Ley 19.983) — SOLO en copias cedibles: el manual de
        // muestras prohíbe el cuadro de acuse en los ejemplares no cedibles.
        $esCedible = strtoupper($copia) === 'CEDIBLE';
        if ($esCedible) {
            $pdf->SetDrawColor(120, 120, 120);
            $pdf->Rect(12, $y, 192, 26);
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->SetXY(14, $y + 1.5);
            $pdf->Cell(100, 4, 'Acuse de Recibo', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetX(14);
            $pdf->Cell(100, 4.5, 'Nombre: ____________________________________', 0, 0, 'L');
            $pdf->Cell(88, 4.5, 'R.U.T.: ____________________', 0, 1, 'L');
            $pdf->SetX(14);
            $pdf->Cell(100, 4.5, 'Fecha: ______ / ______ / __________', 0, 0, 'L');
            $pdf->Cell(88, 4.5, 'Recinto: ____________________', 0, 1, 'L');
            $pdf->SetX(14);
            $pdf->Cell(188, 4.5, 'Firma: ____________________', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 6.2);
            $pdf->SetXY(14, $y + 21);
            $pdf->MultiCell(188, 2.6, 'El acuse de recibo que se declara en este acto, de acuerdo a lo dispuesto en la letra b) del Art. 4°, y la letra c) del Art. 5° de la Ley 19.983, acredita que la entrega de mercaderías o servicio(s) prestado(s) ha(n) sido recibido(s).', 0, 'L');
            $y += 30;
        }

        // Timbre PDF417 — SII mínimo 2×5cm (sin máximo explícito), módulo ≥0.26mm.
        // Página carta real (215.9×279.4mm) → sin escala al imprimir → módulo llega intacto.
        // aspectratio=2 mantiene forma estándar PDF417 (ancho > alto). Floor 0.26mm
        // garantiza margen sobre el mínimo SII; si num_cols>346 tedW pasa de 90mm pero
        // cabe en carta (~175mm útiles). Con aspectratio=1.0 el barcode queda cuadrado
        // y los scanners (incluida app SII) no lo detectan correctamente.
        require_once __DIR__ . '/../../lib/tcpdf/tcpdf_barcodes_2d.php';
        $bc    = new \TCPDF2DBarcode($ted, 'PDF417,2,5');
        $bcArr = $bc->getBarcodeArray();
        $cols  = (int) ($bcArr['num_cols'] ?? 0);
        $rows  = (int) ($bcArr['num_rows'] ?? 0);
        $tedW = 86.0; $tedH = 42.0;
        if ($cols > 0 && $rows > 0) {
            $mm   = max(0.26, min(0.30, 90.0 / $cols)); // 0.26–0.30mm, nunca < mínimo SII
            $tedW = $cols * $mm;
            $tedH = $rows * $mm;
        }
        // Cap de Y para que el timbre + leyenda quepan en carta (279.4mm - 20mm márgenes = 259mm útiles).
        $tedY = max(0.0, min($y + 2, 249.0 - $tedH));
        $pngOk = false;
        if (function_exists('imagecreate')) {
            $png = $bc->getBarcodePngData(4, 4, [0, 0, 0]); // alta densidad de píxeles
            if ($png !== false && $png !== '') {
                $pdf->Image('@' . $png, 20, $tedY, $tedW, $tedH, 'PNG');
                $pngOk = true;
            }
        }
        if (!$pngOk) {
            // Vector con w/h en la misma proporción del símbolo (no deforma).
            $pdf->write2DBarcode($ted, 'PDF417,2,5', 20, $tedY, $tedW, $tedH, [
                'border' => false, 'padding' => 0,
                'fgcolor' => [0, 0, 0], 'bgcolor' => false,
            ], 'N');
        }
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetXY(20, $tedY + $tedH + 1.5);
        $pdf->Cell($tedW, 3.5, 'Timbre Electrónico SII', 0, 2, 'C');
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell($tedW, 3.5, "Res. {$resolNum} de {$resolAno}", 0, 2, 'C');
        $pdf->Cell($tedW, 3.5, 'Verifique documento: www.sii.cl', 0, 2, 'C');
        // Verificación en MyPOS: el cliente abre el link (auto-carga por RUT + folio).
        $rutUrl = str_replace('.', '', (string) $emRut);
        $pdf->SetFont('helvetica', '', 7);
        $pdf->Cell($tedW, 3.2, "Ver boleta: www.mypos.cl/boleta?rut={$rutUrl}&folio={$folio}", 0, 2, 'C');

        // Marca CEDIBLE
        if ($esCedible) {
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->SetDrawColor(0, 0, 0);
            $pdf->SetLineWidth(0.4);
            $txt = $tipo === 52 ? 'CEDIBLE CON SU FACTURA' : 'CEDIBLE';
            $pdf->SetXY(128, $tedY + 18);
            $pdf->Cell(76, 9, $txt, 1, 0, 'C');
        }

        if ($pdf->getNumPages() !== 1) {
            throw new Exception("La muestra T{$tipo}F{$folio} {$copia} ocupa más de una página");
        }

        $bytes = $pdf->Output('', 'S');
        if (strlen($bytes) > 500 * 1024) {
            throw new Exception("La muestra T{$tipo}F{$folio} {$copia} supera el máximo SII de 500 KB");
        }

        return $bytes;
    }

    /**
     * Representación TÉRMICA (ticket 80mm / 58mm) de una boleta como PDF, con el
     * mismo TED byte-exacto que la carta. Se usa cuando la caja eligió formato
     * térmico: así "ver boleta" y la verificación pública coinciden con lo impreso.
     */
    private function renderTermico(DOMXPath $xp, callable $q, int $tipo, int $folio, array $opts, string $ted, string $formato): string
    {
        $width = $formato === '58' ? 58.0 : 80.0;
        $mg = 3.0;
        $cw = $width - 2 * $mg;

        $emRzn  = $q('//s:Encabezado/s:Emisor/s:RznSoc');
        $emRutR = preg_replace('/[^0-9kK]/', '', $q('//s:Encabezado/s:Emisor/s:RUTEmisor')) ?? '';
        $emRut  = self::formatRut($q('//s:Encabezado/s:Emisor/s:RUTEmisor'));
        $emGiro = $q('//s:Encabezado/s:Emisor/s:GiroEmis');
        $cmna   = $q('//s:Encabezado/s:Emisor/s:CmnaOrigen');
        $emDir  = trim($q('//s:Encabezado/s:Emisor/s:DirOrigen') . ($cmna !== '' ? ', ' . $cmna : ''));
        $fchEmis = $q('//s:Encabezado/s:IdDoc/s:FchEmis');
        $mntNeto = $q('//s:Encabezado/s:Totales/s:MntNeto');
        $mntExe  = $q('//s:Encabezado/s:Totales/s:MntExe');
        $iva     = $q('//s:Encabezado/s:Totales/s:IVA');
        $tasa    = $q('//s:Encabezado/s:Totales/s:TasaIVA') ?: '19';
        $mntTot  = $q('//s:Encabezado/s:Totales/s:MntTotal');

        $items = [];
        foreach ($xp->query('//s:Detalle') as $det) {
            $nm = $q('.//s:NmbItem', $det);
            if ($q('.//s:IndExe', $det) === '1') {
                $nm .= ' (EXENTO)';
            }
            $items[] = ['nm' => $nm, 'cant' => $q('.//s:QtyItem', $det), 'mont' => $q('.//s:MontoItem', $det)];
        }

        $resolNum = (int) ($opts['resolNum'] ?? 0);
        $resolAno = substr((string) ($opts['resolFch'] ?? '2021-01-04'), 0, 4) ?: '2021';
        $unidad   = self::unidadSii((string) ($opts['unidadSII'] ?? ''));

        // Alto del ticket: estimación generosa (mejor algo de espacio abajo que cortar).
        $alto = 128.0 + count($items) * 9.0;

        $pdf = new \TCPDF('P', 'mm', [$width, $alto], true, 'UTF-8', false);
        $pdf->SetMargins($mg, 4, $mg);
        $pdf->SetAutoPageBreak(false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();

        // ── Recuadro SII (RUT / tipo / folio) ──
        $by = $pdf->GetY();
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.3);
        $pdf->Rect($mg, $by, $cw, 17);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetXY($mg, $by + 1.5);
        $pdf->Cell($cw, 4, 'R.U.T.: ' . $emRut, 0, 2, 'C');
        $pdf->Cell($cw, 4, self::TIPO_NOMBRE[$tipo] ?? "DOCUMENTO TIPO $tipo", 0, 2, 'C');
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell($cw, 5, 'N° ' . $folio, 0, 2, 'C');
        $pdf->SetY($by + 18);
        $pdf->SetFont('helvetica', '', 7);
        $pdf->Cell($cw, 3.5, 'S.I.I.' . ($unidad !== '' ? ' - ' . $unidad : ''), 0, 2, 'C');
        $pdf->Ln(1.5);

        // ── Emisor ──
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->MultiCell($cw, 3.6, $emRzn, 0, 'L');
        $pdf->SetFont('helvetica', '', 7);
        if ($emGiro !== '') {
            $pdf->MultiCell($cw, 3.2, 'Giro: ' . $emGiro, 0, 'L');
        }
        if ($emDir !== '') {
            $pdf->MultiCell($cw, 3.2, $emDir, 0, 'L');
        }
        $pdf->Cell($cw, 3.6, 'Fecha: ' . $fchEmis, 0, 2, 'L');
        $this->lineaTermica($pdf, $mg, $cw);

        // ── Detalle ──
        $colTotal = 20.0;
        $colCant  = 11.0;
        $colNm    = $cw - $colTotal - $colCant;
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->Cell($colNm, 4, 'Producto', 0, 0, 'L');
        $pdf->Cell($colCant, 4, 'Cant', 0, 0, 'R');
        $pdf->Cell($colTotal, 4, 'Total', 0, 1, 'R');
        $pdf->SetFont('helvetica', '', 7);
        foreach ($items as $it) {
            $y0 = $pdf->GetY();
            $pdf->MultiCell($colNm, 3.4, mb_substr((string) $it['nm'], 0, 70), 0, 'L');
            $yNm = $pdf->GetY();
            $pdf->SetXY($mg + $colNm, $y0);
            $pdf->Cell($colCant, 3.4, self::qty($it['cant']), 0, 0, 'R');
            $pdf->Cell($colTotal, 3.4, '$' . self::n($it['mont']), 0, 0, 'R');
            $pdf->SetY(max($yNm, $y0 + 3.4));
        }
        $this->lineaTermica($pdf, $mg, $cw);

        // ── Totales ──
        $pdf->SetFont('helvetica', '', 8);
        if ($mntNeto !== '' && (float) $mntNeto > 0) {
            $this->totalTermico($pdf, $cw, 'Monto Neto', '$' . self::n($mntNeto));
        }
        if ($iva !== '' && (float) $iva > 0) {
            $this->totalTermico($pdf, $cw, "IVA ({$tasa}%)", '$' . self::n($iva));
        }
        if ($mntExe !== '' && (float) $mntExe > 0) {
            $this->totalTermico($pdf, $cw, 'Monto Exento', '$' . self::n($mntExe));
        }
        $pdf->SetFont('helvetica', 'B', 10);
        $this->totalTermico($pdf, $cw, 'TOTAL', '$' . self::n($mntTot));
        $pdf->Ln(2.5);

        // ── Timbre PDF417 térmico: aspectratio=1.0 → barcode cuadrado (~72×72mm en 80mm),
        // módulo 0.260mm. Es el máximo ratio alcanzable en 80mm con módulo ≥ 0.25mm (SII):
        // cualquier ratio mayor baja el módulo a <0.25mm. aspectratio=0.5 producía 72×116mm
        // (el doble de alto que ancho, excesivo en el ticket). ──
        $tedY = $pdf->GetY();
        $tw = min($cw, $width === 58.0 ? 52.0 : 72.0);
        $tx = $mg + ($cw - $tw) / 2;
        $th = $tw * 1.0;
        if (function_exists('imagecreate')) {
            require_once __DIR__ . '/../../lib/tcpdf/tcpdf_barcodes_2d.php';
            $bc = new \TCPDF2DBarcode($ted, 'PDF417,1.0,5');
            $png = $bc->getBarcodePngData(3, 3, [0, 0, 0]);
            if ($png !== false && $png !== '') {
                $png = self::padBarcodePng($png, 16, 16);
                $dim = @getimagesizefromstring($png);
                if (is_array($dim) && (int) $dim[0] > 0) {
                    $th = $tw * (int) $dim[1] / (int) $dim[0];
                }
                $pdf->Image('@' . $png, $tx, $tedY, $tw, $th, 'PNG');
            }
        }
        $pdf->SetY($tedY + $th + 1.5);
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->Cell($cw, 3.2, 'Timbre Electrónico SII', 0, 2, 'C');
        $pdf->SetFont('helvetica', '', 6.5);
        $pdf->Cell($cw, 3, "Res. {$resolNum} de {$resolAno}", 0, 2, 'C');
        $pdf->Cell($cw, 3, 'Verifique documento: www.sii.cl', 0, 2, 'C');
        $pdf->Cell($cw, 3, "Ver boleta: www.mypos.cl/boleta?rut={$emRutR}&folio={$folio}", 0, 2, 'C');

        return $pdf->Output('', 'S');
    }

    private function lineaTermica(\TCPDF $pdf, float $mg, float $cw): void
    {
        $y = $pdf->GetY() + 0.6;
        $pdf->Line($mg, $y, $mg + $cw, $y);
        $pdf->SetY($y + 1.2);
    }

    private function totalTermico(\TCPDF $pdf, float $cw, string $label, string $value): void
    {
        $pdf->Cell($cw * 0.6, 4.4, $label, 0, 0, 'L');
        $pdf->Cell($cw * 0.4, 4.4, $value, 0, 1, 'R');
    }
}
