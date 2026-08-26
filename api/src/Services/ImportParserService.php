<?php

declare(strict_types=1);

namespace Mypos\Services;

use Exception;
use Mypos\Core\HttpException;

final class ImportParserService
{
    /**
     * Cabeceras del ultimo archivo parseado que no correspondieron a ningun
     * campo conocido.
     *
     * Antes una columna sin mapear se descartaba sin dejar rastro: el caso
     * tipico era "Stock", que no calzaba con ningun campo esperado y hacia que
     * el catalogo entero se importara con existencias en cero, reportando la
     * importacion como exitosa. Quien parsea puede leer esto y advertirlo.
     *
     * @var array<int, string>
     */
    private array $columnasIgnoradas = [];

    /**
     * @return array<int, string>
     */
    public function columnasIgnoradas(): array
    {
        return $this->columnasIgnoradas;
    }

    /**
     * Parsea un archivo CSV detectando el separador y encoding.
     */
    public function parseCsv(string $filePath, array $expectedFields): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new HttpException('El archivo CSV no es válido o no se puede leer', 422);
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new HttpException('No se pudo leer el contenido del archivo CSV', 422);
        }

        // Convertir de ISO-8859-1 / Windows-1252 a UTF-8 si es necesario
        $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
        if ($encoding !== 'UTF-8' && $encoding !== false) {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        // Detectar separador (coma, punto y coma, tabulador)
        $delimiters = [';', ',', "\t"];
        $separator = ';';
        $maxCount = -1;

        // Probar las primeras líneas para ver cuál separador se repite más de forma consistente
        $lines = explode("\n", str_replace("\r", "", $content));
        $testLines = array_slice($lines, 0, 5);

        foreach ($delimiters as $delim) {
            $count = 0;
            foreach ($testLines as $line) {
                if (trim($line) !== '') {
                    $count += count(explode($delim, $line));
                }
            }
            if ($count > $maxCount) {
                $maxCount = $count;
                $separator = $delim;
            }
        }

        // Parsear filas usando str_getcsv
        $rows = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            $rows[] = str_getcsv($trimmed, $separator);
        }

        if ($rows === []) {
            throw new HttpException('El archivo CSV está vacío', 422);
        }

        $header = array_shift($rows);
        $mapping = $this->detectMapping($header, $expectedFields);

        return $this->normalizeRows($rows, $mapping, $expectedFields);
    }

    /**
     * Parsea un archivo Excel (.xlsx) nativamente usando ZipArchive y SimpleXML.
     */
    public function parseXlsx(string $filePath, array $expectedFields): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new HttpException('El archivo Excel no es válido o no se puede leer', 422);
        }

        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new HttpException('No se pudo abrir el archivo Excel. Asegúrese de que es un archivo .xlsx válido', 422);
        }

        // 1. Cargar Shared Strings
        $sharedStrings = [];
        $sharedStringsEntry = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedStringsEntry !== false) {
            try {
                $xml = new \SimpleXMLElement($sharedStringsEntry);
                foreach ($xml->si as $si) {
                    if (isset($si->t)) {
                        $sharedStrings[] = (string) $si->t;
                    } else {
                        $parts = [];
                        if (isset($si->r)) {
                            foreach ($si->r as $r) {
                                $parts[] = (string) $r->t;
                            }
                        }
                        $sharedStrings[] = implode('', $parts);
                    }
                }
            } catch (Exception $e) {
                // Si falla el parseo XML de strings compartidos, continuar con strings vacíos
            }
        }

        // 2. Cargar Hoja 1
        $sheetEntry = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetEntry === false) {
            $zip->close();
            throw new HttpException('No se encontró la primera hoja en el archivo Excel', 422);
        }

        try {
            $xml = new \SimpleXMLElement($sheetEntry);
        } catch (Exception $e) {
            $zip->close();
            throw new HttpException('Error al parsear el archivo XML del Excel', 422);
        }

        $rows = [];
        if (isset($xml->sheetData->row)) {
            foreach ($xml->sheetData->row as $rowNode) {
                $rowIndex = (int) $rowNode['r'];
                $rowCells = [];

                foreach ($rowNode->c as $cellNode) {
                    $coordinate = (string) $cellNode['r'];
                    $colName = preg_replace('/[0-9]/', '', $coordinate);
                    $colIndex = $this->columnLetterToIndex($colName);

                    $type = (string) $cellNode['t'];
                    $val = (string) $cellNode->v;

                    if ($type === 's') { // Shared String
                        $val = $sharedStrings[(int) $val] ?? '';
                    } elseif ($type === 'inlineStr') {
                        // El texto va dentro de <is><t>, no en <v>. Lo escriben asi
                        // openpyxl y varios exportadores; sin este caso las celdas de
                        // texto llegaban vacias, la cabecera no se reconocia y el
                        // archivo se rechazaba con "no contiene filas de datos".
                        $val = '';
                        if (isset($cellNode->is)) {
                            if (isset($cellNode->is->t)) {
                                $val = (string) $cellNode->is->t;
                            } elseif (isset($cellNode->is->r)) {
                                foreach ($cellNode->is->r as $run) {
                                    $val .= (string) $run->t;
                                }
                            }
                        }
                    }

                    $rowCells[$colIndex] = $val;
                }

                if ($rowCells !== []) {
                    $maxIndex = max(array_keys($rowCells));
                    for ($i = 0; $i <= $maxIndex; $i++) {
                        if (!isset($rowCells[$i])) {
                            $rowCells[$i] = '';
                        }
                    }
                    ksort($rowCells);
                    $rows[$rowIndex] = $rowCells;
                }
            }
        }

        $zip->close();

        if ($rows === []) {
            throw new HttpException('El archivo Excel está vacío', 422);
        }

        // Normalizar los índices a secuenciales 0-indexed
        $flatRows = array_values($rows);
        $header = array_shift($flatRows);
        $mapping = $this->detectMapping($header, $expectedFields);

        return $this->normalizeRows($flatRows, $mapping, $expectedFields);
    }

    /**
     * Mapea y normaliza las filas leídas a la estructura de campos requerida.
     */
    private function normalizeRows(array $rows, array $mapping, array $expectedFields): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            $item = [];
            $hasData = false;

            foreach ($expectedFields as $field) {
                $index = $mapping[$field] ?? null;
                $val = $index !== null ? ($row[$index] ?? null) : null;
                $textVal = $val !== null ? trim((string) $val) : '';
                
                if ($textVal !== '') {
                    $hasData = true;
                    // Limpieza específica para campos numéricos/dinero
                    if (in_array($field, ['precio_compra', 'precio_costo', 'precio_venta', 'stock_minimo', 'stock_inicial', 'factor_conversion'], true)) {
                        $item[$field] = $this->cleanNumber($textVal);
                    } else {
                        $item[$field] = $textVal;
                    }
                } else {
                    $item[$field] = null;
                }
            }

            if ($hasData) {
                $normalized[] = $item;
            }
        }

        return $normalized;
    }

    /**
     * Limpia strings que representan números con formatos de moneda, puntos o comas.
     */
    private function cleanNumber(string $str): float
    {
        // Quitar símbolos de moneda y espacios
        $str = str_replace(['$', ' ', '€', '£'], '', $str);
        
        // Si tiene formato chileno/europeo (ej: 1.000,50 o 1.250)
        // Detectar si hay puntos como miles y coma como decimal
        if (str_contains($str, ',') && str_contains($str, '.')) {
            if (strrpos($str, ',') > strrpos($str, '.')) {
                // Puntos como miles, coma como decimal -> Quitar puntos, reemplazar coma por punto
                $str = str_replace('.', '', $str);
                $str = str_replace(',', '.', $str);
            } else {
                // Comas como miles, punto como decimal -> Quitar comas
                $str = str_replace(',', '', $str);
            }
        } elseif (str_contains($str, ',')) {
            // Si solo tiene comas, ver si actúa como decimal (ej: 1,5) o miles (ej: 1,000)
            // En Chile, usualmente es decimal para cantidades o miles para dinero.
            // Para ser seguros, si hay 3 dígitos después de la coma y no hay puntos, puede ser miles,
            // pero si es menor a 3 o es float, reemplazamos por punto para que actúe como decimal en PHP.
            $parts = explode(',', $str);
            if (count($parts) === 2 && strlen($parts[1]) === 3) {
                $str = str_replace(',', '', $str);
            } else {
                $str = str_replace(',', '.', $str);
            }
        }

        return (float) $str;
    }

    /**
     * Convierte una letra de columna de Excel (A, B, C... AA, AB) a índice numérico (0, 1, 2...).
     */
    private function columnLetterToIndex(string $col): int
    {
        $col = strtoupper($col);
        $len = strlen($col);
        $index = 0;
        for ($i = 0; $i < $len; $i++) {
            $index = $index * 26 + (ord($col[$i]) - 64);
        }
        return $index - 1;
    }

    /**
     * Autodetecta el mapeo de columnas según términos conocidos.
     */
    private function detectMapping(array $header, array $expectedFields): array
    {
        $mapping = [];
        $synonyms = [
            'codigo_interno' => ['codigo_interno', 'codigo', 'sku', 'id_interno', 'ref', 'código', 'cod', 'codigo interno', 'código interno'],
            'codigo_barra' => ['codigo_barra', 'barra', 'ean', 'upc', 'barcode', 'código de barra', 'código barras', 'codigo barra', 'codigo de barra', 'barras'],
            'nombre' => ['nombre', 'descripcion', 'producto', 'item', 'title', 'nombre producto', 'nombre_producto', 'nombre comercial', 'mercaderia'],
            'descripcion' => ['descripcion_larga', 'detalle', 'observacion', 'long_description', 'detalles', 'observaciones'],
            'precio_compra' => ['precio_compra', 'valor_compra', 'compra', 'cost', 'costo unitario', 'valor compra', 'costo neto'],
            'precio_costo' => ['precio_costo', 'costo', 'precio costo', 'p.costo', 'p costo'],
            'precio_venta' => ['precio_venta', 'valor_venta', 'venta', 'precio', 'price', 'precio venta', 'valor venta', 'p.venta', 'p venta'],
            'rubro' => ['rubro', 'categoria', 'categoría', 'category', 'departamento', 'familia'],
            'stock_minimo' => ['stock_minimo', 'stock minimo', 'minimo', 'mínimo', 'stock_min', 'min_stock', 'stock min'],
            // Existencias con las que arranca el producto. Ojo: 'stock' a secas
            // pertenece aqui y NO a stock_minimo, que es el umbral de reposicion.
            'stock_inicial' => [
                'stock_inicial', 'stock inicial', 'stock', 'cantidad', 'cantidades', 'existencia',
                'existencias', 'inventario', 'saldo', 'unidades', 'stock actual', 'stock_actual',
            ],
            'activo' => ['activo', 'active', 'estado', 'habilitado', 'vigente'],
            'proveedor' => ['proveedor', 'marca', 'provider', 'brand', 'proveedor_identificado', 'proveedores'],
            'codigo_proveedor' => ['codigo_proveedor', 'codigo_prov', 'prov_code', 'ref_proveedor', 'codigo prov', 'código proveedor', 'codigo de proveedor'],
            'unidad_compra' => ['unidad_compra', 'unidad', 'embalaje', 'unit', 'unidad de compra', 'medida'],
            'factor_conversion' => ['factor_conversion', 'factor', 'conversion', 'multiplo', 'factor de conversión', 'multiplicador', 'factor conversion']
        ];

        $this->columnasIgnoradas = [];

        foreach ($header as $index => $colName) {
            if ($colName === null || trim((string) $colName) === '') {
                continue;
            }
            $cleanColName = $this->normalizeString((string) $colName);
            $mapeada = false;

            foreach ($expectedFields as $field) {
                if (isset($synonyms[$field])) {
                    foreach ($synonyms[$field] as $synonym) {
                        if ($this->normalizeString($synonym) === $cleanColName) {
                            $mapping[$field] = $index;
                            $mapeada = true;
                            break 2;
                        }
                    }
                }
            }

            // Una columna que no calza con ningun campo esperado se descarta.
            // Queda anotada para que quien parsea pueda advertirlo en vez de
            // perder el dato en silencio.
            if (!$mapeada) {
                $this->columnasIgnoradas[] = trim((string) $colName);
            }
        }

        // Si algún campo esperado obligatorio no se mapeó automáticamente, intentar asociarlo por orden secuencial de índices
        // para dar un fallback razonable si la cabecera no tiene nombres claros.
        return $mapping;
    }

    private function normalizeString(string $str): string
    {
        $str = mb_strtolower(trim($str), 'UTF-8');
        // Quitar acentos
        $str = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü'],
            ['a', 'e', 'i', 'o', 'u', 'n', 'u'],
            $str
        );
        // Reemplazar espacios por guiones bajos
        $str = str_replace(' ', '_', $str);
        // Remover caracteres no alfanuméricos simples
        return preg_replace('/[^a-z0-9_]/', '', $str);
    }
}
