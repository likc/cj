<?php
// ============================================================================
// api/download.php - API de Download Corrigida
// ============================================================================

// Desabilitar buffer de saída
if (ob_get_level()) {
    ob_end_clean();
}

// Log da requisição
error_log("Download API accessed - Method: " . $_SERVER['REQUEST_METHOD']);
error_log("GET params: " . json_encode($_GET));
error_log("Headers: " . json_encode(getallheaders()));

// Headers básicos
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, User-Agent, Referer');

// Tratar OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Permitir GET e POST
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Método não permitido: ' . $_SERVER['REQUEST_METHOD']]);
    exit;
}

// Configurações do banco
$db_host = 'localhost';
$db_name = 'minec761_comprejogos';
$db_user = 'minec761_comprejogos';
$db_pass = 'pr9n0xz5zxk2';

// Caminho do arquivo - AJUSTE ESTE CAMINHO!
$download_file_path = __DIR__ . '/../files/COMPREJOGOS.zip';

// Obter parâmetros
$session_token = $_GET['session_token'] ?? $_POST['session_token'] ?? '';
$mac_address = $_GET['mac_address'] ?? $_POST['mac_address'] ?? '';
$user_id = $_GET['user_id'] ?? $_POST['user_id'] ?? '';

error_log("Download params - Token: " . substr($session_token, 0, 8) . "..., MAC: $mac_address, User: $user_id");

// Validar parâmetros
if (empty($session_token) || empty($mac_address) || empty($user_id)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Parâmetros obrigatórios não informados',
        'debug' => [
            'session_token' => !empty($session_token) ? 'presente' : 'ausente',
            'mac_address' => $mac_address,
            'user_id' => $user_id
        ]
    ]);
    exit;
}

try {
    // Conectar ao banco
    $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    error_log("Conexão com banco estabelecida para download");
    
    // Verificar sessão válida
    $stmt = $pdo->prepare("
        SELECT us.*, u.login, u.ativo 
        FROM user_sessions us 
        JOIN usuarios u ON us.usuario_id = u.id 
        WHERE us.session_token = ? AND us.mac_address = ? AND us.usuario_id = ? AND us.expires_at > NOW()
    ");
    
    $stmt->execute([$session_token, $mac_address, $user_id]);
    $sessao = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$sessao) {
        http_response_code(401);
        header('Content-Type: application/json');
        error_log("Sessão inválida para download - Token: " . substr($session_token, 0, 8));
        echo json_encode(['success' => false, 'message' => 'Sessão inválida ou expirada']);
        exit;
    }
    
    error_log("Sessão válida para usuário: " . $sessao['login']);
    
    // Verificar se usuário está ativo
    if ($sessao['ativo'] != 1) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Usuário inativo']);
        exit;
    }
    
    // Verificar se arquivo existe
    if (!file_exists($download_file_path)) {
        http_response_code(404);
        header('Content-Type: application/json');
        error_log("Arquivo não encontrado: $download_file_path");
        echo json_encode([
            'success' => false, 
            'message' => 'Arquivo não encontrado no servidor',
            'debug' => [
                'file_path' => $download_file_path,
                'file_exists' => file_exists($download_file_path)
            ]
        ]);
        exit;
    }
    
    // Verificar se arquivo é legível
    if (!is_readable($download_file_path)) {
        http_response_code(500);
        header('Content-Type: application/json');
        error_log("Arquivo não é legível: $download_file_path");
        echo json_encode(['success' => false, 'message' => 'Erro de permissão no arquivo']);
        exit;
    }
    
    $filesize = filesize($download_file_path);
    error_log("Iniciando download do arquivo: $download_file_path (Size: $filesize bytes)");
    
    // Registrar download no log
    try {
        $stmt = $pdo->prepare("INSERT INTO download_logs (usuario_id, mac_address, ip_address, user_agent, file_size, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $user_id,
            $mac_address,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $filesize
        ]);
    } catch (Exception $e) {
        error_log("Erro ao registrar log de download: " . $e->getMessage());
    }
    
    // Definir nome do arquivo
    $filename = 'COMPREJOGOS.zip';
    
    // Limpar output buffer
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Headers para download
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . $filesize);
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Accept-Ranges: bytes');
    
    // Verificar se é requisição de range (download resumível)
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    
    if (!empty($range) && preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
        // Download resumível
        $start = intval($matches[1]);
        $end = !empty($matches[2]) ? intval($matches[2]) : $filesize - 1;
        
        if ($start >= $filesize || $end >= $filesize || $start > $end) {
            http_response_code(416);
            header("Content-Range: bytes */$filesize");
            exit;
        }
        
        header('HTTP/1.1 206 Partial Content');
        header("Content-Range: bytes $start-$end/$filesize");
        header('Content-Length: ' . ($end - $start + 1));
        
        $file = fopen($download_file_path, 'rb');
        if ($file) {
            fseek($file, $start);
            $remaining = $end - $start + 1;
            
            while ($remaining > 0 && !feof($file) && !connection_aborted()) {
                $chunk_size = min(8192, $remaining);
                echo fread($file, $chunk_size);
                $remaining -= $chunk_size;
                flush();
            }
            fclose($file);
        }
    } else {
        // Download completo
        $file = fopen($download_file_path, 'rb');
        if ($file) {
            while (!feof($file) && !connection_aborted()) {
                echo fread($file, 8192);
                flush();
            }
            fclose($file);
        } else {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Erro ao abrir arquivo']);
            exit;
        }
    }
    
    error_log("Download concluído para usuário: " . $sessao['login']);
    
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    error_log("Erro de banco no download: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro de banco de dados']);
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    error_log("Erro geral no download: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno do servidor']);
}
?>