<?php
// ============================================================================
// api/login_simple.php - VERSÃO FINAL - NUNCA ATUALIZA MAC
// ============================================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

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
$mac_address_raw = trim($_POST['mac_address'] ?? '');

// Normalizar MAC
function normalizarMac($mac_input) {
    if (empty($mac_input)) return false;
    
    $mac_clean = strtoupper(preg_replace('/[^0-9A-F:-]/', '', $mac_input));
    $mac_clean = str_replace('-', ':', $mac_clean);
    
    if (strlen($mac_clean) == 12 && !strpos($mac_clean, ':')) {
        $mac_clean = implode(':', str_split($mac_clean, 2));
    }
    
    if (preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac_clean)) {
        $invalid_macs = ['00:00:00:00:00:00', 'FF:FF:FF:FF:FF:FF'];
        if (!in_array($mac_clean, $invalid_macs)) {
            return $mac_clean;
        }
    }
    
    return false;
}

$mac_address = normalizarMac($mac_address_raw);

error_log("LOGIN: $login, MAC: $mac_address");

// Validações básicas
if (empty($login) || empty($senha) || !$mac_address) {
    echo json_encode([
        'success' => false, 
        'message' => 'Dados inválidos',
        'error_code' => 'VALIDATION_ERROR'
    ]);
    exit;
}

try {
    // Conectar ao banco
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Criar tabelas se necessário
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        mac_address VARCHAR(17) NOT NULL,
        session_token VARCHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_usuario_ativo (usuario_id)
    )");
    
    // Verificar se coluna is_client existe
    $stmt = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'is_client'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN is_client TINYINT(1) DEFAULT 0");
    }
    
    // 1. Buscar usuário
    $stmt = $pdo->prepare("SELECT id, login, senha, ativo, COALESCE(is_client, 0) as is_client FROM usuarios WHERE login = ?");
    $stmt->execute([$login]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario || $usuario['senha'] !== $senha) {
        echo json_encode(['success' => false, 'message' => 'Credenciais inválidas']);
        exit;
    }
    
    if ($usuario['ativo'] != 1) {
        echo json_encode(['success' => false, 'message' => 'Conta suspensa']);
        exit;
    }
    
    if ($usuario['is_client'] != 1) {
        echo json_encode([
            'success' => false, 
            'message' => 'Acesso negado. Conta não autorizada.',
            'is_client' => false
        ]);
        exit;
    }
    
    // 2. VERIFICAÇÃO DE SEGURANÇA CRÍTICA
    error_log("🔒 VERIFICANDO SEGURANÇA MAC para usuário: {$usuario['id']}");
    
    // Verificar se já existe sessão ativa
    $stmt = $pdo->prepare("SELECT mac_address, session_token FROM user_sessions WHERE usuario_id = ? AND expires_at > NOW()");
    $stmt->execute([$usuario['id']]);
    $sessao_existente = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($sessao_existente) {
        $mac_registrado = $sessao_existente['mac_address'];
        
        error_log("SESSÃO EXISTE - MAC Registrado: '$mac_registrado', MAC Tentativa: '$mac_address'");
        
        // REGRA ABSOLUTA: Se MAC for diferente = BLOQUEAR
        if ($mac_registrado !== $mac_address) {
            error_log("🚨 BLOQUEIO: MAC diferente!");
            error_log("   Registrado: $mac_registrado");
            error_log("   Tentativa: $mac_address");
            
            // Registrar violação
            $stmt = $pdo->prepare("INSERT INTO access_logs (usuario_id, mac_address, ip_address, user_agent, login_successful, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
            $stmt->execute([
                $usuario['id'], 
                $mac_address, 
                $_SERVER['REMOTE_ADDR'] ?? '', 
                'VIOLACAO_MAC: ' . ($_SERVER['HTTP_USER_AGENT'] ?? '')
            ]);
            
            $mac_masked = substr($mac_registrado, 0, 8) . "***" . substr($mac_registrado, -5);
            
            echo json_encode([
                'success' => false, 
                'message' => "ACESSO BLOQUEADO: Conta vinculada a outro computador ($mac_masked)",
                'error_code' => 'MAC_SECURITY_BLOCK',
                'registered_mac_partial' => $mac_masked
            ]);
            exit;
        }
        
        // MAC é o mesmo - APENAS renovar token (SEM TOCAR NO MAC)
        error_log("✅ MAC CORRETO - Renovando apenas o token");
        
        $new_token = bin2hex(random_bytes(32));
        $new_expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        // ATUALIZAR APENAS TOKEN E EXPIRAÇÃO (MAC PERMANECE INALTERADO)
        $stmt = $pdo->prepare("UPDATE user_sessions SET session_token = ?, expires_at = ?, updated_at = NOW() WHERE usuario_id = ?");
        $stmt->execute([$new_token, $new_expires, $usuario['id']]);
        
        error_log("✅ Token renovado, MAC mantido inalterado");
        
        $session_token = $new_token;
        $expires_at = $new_expires;
        
    } else {
        // PRIMEIRA SESSÃO - Criar nova
        error_log("📝 PRIMEIRA SESSÃO - Criando nova com MAC: $mac_address");
        
        // Limpar sessões expiradas primeiro
        $pdo->prepare("DELETE FROM user_sessions WHERE usuario_id = ? AND expires_at < NOW()")->execute([$usuario['id']]);
        
        $session_token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        // INSERIR NOVA SESSÃO
        $stmt = $pdo->prepare("INSERT INTO user_sessions (usuario_id, mac_address, session_token, expires_at) VALUES (?, ?, ?, ?)");
        $stmt->execute([$usuario['id'], $mac_address, $session_token, $expires_at]);
        
        error_log("✅ Nova sessão criada com MAC: $mac_address");
    }
    
    // VERIFICAÇÃO FINAL: Confirmar que MAC não mudou
    $stmt = $pdo->prepare("SELECT mac_address FROM user_sessions WHERE usuario_id = ? AND session_token = ?");
    $stmt->execute([$usuario['id'], $session_token]);
    $mac_final = $stmt->fetchColumn();
    
    if ($mac_final !== $mac_address) {
        error_log("🚨 ERRO CRÍTICO: MAC foi alterado inadvertidamente!");
        echo json_encode(['success' => false, 'message' => 'Erro de integridade']);
        exit;
    }
    
    // Log de sucesso
    $stmt = $pdo->prepare("INSERT INTO access_logs (usuario_id, mac_address, ip_address, user_agent, login_successful, created_at) VALUES (?, ?, ?, ?, 1, NOW())");
    $stmt->execute([$usuario['id'], $mac_address, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);
    
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
    
    error_log("✅ LOGIN SEGURO CONCLUÍDO - MAC: $mac_address (INALTERADO)");
    
} catch (Exception $e) {
    error_log("ERRO: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno', 'error_code' => 'SERVER_ERROR']);
}
?>