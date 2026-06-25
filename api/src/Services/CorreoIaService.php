<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\CorreoMensajeRepository;

final class CorreoIaService
{
    private CorreoMensajeRepository $repository;
    private GeminiService $gemini;

    public function __construct(?CorreoMensajeRepository $repository = null, ?GeminiService $gemini = null)
    {
        $this->repository = $repository ?? new CorreoMensajeRepository(Database::connection());
        $this->gemini = $gemini ?? new GeminiService();
    }

    /**
     * Resume una conversacion. Cachea por hash del contenido para no re-llamar
     * a la IA si el hilo no cambio.
     */
    public function resumenHilo(int $empresaId, int $hiloId, bool $forzar = false): array
    {
        if ($empresaId <= 0 || $hiloId <= 0) {
            throw new HttpException('Parametros invalidos', 422);
        }
        $mensajes = $this->repository->mensajesDeHilo($empresaId, $hiloId);
        if ($mensajes === []) {
            throw new HttpException('Hilo no encontrado', 404);
        }

        $contenido = $this->aplanarHilo($mensajes);
        $hash = hash('sha256', $contenido);

        if (!$forzar) {
            $cacheado = $this->repository->resumenCacheado($empresaId, $hiloId, $hash);
            if ($cacheado !== null) {
                return ['resumen' => $cacheado, 'cacheado' => true];
            }
        }

        $prompt = "Eres un asistente del correo de una empresa chilena (PYME). "
            . "Resume la siguiente conversacion de correo en espanol, en 3 a 5 lineas. "
            . "Indica: de que trata, que se pide o acuerda, montos / fechas / vencimientos relevantes, "
            . "y si requiere alguna accion de parte de la empresa. Se concreto y directo, sin saludos.\n\n"
            . "=== CONVERSACION ===\n" . $contenido;

        $resumen = trim($this->generarTexto($prompt));
        if ($resumen === '') {
            throw new HttpException('La IA no devolvio un resumen', 502);
        }

        $this->repository->guardarResumen($empresaId, $hiloId, $resumen, $this->gemini->configuracionPublica(true)['modelo'] ?? null, $hash);

        return ['resumen' => $resumen, 'cacheado' => false];
    }

    /**
     * Busqueda contextual/blanda: responde una pregunta en lenguaje natural
     * usando los correos de la bandeja como contexto.
     */
    public function buscarContextual(int $empresaId, string $pregunta): array
    {
        $pregunta = trim($pregunta);
        if ($empresaId <= 0 || $pregunta === '') {
            throw new HttpException('Debes indicar una pregunta', 422);
        }

        $candidatos = $this->repository->buscarMensajesIa($empresaId, $this->terminosBusqueda($pregunta), 30);
        if ($candidatos === []) {
            // Sin coincidencias por palabras: usar los mas recientes como contexto.
            $candidatos = $this->repository->buscarMensajesIa($empresaId, '', 30);
        }
        if ($candidatos === []) {
            return ['respuesta' => 'No hay correos en la bandeja para responder.', 'correos' => []];
        }

        $contexto = '';
        $porId = [];
        foreach ($candidatos as $row) {
            $id = (int) $row['id'];
            $porId[$id] = $row;
            $contexto .= sprintf(
                "[#%d] De: %s | Fecha: %s | Asunto: %s\n%s\n\n",
                $id,
                (string) ($row['remitente'] ?? ''),
                (string) ($row['fecha'] ?? ''),
                (string) ($row['asunto'] ?? ''),
                (string) ($row['cuerpo'] ?? '')
            );
        }

        $prompt = "Eres un asistente del correo de una empresa chilena. Responde la PREGUNTA del usuario "
            . "usando UNICAMENTE la informacion de los CORREOS listados (cada uno con su id entre corchetes). "
            . "Si la respuesta no esta en los correos, dilo claramente. Responde en espanol, breve y concreto.\n"
            . "Devuelve SOLO un JSON con esta forma: "
            . "{\"respuesta\": \"texto\", \"correos_relevantes\": [ids numericos]}.\n\n"
            . "=== PREGUNTA ===\n" . $pregunta . "\n\n"
            . "=== CORREOS ===\n" . $contexto;

        $json = $this->generarJson($prompt);
        $respuesta = trim((string) ($json['respuesta'] ?? ''));
        if ($respuesta === '') {
            $respuesta = 'No fue posible generar una respuesta.';
        }

        $relevantes = [];
        foreach ((array) ($json['correos_relevantes'] ?? []) as $id) {
            $id = (int) $id;
            if (isset($porId[$id])) {
                $relevantes[] = [
                    'id' => $id,
                    'hilo_id' => (int) ($porId[$id]['hilo_id'] ?? 0),
                    'asunto' => (string) ($porId[$id]['asunto'] ?? ''),
                    'remitente' => (string) ($porId[$id]['remitente'] ?? ''),
                    'fecha' => (string) ($porId[$id]['fecha'] ?? ''),
                ];
            }
        }

        return ['respuesta' => $respuesta, 'correos' => $relevantes];
    }

    /**
     * @param array<int, array<string, mixed>> $mensajes
     */
    private function aplanarHilo(array $mensajes): string
    {
        $partes = [];
        foreach ($mensajes as $mensaje) {
            $cuerpo = trim((string) ($mensaje['body_text'] ?? ''));
            if ($cuerpo === '') {
                $cuerpo = trim((string) ($mensaje['body_html'] ?? ''));
            }
            $partes[] = sprintf(
                "De: %s | Fecha: %s | Asunto: %s\n%s",
                (string) ($mensaje['remitente_nombre'] ?? $mensaje['remitente'] ?? ''),
                (string) ($mensaje['fecha'] ?? ''),
                (string) ($mensaje['asunto'] ?? ''),
                mb_substr($cuerpo, 0, 4000)
            );
        }

        return mb_substr(implode("\n\n---\n\n", $partes), 0, 20000);
    }

    private function terminosBusqueda(string $pregunta): string
    {
        $palabras = preg_split('/\s+/', mb_strtolower($pregunta)) ?: [];
        $stop = ['que', 'cual', 'cuales', 'como', 'donde', 'cuando', 'quien', 'para', 'por', 'con', 'los', 'las', 'del', 'una', 'unos', 'unas', 'sobre', 'dijo', 'hay', 'tengo', 'tiene', 'esta', 'este', 'mio'];
        $utiles = array_filter($palabras, static fn (string $w): bool => mb_strlen($w) >= 4 && !in_array($w, $stop, true));

        return trim(implode(' ', array_slice(array_values($utiles), 0, 1)));
    }

    private function generarTexto(string $prompt): string
    {
        $response = $this->gemini->generateContent([
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.2],
        ]);

        return $this->extraerTexto($response);
    }

    private function generarJson(string $prompt): array
    {
        $response = $this->gemini->generateContent([
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.2],
        ]);
        $texto = $this->extraerTexto($response);
        $decoded = json_decode($texto, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function extraerTexto(array $response): string
    {
        $parts = $response['candidates'][0]['content']['parts'] ?? [];
        $texto = '';
        foreach ((array) $parts as $part) {
            if (isset($part['text'])) {
                $texto .= (string) $part['text'];
            }
        }

        return $texto;
    }
}
