<?php
// ============================================================================
// api/validate.php - API de Validação em Tempo Real
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
error_log("Validate API chamada - Method: " . $_SERVER['REQUEST_METHOD']);

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
$session_token = $_POST['session_token'] ?? '';
$user_id = $_POST['user_id'] ?? '';
$mac_address = $_POST['mac_address'] ?? '';

error_log("Validate - Token: " . substr($session_token, 0, 8) . "..., User: $user_id, MAC: $mac_address");

// Validações básicas
if (empty($session_token) || empty($user_id) || empty($mac_address)) {
    echo json_encode([
        'success' => false, 
        'message' => 'Parâmetros obrigatórios não informados',
        'is_client' => false
    ]);
    exit;
}

try {
    // Conectar ao banco
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Verificar sessão válida e status do usuário
    $stmt = $pdo->prepare("
        SELECT u.id, u.login, u.ativo, COALESCE(u.is_client, 0) as is_client, us.expires_at
        FROM user_sessions us
        JOIN usuarios u ON us.usuario_id = u.id
        WHERE us.session_token = ? AND us.usuario_id = ? AND us.mac_address = ?
    ");
    
    $stmt->execute([$session_token, $user_id, $mac_address]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        error_log("Sessão não encontrada ou inválida");
        echo json_encode([
            'success' => false,
            'message' => 'Sessão inválida',
            'is_client' => false
        ]);
        exit;
    }
    
    // Verificar se sessão não expirou
    if (strtotime($result['expires_at']) < time()) {
        error_log("Sessão expirada para usuário: " . $result['login']);
        echo json_encode([
            'success' => false,
            'message' => 'Sessão expirada',
            'is_client' => false
        ]);
        exit;
    }
    
    // Verificar se usuário ainda está ativo
    if ($result['ativo'] != 1) {
        error_log("Usuário inativo: " . $result['login']);
        echo json_encode([
            'success' => false,
            'message' => 'Usuário foi desativado',
            'is_client' => false
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
    $stmt->execute([$mac_address, $user_id]);
    $mac_em_uso = $stmt->fetch();
    
    if ($mac_em_uso) {
        error_log("MAC em uso por outro usuário: $mac_address (user: " . $mac_em_uso['login'] . ")");
        echo json_encode([
            'success' => false,
            'message' => 'Este computador está vinculado ao usuário: ' . $mac_em_uso['login'],
            'is_client' => false
        ]);
        exit;
    }
    
    // Verificar se usuário está tentando usar MAC diferente
    $stmt = $pdo->prepare("
        SELECT mac_address 
        FROM user_sessions 
        WHERE usuario_id = ? AND expires_at > NOW()
    ");
    $stmt->execute([$user_id]);
    $mac_registrado = $stmt->fetchColumn();
    
    if ($mac_registrado && $mac_registrado !== $mac_address) {
        error_log("Usuário $user_id tentando usar MAC diferente: $mac_address (registrado: $mac_registrado)");
        echo json_encode([
            'success' => false,
            'message' => 'Sua conta está vinculada a outro computador',
            'is_client' => false
        ]);
        exit;
    }
    $is_client = ($result['is_client'] == 1);
    
    if (!$is_client) {
        error_log("Status de cliente removido para: " . $result['login']);
        echo json_encode([
            'success' => false,
            'message' => 'Status de cliente foi removido',
            'is_client' => false
        ]);
        exit;
    }
    
    // Tudo OK - usuário ainda é cliente ativo
    echo json_encode([
        'success' => true,
        'message' => 'Cliente ainda ativo',
        'is_client' => true,
        'login' => $result['login'],
        'expires_at' => $result['expires_at']
    ]);
    
    error_log("Validação bem-sucedida para cliente: " . $result['login']);
    
} catch (PDOException $e) {
    error_log("Erro de banco na validação: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Erro de banco de dados',
        'is_client' => false
    ]);
} catch (Exception $e) {
    error_log("Erro geral na validação: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Erro interno do servidor',
        'is_client' => false
    ]);
}
?>