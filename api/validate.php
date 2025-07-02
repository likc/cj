<?php
// ============================================================================
// api/validate.php - API de Validação FINAL com Segurança Máxima
// ============================================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Log de segurança
error_log("=== VALIDATE API CHAMADA ===");
error_log("Method: " . $_SERVER['REQUEST_METHOD']);
error_log("IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'N/A'));
error_log("User-Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'N/A'));

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

error_log("VALIDATE REQUEST: User=$user_id, MAC=$mac_address, Token=" . substr($session_token, 0, 8) . "..., Version=$version");

// Função para resposta de erro
function errorResponse($message, $error_code = '', $additional_data = []) {
    $response = [
        'success' => false,
        'message' => $message,
        'is_client' => false,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    if (!empty($error_code)) {
        $response['error_code'] = $error_code;
    }
    
    if (!empty($additional_data)) {
        $response = array_merge($response, $additional_data);
    }
    
    error_log("VALIDATE ERROR: $message (Code: $error_code)");
    echo json_encode($response);
    exit;
}

// Validações básicas
if (empty($session_token) || empty($user_id) || empty($mac_address)) {
    errorResponse(
        'Parâmetros obrigatórios não informados',
        'MISSING_PARAMETERS',
        [
            'required' => ['session_token', 'user_id', 'mac_address'],
            'provided' => [
                'session_token' => !empty($session_token),
                'user_id' => !empty($user_id),
                'mac_address' => !empty($mac_address)
            ]
        ]
    );
}

// Validar formato MAC
if (!preg_match('/^([0-9A-F]{2}[:-]){5}([0-9A-F]{2})$/', $mac_address)) {
    errorResponse(
        'Formato de MAC address inválido',
        'INVALID_MAC_FORMAT',
        ['provided_mac' => $mac_address]
    );
}

try {
    // Conectar ao banco
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    error_log("VALIDATE: Conexão com banco estabelecida");
    
    // ========================================================================
    // 1. VALIDAÇÃO PRIMÁRIA: Buscar sessão e dados do usuário
    // ========================================================================
    
    $stmt = $pdo->prepare("
        SELECT 
            u.id as user_id,
            u.login, 
            u.email,
            u.ativo, 
            COALESCE(u.is_client, 0) as is_client,
            u.created_at as user_created,
            us.id as session_id,
            us.mac_address as session_mac, 
            us.expires_at, 
            us.created_at as session_created,
            us.updated_at as session_updated
        FROM user_sessions us
        JOIN usuarios u ON us.usuario_id = u.id
        WHERE us.session_token = ? AND us.usuario_id = ?
    ");
    
    $stmt->execute([$session_token, $user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        errorResponse(
            'Sessão não encontrada ou inválida',
            'SESSION_NOT_FOUND',
            ['user_id' => $user_id, 'token_prefix' => substr($session_token, 0, 8)]
        );
    }
    
    error_log("VALIDATE: Sessão encontrada para usuário: {$result['login']} (ID: {$result['user_id']})");
    
    // ========================================================================
    // 2. VALIDAÇÃO CRÍTICA: Verificar MAC da sessão vs MAC enviado
    // ========================================================================
    
    if ($result['session_mac'] !== $mac_address) {
        error_log("🚨 VIOLAÇÃO CRÍTICA DE MAC!");
        error_log("   Usuário: {$result['login']}");
        error_log("   MAC Sessão: {$result['session_mac']}");
        error_log("   MAC Tentativa: $mac_address");
        error_log("   IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'N/A'));
        
        // Registrar violação crítica
        try {
            $stmt = $pdo->prepare("
                INSERT INTO access_logs (usuario_id, mac_address, ip_address, user_agent, login_successful, created_at) 
                VALUES (?, ?, ?, ?, 0, NOW())
            ");
            $stmt->execute([
                $result['user_id'], 
                $mac_address, 
                $_SERVER['REMOTE_ADDR'] ?? '', 
                'VIOLACAO_MAC_CRITICA: ' . ($_SERVER['HTTP_USER_AGENT'] ?? '')
            ]);
        } catch (Exception $e) {
            error_log("Erro ao registrar violação: " . $e->getMessage());
        }
        
        // Máscara do MAC registrado para resposta
        $mac_masked = substr($result['session_mac'], 0, 8) . "***" . substr($result['session_mac'], -5);
        
        errorResponse(
            "VIOLAÇÃO DE SEGURANÇA: MAC não confere com a sessão",
            'MAC_VIOLATION',
            [
                'expected_mac_partial' => $mac_masked,
                'violation_level' => 'CRITICAL'
            ]
        );
    }
    
    error_log("✅ VALIDATE: MAC correto confirmado");
    
    // ========================================================================
    // 3. VALIDAÇÃO TEMPORAL: Verificar se sessão não expirou
    // ========================================================================
    
    $expiry_timestamp = strtotime($result['expires_at']);
    $current_timestamp = time();
    
    if ($expiry_timestamp < $current_timestamp) {
        error_log("VALIDATE: Sessão expirada - User: {$result['login']}, Expirou em: {$result['expires_at']}");
        
        // Limpar sessão expirada automaticamente
        try {
            $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE id = ?");
            $stmt->execute([$result['session_id']]);
            error_log("VALIDATE: Sessão expirada removida automaticamente");
        } catch (Exception $e) {
            error_log("Erro ao limpar sessão expirada: " . $e->getMessage());
        }
        
        $time_expired = $current_timestamp - $expiry_timestamp;
        errorResponse(
            'Sessão expirada',
            'SESSION_EXPIRED',
            [
                'expired_at' => $result['expires_at'],
                'expired_seconds_ago' => $time_expired
            ]
        );
    }
    
    $remaining_seconds = $expiry_timestamp - $current_timestamp;
    error_log("✅ VALIDATE: Sessão válida, expira em " . gmdate('H:i:s', $remaining_seconds));
    
    // ========================================================================
    // 4. VALIDAÇÃO DE STATUS: Verificar se usuário ainda está ativo
    // ========================================================================
    
    if ($result['ativo'] != 1) {
        error_log("VALIDATE: Usuário foi desativado: {$result['login']}");
        errorResponse(
            'Usuário foi desativado pelo administrador',
            'USER_DEACTIVATED'
        );
    }
    
    // ========================================================================
    // 5. VALIDAÇÃO DE CLIENTE: Verificar se ainda é cliente autorizado
    // ========================================================================
    
    if ($result['is_client'] != 1) {
        error_log("VALIDATE: Status de cliente removido: {$result['login']}");
        errorResponse(
            'Status de cliente foi removido pelo administrador',
            'CLIENT_STATUS_REVOKED'
        );
    }
    
    // ========================================================================
    // 6. VALIDAÇÃO DE INTEGRIDADE: Verificar conflitos de MAC
    // ========================================================================
    
    // Verificar se este MAC não foi vinculado a outro usuário
    $stmt = $pdo->prepare("
        SELECT u.login, u.id
        FROM user_sessions us 
        JOIN usuarios u ON us.usuario_id = u.id 
        WHERE us.mac_address = ? AND us.usuario_id != ? AND us.expires_at > NOW()
    ");
    $stmt->execute([$mac_address, $user_id]);
    $conflito = $stmt->fetch();
    
    if ($conflito) {
        error_log("🚨 CONFLITO DE MAC DETECTADO!");
        error_log("   MAC: $mac_address");
        error_log("   Usuário Original: {$result['login']} (ID: $user_id)");
        error_log("   Usuário Conflitante: {$conflito['login']} (ID: {$conflito['id']})");
        
        errorResponse(
            'CONFLITO: Este computador foi vinculado a outra conta',
            'MAC_CONFLICT',
            [
                'conflicting_user' => $conflito['login'],
                'conflict_level' => 'HIGH'
            ]
        );
    }
    
    // Verificar se o usuário não tem múltiplos MACs ativos
    $stmt = $pdo->prepare("
        SELECT mac_address, id
        FROM user_sessions 
        WHERE usuario_id = ? AND expires_at > NOW()
    ");
    $stmt->execute([$user_id]);
    $all_macs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($all_macs) > 1) {
        error_log("🚨 MÚLTIPLOS MACS DETECTADOS para usuário {$result['login']}:");
        foreach ($all_macs as $mac_record) {
            error_log("   MAC: {$mac_record['mac_address']} (Session ID: {$mac_record['id']})");
        }
        
        errorResponse(
            'VIOLAÇÃO: Múltiplos computadores detectados. Apenas 1 computador por conta é permitido.',
            'MULTIPLE_MACS_VIOLATION',
            [
                'mac_count' => count($all_macs),
                'violation_level' => 'CRITICAL'
            ]
        );
    }
    
    if (count($all_macs) === 1 && $all_macs[0]['mac_address'] !== $mac_address) {
        $registered_mac = $all_macs[0]['mac_address'];
        $mac_masked = substr($registered_mac, 0, 8) . "***" . substr($registered_mac, -5);
        
        error_log("🚨 MAC ERRADO para usuário {$result['login']}!");
        error_log("   Registrado: $registered_mac");
        error_log("   Tentativa: $mac_address");
        
        errorResponse(
            "ERRO: Sua conta está vinculada a outro computador ($mac_masked)",
            'WRONG_MAC',
            [
                'registered_mac_partial' => $mac_masked,
                'violation_level' => 'HIGH'
            ]
        );
    }
    
    // ========================================================================
    // 7. ATUALIZAÇÃO DE ATIVIDADE (Opcional)
    // ========================================================================
    
    try {
        $stmt = $pdo->prepare("UPDATE user_sessions SET updated_at = NOW() WHERE id = ?");
        $stmt->execute([$result['session_id']]);
    } catch (Exception $e) {
        error_log("Erro ao atualizar timestamp da sessão: " . $e->getMessage());
        // Não falhar a validação por causa disso
    }
    
    // ========================================================================
    // 8. RESPONSE DE SUCESSO
    // ========================================================================
    
    $response = [
        'success' => true,
        'message' => 'Cliente válido e autorizado',
        'is_client' => true,
        'user_info' => [
            'login' => $result['login'],
            'user_id' => $result['user_id'],
            'client_since' => $result['user_created']
        ],
        'session_info' => [
            'expires_at' => $result['expires_at'],
            'remaining_seconds' => $remaining_seconds,
            'session_age_seconds' => $current_timestamp - strtotime($result['session_created']),
            'last_updated' => $result['session_updated']
        ],
        'security_info' => [
            'mac_verified' => true,
            'integrity_check' => 'PASSED',
            'validation_timestamp' => date('Y-m-d H:i:s')
        ]
    ];
    
    error_log("✅ VALIDAÇÃO COMPLETA BEM-SUCEDIDA para cliente: {$result['login']}");
    echo json_encode($response);
    
} catch (PDOException $e) {
    error_log("VALIDATE DB ERROR: " . $e->getMessage());
    errorResponse(
        'Erro de banco de dados',
        'DATABASE_ERROR'
    );
} catch (Exception $e) {
    error_log("VALIDATE GENERAL ERROR: " . $e->getMessage());
    errorResponse(
        'Erro interno do servidor',
        'INTERNAL_ERROR'
    );
}
?>