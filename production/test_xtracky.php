<?php
/**
 * Arquivo de teste para verificar integração com XTracky
 * Acesse: http://seusite.com/test_xtracky.php
 */

header('Content-Type: text/html; charset=utf-8');

echo "<h1>🧪 Teste de Integração XTracky</h1>";
echo "<hr>";

// Configuração
$XTRACKY_API_URL = 'https://api.xtracky.com/api/integrations/api';

// Dados de teste
$testData = [
    'orderId' => 'TESTE-' . time(),
    'amount' => 10000, // R$ 100,00 em centavos
    'status' => 'waiting_payment',
    'utm_source' => 'teste_manual'
];

echo "<h2>📤 Enviando dados para XTracky...</h2>";
echo "<pre>";
echo "URL: {$XTRACKY_API_URL}\n";
echo "Dados: " . json_encode($testData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
echo "</pre>";

// Faz a requisição
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $XTRACKY_API_URL,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($testData),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_VERBOSE => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json'
    ]
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
$curlErrno = curl_errno($ch);
$curlInfo = curl_getinfo($ch);
curl_close($ch);

echo "<h2>📥 Resposta da XTracky:</h2>";
echo "<pre>";
echo "Status HTTP: {$httpCode}\n";
echo "Tempo de resposta: {$curlInfo['total_time']}s\n";
echo "URL final: {$curlInfo['url']}\n\n";

if ($curlErrno !== 0) {
    echo "<span style='color: red;'>❌ ERRO CURL: [{$curlErrno}] {$curlError}</span>\n";
} else {
    if ($httpCode >= 200 && $httpCode < 300) {
        echo "<span style='color: green;'>✅ SUCESSO!</span>\n";
    } else {
        echo "<span style='color: orange;'>⚠️ Status HTTP inesperado</span>\n";
    }
}

echo "\nResposta completa:\n";
echo $response ?: '(vazia)';
echo "</pre>";

echo "<hr>";
echo "<h2>🔍 Informações do CURL:</h2>";
echo "<pre>";
print_r($curlInfo);
echo "</pre>";

echo "<hr>";
echo "<h3>💡 Dicas de Debug:</h3>";
echo "<ul>";
echo "<li>Verifique se a URL da API XTracky está correta</li>";
echo "<li>Confirme que o servidor permite conexões HTTPS de saída</li>";
echo "<li>Verifique os logs do servidor em /var/log/apache2/error.log ou /var/log/php-error.log</li>";
echo "<li>Se o Status HTTP for 401/403, pode haver problema de autenticação</li>";
echo "<li>Se o Status HTTP for 0, pode haver bloqueio de firewall</li>";
echo "</ul>";
?>