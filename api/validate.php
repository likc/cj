<?php
// ============================================================================
// api/validate.php - API de Validação com Verificação Rigorosa
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

// Log da requisição
error_log("=== VALIDATE API CHAMADA ===");
error_log("Method: " . $_SERVER['REQUEST_METHOD']);
error_log("IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'N/A'));

// Apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método não permitido', 'is_client' => false]);
    exit;
}

// Configurações do banco
$db_host = 'localhost';
$db_name = 'minec761_comprejogos';
$db_user = 'minec761_comprejogos';
$db_pass = 'pr9n0xz5zxk2';

// Obter dados
$session_token = trim($_POST['session_token'] ?? '');
$user_id = (int)($_POST['user_id'] ?? 0);
$mac_address = strtoupper(trim($_POST['mac_address'] ?? ''));
$version = trim($_POST['version'] ?? '');

error_log("Validate - User: $user_id, MAC: $mac_address, Token: " . substr($session_token, 0, 8) . "...");

// Validações básicas
if (empty($session_token) || empty($user_id) || empty($mac_address)) {
    error_log("Erro: Parâmetros obrigatórios não informados");
    echo json_encode([
        'success' => false, 
        'message' => 'Parâmetros obrigatórios não informados',
        'is_client' => false
    ]);
    exit;
}

// Validar formato MAC
if (!preg_match('/^([0-9A-F]{2}[:-]){5}([0-9A-F]{2})$/', $mac_address)) {
    error_log("Erro: Formato de MAC inválido: $mac_address");
    echo json_encode([
        'success' => false, 
        'message' => 'Identificação do computador inválida',
        'is_client' => false
    ]);
    exit;
}

try {
    // Conectar ao banco
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 1. Verificar sessão válida e dados do usuário
    $stmt = $pdo->prepare("
        SELECT 
            u.id, u.login, u.ativo, COALESCE(u.is_client, 0) as is_client, 
            us.mac_address, us.expires_at, us.created_at as session_created
        FROM user_sessions us
        JOIN usuarios u ON us.usuario_id = u.id
        WHERE us.session_token = ? AND us.usuario_id = ?
    ");
    
    $stmt->execute([$session_token, $user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        error_log("Sessão não encontrada - User: $user_id, Token: " . substr($session_token, 0, 8));
        echo json_encode([
            'success' => false,
            'message' => 'Sessão inválida',
            'is_client' => false
        ]);
        exit;
    }
    
    // 2. VALIDAÇÃO CRÍTICA: Verificar se MAC da sessão confere com o enviado
    if ($result['mac_address'] !== $mac_address) {
        error_log("MAC não confere - Sessão: {$result['mac_address']}, Enviado: $mac_address, User: {$result['login']}");
        echo json_encode([
            'success' => false,
            'message' => 'Identificação do computador não confere',
            'is_client' => false
        ]);
        exit;
    }
    
    // 3. Verificar se sessão não expirou
    if (strtotime($result['expires_at']) < time()) {
        error_log("Sessão expirada - User: {$result['login']}, Expirou em: {$result['expires_at']}");
        
        // Limpar sessão expirada
        try {
            $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE session_token = ?");
            $stmt->execute([$session_token]);
        } catch (Exception $e) {
            error_log("Erro ao limpar sessão expirada: " . $e->getMessage());
        }
        
        echo json_encode([
            'success' => false,
            'message' => 'Sessão expirada',
            'is_client' => false
        ]);
        exit;
    }
    
    // 4. Verificar se usuário ainda está ativo
    if ($result['ativo'] != 1) {
        error_log("Usuário foi desativado: {$result['login']}");
        echo json_encode([
            'success' => false,
            'message' => 'Usuário foi desativado pelo administrador',
            'is_client' => false
        ]);
        exit;
    }
    
    // 5. VALIDAÇÃO CRÍTICA: Verificar se ainda é cliente autorizado
    if ($result['is_client'] != 1) {
        error_log("Status de cliente removido: {$result['login']}");
        echo json_encode([
            'success' => false,
            'message' => 'Status de cliente foi removido pelo administrador',
            'is_client' => false
        ]);
        exit;
    }
    
    // 6. VALIDAÇÃO ADICIONAL: Verificar se MAC não foi vinculado a outro usuário
    $stmt = $pdo->prepare("
        SELECT u.login 
        FROM user_sessions us 
        JOIN usuarios u ON us.usuario_id = u.id 
        WHERE us.mac_address = ? AND us.usuario_id != ? AND us.expires_at > NOW()
    ");
    $stmt->execute([$mac_address, $user_id]);
    $conflito = $stmt->fetch();
    
    if ($conflito) {
        error_log("MAC foi vinculado a outro usuário - MAC: $mac_address, Outro usuário: {$conflito['login']}, Atual: {$result['login']}");
        echo json_encode([
            'success' => false,
            'message' => 'Este computador foi vinculado a outra conta',
            'is_client' => false
        ]);
        exit;
    }
    
    // 7. VALIDAÇÃO ADICIONAL: Verificar se usuário não tem outro MAC ativo
    $stmt = $pdo->prepare("
        SELECT mac_address 
        FROM user_sessions 
        WHERE usuario_id = ? AND expires_at > NOW()
    ");
    $stmt->execute([$user_id]);
    $all_macs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Verificar se há mais de um MAC ou se o MAC atual não está na lista
    if (count($all_macs) > 1) {
        error_log("VIOLAÇÃO: Usuário {$result['login']} tem múltiplos MACs ativos: " . implode(', ', $all_macs));
        echo json_encode([
            'success' => false,
            'message' => 'Múltiplos computadores detectados. Apenas 1 computador por conta é permitido.',
            'is_client' => false,
            'error_code' => 'MULTIPLE_MACS'
        ]);
        exit;
    }
    
    if (count($all_macs) === 1 && $all_macs[0] !== $mac_address) {
        $registered_mac = $all_macs[0];
        $mac_masked = substr($registered_mac, 0, 8) . "***" . substr($registered_mac, -5);
        
        error_log("VIOLAÇÃO: Usuário {$result['login']} tentando usar MAC diferente - Registrado: $registered_mac, Tentativa: $mac_address");
        echo json_encode([
            'success' => false,
            'message' => "Sua conta está vinculada a outro computador ($mac_masked)",
            'is_client' => false,
            'error_code' => 'WRONG_MAC',
            'registered_mac_partial' => $mac_masked
        ]);
        exit;
    }
    
    // 8. Atualizar timestamp da sessão (opcional)
    try {
        $stmt = $pdo->prepare("UPDATE user_sessions SET updated_at = NOW() WHERE session_token = ?");
        $stmt->execute([$session_token]);
    } catch (Exception $e) {
        error_log("Erro ao atualizar timestamp da sessão: " . $e->getMessage());
    }
    
    // 9. Tudo OK - cliente ainda é válido
    $response = [
        'success' => true,
        'message' => 'Cliente válido e autorizado',
        'is_client' => true,
        'login' => $result['login'],
        'expires_at' => $result['expires_at'],
        'session_age' => time() - strtotime($result['session_created'])
    ];
    
    error_log("Validação bem-sucedida para cliente: {$result['login']}");
    echo json_encode($response);
    
} catch (PDOException $e) {
    error_log("Erro de banco na validação: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Erro de banco de dados',
        'is_client' => false,
        'error_code' => 'DB_ERROR'
    ]);
} catch (Exception $e) {
    error_log("Erro geral na validação: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Erro interno do servidor',
        'is_client' => false,
        'error_code' => 'INTERNAL_ERROR'
    ]);
}
?>