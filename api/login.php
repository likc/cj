<?php
// ============================================================================
// api/login.php - API de Login Corrigida para Erro 406
// ============================================================================

// Desabilitar buffer de saída para evitar problemas
if (ob_get_level()) {
    ob_end_clean();
}

// Headers básicos primeiro
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, User-Agent, Referer');
header('Accept: application/json, text/plain, */*');

// Log da requisição para debug
error_log("Login API accessed - Method: " . $_SERVER['REQUEST_METHOD']);
error_log("Headers: " . json_encode(getallheaders()));
error_log("POST data: " . json_encode($_POST));
error_log("Raw input: " . file_get_contents('php://input'));

// Tratar requisições OPTIONS (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Permitir tanto POST quanto GET para debug
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(200); // Mudamos para 200 em vez de 405
    echo json_encode(['success' => false, 'message' => 'Método: ' . $_SERVER['REQUEST_METHOD'] . ' não suportado']);
    exit;
}

// Configurações do banco
$db_host = 'localhost';
$db_name = 'minec761_comprejogos';
$db_user = 'minec761_comprejogos';
$db_pass = 'pr9n0xz5zxk2';

// Obter dados tanto de POST quanto GET para debug
$login = $_POST['login'] ?? $_GET['login'] ?? '';
$senha = $_POST['senha'] ?? $_GET['senha'] ?? '';
$mac_address = $_POST['mac_address'] ?? $_GET['mac_address'] ?? '';

// Log dos dados recebidos
error_log("Dados recebidos - Login: $login, MAC: $mac_address");

// Validar dados obrigatórios
if (empty($login) || empty($senha) || empty($mac_address)) {
    echo json_encode([
        'success' => false, 
        'message' => 'Dados obrigatórios não informados',
        'debug' => [
            'login' => $login,
            'senha' => !empty($senha) ? 'fornecida' : 'vazia',
            'mac' => $mac_address,
            'method' => $_SERVER['REQUEST_METHOD']
        ]
    ]);
    exit;
}

// Validar formato do MAC (mais flexível)
if (!preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $mac_address)) {
    echo json_encode([
        'success' => false, 
        'message' => 'Formato de MAC address inválido: ' . $mac_address
    ]);
    exit;
}

try {
    // Conectar ao banco
    $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    error_log("Conexão com banco estabelecida");
    
    // Verificar se usuário existe
    $stmt = $pdo->prepare("SELECT id, login, senha, email, ativo FROM usuarios WHERE login = ?");
    $stmt->execute([$login]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario) {
        error_log("Usuário não encontrado: $login");
        echo json_encode([
            'success' => false, 
            'message' => 'Usuário ou senha incorretos'
        ]);
        exit;
    }
    
    // Verificar senha
    if ($usuario['senha'] !== $senha) {
        error_log("Senha incorreta para usuário: $login");
        echo json_encode([
            'success' => false, 
            'message' => 'Usuário ou senha incorretos'
        ]);
        exit;
    }
    
    // Verificar se usuário está ativo
    if ($usuario['ativo'] != 1) {
        error_log("Usuário inativo: $login");
        echo json_encode([
            'success' => false, 
            'message' => 'Usuário inativo'
        ]);
        exit;
    }
    
    // Verificar se MAC já está em uso por outro usuário
    $stmt = $pdo->prepare("
        SELECT u.login 
        FROM user_sessions us 
        JOIN usuarios u ON us.usuario_id = u.id 
        WHERE us.mac_address = ? AND us.usuario_id != ? AND us.expires_at > NOW()
    ");
    $stmt->execute([$mac_address, $usuario['id']]);
    $mac_em_uso = $stmt->fetch();
    
    if ($mac_em_uso) {
        error_log("MAC já em uso: $mac_address por usuário: " . $mac_em_uso['login']);
        echo json_encode([
            'success' => false, 
            'message' => 'Este computador já está vinculado ao usuário: ' . $mac_em_uso['login']
        ]);
        exit;
    }
    
    // Verificar se usuário já tem MAC registrado
    $stmt = $pdo->prepare("SELECT mac_address FROM user_sessions WHERE usuario_id = ? AND expires_at > NOW()");
    $stmt->execute([$usuario['id']]);
    $mac_registrado = $stmt->fetchColumn();
    
    if ($mac_registrado && $mac_registrado !== $mac_address) {
        error_log("Usuário $login tentando usar MAC diferente: $mac_address (registrado: $mac_registrado)");
        echo json_encode([
            'success' => false, 
            'message' => 'Usuário já possui um computador vinculado'
        ]);
        exit;
    }
    
    // Gerar token de sessão
    $session_token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
    
    // Inserir ou atualizar sessão
    $stmt = $pdo->prepare("
        INSERT INTO user_sessions (usuario_id, mac_address, session_token, expires_at, created_at, updated_at) 
        VALUES (?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE 
        session_token = VALUES(session_token), 
        expires_at = VALUES(expires_at),
        updated_at = NOW()
    ");
    
    $stmt->execute([$usuario['id'], $mac_address, $session_token, $expires_at]);
    
    // Registrar log de acesso
    $stmt = $pdo->prepare("INSERT INTO access_logs (usuario_id, mac_address, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([
        $usuario['id'], 
        $mac_address, 
        $_SERVER['REMOTE_ADDR'] ?? '', 
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
    error_log("Login bem-sucedido para usuário: $login");
    
    // Resposta de sucesso
    echo json_encode([
        'success' => true,
        'message' => 'Autenticação realizada com sucesso',
        'user_id' => $usuario['id'],
        'login' => $usuario['login'],
        'session_token' => $session_token,
        'expires_at' => $expires_at
    ]);
    
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