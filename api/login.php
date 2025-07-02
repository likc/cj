<?php
// ============================================================================
// api/login_simple.php - API com Validação MAC Rigorosa
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

// Log da requisição para debug
error_log("=== LOGIN API CHAMADA ===");
error_log("Method: " . $_SERVER['REQUEST_METHOD']);
error_log("IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'N/A'));
error_log("User-Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'N/A'));
error_log("POST data: " . json_encode($_POST));

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
$login = trim($_POST['login'] ?? '');
$senha = trim($_POST['senha'] ?? '');
$mac_address = strtoupper(trim($_POST['mac_address'] ?? ''));
$version = trim($_POST['version'] ?? '');

error_log("Dados recebidos - Login: $login, MAC: $mac_address, Version: $version");

// Validações básicas
if (empty($login) || empty($senha) || empty($mac_address)) {
    error_log("Erro: Dados obrigatórios não informados");
    echo json_encode([
        'success' => false, 
        'message' => 'Dados de acesso inválidos',
        'debug' => [
            'login_empty' => empty($login),
            'senha_empty' => empty($senha),
            'mac_empty' => empty($mac_address)
        ]
    ]);
    exit;
}

// Validar formato MAC (mais rigoroso)
if (!preg_match('/^([0-9A-F]{2}[:-]){5}([0-9A-F]{2})$/', $mac_address)) {
    error_log("Erro: Formato de MAC inválido: $mac_address");
    echo json_encode([
        'success' => false, 
        'message' => 'Identificação do computador inválida'
    ]);
    exit;
}

try {
    // Conectar ao banco
    $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    error_log("Conexão com banco estabelecida");
    
    // 1. Verificar se usuário existe e buscar dados
    $stmt = $pdo->prepare("SELECT id, login, senha, email, ativo, COALESCE(is_client, 0) as is_client FROM usuarios WHERE login = ?");
    $stmt->execute([$login]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario) {
        error_log("Usuário não encontrado: $login");
        echo json_encode(['success' => false, 'message' => 'Credenciais inválidas']);
        exit;
    }
    
    // 2. Verificar senha
    if ($usuario['senha'] !== $senha) {
        error_log("Senha incorreta para usuário: $login");
        echo json_encode(['success' => false, 'message' => 'Credenciais inválidas']);
        exit;
    }
    
    // 3. Verificar se usuário está ativo
    if ($usuario['ativo'] != 1) {
        error_log("Usuário inativo: $login");
        echo json_encode(['success' => false, 'message' => 'Conta suspensa. Entre em contato com o suporte.']);
        exit;
    }
    
    // 4. VALIDAÇÃO CRÍTICA: Verificar se é cliente autorizado
    if ($usuario['is_client'] != 1) {
        error_log("Usuário não é cliente autorizado: $login");
        
        // Log da tentativa de acesso não autorizado
        try {
            $stmt = $pdo->prepare("INSERT INTO access_logs (usuario_id, mac_address, ip_address, user_agent, login_successful, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
            $stmt->execute([
                $usuario['id'], 
                $mac_address, 
                $_SERVER['REMOTE_ADDR'] ?? '', 
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
        } catch (Exception $e) {
            error_log("Erro ao salvar log de acesso negado: " . $e->getMessage());
        }
        
        echo json_encode([
            'success' => false, 
            'message' => 'Acesso negado. Sua conta não possui autorização.',
            'is_client' => false
        ]);
        exit;
    }
    
    // 5. VALIDAÇÃO CRÍTICA: Verificar se MAC já está vinculado a OUTRO usuário
    $stmt = $pdo->prepare("
        SELECT u.id, u.login 
        FROM user_sessions us 
        JOIN usuarios u ON us.usuario_id = u.id 
        WHERE us.mac_address = ? AND us.usuario_id != ? AND us.expires_at > NOW()
    ");
    $stmt->execute([$mac_address, $usuario['id']]);
    $mac_em_uso = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($mac_em_uso) {
        error_log("MAC já vinculado a outro usuário - MAC: $mac_address, Usuário atual: {$mac_em_uso['login']}, Tentativa: $login");
        echo json_encode([
            'success' => false, 
            'message' => 'Este computador já está vinculado à conta: ' . $mac_em_uso['login']
        ]);
        exit;
    }
    
    // 6. VALIDAÇÃO CRÍTICA: Verificar se usuário já tem OUTRO MAC vinculado
    $stmt = $pdo->prepare("
        SELECT mac_address 
        FROM user_sessions 
        WHERE usuario_id = ? AND expires_at > NOW()
    ");
    $stmt->execute([$usuario['id']]);
    $mac_registrado = $stmt->fetchColumn();
    
    if ($mac_registrado && $mac_registrado !== $mac_address) {
        error_log("BLOQUEIO: Usuário $login tentando usar MAC diferente - Registrado: $mac_registrado, Tentativa: $mac_address");
        
        // Log da tentativa de violação
        try {
            $stmt = $pdo->prepare("INSERT INTO access_logs (usuario_id, mac_address, ip_address, user_agent, login_successful, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
            $stmt->execute([
                $usuario['id'], 
                $mac_address, 
                $_SERVER['REMOTE_ADDR'] ?? '', 
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
        } catch (Exception $e) {
            error_log("Erro ao salvar log de violação: " . $e->getMessage());
        }
        
        // Mascarar o MAC registrado para segurança
        $mac_masked = substr($mac_registrado, 0, 8) . "***" . substr($mac_registrado, -5);
        
        echo json_encode([
            'success' => false, 
            'message' => "Sua conta já está vinculada a outro computador ($mac_masked). Apenas 1 computador por conta é permitido.",
            'error_code' => 'MAC_ALREADY_LINKED',
            'registered_mac_partial' => $mac_masked
        ]);
        exit;
    }
    
    // 7. Gerar token de sessão seguro
    $session_token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
    
    // 8. Inserir ou atualizar sessão (UPSERT)
    $stmt = $pdo->prepare("
        INSERT INTO user_sessions (usuario_id, mac_address, session_token, expires_at, created_at, updated_at) 
        VALUES (?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE 
        session_token = VALUES(session_token), 
        expires_at = VALUES(expires_at),
        updated_at = NOW()
    ");
    
    $stmt->execute([$usuario['id'], $mac_address, $session_token, $expires_at]);
    error_log("Sessão criada/atualizada para usuário: $login, MAC: $mac_address");
    
    // 9. Registrar log de acesso bem-sucedido
    try {
        $stmt = $pdo->prepare("INSERT INTO access_logs (usuario_id, mac_address, ip_address, user_agent, login_successful, created_at) VALUES (?, ?, ?, ?, 1, NOW())");
        $stmt->execute([
            $usuario['id'], 
            $mac_address, 
            $_SERVER['REMOTE_ADDR'] ?? '', 
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (Exception $e) {
        error_log("Erro ao salvar log de acesso: " . $e->getMessage());
    }
    
    // 10. Resposta de sucesso
    $response = [
        'success' => true,
        'message' => 'Acesso autorizado',
        'user_id' => $usuario['id'],
        'login' => $usuario['login'],
        'session_token' => $session_token,
        'expires_at' => $expires_at,
        'is_client' => true
    ];
    
    error_log("Login bem-sucedido para usuário: $login");
    echo json_encode($response);
    
} catch (PDOException $e) {
    error_log("Erro de banco: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Erro de banco de dados',
        'error_code' => 'DB_ERROR'
    ]);
} catch (Exception $e) {
    error_log("Erro geral: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Erro interno do servidor',
        'error_code' => 'INTERNAL_ERROR'
    ]);
}
?>