<?php
// 1. Recibir los datos de MDirector
$nombre           = $_POST['nombre'] ?? '';
$apellido_paterno = $_POST['apellido_paterno'] ?? '';
$apellido_materno = $_POST['apellido_materno'] ?? '';
$email            = $_POST['email'] ?? '';
$celular          = $_POST['celular'] ?? '';
$documento_tipo   = $_POST['documento'] ?? ''; 
$documento_numero = $_POST['cedula'] ?? '';
$monto            = (int)($_POST['monto'] ?? 0); 
$terminos         = isset($_POST['terminos']) && $_POST['terminos'] === 'on' ? true : false;

$telefono_fijo    = $celular; 

// 2. Armar la estructura JSON
$payload = [
    "record" => [
        "data" => [
            "system_id" => 2, 
            "user" => [
                "names" => $nombre,
                "first_surname" => $apellido_paterno,
                "second_surname" => $apellido_materno,
                "email" => $email,
                "phone" => $telefono_fijo,
                "mobile" => $celular,
                "country" => "CO",
                "state" => "Bogota", 
                "postal_code" => "",
                "contact_by" => "EMAIL",
                "contact_by_wa" => true,
                "terms_conditions" => $terminos
            ],
            "debts" => [
                [
                    "borrower_institute" => "Por definir", 
                    "debt_amount" => $monto,
                    "months_behind" => "",
                    "credit_type" => ""
                ]
            ],
            "mkt" => [
                "landing" => "https://tulanding.com", 
                "utm_source" => $_POST['utm_source'] ?? 'mdirector',
                "utm_medium" => "api",
                "utm_term" => "bravo",
                "utm_flow" => "datacredito",
                "utm_assignment" => "datacredito" 
            ],
            "identity_metadata" => [
                "type" => $documento_tipo,
                "number" => $documento_numero
            ]
        ]
    ]
];

$json_payload = json_encode($payload);
$endpoint = "https://opportunitex.sandbox.resuelve.io/api/records";

// 3. Sistema de envío con REINTENTOS
$max_reintentos = 3;
$intento_actual = 1;
$exito = false;
$respuesta_final = "";
$codigo_http = 0;

while ($intento_actual <= $max_reintentos && !$exito) {
    
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
    // Timeout de 10 segundos para no dejar colgado a MDirector
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); 
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($json_payload)
    ]);

    $respuesta = curl_exec($ch);
    $codigo_http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    // Si el código es 200 (Éxito) o 400 (Error de datos), detenemos los reintentos.
    if ($codigo_http == 200 || $codigo_http == 400) {
        $exito = true;
        $respuesta_final = $respuesta;
    } else {
        // Falló por error del servidor de ellos (ej. 500, 502, timeout)
        error_log("⚠️ ADVERTENCIA: Intento $intento_actual fallido para $email. HTTP: $codigo_http. Error cURL: $curl_error");
        $intento_actual++;
        if ($intento_actual <= $max_reintentos) {
            sleep(2); // Espera 2 segundos antes de reintentar
        }
    }
}

// 4. Sistema de LOGS en Vercel
if ($codigo_http == 200) {
    error_log("✅ ÉXITO: Lead de [$email] enviado correctamente a Opportunitex.");
    echo "Lead procesado con éxito.";
} else if ($codigo_http == 400) {
    error_log("❌ RECHAZADO: El CRM rechazó el lead de [$email] por datos inválidos. Respuesta: " . $respuesta_final);
    echo "Lead rechazado por validación.";
} else {
    error_log("🚨 ERROR CRÍTICO: Falló el envío de [$email] después de $max_reintentos intentos. HTTP Final: $codigo_http.");
    echo "Error interno de conexión.";
}
?>
