<?php
/**
 * ============================================================================
 * TECNIEMPACK S.A.S. - BACKEND PROXY & PROCESADOR DE LEADS B2B
 * ============================================================================
 * Ubicación en cPanel: public_html/api/api-procesar-lead.php
 * Propósito: Recibir solicitudes del portal web v2.0, transmitirlas a n8n
 *            (Twenty CRM) y respaldarlas localmente ante caídas de red.
 * Versión: 2.0.0 (Producción)
 * ============================================================================
 */

// 1. Configuración de Cabeceras HTTP & CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Accept, X-Tecniempack-Source");
header("Content-Type: application/json; charset=UTF-8");

// Manejo de solicitudes PREFLIGHT (OPTIONS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 2. Restricción de Método HTTP
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "status" => "error",
        "mensaje" => "Método no permitido. Utilice únicamente solicitudes POST."
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// 3. Parámetros de Configuración del Servidor
define('N8N_WEBHOOK_URL', 'https://n8n.tecniempack.com/webhook/lead-b2b-v2');
define('EMAIL_NOTIFICACION_BACKUP', 'contacto@tecniempack.com');
define('LOG_DIR', __DIR__ . '/logs/');
define('LOG_FILE', LOG_DIR . 'leads_backup_' . date('Y-m') . '.json');

// 4. Captura y Lectura del Payload (JSON de entrada)
$inputRaw = file_get_contents("php://input");
$data = json_decode($inputRaw, true);

// Fallback en caso de que la solicitud provenga de un formulario HTML tradicional ($_POST)
if (!$data && !empty($_POST)) {
    $data = [
        "origen" => "Portal Web Corporativo Tecniempack v2.0 (Form POST)",
        "fecha_registro" => date('c'),
        "prospecto" => [
            "nombre_completo" => $_POST['nombre'] ?? '',
            "empresa_razon_social" => $_POST['empresa'] ?? '',
            "email_corporativo" => $_POST['email'] ?? '',
            "telefono_whatsapp" => $_POST['telefono'] ?? ''
        ],
        "requerimiento_tecnico" => [
            "linea_producto" => $_POST['tipo_empaque'] ?? '',
            "especificaciones_detalle" => $_POST['mensaje'] ?? '',
            "avatar_sugerido" => $_POST['avatar'] ?? 'Sofia'
        ]
    ];
}

// 5. Sanitización y Validación de Campos Obligatorios
$prospecto = $data['prospecto'] ?? [];
$nombre = trim(filter_var($prospecto['nombre_completo'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS));
$empresa = trim(filter_var($prospecto['empresa_razon_social'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS));
$email = trim(filter_var($prospecto['email_corporativo'] ?? '', FILTER_VALIDATE_EMAIL));
$telefono = trim(filter_var($prospecto['telefono_whatsapp'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS));

if (empty($nombre) || empty($empresa) || empty($email) || empty($telefono)) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "mensaje" => "Datos de prospecto incompletos o no válidos. Verifique Nombre, Empresa, Email y Teléfono."
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// 6. Respaldo Local Inmediato en Servidor cPanel
guardarRespaldoLocal($data);

// 7. Retransmisión cURL hacia el Webhook de n8n
$curlResult = enviarAN8N($data);

// 8. Respuesta Estructurada al Cliente Frontend
if ($curlResult['success']) {
    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "mensaje" => "Lead procesado y retransmitido exitosamente a Twenty CRM.",
        "avatar_asignado" => $data['requerimiento_tecnico']['avatar_sugerido'] ?? 'Sofia'
    ], JSON_UNESCAPED_UNICODE);
} else {
    // Si cURL falla hacia n8n, se dispara correo de emergencia
    enviarCorreoEmergencia($data, $curlResult['error']);

    // Se responde éxito al frontend para no frenar la experiencia del usuario
    http_response_code(200);
    echo json_encode([
        "status" => "success_fallback",
        "mensaje" => "Lead guardado en servidor de contingencia. Notificación enviada al equipo comercial.",
        "avatar_asignado" => $data['requerimiento_tecnico']['avatar_sugerido'] ?? 'Sofia'
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Retransmite el JSON recibido hacia la URL de n8n mediante cURL
 */
function enviarAN8N($payload) {
    $ch = curl_init(N8N_WEBHOOK_URL);
    $payloadJson = json_encode($payload);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payloadJson,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Content-Length: ' . strlen($payloadJson),
            'X-Tecniempack-Source: Server-Proxy-PHP'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6, // Timeout acotado de 6 segundos
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'response' => $response];
    } else {
        return ['success' => false, 'error' => "HTTP Code: {$httpCode}. Error: {$curlError}"];
    }
}

/**
 * Guarda una copia del lead en un archivo JSON en cPanel
 */
function guardarRespaldoLocal($payload) {
    try {
        if (!is_dir(LOG_DIR)) {
            mkdir(LOG_DIR, 0755, true);
        }
        
        // Bloquear acceso público a la carpeta de logs mediante .htaccess
        $htaccessPath = LOG_DIR . '.htaccess';
        if (!file_exists($htaccessPath)) {
            file_put_contents($htaccessPath, "Deny from all\n");
        }

        $logEntry = json_encode([
            'timestamp' => date('Y-m-d H:i:s'),
            'ip_cliente' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Desconocido',
            'data' => $payload
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . ",\n";

        file_put_contents(LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);
    } catch (Exception $e) {
        error_log("Error escribiendo respaldo local en Tecniempack: " . $e->getMessage());
    }
}

/**
 * Correo de respaldo si falla el webhook principal de n8n
 */
function enviarCorreoEmergencia($payload, $errorDetail) {
    $to = EMAIL_NOTIFICACION_BACKUP;
    $subject = "🚨 [CONTINGENCIA LEAD WEB] " . ($payload['prospecto']['empresa_razon_social'] ?? 'Nueva Solicitud');
    
    $message = "====================================================\n";
    $message .= "ALERTA DE CONTINGENCIA - SERVIDOR TECNIEMPACK S.A.S.\n";
    $message .= "====================================================\n\n";
    $message .= "No se pudo transmitir el lead a n8n (Twenty CRM).\n";
    $message .= "Detalle del error: {$errorDetail}\n\n";
    $message .= "DATOS DEL PROSPECTO:\n";
    $message .= "• Empresa: " . ($payload['prospecto']['empresa_razon_social'] ?? 'N/A') . "\n";
    $message .= "• Contacto: " . ($payload['prospecto']['nombre_completo'] ?? 'N/A') . "\n";
    $message .= "• Email: " . ($payload['prospecto']['email_corporativo'] ?? 'N/A') . "\n";
    $message .= "• Teléfono: " . ($payload['prospecto']['telefono_whatsapp'] ?? 'N/A') . "\n";
    $message .= "• Línea de Producto: " . ($payload['requerimiento_tecnico']['linea_producto'] ?? 'N/A') . "\n";
    $message .= "• Avatar Asignado: " . ($payload['requerimiento_tecnico']['avatar_sugerido'] ?? 'Sofia') . "\n\n";
    $message .= "ESPECIFICACIONES TÉCNICAS:\n";
    $message .= ($payload['requerimiento_tecnico']['especificaciones_detalle'] ?? 'Sin detalle') . "\n\n";
    $message .= "====================================================\n";
    $message .= "Fecha/Hora Servidor: " . date('Y-m-d H:i:s') . "\n";

    $headers = [
        'From' => 'no-reply@tecniempack.com',
        'Reply-To' => $payload['prospecto']['email_corporativo'] ?? EMAIL_NOTIFICACION_BACKUP,
        'X-Mailer' => 'PHP/' . phpversion()
    ];

    @mail($to, $subject, $message, $headers);
}
?>