<?php
// ============================================================================
// api/admin_unlink.php - API para Admin Desvincular Computador
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
$admin_key = trim($_POST['admin_key'] ?? '');
$user_id = (int)($_POST['user_id'] ?? 0);
$action = trim($_POST['action'] ?? '');

// Chave de administrador (altere para algo seguro)
$valid_admin_key = 'COMPREJOGOS_ADMIN_2025_SECURE_KEY';

error_log("Admin API chamada - Action: $action, User: $user_id");

// Validar chave de admin
if ($admin_key !== $valid_admin_key) {
    error_log("Tentativa de acesso admin com chave inválida");
    echo json_encode(['success' => false, 'message' => 'Chave de administrador inválida']);
    exit;
}

// Validar parâmetros
if (empty($user_id) || empty($action)) {
    echo json_encode(['success' => false, 'message' => 'Parâmetros obrigatórios não informados']);
    exit;
}

try {
    // Conectar ao banco
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Buscar dados do usuário
    $stmt = $pdo->prepare("SELECT id, login, email FROM usuarios WHERE id = ?");
    $stmt->execute([$user_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario) {
        echo json_encode(['success' => false, 'message' => 'Usuário não encontrado']);
        exit;
    }
    
    switch ($action) {
        case 'unlink_computer':
            // Remover todas as sessões do usuário
            $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE usuario_id = ?");
            $stmt->execute([$user_id]);
            $sessions_removed = $stmt->rowCount();
            
            // Log da ação
            $stmt = $pdo->prepare("INSERT INTO access_logs (usuario_id, mac_address, ip_address, user_agent, login_successful, created_at) VALUES (?, ?, ?, ?, 2, NOW())");
            $stmt->execute([
                $user_id,
                'ADMIN_UNLINK',
                $_SERVER['REMOTE_ADDR'] ?? '',
                'Admin API - Computador desvinculado'
            ]);
            
            error_log("Admin desvinculou computador do usuário: {$usuario['login']} ($sessions_removed sessões removidas)");
            
            echo json_encode([
                'success' => true,
                'message' => "Computador desvinculado com sucesso",
                'user_login' => $usuario['login'],
                'sessions_removed' => $sessions_removed
            ]);
            break;
            
        case 'force_logout':
            // Expirar todas as sessões
            $stmt = $pdo->prepare("UPDATE user_sessions SET expires_at = NOW() WHERE usuario_id = ?");
            $stmt->execute([$user_id]);
            $sessions_expired = $stmt->rowCount();
            
            // Log da ação
            $stmt = $pdo->prepare("INSERT INTO access_logs (usuario_id, mac_address, ip_address, user_agent, login_successful, created_at) VALUES (?, ?, ?, ?, 2, NOW())");
            $stmt->execute([
                $user_id,
                'ADMIN_LOGOUT',
                $_SERVER['REMOTE_ADDR'] ?? '',
                'Admin API - Logout forçado'
            ]);
            
            error_log("Admin forçou logout do usuário: {$usuario['login']} ($sessions_expired sessões expiradas)");
            
            echo json_encode([
                'success' => true,
                'message' => "Logout forçado com sucesso",
                'user_login' => $usuario['login'],
                'sessions_expired' => $sessions_expired
            ]);
            break;
            
        case 'get_user_sessions':
            // Obter sessões ativas do usuário
            $stmt = $pdo->prepare("
                SELECT mac_address, expires_at, created_at, updated_at 
                FROM user_sessions 
                WHERE usuario_id = ? AND expires_at > NOW()
                ORDER BY created_at DESC
            ");
            $stmt->execute([$user_id]);
            $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'user_login' => $usuario['login'],
                'active_sessions' => $sessions,
                'session_count' => count($sessions)
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Ação não reconhecida']);
            break;
    }
    
} catch (PDOException $e) {
    error_log("Erro de banco na API admin: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro de banco de dados']);
} catch (Exception $e) {
    error_log("Erro geral na API admin: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno do servidor']);
}
?>