<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Context;

/**
 * Parsea y persiste el Set de Pruebas del SII vinculado a una empresa.
 *
 * Permite subir el archivo .txt tal cual lo entrega el SII (ej.
 * "Set Prueba BE.txt" para boletas o "SIISetDePruebas{rut}.txt" para el set
 * básico de facturas) y convertirlo a una estructura JSON reutilizable, sin
 * hardcodear casos ni números de atención.
 *
 * Estructura persistida (cert_sets/{RUT}/set.json):
 * {
 *   "rut": "...", "uploaded": "ISO8601", "origen": "nombre archivo",
 *   "atencion_boletas": null,
 *   "atencion_basico":  "4879708",
 *   "boletas":  [ { "caso","tipoDTE",39,"referencia":{...},"items":[...] }, ... ],
 *   "facturas": [ { "caso","tipoDTE","referencia"?,"descuentoGlobal"?,"items":[...] }, ... ]
 * }
 */
class CertSetManager
{
    private Context $context;
    private string  $setPath;

    public function __construct(Context $context)
    {
        $this->context = $context;
        $this->setPath = $context->getSetPath();
    }

    // =========================================================
    //  PERSISTENCIA
    // =========================================================

    public function load(): ?array
    {
        if (!is_file($this->setPath)) return null;
        $j = json_decode((string)file_get_contents($this->setPath), true);
        return is_array($j) ? $j : null;
    }

    private function save(array $set): void
    {
        $dir = dirname($this->setPath);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        file_put_contents($this->setPath, json_encode($set, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function delete(): bool
    {
        return is_file($this->setPath) ? @unlink($this->setPath) : true;
    }

    /** Devuelve solo los casos de boletas del set vinculado (o []). */
    public function getBoletas(): array
    {
        $s = $this->load();
        return $s['boletas'] ?? [];
    }

    /** Devuelve solo los casos del set basico facturas/NC/ND/guias (o []). */
    public function getFacturas(): array
    {
        $s = $this->load();
        return $s['facturas'] ?? [];
    }

    /** Devuelve las entradas del libro de compras del set vinculado (o []). */
    public function getLibroCompras(): array
    {
        $s = $this->load();
        return $s['libroCompras'] ?? [];
    }

    // =========================================================
    //  IMPORTACIÓN
    // =========================================================

    /**
     * Parsea el contenido crudo del .txt y lo persiste asociado a la empresa.
     *
     * Si GEMINI_API_KEY está configurada en .env, delega el parseo a Gemini
     * (más robusto para variaciones de formato y codificación). Si no, usa
     * el parser nativo PHP. En caso de error de Gemini, cae al parser nativo.
     *
     * @return array resumen { ok, boletas:int, facturas:int, atencion_basico }
     */
    public function importarTxt(string $rawContent, string $origen = 'set.txt'): array
    {
        // ── Intentar parser Gemini si la API key está configurada ──────────────
        if (GeminiParser::disponible()) {
            try {
                return $this->importarConGemini($rawContent, $origen);
            } catch (\RuntimeException $e) {
                // Loguear el error pero continuar con el parser nativo
                error_log('[CertSetManager] Gemini falló, usando parser nativo. Error: ' . $e->getMessage());
            }
        }

        // ── Parser nativo PHP ─────────────────────────────────────────────────
        $txt = $this->normalizarTexto($rawContent);

        $boletas  = $this->parseBoletas($txt);
        $casosGenerales = $this->parseCasosGenerales($txt);
        $libroCompras = $this->parseLibroCompras($txt);

        if (empty($boletas) && empty($casosGenerales)) {
            return ['ok' => false, 'error' => 'No se reconocieron casos de boleta ni de facturación en el archivo. Verifique que sea el .txt del Set de Pruebas del SII.'];
        }

        $actual = $this->load() ?? [];
        $set = [
            'rut'              => $this->context->getRut(),
            'uploaded'         => date('c'),
            'origen'           => $origen,
            'origen_boletas'   => !empty($boletas) ? $origen : ($actual['origen_boletas'] ?? null),
            'origen_basico'    => !empty($casosGenerales) ? $origen : ($actual['origen_basico'] ?? null),
            'atencion_basico'      => $this->extraerAtencion($txt, 'SET BASICO') ?? ($actual['atencion_basico'] ?? null),
            'atencion_ventas'      => $this->extraerAtencion($txt, 'SET LIBRO DE VENTAS') ?? ($actual['atencion_ventas'] ?? null),
            'atencion_compras'     => $this->extraerAtencion($txt, 'SET LIBRO DE COMPRAS') ?? ($actual['atencion_compras'] ?? null),
            'atencion_guias'       => $this->extraerAtencion($txt, 'SET GUIA DE DESPACHO') ?? ($actual['atencion_guias'] ?? null),
            'atencion_libro_guias' => $this->extraerAtencion($txt, 'SET LIBRO DE GUIAS') ?? ($actual['atencion_libro_guias'] ?? null),
            'boletas'              => !empty($boletas) ? $boletas : ($actual['boletas'] ?? []),
            'facturas'             => !empty($casosGenerales) ? $casosGenerales : ($actual['facturas'] ?? []),
            'libroCompras'         => !empty($libroCompras) ? $libroCompras : ($actual['libroCompras'] ?? []),
        ];
        $this->save($set);

        return [
            'ok'              => true,
            'boletas'         => count($boletas),
            'facturas'        => count($casosGenerales),
            'atencion_basico' => $set['atencion_basico'],
            'atencion_ventas' => $set['atencion_ventas'],
            'atencion_compras'=> $set['atencion_compras'],
            'origen'          => $origen,
        ];
    }

    // =========================================================
    //  PARSEO VÍA GEMINI
    // =========================================================

    /**
     * Parsea el .txt usando Gemini y persiste el resultado.
     * Tiene el mismo contrato de retorno que importarTxt().
     */
    private function importarConGemini(string $rawContent, string $origen): array
    {
        $gemini = new GeminiParser();
        $parsed = $gemini->parsearSetTxt($rawContent);

        $boletas        = $parsed['boletas']      ?? [];
        $casosGenerales = $parsed['facturas']     ?? [];
        $libroCompras   = $parsed['libroCompras'] ?? [];

        if (empty($boletas) && empty($casosGenerales)) {
            throw new \RuntimeException('Gemini no reconoció casos en el archivo.');
        }

        $actual = $this->load() ?? [];
        $set = [
            'rut'              => $this->context->getRut(),
            'uploaded'         => date('c'),
            'origen'           => $origen,
            'origen_boletas'   => !empty($boletas) ? $origen : ($actual['origen_boletas'] ?? null),
            'origen_basico'    => !empty($casosGenerales) ? $origen : ($actual['origen_basico'] ?? null),
            'atencion_basico'      => $parsed['atencion_basico']      ?? ($actual['atencion_basico']      ?? null),
            'atencion_ventas'      => $parsed['atencion_ventas']      ?? ($actual['atencion_ventas']      ?? null),
            'atencion_compras'     => $parsed['atencion_compras']     ?? ($actual['atencion_compras']     ?? null),
            'atencion_guias'       => $parsed['atencion_guias']       ?? ($actual['atencion_guias']       ?? null),
            'atencion_libro_guias' => $parsed['atencion_libro_guias'] ?? ($actual['atencion_libro_guias'] ?? null),
            'boletas'              => !empty($boletas) ? $boletas : ($actual['boletas'] ?? []),
            'facturas'             => !empty($casosGenerales) ? $casosGenerales : ($actual['facturas'] ?? []),
            'libroCompras'         => !empty($libroCompras) ? $libroCompras : ($actual['libroCompras'] ?? []),
        ];
        $this->save($set);

        return [
            'ok'               => true,
            'via'              => 'gemini',
            'boletas'          => count($boletas),
            'facturas'         => count($casosGenerales),
            'atencion_basico'  => $set['atencion_basico'],
            'atencion_ventas'  => $set['atencion_ventas'],
            'atencion_compras' => $set['atencion_compras'],
            'origen'           => $origen,
        ];
    }

    /** Convierte a UTF-8 (los .txt del SII vienen en ISO-8859-1) y normaliza saltos. */
    private function normalizarTexto(string $raw): string
    {
        $enc = mb_detect_encoding($raw, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true) ?: 'ISO-8859-1';
        if ($enc !== 'UTF-8') {
            $raw = mb_convert_encoding($raw, 'UTF-8', $enc);
        }
        return str_replace(["\r\n", "\r"], "\n", $raw);
    }

    private function extraerAtencion(string $txt, string $marcador): ?string
    {
        if (preg_match('/' . preg_quote($marcador, '/') . '.*?N[ÚU]MERO DE ATENCI[ÓO]N:\s*(\d+)/iu', $txt, $m)) {
            return $m[1];
        }
        return null;
    }

    // =========================================================
    //  PARSER — BOLETAS
    // =========================================================

    /**
     * Reconoce los casos de boleta (CASO-1, CASO-2, ...). El set de boletas usa
     * "Precio Unitario con IVA", referencia CodRef=SET / RazonRef=CASO-N, y
     * observaciones que marcan ítems exentos o unidad de medida.
     */
    private function parseBoletas(string $txt): array
    {
        // Solo procesar la sección de boletas si el archivo la contiene.
        if (!preg_match('/BOLETA\s+ELECTRONICA/i', $txt)) {
            return [];
        }

        // Aislar la parte de boletas: desde el encabezado hasta un separador de set básico (si lo hubiera).
        $seccion = $txt;
        if (preg_match('/(SET\s+B[ÁA]SICO|SET\s+LIBRO|NUMERO DE ATENCION)/iu', $txt, $m, PREG_OFFSET_CAPTURE)) {
            // Si "CASO-" (boletas) aparece antes que "SET BASICO", recortar en el set básico.
            $posBasico = $m[0][1];
            $posBoleta = stripos($txt, 'CASO-');
            if ($posBoleta !== false && $posBoleta < $posBasico) {
                $seccion = substr($txt, 0, $posBasico);
            }
        }

        // Dividir por casos "CASO-N"
        $bloques = preg_split('/^CASO-(\d+)\s*$/m', $seccion, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (count($bloques) < 3) return [];

        $casos = [];
        // $bloques: [pre, "1", cuerpo1, "2", cuerpo2, ...]
        for ($i = 1; $i < count($bloques); $i += 2) {
            $num    = $bloques[$i];
            $cuerpo = $bloques[$i + 1] ?? '';
            $items  = $this->parseItems($cuerpo, /*conIvaIncluido*/ true);
            if (empty($items)) continue;

            $this->aplicarObservaciones($cuerpo, $items);

            $casos[] = [
                'caso'       => "CASO-$num",
                'tipoDTE'    => 39,
                'referencia' => ['codigo' => 'SET', 'razon' => "CASO-$num"],
                'items'      => $items,
            ];
        }
        return $casos;
    }

    // =========================================================
    //  PARSER – SETS GENERALES (FACTURAS / GUÍAS / NC / ND / ETC)
    // =========================================================

    private function parseCasosGenerales(string $txt): array
    {
        $sub = $txt;
        if (preg_match('/SET\s+B[AÁ]SICO/iu', $txt, $m, PREG_OFFSET_CAPTURE)) {
            $sub = substr($txt, $m[0][1]);
        }

        // Casos "CASO 4879708-1"
        $bloques = preg_split('/^CASO\s+([0-9]+)-(\d+)\s*$/m', $sub, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (count($bloques) < 4) return [];

        $casos = [];
        for ($i = 1; $i < count($bloques); $i += 3) {
            $atencion = $bloques[$i];
            $num      = $bloques[$i + 1];
            $cuerpo   = $bloques[$i + 2] ?? '';
            $caseId   = "$atencion-$num";

            $tipoDTE = $this->detectarTipoDoc($cuerpo);
            $items   = $this->parseItems($cuerpo, /*conIvaIncluido*/ false);

            $caso = ['caso' => $caseId, 'tipoDTE' => $tipoDTE, 'items' => $items, 'atencion' => $atencion, 'num' => (int)$num];

            // Detalles específicos de guías de despacho (traslado / motivo)
            if (preg_match('/MOTIVO:\s*(.+?)(?:\n|$)/iu', $cuerpo, $mm)) {
                $caso['motivo'] = trim($mm[1]);
            }
            if (preg_match('/TRASLADO\s+POR:\s*(.+?)(?:\n|$)/iu', $cuerpo, $mm)) {
                $caso['trasladoPor'] = trim($mm[1]);
            }

            // Descuento global "DESCUENTO GLOBAL ITEMES AFECTOS  23%"
            if (preg_match('/DESCUENTO\s+GLOBAL[^\d]*(\d+)\s*%/iu', $cuerpo, $m)) {
                $caso['descuentoGlobal'] = (int)$m[1];
            }

            // Referencia a otro caso (NC/ND)
            if (preg_match('/REFERENCIA\s+.*?CASO\s+[0-9]+-(\d+)/isu', $cuerpo, $m)) {
                $razon = '';
                if (preg_match('/RAZON\s+REFERENCIA\s+(.+)/iu', $cuerpo, $mr)) {
                    $razon = trim(preg_split('/\n/', $mr[1])[0]);
                }
                $caso['referencia'] = ['caso_ref' => $atencion . '-' . $m[1], 'razon' => $razon];
            }

            $casos[] = $caso;
        }
        return $casos;
    }

    private function detectarTipoDoc(string $cuerpo): int
    {
        $docLine = '';
        if (preg_match('/^DOCUMENTO\s+(.+)$/imu', $cuerpo, $m)) {
            $docLine = trim($m[1]);
        }
        $scope = $docLine !== '' ? $docLine : $cuerpo;

        if (preg_match('/NOTA\s+DE\s+DEBITO/iu', $scope))   return 56;
        if (preg_match('/NOTA\s+DE\s+CREDITO/iu', $scope))  return 61;
        if (preg_match('/FACTURA\s+DE\s+COMPRA/iu', $scope)) return 46;
        if (preg_match('/GUIA\s+DE\s+DESPACHO/iu', $scope)) return 52;
        if (preg_match('/LIQUIDACION/iu', $scope))          return 43;
        return 33; // Factura electronica por defecto
    }

    // =========================================================
    //  HELPERS DE PARSEO DE LÍNEAS
    // =========================================================

    /**
     * Extrae ítems de un bloque de texto. Cada línea de ítem termina en
     * cantidad y (opcionalmente) precio; el resto es el nombre.
     *
     * Acepta:
     *   "Cambio de aceite      1      19900"        → qty, precio
     *   "Pañuelo AFECTO   767   5937   10%"         → qty, precio, descuento%
     *   "ITEM 1                71"                  → solo cantidad (guías)
     */
    private function parseItems(string $cuerpo, bool $conIvaIncluido): array
    {
        $items = [];
        foreach (explode("\n", $cuerpo) as $linea) {
            $l = trim($linea);
            if ($l === '') continue;

            // Saltar metadatos y la fila de ENCABEZADO de columnas.
            // OJO: no filtrar por "ITEM" al inicio — hay ítems reales llamados
            // "ITEM 1 AFECTO", "item exento 2", etc. La fila de encabezado se
            // reconoce porque contiene la columna CANTIDAD / PRECIO / TOTAL LINEA.
            if (preg_match('/^(DOCUMENTO|REFERENCIA|RAZON|MOTIVO|TRASLADO|OBSERVAC|DESCUENTO\s+GLOBAL|COMISIONES|={3,}|-{3,})/iu', $l)
                || preg_match('/\b(CANTIDAD|PRECIO\s+UNITARIO|TOTAL\s+LINEA)\b/iu', $l)) {
                continue;
            }

            // nombre + cantidad + precio (+ opcional descuento %)
            if (preg_match('/^(.+?)\s+(\d{1,7})\s+([\d.]+)(?:\s+(\d{1,3})\s*%)?\s*$/u', $l, $m)) {
                $items[] = [
                    'nombre'    => trim($m[1]),
                    'cantidad'  => (int)$m[2],
                    'precio'    => (int)str_replace('.', '', $m[3]),
                    'descuento' => isset($m[4]) ? (int)$m[4] : 0,
                    'exento'    => (bool)preg_match('/exento/i', $m[1]),
                ];
                continue;
            }

            // nombre + cantidad (guías de despacho sin precio)
            if (preg_match('/^(.+?)\s+(\d{1,7})\s*$/u', $l, $m) && !preg_match('/^(CASO|SET|FACTURA|BOLETA)/i', $l)) {
                $items[] = [
                    'nombre'   => trim($m[1]),
                    'cantidad' => (int)$m[2],
                    'precio'   => 0,
                    'exento'   => (bool)preg_match('/exento/i', $m[1]),
                ];
            }
        }
        return $items;
    }

    /**
     * Aplica las OBSERVACIONES de un caso a sus ítems:
     *   - "El item N ... es ... exento"  → marca ese ítem como exento
     *   - "Unidad de medida en Kg"       → fija unidadMedida en los ítems
     */
    private function aplicarObservaciones(string $cuerpo, array &$items): void
    {
        if (!preg_match('/OBSERVAC/iu', $cuerpo)) return;

        // Ítems exentos por número: "item 2 ... exento"
        if (preg_match_all('/item\s+(\d+)[^.]*?exento/iu', $cuerpo, $mm)) {
            foreach ($mm[1] as $idx) {
                $k = (int)$idx - 1;
                if (isset($items[$k])) $items[$k]['exento'] = true;
            }
        }

        // Unidad de medida (ej. Kg)
        if (preg_match('/unidad\s+de\s+medida\s+en\s+([A-Za-zñÑ]+)/iu', $cuerpo, $m)) {
            $um = trim($m[1]);
            foreach ($items as &$it) { $it['unidadMedida'] = $um; }
            unset($it);
        }
    }

    public function parseLibroCompras(string $txt): array
    {
        // El .txt del SII tiene una línea en blanco (con espacio) entre el encabezado
        // y los separadores ====. El patrón salta esas líneas con [\s\S]*? antes del
        // primer ====, luego salta la sección de cabeceras de columna (hasta el segundo
        // ====) y captura los registros hasta el tercer ==== o fin del bloque.
        if (!preg_match('/SET\s+LIBRO\s+DE\s+COMPRAS[\s\S]*?={5,}[^=]+={5,}([\s\S]*?)={5,}/iu', $txt, $m)) {
            return [];
        }

        $sub = trim($m[1]);

        $lines = explode("\n", $sub);
        $compras = [];

        for ($i = 0; $i < count($lines); $i++) {
            $l = trim($lines[$i]);
            if ($l === '') continue;

            if (preg_match('/^(.+?)\s+(\d+)$/u', $l, $mm)) {
                $tipoDocStr = trim($mm[1]);
                $folio = (int)$mm[2];
                $obs = trim($lines[$i+1] ?? '');
                
                $montosLine = trim($lines[$i+2] ?? '');
                $exento = 0;
                $afecto = 0;
                if (preg_match('/^(\d+)\s+(\d+)$/', $montosLine, $mmm)) {
                    $exento = (int)$mmm[1];
                    $afecto = (int)$mmm[2];
                } elseif (preg_match('/^(\d+)$/', $montosLine, $mmm)) {
                    $afecto = (int)$mmm[1];
                }

                $tipo = 33;
                if (stripos($tipoDocStr, 'FACTURA DE COMPRA ELECTRONICA') !== false) $tipo = 46;
                elseif (stripos($tipoDocStr, 'FACTURA DE COMPRA') !== false) $tipo = 46;
                elseif (stripos($tipoDocStr, 'FACTURA ELECTRONICA') !== false) $tipo = 33;
                elseif (stripos($tipoDocStr, 'FACTURA NO AFECTA') !== false) $tipo = 34;
                elseif (stripos($tipoDocStr, 'FACTURA') !== false) $tipo = 33;
                elseif (stripos($tipoDocStr, 'NOTA DE CREDITO ELECTRONICA') !== false) $tipo = 61;
                elseif (stripos($tipoDocStr, 'NOTA DE CREDITO') !== false) $tipo = 61;
                elseif (stripos($tipoDocStr, 'NOTA DE DEBITO') !== false) $tipo = 56;

                $compras[] = [
                    'tipo'   => $tipo,
                    'folio'  => $folio,
                    'obs'    => $obs,
                    'exento' => $exento,
                    'afecto' => $afecto,
                ];
                $i += 2;
            }
        }
        return $compras;
    }
}
