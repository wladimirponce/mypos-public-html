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

    /** Tipos que requieren ejemplar CEDIBLE además del tributario */
    public const TIPOS_CEDIBLE = [33, 34, 43, 46, 52];

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

    /**
     * @param array  $dte  ['tipo','folio','xml' (UTF-8), 'raw' (ISO-8859-1), 'label']
     * @param array  $opts ['unidadSII','resolNum','resolFch','certBanner'=>bool]
     * @param string $copia 'TRIBUTARIA' | 'CEDIBLE'
     * @return string bytes del PDF
     */
    public function render(array $dte, array $opts, string $copia = 'TRIBUTARIA'): string
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

        // TED con los bytes ORIGINALES firmados (ISO-8859-1)
        $ted = '';
        if (!empty($dte['raw']) && preg_match('/<TED[\s\S]*?<\/TED>/', $dte['raw'], $m)) {
            $ted = $m[0];
        }
        if ($ted === '') {
            throw new Exception("No se encontró el TED en el XML de T{$tipo}F{$folio}");
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
        $unidad   = (string)($opts['unidadSII'] ?? 'S.I.I.');

        // ── PDF ──────────────────────────────────────────────────────────────
        $pdf = new \TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
        $pdf->SetCreator('MyPOS DTE');
        $pdf->SetTitle("Muestra T{$tipo} F{$folio} {$copia}");
        $pdf->SetMargins(12, 10, 12);
        $pdf->SetAutoPageBreak(true, 10);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();

        // Banner de certificación (discreto)
        if (!empty($opts['certBanner'])) {
            $pdf->SetFont('helvetica', '', 7);
            $pdf->SetTextColor(150, 150, 150);
            $pdf->SetXY(12, 5);
            $pdf->Cell(120, 3, 'DOCUMENTO DE CERTIFICACIÓN — SIN VALIDEZ TRIBUTARIA', 0, 0, 'L');
            $pdf->SetTextColor(0, 0, 0);
        }

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
        $pdf->Cell($bw, 4, 'S.I.I. — ' . $unidad, 0, 0, 'C');
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
        $pdf->Cell(120, 4, 'Ciudad: ' . $reCiu, 0, 0, 'L');
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
                $linea  = "Doc: {$tpoRef}  Folio {$folRef}  ({$fchRef})";
                if ($codRef !== '') $linea .= "  Cód: {$codRef}";
                if ($rznRef !== '') $linea .= "  — {$rznRef}";
                $pdf->SetX(14);
                $pdf->Cell(190, 3.8, $linea, 0, 1, 'L');
            }
            $y = $pdf->GetY() + 2;
        }

        // Traslado (guías)
        if ($tipo === 52) {
            $indT = (int)$q('//s:Encabezado/s:IdDoc/s:IndTraslado');
            $tDes = (int)$q('//s:Encabezado/s:IdDoc/s:TipoDespacho');
            $pdf->SetXY(12, $y);
            $pdf->SetFont('helvetica', '', 8);
            if ($indT) $pdf->Cell(192, 4, 'TIPO DE TRASLADO: ' . (self::IND_TRASLADO[$indT] ?? $indT), 0, 1, 'L');
            if ($tDes) { $pdf->SetX(12); $pdf->Cell(192, 4, 'TIPO DE DESPACHO: ' . (self::TIPO_DESPACHO[$tDes] ?? $tDes), 0, 1, 'L'); }
            $y = $pdf->GetY() + 2;
        }

        // Detalle
        $pdf->SetXY(12, $y);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell(8, 5, '#', 'B', 0, 'L');
        $pdf->Cell(92, 5, 'Descripción', 'B', 0, 'L');
        $pdf->Cell(18, 5, 'Cant.', 'B', 0, 'R');
        $pdf->Cell(26, 5, 'P. Unitario', 'B', 0, 'R');
        $pdf->Cell(16, 5, 'Dcto.%', 'B', 0, 'R');
        $pdf->Cell(32, 5, 'Total', 'B', 1, 'R');
        $pdf->SetFont('helvetica', '', 8);
        foreach ($xp->query('//s:Detalle') as $det) {
            $nl   = $q('.//s:NroLinDet', $det);
            $nm   = $q('.//s:NmbItem', $det);
            $cant = $q('.//s:QtyItem', $det);
            $prc  = $q('.//s:PrcItem', $det);
            $dcto = $q('.//s:DescuentoPct', $det);
            $mont = $q('.//s:MontoItem', $det);
            $exe  = $q('.//s:IndExe', $det);
            $unm  = $q('.//s:UnmdItem', $det);
            if ($exe === '1') $nm .= ' (EXENTO)';
            if ($unm !== '')  $cant = ($cant !== '' ? self::qty($cant) . ' ' . $unm : $unm);
            elseif ($cant !== '') $cant = self::qty($cant);
            $pdf->SetX(12);
            $pdf->Cell(8, 4.5, $nl, 0, 0, 'L');
            $pdf->Cell(92, 4.5, mb_substr($nm, 0, 60), 0, 0, 'L');
            $pdf->Cell(18, 4.5, $cant, 0, 0, 'R');
            $pdf->Cell(26, 4.5, $prc !== '' ? '$' . self::n($prc) : '', 0, 0, 'R');
            $pdf->Cell(16, 4.5, $dcto !== '' ? self::n($dcto) . '%' : '', 0, 0, 'R');
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

        // Acuse de recibo (cedibles y guías) — Ley 19.983
        $esCedible = strtoupper($copia) === 'CEDIBLE';
        if ($esCedible || $tipo === 52) {
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

        // Timbre PDF417 — mínimo 2x5 cm, a ≥2 cm del borde izquierdo
        $tedY = min($y + 2, 235.0);
        $pdf->write2DBarcode($ted, 'PDF417', 20, $tedY, 60, 22, [
            'border' => false, 'padding' => 0,
            'fgcolor' => [0, 0, 0], 'bgcolor' => false,
        ], 'N');
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetXY(20, $tedY + 23);
        $pdf->Cell(60, 3.5, 'Timbre Electrónico SII', 0, 2, 'C');
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell(60, 3.5, "Res. Ex. SII N° {$resolNum} del {$resolAno}", 0, 2, 'C');
        $pdf->Cell(60, 3.5, 'Verifique documento: www.sii.cl', 0, 2, 'C');

        // Marca CEDIBLE
        if ($esCedible) {
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->SetDrawColor(0, 0, 0);
            $pdf->SetLineWidth(0.4);
            $txt = $tipo === 52 ? 'CEDIBLE CON SU FACTURA' : 'CEDIBLE';
            $pdf->SetXY(128, $tedY + 18);
            $pdf->Cell(76, 9, $txt, 1, 0, 'C');
        }

        return $pdf->Output('', 'S');
    }
}
