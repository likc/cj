<?php
// ============================================================================
// api/login_simple.php - VERSÃO CORRIGIDA - SALVA MAC CORRETAMENTE
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

error_log("API LOGIN: Usuario=$login, MAC=$mac_address");

// Validações básicas
if (empty($login) || empty($senha) || !$mac_address) {
    echo json_encode(['success' => false, 'message' => 'Dados inválidos']);
    exit;
}

try {
    // Conectar ao banco
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Verificar se coluna is_client existe
    $stmt = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'is_client'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN is_client TINYINT(1) DEFAULT 0");
    }
    
    // 1. VALIDAR USUÁRIO
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
    
    error_log("Usuario válido: {$usuario['login']} (ID: {$usuario['id']})");
    
    // 2. VERIFICAR MAC NA DATABASE
    $stmt = $pdo->prepare("SELECT mac_address, session_token, expires_at FROM user_sessions WHERE usuario_id = ?");
    $stmt->execute([$usuario['id']]);
    $sessao_existente = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($sessao_existente) {
        $mac_salvo = $sessao_existente['mac_address'];
        error_log("MAC encontrado na DB: '$mac_salvo'");
        
        // Verificar se MAC é diferente
        if ($mac_salvo !== $mac_address) {
            error_log("BLOQUEIO: MAC diferente - DB='$mac_salvo', Enviado='$mac_address'");
            
            // Log da violação
            $stmt = $pdo->prepare("INSERT INTO access_logs (usuario_id, mac_address, ip_address, user_agent, login_successful, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
            $stmt->execute([
                $usuario['id'], 
                $mac_address, 
                $_SERVER['REMOTE_ADDR'] ?? '', 
                'VIOLACAO_MAC: ' . ($_SERVER['HTTP_USER_AGENT'] ?? '')
            ]);
            
            $mac_masked = substr($mac_salvo, 0, 8) . "***" . substr($mac_salvo, -5);
            
            echo json_encode([
                'success' => false, 
                'message' => "ACESSO BLOQUEADO: Conta vinculada a outro computador ($mac_masked)",
                'error_code' => 'MAC_SECURITY_VIOLATION',
                'registered_mac_partial' => $mac_masked
            ]);
            exit;
        }
        
        // MAC correto - apenas renovar token
        error_log("MAC correto - renovando token");
        $session_token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        $stmt = $pdo->prepare("UPDATE user_sessions SET session_token = ?, expires_at = ?, updated_at = NOW() WHERE usuario_id = ?");
        $stmt->execute([$session_token, $expires_at, $usuario['id']]);
        
        error_log("Token renovado para usuario: {$usuario['login']}");
        
    } else {
        // NÃO TEM SESSÃO - CRIAR NOVA
        error_log("Criando nova sessão para usuario: {$usuario['login']}");
        
        $session_token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        // INSERIR NOVA SESSÃO COM MAC
        $stmt = $pdo->prepare("INSERT INTO user_sessions (usuario_id, mac_address, session_token, expires_at, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
        $result = $stmt->execute([$usuario['id'], $mac_address, $session_token, $expires_at]);
        
        if ($result) {
            error_log("SUCESSO: Nova sessão criada - MAC='$mac_address' salvo na database");
            
            // VERIFICAR SE REALMENTE SALVOU
            $stmt = $pdo->prepare("SELECT mac_address FROM user_sessions WHERE usuario_id = ?");
            $stmt->execute([$usuario['id']]);
            $mac_verificacao = $stmt->fetchColumn();
            
            if ($mac_verificacao === $mac_address) {
                error_log("CONFIRMADO: MAC foi salvo corretamente na database");
            } else {
                error_log("ERRO: MAC não foi salvo corretamente!");
                echo json_encode(['success' => false, 'message' => 'Erro ao salvar sessão']);
                exit;
            }
        } else {
            error_log("ERRO: Falha ao inserir sessão na database");
            echo json_encode(['success' => false, 'message' => 'Erro ao criar sessão']);
            exit;
        }
    }
    
    // 3. LOG DE ACESSO AUTORIZADO
    $stmt = $pdo->prepare("INSERT INTO access_logs (usuario_id, mac_address, ip_address, user_agent, login_successful, created_at) VALUES (?, ?, ?, ?, 1, NOW())");
    $stmt->execute([
        $usuario['id'], 
        $mac_address, 
        $_SERVER['REMOTE_ADDR'] ?? '', 
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
    // 4. RESPOSTA DE SUCESSO
    echo json_encode([
        'success' => true,
        'message' => 'Login realizado com sucesso',
        'user_id' => $usuario['id'],
        'login' => $usuario['login'],
        'session_token' => $session_token,
        'expires_at' => $expires_at,
        'is_client' => true
    ]);
    
    error_log("LOGIN SUCESSO: {$usuario['login']} - MAC $mac_address salvo/verificado");
    
} catch (Exception $e) {
    error_log("ERRO CRÍTICO: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno do servidor']);
}
?>