<?php
// ============================================================================
// config.php - Configurações de Banco de Dados e Sistema
// ============================================================================

// Configurações do MySQL
define('DB_HOST', 'localhost');
define('DB_NAME', 'minec761_comprejogos');
define('DB_USER', 'minec761_comprejogos');
define('DB_PASS', 'pr9n0xz5zxk2');

// Configurações gerais
define('DOWNLOAD_FILE_PATH', __DIR__ . '/files/COMPREJOGOS.zip'); // Caminho para o arquivo ZIP
define('MAX_DOWNLOADS_PER_MAC', 1);
define('SESSION_DURATION_HOURS', 24);

// Configurações de segurança
define('ALLOWED_ORIGINS', [
    'https://likc.net',
    'https://comprejogos.com',
    'http://localhost' // Para testes locais
]);

// Função para conectar ao banco
function conectarBanco() {
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch(PDOException $e) {
        error_log("Erro de conexão: " . $e->getMessage());
        return null;
    }
}

// Função para gerar token de sessão
function gerarToken() {
    return bin2hex(random_bytes(32));
}

// Função para validar MAC address
function validarMacAddress($mac) {
    return preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $mac);
}

// Função para validar origem da requisição
function validarOrigem() {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    
    // Permitir requisições locais (aplicação desktop)
    if (empty($origin) && empty($referer)) {
        return true;
    }
    
    foreach (ALLOWED_ORIGINS as $allowed) {
        if (strpos($origin, $allowed) === 0 || strpos($referer, $allowed) === 0) {
            return true;
        }
    }
    
    return false;
}

// Função para log de erros
function logError($message, $context = []) {
    $log = date('Y-m-d H:i:s') . " - " . $message;
    if (!empty($context)) {
        $log .= " - Context: " . json_encode($context);
    }
    error_log($log);
}

// Headers de segurança padrão
function setSecurityHeaders() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}
?>