<?php
// ============================================================================
// api/login_simple.php - VERSÃO FINAL SEM LOGS DE DEBUG
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

if (empty($login) || empty($senha) || !$mac_address) {
    echo json_encode(['success' => false, 'message' => 'Dados inválidos']);
    exit;
}

try {
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'is_client'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN is_client TINYINT(1) DEFAULT 0");
    }
    
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
    
    $stmt = $pdo->prepare("SELECT mac_address, session_token, expires_at FROM user_sessions WHERE usuario_id = ?");
    $stmt->execute([$usuario['id']]);
    $sessao_existente = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($sessao_existente) {
        $mac_salvo = $sessao_existente['mac_address'];
        
        if ($mac_salvo !== $mac_address) {
            $mac_masked = substr($mac_salvo, 0, 8) . "***" . substr($mac_salvo, -5);
            echo json_encode([
                'success' => false, 
                'message' => "ACESSO BLOQUEADO: Conta vinculada a outro computador ($mac_masked)",
                'error_code' => 'MAC_SECURITY_VIOLATION',
                'registered_mac_partial' => $mac_masked
            ]);
            exit;
        }
        
        $session_token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        $stmt = $pdo->prepare("UPDATE user_sessions SET session_token = ?, expires_at = ?, updated_at = NOW() WHERE usuario_id = ?");
        $stmt->execute([$session_token, $expires_at, $usuario['id']]);
        
    } else {
        $session_token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        $stmt = $pdo->prepare("INSERT INTO user_sessions (usuario_id, mac_address, session_token, expires_at, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([$usuario['id'], $mac_address, $session_token, $expires_at]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Login realizado com sucesso',
        'user_id' => $usuario['id'],
        'login' => $usuario['login'],
        'session_token' => $session_token,
        'expires_at' => $expires_at,
        'is_client' => true
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro interno do servidor']);
}
?>