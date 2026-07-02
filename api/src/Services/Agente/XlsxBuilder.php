<?php

declare(strict_types=1);

namespace Mypos\Services\Agente;

/**
 * Generador de .xlsx SIN dependencias (ni PhpSpreadsheet ni ext-zip):
 * arma el contenedor ZIP a mano con entradas SIN comprimir (método store,
 * válido según la especificación ZIP y aceptado por Excel/LibreOffice) y
 * el XML mínimo de SpreadsheetML (una hoja, strings inline).
 *
 * Uso: XlsxBuilder::build(['Col A', 'Col B'], [[1, 'x'], [2, 'y']], 'Hoja')
 * → string binario listo para adjuntar por correo.
 *
 * Pensado para las exportaciones del agente IA (docs/AGENTE_PLAN_MEJORA.md):
 * volúmenes de miles de filas, no cientos de miles.
 */
final class XlsxBuilder
{
    /**
     * @param string[] $headers
     * @param array<int, array<int, mixed>> $rows
     */
    public static function build(array $headers, array $rows, string $sheetName = 'Datos'): string
    {
        $files = [
            '[Content_Types].xml' => self::contentTypesXml(),
            '_rels/.rels' => self::relsXml(),
            'xl/workbook.xml' => self::workbookXml($sheetName),
            'xl/_rels/workbook.xml.rels' => self::workbookRelsXml(),
            'xl/worksheets/sheet1.xml' => self::sheetXml($headers, $rows),
        ];

        return self::zip($files);
    }

    private static function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';
    }

    private static function relsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbookXml(string $sheetName): string
    {
        $name = self::xmlEscape(mb_substr($sheetName !== '' ? $sheetName : 'Datos', 0, 31));
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $name . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>';
    }

    /**
     * @param string[] $headers
     * @param array<int, array<int, mixed>> $rows
     */
    private static function sheetXml(array $headers, array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        $xml .= self::rowXml($headers);
        foreach ($rows as $row) {
            $xml .= self::rowXml(is_array($row) ? array_values($row) : [$row]);
        }

        return $xml . '</sheetData></worksheet>';
    }

    /** @param array<int, mixed> $cells */
    private static function rowXml(array $cells): string
    {
        $xml = '<row>';
        foreach ($cells as $cell) {
            if ($cell === null || $cell === '') {
                $xml .= '<c/>';
            } elseif (is_int($cell) || is_float($cell) || (is_string($cell) && preg_match('/^-?\d+(\.\d+)?$/', $cell) === 1)) {
                $xml .= '<c><v>' . $cell . '</v></c>';
            } else {
                $xml .= '<c t="inlineStr"><is><t xml:space="preserve">'
                    . self::xmlEscape((string) $cell) . '</t></is></c>';
            }
        }
        return $xml . '</row>';
    }

    private static function xmlEscape(string $value): string
    {
        // Excel rechaza caracteres de control en el XML; se filtran primero.
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
        return htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    // ── Contenedor ZIP (método store, sin ext-zip) ───────────────────────────

    /** @param array<string, string> $files nombre → contenido */
    private static function zip(array $files): string
    {
        $body = '';
        $central = '';
        $offset = 0;
        // Fecha DOS fija (2026-01-01 00:00): campo obligatorio, valor irrelevante.
        $dosTime = 0;
        $dosDate = ((2026 - 1980) << 9) | (1 << 5) | 1;

        foreach ($files as $name => $content) {
            $crc = crc32($content);
            $size = strlen($content);

            $local = "PK\x03\x04"
                . pack('v', 20)        // versión requerida
                . pack('v', 0)         // flags
                . pack('v', 0)         // método: store
                . pack('v', $dosTime)
                . pack('v', $dosDate)
                . pack('V', $crc)
                . pack('V', $size)     // comprimido = original (store)
                . pack('V', $size)
                . pack('v', strlen($name))
                . pack('v', 0)         // extra
                . $name
                . $content;

            $central .= "PK\x01\x02"
                . pack('v', 20)        // versión creadora
                . pack('v', 20)
                . pack('v', 0)
                . pack('v', 0)
                . pack('v', $dosTime)
                . pack('v', $dosDate)
                . pack('V', $crc)
                . pack('V', $size)
                . pack('V', $size)
                . pack('v', strlen($name))
                . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('v', 0)
                . pack('V', 32)        // atributos externos (archivo)
                . pack('V', $offset)
                . $name;

            $offset += strlen($local);
            $body .= $local;
        }

        $eocd = "PK\x05\x06"
            . pack('v', 0) . pack('v', 0)
            . pack('v', count($files)) . pack('v', count($files))
            . pack('V', strlen($central))
            . pack('V', $offset)
            . pack('v', 0);

        return $body . $central . $eocd;
    }
}
