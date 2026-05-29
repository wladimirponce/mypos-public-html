<?php
/**
 * Webhook para WhatsApp Business API con Gemini AI
 * MyPOS - Asistente Virtual
 */

require_once dirname(__DIR__) . '/web/mypos-backend/backend/vendor/autoload.php';
\Mypos\Support\Env::loadFile(dirname(__DIR__) . '/web/mypos-backend/backend/.env');

// Configuración
$verify_token = 'AGENTIKA-MYPOS';
$access_token = 'EAAVtlSjFmaoBQ7ekBa03OgsdDB6ZCYiQnERrvLIeRNeSOZCZBwQ3BkmJMZA76cST22aJRV75ydBbbfjzdh1JUwOHdI8kvmhUZCMYFaZAHakVdZBMVZAMySkcvjZAwJnTYZAIy7hwE1KZCeZBUadJiLoySkw30b3ypo0ZBWdcxm0Bcufx7VzsRp7DLmyjTw3o80oogHwZDZD';
$api_url = 'https://graph.facebook.com/v24.0/958843943971859/messages';
$gemini_api_key = 'AIzaSyC6FvENHDtsxZ_C6e4lWLH35r7riuAFowE'; // TODO: Reemplazar con la API Key real

// Manejo de errores global
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $log_file = __DIR__ . '/webhook_log.txt';
    $msg = "[" . date('Y-m-d H:i:s') . "] PHP ERROR ($errno): $errstr in $errfile on line $errline\n";
    file_put_contents($log_file, $msg, FILE_APPEND);
    return false;
});

set_exception_handler(function($e) {
    $log_file = __DIR__ . '/webhook_log.txt';
    $msg = "[" . date('Y-m-d H:i:s') . "] UNCAUGHT EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n" . $e->getTraceAsString() . "\n";
    file_put_contents($log_file, $msg, FILE_APPEND);
});

// Sanitizar string para log
function sanitizeForLog(string $data): string {
    $data = preg_replace('/[\x00-\x1F\x7F]/', '', $data);
    return substr($data, 0, 200);
}

// Verificación del webhook (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';
    
    if ($mode === 'subscribe' && $token === $verify_token) {
        echo $challenge;
        exit;
    } else {
        http_response_code(403);
        exit;
    }
}

// Procesar mensajes entrantes (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    $log_file = __DIR__ . '/webhook_log.txt';
    file_put_contents($log_file, "\n" . str_repeat("=", 80) . "\n", FILE_APPEND);
    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Webhook recibido\n", FILE_APPEND);
    
    $from = null;
    $userMessage = '';
    $userName = '';
    
    if (isset($data['entry'][0]['changes'][0]['value']['messages'][0])) {
        $message = $data['entry'][0]['changes'][0]['value']['messages'][0];
        $from = $message['from'];
        
        if (isset($message['text']['body'])) {
            $userMessage = $message['text']['body'];
        }
        
        if (isset($data['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['name'])) {
            $userName = $data['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['name'];
        }
    }
    
    // Si hay un mensaje de texto válido
    if ($from && $userMessage) {
        file_put_contents($log_file, "De: " . sanitizeForLog($from) . ($userName ? " (" . sanitizeForLog($userName) . ")" : "") . "\n", FILE_APPEND);
        file_put_contents($log_file, "Mensaje: " . sanitizeForLog($userMessage) . "\n", FILE_APPEND);
        
        // --- INTERCEPTAR VERIFICACION ONBOARDING ---
        if (preg_match('/^Verificar MyPOS (WTS-[A-Z0-9]+)$/i', trim($userMessage), $matches)) {
            $token = $matches[1];
            try {
                $db = \Mypos\Config\Database::connection();
                $stmt = $db->prepare('UPDATE whatsapp_verifications SET telefono = ?, estado = "verificado" WHERE token = ? AND estado = "pendiente"');
                $stmt->execute([$from, $token]);
                
                if ($stmt->rowCount() > 0) {
                    $aiResponse = "¡Gracias! Tu número ha sido verificado con éxito. Puedes continuar tu registro en la pantalla.";
                } else {
                    $aiResponse = "El código de verificación no es válido o ya fue utilizado.";
                }
            } catch (Exception $e) {
                file_put_contents($log_file, "DB Error: " . $e->getMessage() . "\n", FILE_APPEND);
                $aiResponse = "Ocurrió un error al verificar tu número. Por favor intenta de nuevo.";
            }

            // Enviar respuesta por WhatsApp
            $response_data = [
                'messaging_product' => 'whatsapp',
                'to' => $from,
                'type' => 'text',
                'text' => [
                    'body' => $aiResponse
                ]
            ];
            
            $options = [
                'http' => [
                    'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . $access_token . "\r\n",
                    'method' => 'POST',
                    'content' => json_encode($response_data),
                    'timeout' => 15,
                    'ignore_errors' => true
                ]
            ];
            $context = stream_context_create($options);
            file_get_contents($api_url, false, $context);
            
            http_response_code(200);
            echo 'OK';
            exit;
        }
        // --- FIN INTERCEPCION ---

        try {
            // Llamar a Gemini API directamente
            $gemini_endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=' . $gemini_api_key;
            
            $prompt = $userMessage;
            
            $gemini_payload = [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ],
                'systemInstruction' => [
                    'parts' => [
                        ['text' => "Eres el asistente virtual de MyPOS, un avanzado software de Punto de Venta (POS) en la nube. Tu objetivo es ayudar a los clientes y usuarios respondiendo de manera amigable, rápida, concisa y muy profesional en español. Representas a la marca MyPOS. El usuario con el que estás hablando se llama $userName."]
                    ]
                ]
            ];
            
            $gemini_options = [
                'http' => [
                    'header'  => "Content-Type: application/json\r\n",
                    'method'  => 'POST',
                    'content' => json_encode($gemini_payload),
                    'ignore_errors' => true
                ]
            ];
            
            $gemini_context  = stream_context_create($gemini_options);
            $gemini_response_raw = file_get_contents($gemini_endpoint, false, $gemini_context);
            
            $aiResponse = "Lo siento, en este momento no puedo procesar tu solicitud. Por favor, intenta de nuevo más tarde www.mypos.cl";
            
            if ($gemini_response_raw) {
                $gemini_data = json_decode($gemini_response_raw, true);
                if (isset($gemini_data['candidates'][0]['content']['parts'][0]['text'])) {
                    $aiResponse = trim($gemini_data['candidates'][0]['content']['parts'][0]['text']);
                } else {
                    file_put_contents($log_file, "Error Gemini Response: " . sanitizeForLog($gemini_response_raw) . "\n", FILE_APPEND);
                }
            } else {
                file_put_contents($log_file, "Error Gemini API Request Failed\n", FILE_APPEND);
            }
            
            file_put_contents($log_file, "Respuesta AI generada: " . sanitizeForLog(substr($aiResponse, 0, 100)) . "...\n", FILE_APPEND);
            
            // Enviar respuesta por WhatsApp
            $response_data = [
                'messaging_product' => 'whatsapp',
                'to' => $from,
                'type' => 'text',
                'text' => [
                    'body' => $aiResponse
                ]
            ];
            
            $options = [
                'http' => [
                    'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . $access_token . "\r\n",
                    'method' => 'POST',
                    'content' => json_encode($response_data),
                    'timeout' => 15,
                    'ignore_errors' => true
                ]
            ];
            
            $context = stream_context_create($options);
            $result = file_get_contents($api_url, false, $context);
            file_put_contents($log_file, "Respuesta Meta: " . sanitizeForLog($result) . "\n", FILE_APPEND);
            
        } catch (Exception $e) {
            file_put_contents($log_file, "ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
        }
    } else {
        file_put_contents($log_file, "⚠ Webhook ignorado - Sin mensaje de texto válido\n", FILE_APPEND);
    }
    
    http_response_code(200);
    echo 'OK';
}
?>
