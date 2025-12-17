<?php
// Limpa qualquer output anterior
ob_clean();

// Define headers ANTES de qualquer output
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

// Desabilita exibição de erros no output
ini_set('display_errors', 0);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// Configuração da API GhostsPay
define('GHOSTSPAY_API_URL', 'https://api.ghostspaysv2.com/functions/v1/transactions');
define('GHOSTSPAY_AUTH', 'Basic c2tfbGl2ZV9RaHhXZDlMWmU4UUtneDJNOXQ3dWpjNXV6Q1dBTm1GUGZuNjlBYnNnYWRjbDhERUc6YjVlZjYxNTAtNmEzNS00NzQzLWI4MDItOTI0ZDg5Y2I4YzI1');

// Configuração XTracky
define('XTRACKY_API_URL', 'https://api.xtracky.com/api/integrations/api');

// Função para enviar evento para XTracky
function enviarEventoXTracky($orderId, $amount, $status, $utmSource = '') {
    $data = [
        'orderId' => (string)$orderId,
        'amount' => (int)$amount,
        'status' => $status,
        'utm_source' => $utmSource
    ];
    
    $jsonData = json_encode($data);
    
    // Log dos dados sendo enviados
    error_log("=== ENVIANDO PARA XTRACKY ===");
    error_log("URL: " . XTRACKY_API_URL);
    error_log("Dados: " . $jsonData);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => XTRACKY_API_URL,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Content-Length: ' . strlen($jsonData)
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);
    
    // Log da resposta
    error_log("Status HTTP: " . $httpCode);
    error_log("Resposta: " . ($response ?: 'vazia'));
    if ($curlErrno !== 0) {
        error_log("Erro CURL: [{$curlErrno}] {$curlError}");
    }
    error_log("=== FIM XTRACKY ===");
    
    return $httpCode >= 200 && $httpCode < 300;
}

// Se for GET, verifica o status do pagamento
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $transactionId = '';
    
    // Tenta pegar do PATH_INFO
    if (isset($_SERVER['PATH_INFO'])) {
        $transactionId = trim($_SERVER['PATH_INFO'], '/');
    }
    
    // Se não achou, tenta pegar do query string
    if (empty($transactionId) && isset($_GET['transactionId'])) {
        $transactionId = $_GET['transactionId'];
    }
    
    if (empty($transactionId)) {
        http_response_code(200);
        echo json_encode([
            'error' => 'transactionId é obrigatório',
            'success' => false,
            'status' => 'PENDING'
        ], JSON_UNESCAPED_UNICODE);
        exit(0);
    }
    
    // Faz requisição para verificar status na GhostsPay
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => GHOSTSPAY_API_URL . '/' . urlencode($transactionId),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: ' . GHOSTSPAY_AUTH
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if (empty($response) || $httpCode !== 200) {
        http_response_code(200);
        echo json_encode([
            'error' => 'Erro ao verificar status',
            'success' => false,
            'status' => 'PENDING'
        ], JSON_UNESCAPED_UNICODE);
        exit(0);
    }
    
    $data = json_decode($response, true);
    
    // Mapeia status da GhostsPay para o formato esperado
    $statusMap = [
        'waiting_payment' => 'PENDING',
        'paid' => 'APPROVED',
        'refunded' => 'REFUNDED',
        'refused' => 'REJECTED'
    ];
    
    $ghostspayStatus = $data['status'] ?? 'waiting_payment';
    $status = $statusMap[$ghostspayStatus] ?? 'PENDING';
    
    // 🎯 EVENTO 2: Se o status mudou para PAGO, envia evento para XTracky
    if ($ghostspayStatus === 'paid' && isset($data['amount'])) {
        $utmSource = isset($_GET['utm_source']) ? $_GET['utm_source'] : '';
        
        // Tenta pegar do Referer se não vier na URL
        if (empty($utmSource) && isset($_SERVER['HTTP_REFERER'])) {
            parse_str(parse_url($_SERVER['HTTP_REFERER'], PHP_URL_QUERY), $queryParams);
            if (isset($queryParams['utm_source'])) {
                $utmSource = $queryParams['utm_source'];
            }
        }
        
        error_log("🚀 Preparando envio: PIX PAGO");
        error_log("   TransactionID: {$transactionId}");
        error_log("   Amount: {$data['amount']}");
        error_log("   UTM Source: " . ($utmSource ?: 'VAZIO'));
        
        $xTrackyResult = enviarEventoXTracky($transactionId, $data['amount'], 'paid', $utmSource);
        
        if ($xTrackyResult) {
            error_log("✅ XTracky PIX Pago: ENVIADO com sucesso - ID: {$transactionId}");
        } else {
            error_log("❌ XTracky PIX Pago: FALHOU - ID: {$transactionId}");
        }
    }
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'status' => $status,
        'transactionId' => $transactionId,
        'paidAt' => $data['paidAt'] ?? null
    ], JSON_UNESCAPED_UNICODE);
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use POST ou GET.', 'success' => false]);
    exit(0);
}

// Função para gerar QR Code em base64
function gerarQRCodeBase64($pixCode) {
    $size = '300x300';
    $url = 'https://chart.googleapis.com/chart?cht=qr&chs=' . $size . '&chl=' . urlencode($pixCode);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $imageData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && !empty($imageData)) {
        return 'data:image/png;base64,' . base64_encode($imageData);
    }
    
    // Fallback: tenta usar API alternativa
    $url2 = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($pixCode);
    
    $ch2 = curl_init();
    curl_setopt_array($ch2, [
        CURLOPT_URL => $url2,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $imageData2 = curl_exec($ch2);
    $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
    
    if ($httpCode2 === 200 && !empty($imageData2)) {
        return 'data:image/png;base64,' . base64_encode($imageData2);
    }
    
    return '';
}

// Função para validar CPF
function validarCPF($cpf) {
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
    if (strlen($cpf) != 11 || preg_match('/(\d)\1{10}/', $cpf)) return false;
    for ($t = 9; $t < 11; $t++) {
        $d = 0;
        for ($c = 0; $c < $t; $c++) {
            $d += $cpf[$c] * (($t + 1) - $c);
        }
        $d = ((10 * $d) % 11) % 10;
        if ($cpf[$c] != $d) return false;
    }
    return true;
}

// Função para gerar CPF válido
function gerarCPF() {
    $n1 = rand(0, 9);
    $n2 = rand(0, 9);
    $n3 = rand(0, 9);
    $n4 = rand(0, 9);
    $n5 = rand(0, 9);
    $n6 = rand(0, 9);
    $n7 = rand(0, 9);
    $n8 = rand(0, 9);
    $n9 = rand(0, 9);
    
    $d1 = $n9 * 2 + $n8 * 3 + $n7 * 4 + $n6 * 5 + $n5 * 6 + $n4 * 7 + $n3 * 8 + $n2 * 9 + $n1 * 10;
    $d1 = 11 - ($d1 % 11);
    if ($d1 >= 10) $d1 = 0;
    
    $d2 = $d1 * 2 + $n9 * 3 + $n8 * 4 + $n7 * 5 + $n6 * 6 + $n5 * 7 + $n4 * 8 + $n3 * 9 + $n2 * 10 + $n1 * 11;
    $d2 = 11 - ($d2 % 11);
    if ($d2 >= 10) $d2 = 0;
    
    return "$n1$n2$n3$n4$n5$n6$n7$n8$n9$d1$d2";
}

try {
    $rawInput = file_get_contents('php://input');
    
    if (empty($rawInput)) {
        throw new Exception('Nenhum dado recebido');
    }
    
    $data = json_decode($rawInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON inválido');
    }
    
    if (!isset($data['value']) || !isset($data['payerName']) || !isset($data['productName'])) {
        throw new Exception('Campos obrigatórios: value, payerName, productName');
    }
    
    $amountInCents = (int)round($data['value'] * 100);
    
    if ($amountInCents < 100) {
        throw new Exception('Valor mínimo de R$ 1,00');
    }
    
    // Processa CPF
    $document = '';
    if (isset($data['document']) && !empty($data['document'])) {
        $document = preg_replace('/[^0-9]/', '', $data['document']);
        if (!validarCPF($document)) {
            $document = gerarCPF();
        }
    } else {
        $document = gerarCPF();
    }
    
    // Processa email
    $email = '';
    if (isset($data['email']) && !empty($data['email'])) {
        $email = filter_var($data['email'], FILTER_VALIDATE_EMAIL);
        if ($email === false) {
            $email = 'cliente_' . uniqid() . '@bonuseventindottk.site';
        }
    } else {
        $email = 'cliente_' . uniqid() . '@bonuseventindottk.site';
    }
    
    // Processa telefone
    $phone = '';
    if (isset($data['phone'])) {
        $phone = preg_replace('/[^0-9]/', '', $data['phone']);
    }
    
    // Captura utm_source de múltiplas fontes
    $utmSource = '';
    
    // Prioridade 1: Do payload enviado
    if (isset($data['utm_source']) && !empty($data['utm_source'])) {
        $utmSource = $data['utm_source'];
    }
    // Prioridade 2: Do objeto utm
    elseif (isset($data['utm']['source']) && !empty($data['utm']['source'])) {
        $utmSource = $data['utm']['source'];
    }
    // Prioridade 3: Da URL (se disponível via Referer ou Header)
    elseif (isset($_SERVER['HTTP_REFERER'])) {
        parse_str(parse_url($_SERVER['HTTP_REFERER'], PHP_URL_QUERY), $queryParams);
        if (isset($queryParams['utm_source'])) {
            $utmSource = $queryParams['utm_source'];
        }
    }
    
    error_log("📍 UTM Source capturado para evento PIX GERADO: " . ($utmSource ?: 'VAZIO'));
    
    // Monta payload para GhostsPay
    $payload = [
        'customer' => [
            'document' => [
                'number' => $document
            ],
            'name' => $data['payerName'],
            'email' => $email,
            'phone' => $phone
        ],
        'paymentMethod' => 'PIX',
        'items' => [
            [
                'title' => $data['productName'],
                'unitPrice' => $amountInCents,
                'quantity' => 1
            ]
        ],
        'amount' => $amountInCents
    ];
    
    // Faz requisição para API GhostsPay
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => GHOSTSPAY_API_URL,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: ' . GHOSTSPAY_AUTH
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);
    
    if ($curlErrno !== 0) {
        throw new Exception("Erro na conexão: {$curlError}");
    }
    
    if (empty($response)) {
        throw new Exception('Resposta vazia da API');
    }
    
    $apiResponse = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Resposta inválida da API');
    }
    
    if (!is_array($apiResponse)) {
        throw new Exception('Resposta inválida da API');
    }
    
    // Verifica se houve erro
    if ($httpCode !== 200 && $httpCode !== 201) {
        $errorMsg = isset($apiResponse['message']) ? $apiResponse['message'] : 'Erro ao processar pagamento';
        throw new Exception($errorMsg);
    }
    
    // Extrai dados do PIX
    $pixCode = $apiResponse['pix']['qrcode'] ?? '';
    $transactionId = $apiResponse['id'] ?? '';
    $status = $apiResponse['status'] ?? 'waiting_payment';
    
    // Gera o QR Code em base64
    $qrCodeBase64 = gerarQRCodeBase64($pixCode);
    
    // 🎯 EVENTO 1: Envia para XTracky - PIX GERADO (waiting_payment)
    if (!empty($transactionId)) {
        error_log("🚀 Preparando envio: PIX GERADO");
        error_log("   TransactionID: {$transactionId}");
        error_log("   Amount: {$amountInCents}");
        error_log("   UTM Source: " . ($utmSource ?: 'VAZIO'));
        
        $xTrackyResult = enviarEventoXTracky($transactionId, $amountInCents, 'waiting_payment', $utmSource);
        
        if ($xTrackyResult) {
            error_log("✅ XTracky PIX Gerado: ENVIADO com sucesso - ID: {$transactionId}");
        } else {
            error_log("❌ XTracky PIX Gerado: FALHOU - ID: {$transactionId}");
        }
    }
    
    // Mapeia status da GhostsPay para o formato esperado
    $statusMap = [
        'waiting_payment' => 'PENDING',
        'paid' => 'APPROVED',
        'refunded' => 'REFUNDED',
        'refused' => 'REJECTED'
    ];
    
    $mappedStatus = $statusMap[$status] ?? 'PENDING';
    
    // Converte a resposta para o formato esperado
    $response = [
        'success' => true,
        'paymentInfo' => [
            'id' => $transactionId,
            'qrCode' => $pixCode,
            'base64QrCode' => $qrCodeBase64,
            'status' => $mappedStatus,
            'transactionId' => $transactionId
        ],
        'value' => $data['value'],
        'pixCode' => $pixCode,
        'transactionId' => $transactionId,
        'status' => $mappedStatus,
        'expirationDate' => $apiResponse['pix']['expirationDate'] ?? null
    ];
    
    http_response_code(200);
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    http_response_code(200);
    echo json_encode([
        'error' => $e->getMessage(),
        'success' => false
    ], JSON_UNESCAPED_UNICODE);
}

// Força o término do script
exit(0);
?>