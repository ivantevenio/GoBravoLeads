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
$endpoint = "https://opportunitex.resuelve.io/api/records";

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

// 4. Salida de respuesta segura (Lo que leerá MDirector) y envío a Zapier

if ($codigo_http == 200) {
    error_log("✅ ÉXITO: Lead de [$email] enviado."); 
    
   // Extraemos los datos de la respuesta de Opportunitex
    $respuesta_json = json_decode($respuesta_final, true);
    $lead_id = $respuesta_json['record']['id'] ?? 'ID_NO_ENCONTRADO';
    
    // EXTRAEMOS LA FECHA OFICIAL DEL CRM 
    $fecha_creacion = $respuesta_json['record']['data']['date_created'] ?? date("Y-m-d H:i:s");
    
    // --- NUEVO: ENVIAR EL ID A ZAPIER ---
    $url_zapier = "https://hooks.zapier.com/hooks/catch/4797659/ujfu1no/"; // Pega aquí tu webhook de Zapier
    
// Preparamos TODOS los datos para Zapier
    $datos_zapier = json_encode([
        "lead_id"          => $lead_id,
        "fecha_registro"   => $fecha_creacion,
        "status"           => "Registrado en Opportunitex",
        "nombre"           => $nombre,
        "apellido_paterno" => $apellido_paterno,
        "apellido_materno" => $apellido_materno,
        "email"            => $email,
        "celular"          => $celular,
        "documento_tipo"   => $documento_tipo,
        "documento_numero" => $documento_numero,
        "monto"            => $monto
    ]);

    // Hacemos un cURL rápido hacia Zapier
    $ch_zapier = curl_init($url_zapier);
    curl_setopt($ch_zapier, CURLOPT_POST, true);
    curl_setopt($ch_zapier, CURLOPT_POSTFIELDS, $datos_zapier);
    curl_setopt($ch_zapier, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch_zapier, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_zapier, CURLOPT_TIMEOUT, 3); // Le damos max 3 segundos para no alentar el proceso
    curl_exec($ch_zapier);
    curl_close($ch_zapier);
    // ------------------------------------

    // Imprimimos la respuesta para MDirector
    echo "HTTP 200 (Éxito) - Lead ID: " . $lead_id;

} else if ($codigo_http == 400) {
    error_log("❌ RECHAZADO: Lead de [$email]. Respuesta: " . $respuesta_final);
    echo "HTTP 400 (Rechazado) - Error de validación de datos.";

} else {
    error_log("🚨 ERROR CRÍTICO: HTTP $codigo_http. Respuesta: " . $respuesta_final);
    echo "HTTP $codigo_http (Error) - Fallo de conexión.";
}
?>
