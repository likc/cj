<?php
// ============================================================================
// api/login_simple.php - API Simplificada para Teste
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
error_log("Login API chamada - Method: " . $_SERVER['REQUEST_METHOD']);
error_log("POST data: " . json_encode($_POST));

// Apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Apenas POST permitido']);
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

error_log("Dados recebidos - Login: $login, MAC: $mac_address");

// Validações básicas
if (empty($login) || empty($senha) || empty($mac_address)) {
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

try {
    // Conectar ao banco
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    error_log("Conexão com banco estabelecida");
    
    // Buscar usuário
    $stmt = $pdo->prepare("SELECT id, login, senha, ativo, COALESCE(is_client, 0) as is_client FROM usuarios WHERE login = ?");
    $stmt->execute([$login]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario) {
        error_log("Usuário não encontrado: $login");
        echo json_encode(['success' => false, 'message' => 'Usuário não encontrado']);
        exit;
    }
    
    // Verificar senha
    if ($usuario['senha'] !== $senha) {
        error_log("Senha incorreta para: $login");
        echo json_encode(['success' => false, 'message' => 'Senha incorreta']);
        exit;
    }
    
    // Verificar se usuário está ativo
    if ($usuario['ativo'] != 1) {
        error_log("Usuário inativo: $login");
        echo json_encode(['success' => false, 'message' => 'Usuário inativo']);
        exit;
    }
    
    // VERIFICAR SE É CLIENTE (PRINCIPAL VALIDAÇÃO)
    if ($usuario['is_client'] != 1) {
        error_log("Usuário não é cliente autorizado: $login");
        echo json_encode([
            'success' => false, 
            'message' => 'Acesso negado. Sua conta não possui autorização.',
            'is_client' => false
        ]);
        exit;
    }
    
    // Gerar token simples
    $session_token = bin2hex(random_bytes(16));
    $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
    
    // Tentar salvar sessão (pode falhar se tabela não existir)
    try {
        $stmt = $pdo->prepare("
            INSERT INTO user_sessions (usuario_id, mac_address, session_token, expires_at, created_at, updated_at) 
            VALUES (?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
            session_token = VALUES(session_token), 
            expires_at = VALUES(expires_at),
            updated_at = NOW()
        ");
        $stmt->execute([$usuario['id'], $mac_address, $session_token, $expires_at]);
        error_log("Sessão salva com sucesso");
    } catch (Exception $e) {
        error_log("Erro ao salvar sessão: " . $e->getMessage());
        // Continua mesmo se falhar
    }
    
    // Log de sucesso
    try {
        $stmt = $pdo->prepare("INSERT INTO access_logs (usuario_id, mac_address, ip_address, user_agent, login_successful, created_at) VALUES (?, ?, ?, ?, 1, NOW())");
        $stmt->execute([
            $usuario['id'], 
            $mac_address, 
            $_SERVER['REMOTE_ADDR'] ?? '', 
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (Exception $e) {
        error_log("Erro ao salvar log: " . $e->getMessage());
        // Continua mesmo se falhar
    }
    
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
    
    error_log("Login bem-sucedido para: $login");
    
} catch (PDOException $e) {
    error_log("Erro de banco: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Erro de banco de dados',
        'debug' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("Erro geral: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Erro interno do servidor',
        'debug' => $e->getMessage()
    ]);
}
?>