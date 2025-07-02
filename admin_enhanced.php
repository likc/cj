<?php
// ============================================================================
// admin_enhanced.php - Painel Admin com Debug de MAC
// ============================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Configurações do banco
$db_host = 'localhost';
$db_name = 'minec761_comprejogos';
$db_user = 'minec761_comprejogos';
$db_pass = 'pr9n0xz5zxk2';

function conectarBanco() {
    global $db_host, $db_name, $db_user, $db_pass;
    try {
        $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch(PDOException $e) {
        die("Erro de conexão: " . $e->getMessage());
    }
}

session_start();

// Senha do admin
$admin_password = 'admin123';

// Verificar login
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    if (isset($_POST['admin_login'])) {
        $password = $_POST['password'] ?? '';
        if ($password === $admin_password) {
            $_SESSION['admin_logged'] = true;
            header('Location: admin_enhanced.php');
            exit;
        } else {
            $login_error = 'Senha incorreta!';
        }
    }
    
    // Tela de login
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>COMPREJOGOS - Admin Enhanced</title>
        <style>
            body { font-family: Arial, sans-serif; background: #f0f0f0; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
            .login-box { background: white; padding: 40px; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); text-align: center; }
            .login-box h2 { color: #667eea; margin-bottom: 20px; }
            input[type="password"] { width: 200px; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 5px; }
            button { background: #667eea; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
            .error { color: red; margin-top: 10px; }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h2>🔐 COMPREJOGOS Admin Enhanced</h2>
            <p>Painel com Debug de MAC</p>
            <form method="POST">
                <input type="password" name="password" placeholder="Senha: admin123" required>
                <br>
                <button type="submit" name="admin_login">Entrar</button>
                <?php if (isset($login_error)): ?>
                    <div class="error"><?php echo $login_error; ?></div>
                <?php endif; ?>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin_enhanced.php');
    exit;
}

// Conectar ao banco
$pdo = conectarBanco();

$message = '';

// Ações administrativas
if (isset($_POST['action'])) {
    $action = $_POST['action'];
    
    try {
        switch ($action) {
            case 'activate_client':
                $user_id = (int)$_POST['user_id'];
                $stmt = $pdo->prepare("UPDATE usuarios SET is_client = 1 WHERE id = ?");
                $stmt->execute([$user_id]);
                $message = "Cliente ativado com sucesso!";
                break;
                
            case 'deactivate_client':
                $user_id = (int)$_POST['user_id'];
                $stmt = $pdo->prepare("UPDATE usuarios SET is_client = 0 WHERE id = ?");
                $stmt->execute([$user_id]);
                $message = "Cliente desativado!";
                break;
                
            case 'toggle_user':
                $user_id = (int)$_POST['user_id'];
                $stmt = $pdo->prepare("UPDATE usuarios SET ativo = NOT ativo WHERE id = ?");
                $stmt->execute([$user_id]);
                $message = "Status do usuário alterado!";
                break;
                
            case 'delete_user':
                $user_id = (int)$_POST['user_id'];
                
                // Remover sessões e logs primeiro
                $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE usuario_id = ?");
                $stmt->execute([$user_id]);
                
                $stmt = $pdo->prepare("DELETE FROM access_logs WHERE usuario_id = ?");
                $stmt->execute([$user_id]);
                
                $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
                $stmt->execute([$user_id]);
                
                $message = "Usuário e todos os dados relacionados foram excluídos!";
                break;
                
            case 'unlink_computer':
                $user_id = (int)$_POST['user_id'];
                $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE usuario_id = ?");
                $result = $stmt->execute([$user_id]);
                $affected = $stmt->rowCount();
                
                // Log da ação
                $stmt = $pdo->prepare("INSERT INTO access_logs (usuario_id, mac_address, ip_address, user_agent, login_successful, created_at) VALUES (?, ?, ?, ?, 2, NOW())");
                $stmt->execute([
                    $user_id,
                    'ADMIN_UNLINK',
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    'Admin - Computador desvinculado'
                ]);
                
                $message = "Computador desvinculado! ($affected sessões removidas)";
                break;
                
            case 'fix_mac_format':
                // Corrigir formato de MAC addresses
                $stmt = $pdo->prepare("
                    UPDATE user_sessions 
                    SET mac_address = UPPER(REPLACE(REPLACE(mac_address, '-', ':'), ' ', '')) 
                    WHERE mac_address IS NOT NULL AND mac_address != ''
                ");
                $result = $stmt->execute();
                $affected = $stmt->rowCount();
                $message = "Formato de MAC corrigido! ($affected registros atualizados)";
                break;
                
            case 'clean_expired_sessions':
                $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE expires_at < NOW()");
                $result = $stmt->execute();
                $affected = $stmt->rowCount();
                $message = "Sessões expiradas removidas! ($affected registros)";
                break;
                
            case 'clean_old_logs':
                $stmt = $pdo->prepare("DELETE FROM access_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
                $result = $stmt->execute();
                $affected = $stmt->rowCount();
                $message = "Logs antigos removidos! ($affected registros)";
                break;
        }
    } catch (Exception $e) {
        $message = "Erro: " . $e->getMessage();
    }
}

// Buscar estatísticas
try {
    $stats = [];
    $stats['total_users'] = $pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
    $stats['active_users'] = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE ativo = 1")->fetchColumn();
    $stats['total_clients'] = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE is_client = 1")->fetchColumn();
    $stats['active_clients'] = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE ativo = 1 AND is_client = 1")->fetchColumn();
    $stats['active_sessions'] = $pdo->query("SELECT COUNT(*) FROM user_sessions WHERE expires_at > NOW()")->fetchColumn();
    $stats['expired_sessions'] = $pdo->query("SELECT COUNT(*) FROM user_sessions WHERE expires_at < NOW()")->fetchColumn();
    $stats['problematic_macs'] = $pdo->query("
        SELECT COUNT(*) FROM user_sessions 
        WHERE mac_address IS NULL 
           OR mac_address = '' 
           OR LENGTH(mac_address) != 17
           OR mac_address NOT REGEXP '^([0-9A-F]{2}:){5}[0-9A-F]{2}$'
    ")->fetchColumn();
    
} catch (Exception $e) {
    $stats = array_fill_keys(['total_users', 'active_users', 'total_clients', 'active_clients', 'active_sessions', 'expired_sessions', 'problematic_macs'], 0);
    $message = "Erro ao buscar estatísticas: " . $e->getMessage();
}

// Buscar usuários com informações detalhadas
try {
    $stmt = $pdo->query("
        SELECT 
            u.id, 
            u.login, 
            u.email, 
            u.ativo, 
            COALESCE(u.is_client, 0) as is_client, 
            u.created_at,
            us.mac_address,
            us.session_token,
            us.expires_at,
            us.created_at as session_created,
            CASE 
                WHEN us.expires_at > NOW() THEN 'ATIVA'
                WHEN us.expires_at IS NOT NULL THEN 'EXPIRADA'
                ELSE 'SEM SESSÃO'
            END as session_status,
            CASE 
                WHEN us.mac_address IS NULL THEN 'MAC NULL'
                WHEN us.mac_address = '' THEN 'MAC VAZIO'
                WHEN LENGTH(us.mac_address) != 17 THEN 'MAC TAMANHO INCORRETO'
                WHEN us.mac_address NOT REGEXP '^([0-9A-F]{2}:){5}[0-9A-F]{2}$' THEN 'MAC FORMATO INVÁLIDO'
                ELSE 'MAC OK'
            END as mac_status,
            (SELECT COUNT(*) FROM access_logs al WHERE al.usuario_id = u.id) as total_logins,
            (SELECT MAX(created_at) FROM access_logs al WHERE al.usuario_id = u.id) as last_access
        FROM usuarios u 
        LEFT JOIN user_sessions us ON u.id = us.usuario_id
        ORDER BY u.created_at DESC
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $users = [];
    $message = "Erro ao buscar usuários: " . $e->getMessage();
}

// Buscar problemas de MAC
try {
    $stmt = $pdo->query("
        SELECT 
            us.id as session_id,
            us.usuario_id,
            u.login,
            us.mac_address,
            LENGTH(us.mac_address) as mac_length,
            HEX(us.mac_address) as mac_hex,
            us.expires_at,
            CASE 
                WHEN us.mac_address IS NULL THEN 'NULL'
                WHEN us.mac_address = '' THEN 'VAZIO'
                WHEN LENGTH(us.mac_address) != 17 THEN CONCAT('TAMANHO INCORRETO (', LENGTH(us.mac_address), ')')
                WHEN us.mac_address NOT REGEXP '^([0-9A-F]{2}:){5}[0-9A-F]{2}$' THEN 'FORMATO INVÁLIDO'
                ELSE 'OK'
            END as problema
        FROM user_sessions us
        LEFT JOIN usuarios u ON us.usuario_id = u.id
        WHERE us.mac_address IS NULL 
           OR us.mac_address = '' 
           OR LENGTH(us.mac_address) != 17
           OR us.mac_address NOT REGEXP '^([0-9A-F]{2}:){5}[0-9A-F]{2}$'
        ORDER BY us.created_at DESC
    ");
    $mac_problems = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $mac_problems = [];
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>COMPREJOGOS - Admin Enhanced</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f5f5f5; }
        .header { background: linear-gradient(45deg, #667eea, #764ba2); color: white; padding: 20px; text-align: center; position: relative; }
        .logout { position: absolute; right: 20px; top: 20px; background: rgba(255,255,255,0.2); color: white; padding: 8px 15px; text-decoration: none; border-radius: 5px; }
        .container { max-width: 1400px; margin: 20px auto; padding: 0 20px; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); text-align: center; }
        .stat-number { font-size: 1.5em; font-weight: bold; color: #667eea; }
        .stat-label { color: #666; margin-top: 5px; font-size: 12px; }
        .section { background: white; margin: 20px 0; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: hidden; }
        .section-header { background: #f8f9fa; padding: 15px 20px; border-bottom: 1px solid #dee2e6; font-weight: bold; display: flex; justify-content: space-between; align-items: center; }
        .section-content { padding: 20px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; font-weight: 600; position: sticky; top: 0; }
        .btn { padding: 4px 8px; border: none; border-radius: 3px; cursor: pointer; font-size: 11px; margin: 1px; color: white; text-decoration: none; display: inline-block; }
        .btn-success { background: #28a745; }
        .btn-warning { background: #ffc107; color: black; }
        .btn-danger { background: #dc3545; }
        .btn-info { background: #17a2b8; }
        .btn-primary { background: #007bff; }
        .status-client { background: #d4edda; color: #155724; padding: 2px 6px; border-radius: 3px; font-size: 10px; }
        .status-user { background: #fff3cd; color: #856404; padding: 2px 6px; border-radius: 3px; font-size: 10px; }
        .status-inactive { background: #f8d7da; color: #721c24; padding: 2px 6px; border-radius: 3px; font-size: 10px; }
        .mac-ok { color: #28a745; font-weight: bold; }
        .mac-problem { color: #dc3545; font-weight: bold; }
        .session-active { color: #28a745; }
        .session-expired { color: #ffc107; }
        .session-none { color: #6c757d; }
        .message { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 15px 0; }
        .error { background: #f8d7da; color: #721c24; }
        .admin-tools { display: flex; gap: 10px; flex-wrap: wrap; }
        .problem-highlight { background: #fff3cd; }
    </style>
</head>
<body>
    <div class="header">
        <h1>🎮 COMPREJOGOS - Admin Enhanced</h1>
        <p>Painel com Debug de MAC e Gestão Avançada</p>
        <a href="?logout=1" class="logout">Sair</a>
    </div>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="message <?php echo strpos($message, 'Erro') !== false ? 'error' : ''; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <div class="stats">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_users']; ?></div>
                <div class="stat-label">Total Usuários</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_clients']; ?></div>
                <div class="stat-label">Total Clientes</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['active_clients']; ?></div>
                <div class="stat-label">Clientes Ativos</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['active_sessions']; ?></div>
                <div class="stat-label">Sessões Ativas</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['expired_sessions']; ?></div>
                <div class="stat-label">Sessões Expiradas</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: <?php echo $stats['problematic_macs'] > 0 ? '#dc3545' : '#28a745'; ?>">
                    <?php echo $stats['problematic_macs']; ?>
                </div>
                <div class="stat-label">MACs Problemáticos</div>
            </div>
        </div>
        
        <div class="section">
            <div class="section-header">
                🛠️ Ferramentas de Administração
                <div class="admin-tools">
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="fix_mac_format">
                        <button type="submit" class="btn btn-warning">🔧 Corrigir MACs</button>
                    </form>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="clean_expired_sessions">
                        <button type="submit" class="btn btn-info">🧹 Limpar Expiradas</button>
                    </form>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="clean_old_logs">
                        <button type="submit" class="btn btn-primary">📋 Limpar Logs</button>
                    </form>
                </div>
            </div>
        </div>
        
        <?php if (!empty($mac_problems)): ?>
        <div class="section">
            <div class="section-header">
                🚨 Problemas de MAC Detectados (<?php echo count($mac_problems); ?>)
            </div>
            <div class="section-content">
                <table>
                    <thead>
                        <tr>
                            <th>ID Sessão</th>
                            <th>Usuário</th>
                            <th>MAC Address</th>
                            <th>Tamanho</th>
                            <th>HEX</th>
                            <th>Problema</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mac_problems as $problem): ?>
                        <tr class="problem-highlight">
                            <td><?php echo $problem['session_id']; ?></td>
                            <td><?php echo htmlspecialchars($problem['login'] ?? 'N/A'); ?></td>
                            <td><code><?php echo htmlspecialchars($problem['mac_address'] ?? 'NULL'); ?></code></td>
                            <td><?php echo $problem['mac_length'] ?? 0; ?></td>
                            <td><small><?php echo $problem['mac_hex'] ?? 'N/A'; ?></small></td>
                            <td><span class="mac-problem"><?php echo $problem['problema']; ?></span></td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="unlink_computer">
                                    <input type="hidden" name="user_id" value="<?php echo $problem['usuario_id']; ?>">
                                    <button type="submit" class="btn btn-danger" onclick="return confirm('Desvincular computador?')">🔗 Desvincular</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="section">
            <div class="section-header">👥 Gerenciar Usuários e Clientes</div>
            <div class="section-content">
                <?php if (empty($users)): ?>
                    <p>Nenhum usuário encontrado. <a href="register.php">Criar primeiro usuário</a></p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Login</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Tipo</th>
                                <th>MAC Address</th>
                                <th>Status MAC</th>
                                <th>Sessão</th>
                                <th>Último Acesso</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo $user['id']; ?></td>
                                <td><?php echo htmlspecialchars($user['login']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td>
                                    <?php if ($user['ativo'] && $user['is_client']): ?>
                                        <span class="status-client">Cliente Ativo</span>
                                    <?php elseif ($user['ativo']): ?>
                                        <span class="status-user">Usuário Comum</span>
                                    <?php else: ?>
                                        <span class="status-inactive">Inativo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($user['is_client']): ?>
                                        👑 Cliente
                                    <?php else: ?>
                                        👤 Usuário
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($user['mac_address']): ?>
                                        <code style="font-size: 10px;"><?php echo htmlspecialchars($user['mac_address']); ?></code>
                                    <?php else: ?>
                                        <span style="color: #999;">Sem MAC</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="<?php echo $user['mac_status'] === 'MAC OK' ? 'mac-ok' : 'mac-problem'; ?>">
                                        <?php echo $user['mac_status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="session-<?php echo strtolower(str_replace(' ', '-', $user['session_status'])); ?>">
                                        <?php echo $user['session_status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($user['last_access']): ?>
                                        <?php echo date('d/m H:i', strtotime($user['last_access'])); ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$user['is_client']): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="activate_client">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <button type="submit" class="btn btn-success" title="Ativar como Cliente">👑</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="deactivate_client">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <button type="submit" class="btn btn-warning" title="Desativar Cliente">👤</button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="toggle_user">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" class="btn <?php echo $user['ativo'] ? 'btn-warning' : 'btn-success'; ?>" title="<?php echo $user['ativo'] ? 'Pausar' : 'Ativar'; ?>">
                                            <?php echo $user['ativo'] ? '⏸️' : '▶️'; ?>
                                        </button>
                                    </form>
                                    
                                    <?php if ($user['mac_address']): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="unlink_computer">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" class="btn btn-info" title="Desvincular Computador" onclick="return confirm('Desvincular computador?')">🔗</button>
                                    </form>
                                    <?php endif; ?>
                                    
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" class="btn btn-danger" onclick="return confirm('Excluir usuário PERMANENTEMENTE?')" title="Excluir">🗑️</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="section">
            <div class="section-header">📋 Instruções e Legendas</div>
            <div class="section-content">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div>
                        <h4>🎯 Ações Disponíveis:</h4>
                        <ul>
                            <li><strong>👑 Ativar Cliente:</strong> Permite usar o programa</li>
                            <li><strong>👤 Desativar Cliente:</strong> Remove autorização</li>
                            <li><strong>⏸️ Pausar:</strong> Suspende usuário temporariamente</li>
                            <li><strong>▶️ Ativar:</strong> Reativa usuário suspenso</li>
                            <li><strong>🔗 Desvincular:</strong> Remove MAC vinculado</li>
                            <li><strong>🗑️ Excluir:</strong> Remove usuário permanentemente</li>
                        </ul>
                    </div>
                    <div>
                        <h4>📊 Status e Códigos:</h4>
                        <ul>
                            <li><span class="status-client">Cliente Ativo</span> - Pode usar o programa</li>
                            <li><span class="status-user">Usuário Comum</span> - Sem autorização</li>
                            <li><span class="status-inactive">Inativo</span> - Conta suspensa</li>
                            <li><span class="mac-ok">MAC OK</span> - Formato correto</li>
                            <li><span class="mac-problem">MAC Problemático</span> - Precisa correção</li>
                        </ul>
                    </div>
                </div>
                
                <div style="margin-top: 20px; padding: 15px; background: #e3f2fd; border-radius: 5px;">
                    <h4>🔧 Ferramentas de Manutenção:</h4>
                    <p><strong>Corrigir MACs:</strong> Normaliza formato de endereços MAC para XX:XX:XX:XX:XX:XX</p>
                    <p><strong>Limpar Expiradas:</strong> Remove sessões com data/hora expirada</p>
                    <p><strong>Limpar Logs:</strong> Remove logs de acesso com mais de 30 dias</p>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Auto-refresh a cada 30 segundos
        setTimeout(() => location.reload(), 30000);
        
        // Confirmar ações críticas
        document.querySelectorAll('.btn-danger').forEach(btn => {
            if (!btn.onclick) {
                btn.onclick = () => confirm('Tem certeza? Esta ação não pode ser desfeita.');
            }
        });
    </script>
</body>
</html>