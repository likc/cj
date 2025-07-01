<?php
// ============================================================================
// admin.php - Painel Administrativo COMPREJOGOS
// ============================================================================

require_once 'config.php';
setSecurityHeaders();

// Senha de administrador (altere em produção)
$admin_password = 'admin2025!';

session_start();

// Verificar login
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    if (isset($_POST['admin_login'])) {
        $password = $_POST['password'] ?? '';
        if ($password === $admin_password) {
            $_SESSION['admin_logged'] = true;
            header('Location: admin.php');
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
        <title>COMPREJOGOS - Admin</title>
        <style>
            body { font-family: Arial, sans-serif; background: #f0f0f0; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
            .login-box { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
            input[type="password"] { width: 200px; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 5px; }
            button { background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
            .error { color: red; margin-top: 10px; }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h2>🔐 Admin COMPREJOGOS</h2>
            <form method="POST">
                <input type="password" name="password" placeholder="Senha de administrador" required>
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
    header('Location: admin.php');
    exit;
}

// Conectar ao banco
$pdo = conectarBanco();
if (!$pdo) {
    die('Erro de conexão com banco de dados');
}

// Ações administrativas
if (isset($_POST['action'])) {
    $action = $_POST['action'];
    
    switch ($action) {
        case 'toggle_user':
            $user_id = (int)$_POST['user_id'];
            $stmt = $pdo->prepare("UPDATE usuarios SET ativo = NOT ativo WHERE id = ?");
            $stmt->execute([$user_id]);
            $message = "Status do usuário alterado!";
            break;
            
        case 'delete_user':
            $user_id = (int)$_POST['user_id'];
            $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
            $stmt->execute([$user_id]);
            $message = "Usuário excluído!";
            break;
            
        case 'clear_session':
            $user_id = (int)$_POST['user_id'];
            $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE usuario_id = ?");
            $stmt->execute([$user_id]);
            $message = "Sessão do usuário limpa!";
            break;
            
        case 'clean_logs':
            $pdo->exec("DELETE FROM access_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
            $pdo->exec("DELETE FROM download_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
            $message = "Logs antigos limpos!";
            break;
    }
}

// Buscar estatísticas
$stats = [];
$stats['total_users'] = $pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
$stats['active_users'] = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE ativo = 1")->fetchColumn();
$stats['active_sessions'] = $pdo->query("SELECT COUNT(*) FROM user_sessions WHERE expires_at > NOW()")->fetchColumn();
$stats['total_downloads'] = $pdo->query("SELECT COUNT(*) FROM download_logs")->fetchColumn();
$stats['downloads_today'] = $pdo->query("SELECT COUNT(*) FROM download_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$stats['file_size'] = file_exists(DOWNLOAD_FILE_PATH) ? filesize(DOWNLOAD_FILE_PATH) : 0;

// Buscar usuários
$users = $pdo->query("
    SELECT u.*, 
           us.mac_address, 
           us.expires_at as session_expires,
           (SELECT COUNT(*) FROM download_logs dl WHERE dl.usuario_id = u.id) as total_downloads,
           (SELECT MAX(created_at) FROM access_logs al WHERE al.usuario_id = u.id) as last_access
    FROM usuarios u
    LEFT JOIN user_sessions us ON u.id = us.usuario_id AND us.expires_at > NOW()
    ORDER BY u.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Buscar logs recentes
$recent_logs = $pdo->query("
    SELECT 'access' as type, u.login, al.mac_address, al.ip_address, al.created_at
    FROM access_logs al
    JOIN usuarios u ON al.usuario_id = u.id
    WHERE al.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    
    UNION ALL
    
    SELECT 'download' as type, u.login, dl.mac_address, dl.ip_address, dl.created_at
    FROM download_logs dl
    JOIN usuarios u ON dl.usuario_id = u.id
    WHERE dl.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    
    ORDER BY created_at DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>COMPREJOGOS - Painel Admin</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; }
        .header { background: linear-gradient(45deg, #667eea, #764ba2); color: white; padding: 20px; text-align: center; }
        .container { max-width: 1200px; margin: 20px auto; padding: 0 20px; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); text-align: center; }
        .stat-number { font-size: 2em; font-weight: bold; color: #667eea; }
        .stat-label { color: #666; margin-top: 5px; }
        .section { background: white; margin: 20px 0; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: hidden; }
        .section-header { background: #f8f9fa; padding: 15px 20px; border-bottom: 1px solid #dee2e6; font-weight: bold; }
        .section-content { padding: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; font-weight: 600; }
        .btn { padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer; font-size: 12px; margin: 2px; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-warning { background: #ffc107; color: black; }
        .btn-info { background: #17a2b8; color: white; }
        .btn-success { background: #28a745; color: white; }
        .status-active { color: #28a745; font-weight: bold; }
        .status-inactive { color: #dc3545; font-weight: bold; }
        .message { background: #d4edda; color: #155724; padding: 10px; border-radius: 5px; margin: 10px 0; }
        .file-info { background: #e3f2fd; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .logout { float: right; background: rgba(255,255,255,0.2); color: white; padding: 5px 15px; text-decoration: none; border-radius: 5px; }
        .logout:hover { background: rgba(255,255,255,0.3); }
        .log-access { color: #007bff; }
        .log-download { color: #28a745; }
        @media (max-width: 768px) {
            .stats { grid-template-columns: 1fr 1fr; }
            table { font-size: 14px; }
            th, td { padding: 5px; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🎮 COMPREJOGOS - Painel Administrativo</h1>
        <a href="?logout=1" class="logout">Sair</a>
    </div>
    
    <div class="container">
        <?php if (isset($message)): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <div class="stats">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_users']; ?></div>
                <div class="stat-label">Total de Usuários</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['active_users']; ?></div>
                <div class="stat-label">Usuários Ativos</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['active_sessions']; ?></div>
                <div class="stat-label">Sessões Ativas</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_downloads']; ?></div>
                <div class="stat-label">Total Downloads</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['downloads_today']; ?></div>
                <div class="stat-label">Downloads Hoje</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['file_size'] / 1024 / 1024, 1); ?> MB</div>
                <div class="stat-label">Tamanho do Arquivo</div>
            </div>
        </div>
        
        <div class="file-info">
            <strong>📁 Arquivo de Download:</strong> <?php echo DOWNLOAD_FILE_PATH; ?><br>
            <strong>Status:</strong> <?php echo file_exists(DOWNLOAD_FILE_PATH) ? '✅ Existe' : '❌ Não encontrado'; ?>
        </div>
        
        <div class="section">
            <div class="section-header">
                👥 Gerenciar Usuários
                <form method="POST" style="display: inline; float: right;">
                    <input type="hidden" name="action" value="clean_logs">
                    <button type="submit" class="btn btn-warning" onclick="return confirm('Limpar logs antigos?')">🧹 Limpar Logs</button>
                </form>
            </div>
            <div class="section-content">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Login</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>MAC Address</th>
                            <th>Downloads</th>
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
                                <span class="<?php echo $user['ativo'] ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo $user['ativo'] ? '✅ Ativo' : '❌ Inativo'; ?>
                                </span>
                            </td>
                            <td><?php echo $user['mac_address'] ?? '—'; ?></td>
                            <td><?php echo $user['total_downloads']; ?></td>
                            <td><?php echo $user['last_access'] ? date('d/m/Y H:i', strtotime($user['last_access'])) : '—'; ?></td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="toggle_user">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <button type="submit" class="btn <?php echo $user['ativo'] ? 'btn-warning' : 'btn-success'; ?>">
                                        <?php echo $user['ativo'] ? '⏸️' : '▶️'; ?>
                                    </button>
                                </form>
                                
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="clear_session">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <button type="submit" class="btn btn-info">🔄</button>
                                </form>
                                
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <button type="submit" class="btn btn-danger" onclick="return confirm('Excluir usuário?')">🗑️</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="section">
            <div class="section-header">📊 Logs Recentes (últimas 24h)</div>
            <div class="section-content">
                <table>
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Usuário</th>
                            <th>MAC</th>
                            <th>IP</th>
                            <th>Data/Hora</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_logs as $log): ?>
                        <tr>
                            <td>
                                <span class="<?php echo $log['type'] === 'access' ? 'log-access' : 'log-download'; ?>">
                                    <?php echo $log['type'] === 'access' ? '🔐 Login' : '📥 Download'; ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($log['login']); ?></td>
                            <td><?php echo $log['mac_address']; ?></td>
                            <td><?php echo $log['ip_address']; ?></td>
                            <td><?php echo date('d/m/Y H:i:s', strtotime($log['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        // Auto-refresh a cada 30 segundos
        setTimeout(() => location.reload(), 30000);
    </script>
</body>
</html>