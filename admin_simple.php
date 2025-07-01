<?php
// ============================================================================
// admin_simple.php - Painel Admin Simplificado
// ============================================================================

// Habilitar exibição de erros para debug
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Configurações básicas do banco
$db_host = 'localhost';
$db_name = 'minec761_comprejogos';
$db_user = 'minec761_comprejogos';
$db_pass = 'pr9n0xz5zxk2';

// Função de conexão simples
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

// Iniciar sessão
session_start();

// Senha do admin (altere aqui)
$admin_password = 'admin123';

// Verificar login
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    if (isset($_POST['admin_login'])) {
        $password = $_POST['password'] ?? '';
        if ($password === $admin_password) {
            $_SESSION['admin_logged'] = true;
            header('Location: admin_simple.php');
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
            .login-box { background: white; padding: 40px; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); text-align: center; }
            .login-box h2 { color: #667eea; margin-bottom: 20px; }
            input[type="password"] { width: 200px; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 5px; }
            button { background: #667eea; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
            .error { color: red; margin-top: 10px; }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h2>🔐 COMPREJOGOS Admin</h2>
            <p>Versão Simplificada</p>
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
    header('Location: admin_simple.php');
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
                $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
                $stmt->execute([$user_id]);
                $message = "Usuário excluído!";
                break;
                
            case 'clear_session':
                $user_id = (int)$_POST['user_id'];
                $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE usuario_id = ?");
                $stmt->execute([$user_id]);
                $message = "Sessão limpa!";
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
    
    // Verificar se coluna is_client existe
    try {
        $stats['total_clients'] = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE is_client = 1")->fetchColumn();
        $stats['active_clients'] = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE ativo = 1 AND is_client = 1")->fetchColumn();
    } catch (Exception $e) {
        // Coluna is_client não existe, criar
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN is_client TINYINT(1) DEFAULT 0");
        $stats['total_clients'] = 0;
        $stats['active_clients'] = 0;
        $message = "Coluna is_client criada automaticamente!";
    }
    
    // Verificar se tabela user_sessions existe
    try {
        $stats['active_sessions'] = $pdo->query("SELECT COUNT(*) FROM user_sessions WHERE expires_at > NOW()")->fetchColumn();
    } catch (Exception $e) {
        $stats['active_sessions'] = 0;
    }
    
} catch (Exception $e) {
    $stats = ['total_users' => 0, 'active_users' => 0, 'total_clients' => 0, 'active_clients' => 0, 'active_sessions' => 0];
    $message = "Erro ao buscar estatísticas: " . $e->getMessage();
}

// Buscar usuários
try {
    $stmt = $pdo->query("
        SELECT id, login, email, ativo, 
               COALESCE(is_client, 0) as is_client, 
               created_at,
               (SELECT COUNT(*) FROM access_logs al WHERE al.usuario_id = usuarios.id) as total_logins
        FROM usuarios 
        ORDER BY created_at DESC
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $users = [];
    $message = "Erro ao buscar usuários: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>COMPREJOGOS - Admin Simplificado</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f5f5f5; }
        .header { background: linear-gradient(45deg, #667eea, #764ba2); color: white; padding: 20px; text-align: center; position: relative; }
        .logout { position: absolute; right: 20px; top: 20px; background: rgba(255,255,255,0.2); color: white; padding: 8px 15px; text-decoration: none; border-radius: 5px; }
        .container { max-width: 1200px; margin: 20px auto; padding: 0 20px; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); text-align: center; }
        .stat-number { font-size: 2em; font-weight: bold; color: #667eea; }
        .stat-label { color: #666; margin-top: 5px; }
        .section { background: white; margin: 20px 0; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: hidden; }
        .section-header { background: #f8f9fa; padding: 15px 20px; border-bottom: 1px solid #dee2e6; font-weight: bold; }
        .section-content { padding: 20px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; font-weight: 600; }
        .btn { padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer; font-size: 12px; margin: 2px; color: white; text-decoration: none; display: inline-block; }
        .btn-success { background: #28a745; }
        .btn-warning { background: #ffc107; color: black; }
        .btn-danger { background: #dc3545; }
        .btn-info { background: #17a2b8; }
        .status-client { background: #d4edda; color: #155724; padding: 3px 8px; border-radius: 3px; font-size: 11px; }
        .status-user { background: #fff3cd; color: #856404; padding: 3px 8px; border-radius: 3px; font-size: 11px; }
        .status-inactive { background: #f8d7da; color: #721c24; padding: 3px 8px; border-radius: 3px; font-size: 11px; }
        .message { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 15px 0; }
        .error { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <div class="header">
        <h1>🎮 COMPREJOGOS - Admin Simplificado</h1>
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
                <div class="stat-label">Sessões Online</div>
            </div>
        </div>
        
        <div class="section">
            <div class="section-content">
                <h3>🎯 Como Usar:</h3>
                <ol>
                    <li><strong>👑 Ativar:</strong> Transforma usuário comum em cliente autorizado</li>
                    <li><strong>👤 Desativar:</strong> Remove autorização de cliente</li>
                    <li><strong>⏸️ Pausar:</strong> Suspende usuário temporariamente</li>
                    <li><strong>▶️ Ativar:</strong> Reativa usuário suspenso</li>
                    <li><strong>🔄 Limpar:</strong> Força novo login do usuário</li>
                    <li><strong>🗑️ Excluir:</strong> Remove usuário permanentemente</li>
                </ol>
                
                <h3>📊 Status dos Usuários:</h3>
                <ul>
                    <li><span class="status-client">Cliente Ativo</span> - Pode usar o programa</li>
                    <li><span class="status-user">Usuário Comum</span> - Cadastrado mas sem autorização</li>
                    <li><span class="status-inactive">Inativo</span> - Conta suspensa</li>
                </ul>
                
                <h3>⚠️ Importante:</h3>
                <p><strong>Senha do Admin:</strong> admin123 (altere na linha 17 do código)</p>
                <p><strong>Apenas clientes ativos</strong> podem instalar jogos pelo programa.</p>
            </div>
        </div>
    </div>
    
    <script>
        // Auto-refresh a cada 30 segundos
        setTimeout(() => location.reload(), 30000);
    </script>
</body>
</html>-header">👥 Gerenciar Usuários e Clientes</div>
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
                                <th>Criado</th>
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
                                <td><?php echo date('d/m/Y', strtotime($user['created_at'])); ?></td>
                                <td>
                                    <?php if (!$user['is_client']): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="activate_client">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <button type="submit" class="btn btn-success" title="Ativar como Cliente">👑 Ativar</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="deactivate_client">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <button type="submit" class="btn btn-warning" title="Desativar Cliente">👤 Desativar</button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="toggle_user">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" class="btn <?php echo $user['ativo'] ? 'btn-warning' : 'btn-success'; ?>">
                                            <?php echo $user['ativo'] ? '⏸️ Pausar' : '▶️ Ativar'; ?>
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
                <?php endif; ?>
            </div>
        </div>
        
        <div class="section">
            <div class="section-header">📋 Instruções</div>
            <div class="section