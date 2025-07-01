<?php
// ============================================================================
// api/login_secure.php - API Segura com Validação de Cliente
// ============================================================================

// Headers básicos
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: *');

// CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

// Configurações do banco
$db_host = 'localhost';
$db_name = 'minec761_comprejogos';
$db_user = 'minec761_comprejogos';
$db_pass = 'pr9n0xz5zxk2';

// Obter dados
$login = $_POST['login'] ?? '';
$senha = $_POST['senha'] ?? '';
$mac_address = $_POST['mac_address'] ?? '';
$version = $_POST['version'] ?? '';

// Log apenas para desenvolvimento (removido em produção)
if (defined('DEBUG_MODE') && DEBUG_MODE) {
    error_log("Login attempt: $login, MAC: $mac_address, Version: $version");
}

// Validações básicas
if (empty($login) || empty($senha) || empty($mac_address)) {
    echo json_encode([
        'success' => false, 
        'message' => 'Dados de acesso inválidos'
    ]);
    exit;
}

// Validar formato MAC
if (!preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $mac_address)) {
    echo json_encode([
        'success' => false, 
        'message' => 'Identificação do computador inválida'
    ]);
    exit;
}

try {
    // Conectar ao banco
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Buscar usuário com informação de cliente
    $stmt = $pdo->prepare("SELECT id, login, senha, ativo, is_client FROM usuarios WHERE login = ?");
    $stmt->execute([$mac_address, $usuario['id']]);
    $mac_em_uso = $stmt->fetch();
    
    if ($mac_em_uso) {
        echo json_encode([
            'success' => false, 
            'message' => 'Este computador já está vinculado a outra conta'
        ]);
        exit;
    }
    
    // Verificar se usuário já tem outro MAC (limite 1 por usuário)
    $stmt = $pdo->prepare("SELECT mac_address FROM user_sessions WHERE usuario_id = ? AND expires_at > NOW()");
    $stmt->execute([$usuario['id']]);
    $mac_registrado = $stmt->fetchColumn();
    
    if ($mac_registrado && $mac_registrado !== $mac_address) {
        echo json_encode([
            'success' => false, 
            'message' => 'Sua conta já está vinculada a outro computador'
        ]);
        exit;
    }
    
    // Gerar token de sessão
    $session_token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
    
    // Salvar sessão
    $stmt = $pdo->prepare("
        INSERT INTO user_sessions (usuario_id, mac_address, session_token, expires_at, created_at, updated_at) 
        VALUES (?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE 
        session_token = VALUES(session_token), 
        expires_at = VALUES(expires_at),
        updated_at = NOW()
    ");
    $stmt->execute([$usuario['id'], $mac_address, $session_token, $expires_at]);
    
    // Log de acesso bem-sucedido
    $stmt = $pdo->prepare("INSERT INTO access_logs (usuario_id, mac_address, ip_address, user_agent, login_successful, created_at) VALUES (?, ?, ?, ?, 1, NOW())");
    $stmt->execute([
        $usuario['id'], 
        $mac_address, 
        $_SERVER['REMOTE_ADDR'] ?? '', 
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
    // Resposta de sucesso
    echo json_encode([
        'success' => true,
        'message' => 'Acesso autorizado',
        'user_id' => $usuario['id'],
        'login' => $usuario['login'],
        'session_token' => $session_token,
        'expires_at' => $expires_at,
        'is_client' => true
    ]);
    
} catch (Exception $e) {
    // Log apenas em desenvolvimento
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        error_log("Erro no login: " . $e->getMessage());
    }
    
    echo json_encode([
        'success' => false, 
        'message' => 'Erro interno do servidor'
    ]);
}
?>login]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario) {
        echo json_encode(['success' => false, 'message' => 'Credenciais inválidas']);
        exit;
    }
    
    // Verificar senha
    if ($usuario['senha'] !== $senha) {
        echo json_encode(['success' => false, 'message' => 'Credenciais inválidas']);
        exit;
    }
    
    // Verificar se usuário está ativo
    if ($usuario['ativo'] != 1) {
        echo json_encode([
            'success' => false, 
            'message' => 'Conta suspensa. Entre em contato com o suporte.'
        ]);
        exit;
    }
    
    // NOVA VALIDAÇÃO: Verificar se é cliente autorizado
    if ($usuario['is_client'] != 1) {
        echo json_encode([
            'success' => false, 
            'message' => 'Acesso negado. Sua conta não possui autorização.',
            'is_client' => false
        ]);
        
        // Log da tentativa de acesso não autorizado
        $stmt = $pdo->prepare("INSERT INTO access_logs (usuario_id, mac_address, ip_address, user_agent, login_successful, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
        $stmt->execute([
            $usuario['id'], 
            $mac_address, 
            $_SERVER['REMOTE_ADDR'] ?? '', 
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
        exit;
    }
    
    // Verificar MAC único (1 por usuário)
    $stmt = $pdo->prepare("
        SELECT u.login 
        FROM user_sessions us 
        JOIN usuarios u ON us.usuario_id = u.id 
        WHERE us.mac_address = ? AND us.usuario_id != ? AND us.expires_at > NOW()
    ");
    $stmt->execute([$