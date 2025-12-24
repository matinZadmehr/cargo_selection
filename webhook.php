<?php
/**
 * Webhook Handler for Cargo Information
 * Sends cargo type, weight, and value data to n8n webhook
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set headers for JSON response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Only POST method allowed']);
    exit();
}

// Get raw POST data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Validate input
if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON data']);
    exit();
}

// Log received data (for debugging)
logData('Cargo info received:', $data);

// CONFIGURATION - SET YOUR N8N WEBHOOK URL HERE
$n8n_webhook_url = 'https://aistudio.didbi.com/webhook/form/cargo_selection'; // ← CHANGE THIS!

// Validate webhook URL
if (empty($n8n_webhook_url) || strpos($n8n_webhook_url, 'your-n8n-domain') !== false) {
    $response = [
        'success' => false,
        'error' => 'N8N webhook URL not configured',
        'received_data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    echo json_encode($response);
    exit();
}

// Prepare data for n8n
$payload = prepareN8NPayload($data);

// Send to n8n webhook
$result = sendToN8N($n8n_webhook_url, $payload);

// Return response
if ($result['success']) {
    echo json_encode([
        'success' => true,
        'message' => 'Cargo data sent to n8n successfully',
        'n8n_response' => $result['response'],
        'timestamp' => date('Y-m-d H:i:s'),
        'cargo_id' => uniqid('CARGO_')
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to send cargo data to n8n',
        'n8n_error' => $result['error'],
        'received_data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Prepare payload for n8n
 */
function prepareN8NPayload($data) {
    $payload = [
        'event_type' => 'cargo_information',
        'timestamp' => date('Y-m-d H:i:s'),
        'server_time' => time(),
        'source' => $data['source'] ?? 'telegram_web_app',
        'action' => $data['action'] ?? 'unknown'
    ];
    
    // Add cargo information
    if (isset($data['cargo_info'])) {
        $payload['cargo_details'] = [
            'type' => $data['cargo_info']['type']['name'] ?? 'unknown',
            'type_id' => $data['cargo_info']['type']['id'] ?? 'unknown',
            'type_description' => $data['cargo_info']['type']['description'] ?? '',
            'risk_level' => $data['cargo_info']['type']['risk_level'] ?? 'medium',
            
            'weight_kg' => $data['cargo_info']['weight']['kg'] ?? 0,
            'weight_grams' => $data['cargo_info']['weight']['grams'] ?? 0,
            'weight_display' => $data['cargo_info']['weight']['display'] ?? '0 kg',
            
            'value_amount' => $data['cargo_info']['value']['amount'] ?? 0,
            'value_currency' => $data['cargo_info']['value']['currency'] ?? 'unknown',
            'value_currency_symbol' => $data['cargo_info']['value']['currency_symbol'] ?? '',
            'value_formatted' => $data['cargo_info']['value']['formatted'] ?? '0',
            
            'insurance_required' => $data['cargo_info']['insurance_required'] ?? false,
            'requires_special_handling' => in_array($data['cargo_info']['type']['risk_level'] ?? '', ['high'])
        ];
        
        // Calculate shipping category based on weight and value
        $weight = $data['cargo_info']['weight']['kg'] ?? 0;
        $value = $data['cargo_info']['value']['amount'] ?? 0;
        
        $payload['cargo_details']['shipping_category'] = calculateShippingCategory($weight, $value);
        $payload['cargo_details']['estimated_cost_range'] = estimateShippingCost($weight, $value, $data['cargo_info']['type']['risk_level'] ?? 'medium');
    }
    
    // Add Telegram user info
    if (isset($data['telegram_user'])) {
        $payload['user'] = $data['telegram_user'];
    }
    
    // Add metadata
    $payload['metadata'] = [
        'ip_address' => $data['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $data['metadata']['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'timezone' => $data['metadata']['timezone'] ?? 'unknown',
        'language' => $data['metadata']['language'] ?? 'unknown',
        'screen_resolution' => $data['metadata']['screen_resolution'] ?? 'unknown',
        'server_name' => $_SERVER['SERVER_NAME'] ?? 'unknown',
        'processed_at' => date('Y-m-d H:i:s'),
        'data_validation' => validateCargoData($data['cargo_info'] ?? [])
    ];
    
    return $payload;
}

/**
 * Calculate shipping category
 */
function calculateShippingCategory($weight, $value) {
    if ($weight <= 1 && $value <= 1000000) return 'small_parcel';
    if ($weight <= 5 && $value <= 5000000) return 'medium_parcel';
    if ($weight <= 15 && $value <= 20000000) return 'large_parcel';
    if ($weight <= 30 && $value <= 100000000) return 'extra_large_parcel';
    return 'special_handling';
}

/**
 * Estimate shipping cost
 */
function estimateShippingCost($weight, $value, $risk_level) {
    $baseCost = $weight * 5000; // Base cost per kg
    
    // Add risk premium
    if ($risk_level === 'high') {
        $baseCost *= 1.5;
    } elseif ($risk_level === 'medium') {
        $baseCost *= 1.2;
    }
    
    // Add value premium (0.1% of value for insurance)
    $insurancePremium = $value * 0.001;
    
    $totalMin = $baseCost + $insurancePremium;
    $totalMax = $totalMin * 1.3; // 30% variance
    
    return [
        'min' => round($totalMin),
        'max' => round($totalMax),
        'currency' => 'IRR'
    ];
}

/**
 * Validate cargo data
 */
function validateCargoData($cargoInfo) {
    $validation = [
        'weight_valid' => ($cargoInfo['weight']['kg'] ?? 0) > 0 && ($cargoInfo['weight']['kg'] ?? 0) <= 30,
        'value_valid' => ($cargoInfo['value']['amount'] ?? 0) > 0,
        'type_valid' => !empty($cargoInfo['type']['id'] ?? ''),
        'currency_valid' => in_array($cargoInfo['value']['currency'] ?? '', ['IRR', 'USD', 'EUR']),
        'all_valid' => true
    ];
    
    // Check if all validations pass
    foreach ($validation as $key => $value) {
        if ($key !== 'all_valid' && !$value) {
            $validation['all_valid'] = false;
            break;
        }
    }
    
    return $validation;
}

/**
 * Send data to n8n webhook
 */
function sendToN8N($url, $payload) {
    $ch = curl_init($url);
    
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'User-Agent: Telegram-Cargo-Webhook/1.0'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FAILONERROR => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    // Log the request
    logData('Cargo data sent to n8n:', [
        'url' => $url,
        'payload_size' => strlen(json_encode($payload)),
        'http_code' => $httpCode,
        'response' => $response,
        'error' => $error
    ]);
    
    if ($error) {
        return ['success' => false, 'error' => $error];
    }
    
    // Consider 2xx and 3xx status codes as success
    if ($httpCode >= 200 && $httpCode < 400) {
        return [
            'success' => true,
            'response' => json_decode($response, true) ?: $response,
            'http_code' => $httpCode
        ];
    }
    
    return [
        'success' => false,
        'error' => "HTTP $httpCode",
        'response' => $response
    ];
}

/**
 * Log data for debugging
 */
function logData($message, $data) {
    $logFile = __DIR__ . '/webhook_cargo_log.txt';
    $logEntry = date('Y-m-d H:i:s') . " - $message\n";
    $logEntry .= print_r($data, true) . "\n";
    $logEntry .= str_repeat('-', 80) . "\n";
    
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// Create a simple HTML test page if accessed via browser
if (empty($input) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    ?>
    <!DOCTYPE html>
    <html dir="rtl">
    <head>
        <title>Webhook Test Page - Cargo Information</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 20px; direction: rtl; }
            .container { max-width: 800px; margin: 0 auto; }
            .status { padding: 10px; margin: 10px 0; border-radius: 5px; }
            .success { background: #d4edda; color: #155724; }
            .error { background: #f8d7da; color: #721c24; }
            .test-form { background: #f8f9fa; padding: 20px; border-radius: 10px; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>وب‌هوک اطلاعات محموله - صفحه تست</h1>
            
            <div class="status success">
                <strong>وضعیت:</strong> وب‌هوک در حال اجراست
            </div>
            
            <div class="test-form">
                <h2>تست وب‌هوک</h2>
                <p>از این فرم برای تست دستی وب‌هوک استفاده کنید:</p>
                
                <form id="testForm">
                    <div>
                        <label>نوع محموله:</label>
                        <select id="cargoType">
                            <option value="valuable_metals">فلزات و جواهرات</option>
                            <option value="fragile_items">کالاهای شکننده</option>
                            <option value="snacks_food">مواد غذایی</option>
                            <option value="clothes_accessories">پوشاک</option>
                        </select>
                    </div>
                    
                    <div>
                        <label>وزن (کیلوگرم):</label>
                        <input type="number" id="weight" value="2.5" step="0.1" min="0.1" max="30">
                    </div>
                    
                    <div>
                        <label>ارزش:</label>
                        <input type="number" id="value" value="15000000">
                        <select id="currency">
                            <option value="IRR">ریال</option>
                            <option value="USD">دلار</option>
                            <option value="EUR">یورو</option>
                        </select>
                    </div>
                    
                    <button type="button" onclick="testWebhook()">تست وب‌هوک</button>
                </form>
                
                <div id="testResult"></div>
            </div>
        </div>
        
        <script>
            async function testWebhook() {
                const data = {
                    action: 'cargo_info_submitted',
                    timestamp: new Date().toISOString(),
                    cargo_info: {
                        type: {
                            id: document.getElementById('cargoType').value,
                            name: document.getElementById('cargoType').selectedOptions[0].text,
                            description: 'تست دستی',
                            risk_level: 'medium'
                        },
                        weight: {
                            kg: parseFloat(document.getElementById('weight').value),
                            grams: Math.round(parseFloat(document.getElementById('weight').value) * 1000),
                            display: `${document.getElementById('weight').value} کیلوگرم`
                        },
                        value: {
                            amount: parseFloat(document.getElementById('value').value),
                            currency: document.getElementById('currency').value,
                            currency_symbol: document.getElementById('currency').value === 'IRR' ? 'ریال' : 
                                           document.getElementById('currency').value === 'USD' ? '$' : '€',
                            formatted: `${document.getElementById('value').value} ${document.getElementById('currency').value === 'IRR' ? 'ریال' : 
                                       document.getElementById('currency').value === 'USD' ? '$' : '€'}`
                        },
                        insurance_required: true
                    },
                    telegram_user: {
                        telegram_id: 123456789,
                        telegram_username: 'testuser'
                    },
                    source: 'web_test',
                    ip_address: '127.0.0.1'
                };
                
                try {
                    const response = await fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify(data)
                    });
                    
                    const result = await response.json();
                    document.getElementById('testResult').innerHTML = 
                        '<pre>' + JSON.stringify(result, null, 2) + '</pre>';
                } catch (error) {
                    document.getElementById('testResult').innerHTML = 
                        'خطا: ' + error.message;
                }
            }
        </script>
    </body>
    </html>
    <?php
    exit();
}
?>