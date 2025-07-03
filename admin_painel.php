<?php
// admin_painel_v2.php - VERSÃO CORRIGIDA - Sem Bugs de Renderização

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Configurações do banco
$db_host = 'localhost';
$db_name = 'minec761_comprejogos';
$db_user = 'minec761_comprejogos';
$db_pass = 'pr9n0xz5zxk2';

try {
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) { 
    die("Erro fatal de conexão: " . $e->getMessage()); 
}

session_start();

// Senha do admin (altere conforme necessário)
$admin_password = 'admin123';

// Verificar login
$is_logged_in = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;

if (!$is_logged_in) {
    if (isset($_POST['password'])) {
        if ($_POST['password'] === $admin_password) {
            $_SESSION['admin_logged'] = true;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else { 
            $login_error = "Senha incorreta!"; 
        }
    }
    
    // Página de login
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <title>Admin Login</title>
        <style>
            body{font-family:sans-serif;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);display:flex;align-items:center;justify-content:center;height:100vh;margin:0}
            .login-container{background:#fff;padding:40px;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,0.1);text-align:center;width:380px;backdrop-filter:blur(10px)}
            h1{color:#1c1e21;margin-bottom:30px;font-size:1.8em}
            .logo{font-size:3em;margin-bottom:20px}
            input[type="password"]{width:100%;padding:16px;margin-bottom:25px;border:2px solid #e1e5e9;border-radius:8px;font-size:1.1em;transition:border-color 0.3s}
            input[type="password"]:focus{outline:none;border-color:#667eea}
            button{width:100%;padding:16px;border:none;border-radius:8px;background:linear-gradient(45deg,#667eea,#764ba2);color:white;font-weight:bold;cursor:pointer;font-size:1.1em;transition:transform 0.2s}
            button:hover{transform:translateY(-2px)}
            .error{color:#dc2626;margin-top:15px;background:#fee2e2;padding:10px;border-radius:6px}
        </style>
    </head>
    <body>
        <div class="login-container">
            <div class="logo">🎮</div>
            <h1>COMPREJOGOS</h1>
            <p style="color:#666;margin-bottom:30px;">Painel Administrativo</p>
            <form method="POST">
                <input type="password" name="password" placeholder="Digite sua senha" required>
                <button type="submit">Entrar no Sistema</button>
                <?php if(isset($login_error)): ?>
                    <p class="error">❌ <?php echo $login_error; ?></p>
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
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Rotas de API (Ações via AJAX)
if (isset($_REQUEST['action'])) {
    header('Content-Type: application/json');
    $action = $_REQUEST['action'];
    $user_id = (int)($_REQUEST['user_id'] ?? 0);
    $response = ['success' => false, 'message' => 'Ação inválida.'];
    
    try {
        if ($action === 'reset_password' && $user_id) {
            $new_password = bin2hex(random_bytes(6));
            $stmt = $pdo->prepare("UPDATE usuarios SET senha = ? WHERE id = ?");
            $stmt->execute([$new_password, $user_id]);
            $response = ['success' => true, 'message' => 'Senha resetada!', 'new_password' => $new_password];
        }
        
        if ($action === 'get_user_games' && $user_id) {
            try {
                // Buscar todos os jogos da tabela real
                $todos_jogos = $pdo->query("SELECT id, nome, appid FROM jogos ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
                
                // Buscar jogos que o usuário possui
                $stmt_owned = $pdo->prepare("SELECT jogo_id FROM usuario_jogos WHERE usuario_id = ?"); 
                $stmt_owned->execute([$user_id]);
                $jogos_do_usuario_ids = $stmt_owned->fetchAll(PDO::FETCH_COLUMN, 0);
                
                // Marcar quais jogos o usuário possui
                foreach ($todos_jogos as &$jogo) { 
                    $jogo['possui'] = in_array($jogo['id'], $jogos_do_usuario_ids); 
                }
                
                $response = ['success' => true, 'games' => $todos_jogos];
            } catch (Exception $e) {
                // Se a tabela de jogos não existir, usar jogos de exemplo
                $todos_jogos = [
                    ['id' => 1, 'nome' => 'Counter-Strike 2', 'appid' => '730', 'possui' => false],
                    ['id' => 2, 'nome' => 'Dota 2', 'appid' => '570', 'possui' => false],
                    ['id' => 3, 'nome' => 'Grand Theft Auto V', 'appid' => '271590', 'possui' => false],
                    ['id' => 4, 'nome' => 'Team Fortress 2', 'appid' => '440', 'possui' => false],
                    ['id' => 5, 'nome' => 'Portal 2', 'appid' => '620', 'possui' => false]
                ];
                $response = ['success' => true, 'games' => $todos_jogos];
            }
        }
        
        if ($action === 'toggle_game_access' && $user_id && ($jogo_id = (int)($_POST['jogo_id'] ?? 0))) {
            $status = $_POST['status'] ?? '';
            try {
                if ($status === 'grant') {
                    $pdo->prepare("INSERT IGNORE INTO usuario_jogos (usuario_id, jogo_id) VALUES (?, ?)")->execute([$user_id, $jogo_id]);
                } elseif ($status === 'revoke') {
                    $pdo->prepare("DELETE FROM usuario_jogos WHERE usuario_id = ? AND jogo_id = ?")->execute([$user_id, $jogo_id]);
                }
                $response = ['success' => true];
            } catch (Exception $e) {
                $response = ['success' => false, 'message' => 'Erro ao atualizar jogo: ' . $e->getMessage()];
            }
        }
        
        if ($action === 'bulk_toggle_games' && $user_id) {
            $grant_all = $_POST['grant_all'] === 'true';
            try {
                if ($grant_all) {
                    // Dar acesso a todos os jogos
                    $pdo->prepare("
                        INSERT IGNORE INTO usuario_jogos (usuario_id, jogo_id) 
                        SELECT ?, id FROM jogos
                    ")->execute([$user_id]);
                } else {
                    // Remover acesso a todos os jogos
                    $pdo->prepare("DELETE FROM usuario_jogos WHERE usuario_id = ?")->execute([$user_id]);
                }
                $response = ['success' => true];
            } catch (Exception $e) {
                $response = ['success' => false, 'message' => 'Erro na ação em massa: ' . $e->getMessage()];
            }
        }
        
    } catch (Exception $e) { 
        $response['message'] = 'Erro: ' . $e->getMessage(); 
    }
    
    echo json_encode($response);
    exit;
}

// Ações POST (Recarregam a página)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action'])) {
    $form_action = $_POST['form_action']; 
    $user_id = (int)($_POST['user_id'] ?? 0);
    
    if ($user_id) {
        try {
            if ($form_action === 'toggle_user_status') {
                $pdo->prepare("UPDATE usuarios SET ativo = NOT ativo WHERE id = ?")->execute([$user_id]);
            }
            if ($form_action === 'toggle_client_status') {
                // Adicionar coluna is_client se não existir
                try {
                    $pdo->exec("ALTER TABLE usuarios ADD COLUMN is_client TINYINT(1) DEFAULT 0");
                } catch (Exception $e) {
                    // Coluna já existe
                }
                $pdo->prepare("UPDATE usuarios SET is_client = NOT COALESCE(is_client, 0) WHERE id = ?")->execute([$user_id]);
            }
            if ($form_action === 'toggle_admin_status') {
                // Adicionar coluna is_admin se não existir
                try {
                    $pdo->exec("ALTER TABLE usuarios ADD COLUMN is_admin TINYINT(1) DEFAULT 0");
                } catch (Exception $e) {
                    // Coluna já existe
                }
                $pdo->prepare("UPDATE usuarios SET is_admin = NOT COALESCE(is_admin, 0) WHERE id = ?")->execute([$user_id]);
            }
            if ($form_action === 'unlink_mac') {
                $pdo->prepare("DELETE FROM user_sessions WHERE usuario_id = ?")->execute([$user_id]);
            }
            if ($form_action === 'delete_user') {
                $pdo->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$user_id]);
            }
        } catch (Exception $e) {
            // Erro silencioso para não quebrar o fluxo
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']); 
    exit;
}

// Dados para a página
try {
    $users = $pdo->query("
        SELECT u.id, u.login, u.email, u.ativo, 
               COALESCE(u.is_client, 0) as is_client, 
               COALESCE(u.is_admin, 0) as is_admin, 
               us.mac_address,
               (SELECT COUNT(*) FROM usuario_jogos uj WHERE uj.usuario_id = u.id) as total_games
        FROM usuarios u 
        LEFT JOIN user_sessions us ON u.id = us.usuario_id 
        ORDER BY u.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Se a tabela usuario_jogos não existir, buscar usuários sem contar jogos
    try {
        $users = $pdo->query("
            SELECT u.id, u.login, u.email, u.ativo, 
                   COALESCE(u.is_client, 0) as is_client, 
                   COALESCE(u.is_admin, 0) as is_admin, 
                   us.mac_address,
                   0 as total_games
            FROM usuarios u 
            LEFT JOIN user_sessions us ON u.id = us.usuario_id 
            ORDER BY u.id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {
        $users = [];
    }
}

// Estatísticas
try {
    $stats = [
        'total_users' => $pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn(),
        'active_users' => $pdo->query("SELECT COUNT(*) FROM usuarios WHERE ativo = 1")->fetchColumn(),
        'total_clients' => $pdo->query("SELECT COUNT(*) FROM usuarios WHERE COALESCE(is_client, 0) = 1")->fetchColumn(),
        'total_admins' => $pdo->query("SELECT COUNT(*) FROM usuarios WHERE COALESCE(is_admin, 0) = 1")->fetchColumn(),
        'total_games' => $pdo->query("SELECT COUNT(*) FROM jogos")->fetchColumn(),
    ];
} catch (Exception $e) {
    $stats = [
        'total_users' => count($users),
        'active_users' => count(array_filter($users, fn($u) => $u['ativo'] == 1)),
        'total_clients' => count(array_filter($users, fn($u) => $u['is_client'] == 1)),
        'total_admins' => count(array_filter($users, fn($u) => $u['is_admin'] == 1)),
        'total_games' => 10, // Fallback se a tabela de jogos não existir
    ];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>COMPREJOGOS - Painel Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { 
            --primary: #667eea; 
            --primary-dark: #5a6fd8;
            --secondary: #764ba2;
            --danger: #dc2626; 
            --success: #16a34a; 
            --warning: #d97706; 
            --info: #2563eb; 
            --bg-light: #f8fafc; 
            --bg-dark: #f1f5f9; 
            --text-light: #64748b; 
            --text-dark: #1e293b; 
            --purple: #7c3aed; 
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "Segoe UI", system-ui, sans-serif; background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); min-height: 100vh; color: var(--text-dark); }
        .header { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); padding: 1.5rem 2rem; margin-bottom: 2rem; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1); }
        .header-content { max-width: 1600px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { color: var(--primary); font-size: 2rem; font-weight: 700; }
        .header .subtitle { color: var(--text-light); font-size: 1rem; margin-top: 0.25rem; }
        .logout-btn { background: var(--danger); color: white; padding: 0.75rem 1.5rem; border: none; border-radius: 8px; text-decoration: none; font-weight: 600; transition: all 0.3s; display: flex; align-items: center; gap: 0.5rem; }
        .logout-btn:hover { background: #b91c1c; transform: translateY(-2px); }
        .container { padding: 0 2rem 2rem; max-width: 1600px; margin: auto; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .stat-card { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); padding: 1.5rem; border-radius: 12px; text-align: center; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1); transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-number { font-size: 2.5rem; font-weight: 800; color: var(--primary); }
        .stat-label { color: var(--text-light); font-weight: 600; margin-top: 0.5rem; }
        .card { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); border-radius: 12px; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1); overflow: hidden; }
        .card-header { padding: 1.5rem 2rem; border-bottom: 1px solid var(--bg-dark); font-size: 1.25rem; font-weight: 700; background: linear-gradient(45deg, var(--primary), var(--secondary)); color: white; display: flex; justify-content: space-between; align-items: center; }
        .card-body { padding: 0; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 1rem 1.5rem; text-align: left; border-bottom: 1px solid var(--bg-dark); vertical-align: middle; white-space: nowrap; }
        th { font-size: 0.875rem; text-transform: uppercase; color: var(--text-light); font-weight: 700; background: var(--bg-dark); }
        .actions-cell { display: flex; flex-wrap: wrap; gap: 0.5rem; min-width: 600px; }
        .action-btn { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.6rem 1rem; border: none; border-radius: 8px; cursor: pointer; color: white; font-weight: 600; font-size: 0.875rem; transition: all 0.3s; text-decoration: none; white-space: nowrap; }
        .action-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2); }
        .action-btn i { font-size: 1rem; }
        .btn-library { background: var(--info); }
        .btn-status { background: var(--warning); color: var(--text-dark); }
        .btn-client { background: var(--purple); }
        .btn-admin { background: var(--danger); }
        .btn-password { background: #64748b; }
        .btn-unlink { background: #0f766e; }
        .btn-delete { background: var(--text-dark); }
        .status { padding: 0.4rem 0.8rem; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .status-active { background: #dcfce7; color: #15803d; } 
        .status-inactive { background: #fee2e2; color: #b91c1c; }
        .role-badge { padding: 0.4rem 0.8rem; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .role-admin { background: #fecaca; color: #991b1b; } 
        .role-client { background: #dbeafe; color: #1e40af; } 
        .role-user { background: #e5e7eb; color: #4b5563; }
        .user-info { display: flex; flex-direction: column; }
        .user-email { color: var(--text-light); font-size: 0.875rem; }
        .games-count { display: inline-flex; align-items: center; gap: 0.25rem; background: var(--info); color: white; padding: 0.25rem 0.5rem; border-radius: 12px; font-size: 0.75rem; font-weight: 600; }
        .mac-address { font-family: 'Courier New', monospace; background: var(--bg-dark); padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; color: var(--text-dark); }
        .mac-none { color: var(--text-light); font-style: italic; }
        
        /* Pesquisa */
        .search-container { position: relative; display: flex; align-items: center; }
        .search-input { padding: 0.75rem 1rem 0.75rem 2.5rem; border: 2px solid var(--bg-dark); border-radius: 25px; font-size: 0.875rem; width: 300px; transition: all 0.3s; background: white; }
        .search-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); width: 350px; }
        .search-icon { position: absolute; left: 1rem; color: var(--text-light); z-index: 2; }
        .search-results { position: absolute; top: 100%; right: 0; background: white; border-radius: 8px; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1); padding: 0.5rem; font-size: 0.75rem; color: var(--text-light); min-width: 200px; display: none; }
        .table-row-hidden { display: none !important; }
        .game-item-hidden { display: none !important; }
        .highlight { background: yellow; padding: 0.1rem 0.2rem; border-radius: 3px; }
        
        /* Modal */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); display: none; justify-content: center; align-items: center; z-index: 1000; }
        .modal-content { background: white; padding: 2rem; border-radius: 12px; max-width: 700px; width: 90%; max-height: 80vh; overflow-y: auto; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 2px solid var(--bg-dark); }
        .modal-header h2 { color: var(--primary); font-size: 1.5rem; }
        .modal-close { background: none; border: none; font-size: 2rem; cursor: pointer; color: #aaa; transition: color 0.3s; }
        .modal-close:hover { color: var(--danger); }
        .library-controls { margin-bottom: 1.5rem; display: flex; flex-direction: column; gap: 1rem; }
        .library-search-container { display: flex; justify-content: center; }
        .bulk-actions { padding: 1rem; background: var(--bg-light); border-radius: 8px; display: flex; gap: 1rem; justify-content: center; }
        .bulk-btn { padding: 0.75rem 1.5rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; gap: 0.5rem; }
        .bulk-select-all { background: var(--success); color: white; }
        .bulk-deselect-all { background: var(--warning); color: white; }
        .bulk-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2); }
        .game-list { max-height: 400px; overflow-y: auto; }
        .game-list label { display: flex; align-items: center; padding: 1rem; border-radius: 8px; transition: all 0.3s; cursor: pointer; margin-bottom: 0.5rem; border: 2px solid transparent; }
        .game-list label:hover { background: var(--bg-light); border-color: var(--primary); }
        .game-list input[type="checkbox"] { transform: scale(1.5); margin-right: 1rem; accent-color: var(--primary); }
        .game-info { flex: 1; }
        .game-name { font-weight: 600; color: var(--text-dark); }
        .game-appid { color: var(--text-light); font-size: 0.875rem; margin-top: 0.25rem; }
        .no-results { color: var(--text-light); text-align: center; padding: 2rem; font-style: italic; }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <div>
                <h1>🎮 COMPREJOGOS</h1>
                <div class="subtitle">Painel de Administração</div>
            </div>
            <a href="?logout=1" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i>
                Sair do Sistema
            </a>
        </div>
    </div>

    <div class="container">
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_users']; ?></div>
                <div class="stat-label">Total de Usuários</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['active_users']; ?></div>
                <div class="stat-label">Usuários Ativos</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_clients']; ?></div>
                <div class="stat-label">Clientes Autorizados</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_admins']; ?></div>
                <div class="stat-label">Administradores</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_games']; ?></div>
                <div class="stat-label">Jogos Disponíveis</div>
            </div>
        </div>

        <!-- Tabela de Usuários -->
        <div class="card">
            <div class="card-header">
                <div>
                    <i class="fas fa-users"></i> Gerenciamento de Usuários
                </div>
                <div class="search-container">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" id="userSearch" placeholder="Pesquisar usuários..." class="search-input">
                    <div class="search-results" id="searchResults"></div>
                </div>
            </div>
            <div class="card-body">
                <table>
                    <thead>
                        <tr>
                            <th>Usuário</th>
                            <th>Status</th>
                            <th>Função</th>
                            <th>Jogos</th>
                            <th>MAC Vinculado</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td>
                                    <div class="user-info">
                                        <div><?php echo htmlspecialchars($user['login']); ?></div>
                                        <div class="user-email"><?php echo htmlspecialchars($user['email']); ?></div>
                                    </div>
                                </td>
                                <td>
                                    <span class="status <?php echo $user['ativo'] ? 'status-active' : 'status-inactive'; ?>">
                                        <?php echo $user['ativo'] ? 'Ativo' : 'Inativo'; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                        $role = 'user';
                                        if ($user['is_admin']) $role = 'admin';
                                        elseif ($user['is_client']) $role = 'client';
                                    ?>
                                    <span class="role-badge role-<?php echo $role; ?>">
                                        <?php 
                                            if ($role == 'admin') echo 'Admin';
                                            elseif ($role == 'client') echo 'Cliente';
                                            else echo 'Usuário';
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="games-count">
                                        <i class="fas fa-gamepad"></i>
                                        <?php echo $user['total_games']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($user['mac_address']): ?>
                                        <span class="mac-address"><?php echo htmlspecialchars($user['mac_address']); ?></span>
                                    <?php else: ?>
                                        <span class="mac-none">Nenhum</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions-cell">
                                    <button type="button" class="action-btn btn-library" 
                                            data-userid="<?php echo $user['id']; ?>" 
                                            data-username="<?php echo htmlspecialchars($user['login']); ?>">
                                        <i class="fas fa-gamepad"></i> Biblioteca
                                    </button>
                                    
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" name="form_action" value="toggle_user_status" 
                                                class="action-btn btn-status">
                                            <i class="fas fa-power-off"></i> 
                                            <?php echo $user['ativo'] ? 'Inativar' : 'Ativar'; ?>
                                        </button>
                                    </form>
                                    
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" name="form_action" value="toggle_client_status" 
                                                class="action-btn btn-client">
                                            <i class="fas fa-crown"></i> 
                                            <?php echo $user['is_client'] ? 'Remover Cliente' : 'Tornar Cliente'; ?>
                                        </button>
                                    </form>
                                    
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" name="form_action" value="toggle_admin_status" 
                                                class="action-btn btn-admin">
                                            <i class="fas fa-user-shield"></i> 
                                            <?php echo $user['is_admin'] ? 'Rebaixar' : 'Promover'; ?>
                                        </button>
                                    </form>
                                    
                                    <button type="button" class="action-btn btn-password reset-password-btn" 
                                            data-userid="<?php echo $user['id']; ?>" 
                                            data-username="<?php echo htmlspecialchars($user['login']); ?>">
                                        <i class="fas fa-key"></i> Reset Senha
                                    </button>
                                    
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" name="form_action" value="unlink_mac" 
                                                class="action-btn btn-unlink">
                                            <i class="fas fa-unlink"></i> Desvincular
                                        </button>
                                    </form>
                                    
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('EXCLUIR usuário permanentemente?')">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" name="form_action" value="delete_user" 
                                                class="action-btn btn-delete">
                                            <i class="fas fa-trash"></i> Excluir
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal da Biblioteca -->
    <div class="modal-overlay" id="gamesModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalUserName">Biblioteca do Usuário</h2>
                <button class="modal-close" id="modalCloseBtn">&times;</button>
            </div>
            
            <div class="library-controls">
                <div class="library-search-container">
                    <div class="search-container">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="gameSearch" placeholder="Pesquisar jogos..." class="search-input">
                    </div>
                </div>
                
                <div class="bulk-actions">
                    <button class="bulk-btn bulk-select-all" id="selectAllBtn">
                        <i class="fas fa-check-double"></i> Selecionar Todos
                    </button>
                    <button class="bulk-btn bulk-deselect-all" id="deselectAllBtn">
                        <i class="fas fa-times-circle"></i> Desmarcar Todos
                    </button>
                </div>
            </div>
            
            <div class="game-list" id="modalGameList">
                <p style="text-align: center; padding: 2rem;">Carregando jogos...</p>
            </div>
        </div>
    </div>

    <script>
        console.log('🎮 Carregando painel administrativo...');
        
        // Elementos DOM
        const modalOverlay = document.getElementById('gamesModal');
        const modalUserName = document.getElementById('modalUserName');
        const modalGameList = document.getElementById('modalGameList');
        const modalCloseBtn = document.getElementById('modalCloseBtn');
        const selectAllBtn = document.getElementById('selectAllBtn');
        const deselectAllBtn = document.getElementById('deselectAllBtn');
        const userSearchInput = document.getElementById('userSearch');
        const gameSearchInput = document.getElementById('gameSearch');
        const searchResults = document.getElementById('searchResults');
        
        let currentUserId = null;
        
        // Função para abrir modal da biblioteca
        function openLibraryModal(button) {
            try {
                currentUserId = button.dataset.userid;
                const userName = button.dataset.username || 'Usuário';
                modalUserName.textContent = `Biblioteca de ${userName}`;
                modalGameList.innerHTML = '<p style="text-align: center; padding: 2rem;"><i class="fas fa-spinner fa-spin"></i> Carregando jogos...</p>';
                modalOverlay.style.display = 'flex';
                
                if (gameSearchInput) gameSearchInput.value = '';
                
                fetch(`?action=get_user_games&user_id=${currentUserId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data && data.success) {
                            renderGameList(data.games || []);
                        } else {
                            modalGameList.innerHTML = '<p style="text-align: center; color: var(--danger); padding: 2rem;">❌ Erro ao carregar jogos</p>';
                        }
                    })
                    .catch(() => {
                        modalGameList.innerHTML = '<p style="text-align: center; color: var(--danger); padding: 2rem;">❌ Erro de conexão</p>';
                    });
            } catch (error) {
                console.error('Erro ao abrir modal:', error);
            }
        }
        
        // Função para renderizar lista de jogos
        function renderGameList(games) {
            modalGameList.innerHTML = '';
            
            if (!games || games.length === 0) {
                modalGameList.innerHTML = '<p style="text-align: center; padding: 2rem; color: var(--text-light);">📱 Nenhum jogo disponível</p>';
                return;
            }
            
            games.forEach(game => {
                if (!game || !game.nome) return;
                
                const label = document.createElement('label');
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.checked = game.possui || false;
                checkbox.dataset.gameid = game.id || '';
                
                const gameInfoDiv = document.createElement('div');
                gameInfoDiv.className = 'game-info';
                gameInfoDiv.innerHTML = `
                    <div class="game-name">${escapeHtml(game.nome)}</div>
                    <div class="game-appid">AppID: ${escapeHtml(game.appid || 'N/A')}</div>
                `;
                
                label.appendChild(checkbox);
                label.appendChild(gameInfoDiv);
                modalGameList.appendChild(label);
                
                checkbox.addEventListener('change', () => {
                    toggleGameAccess(game.id, checkbox.checked);
                });
            });
        }
        
        // Função para filtrar jogos
        function filterGames(searchTerm) {
            try {
                const gameLabels = modalGameList.querySelectorAll('label');
                let visibleCount = 0;
                
                gameLabels.forEach(label => {
                    const gameName = label.querySelector('.game-name')?.textContent?.toLowerCase() || '';
                    const gameAppId = label.querySelector('.game-appid')?.textContent?.toLowerCase() || '';
                    const searchableText = `${gameName} ${gameAppId}`;
                    
                    if (searchTerm === '' || searchableText.includes(searchTerm)) {
                        label.classList.remove('game-item-hidden');
                        visibleCount++;
                    } else {
                        label.classList.add('game-item-hidden');
                    }
                });
                
                if (searchTerm !== '' && visibleCount === 0) {
                    const existingNoResults = modalGameList.querySelector('.no-results');
                    if (existingNoResults) existingNoResults.remove();
                    
                    const noResultsDiv = document.createElement('div');
                    noResultsDiv.className = 'no-results';
                    noResultsDiv.innerHTML = `
                        <i class="fas fa-search" style="font-size: 2rem; color: var(--text-light); margin-bottom: 1rem;"></i>
                        <div>Nenhum jogo encontrado para "${searchTerm}"</div>
                    `;
                    modalGameList.appendChild(noResultsDiv);
                } else {
                    const existingNoResults = modalGameList.querySelector('.no-results');
                    if (existingNoResults) existingNoResults.remove();
                }
            } catch (error) {
                console.error('Erro no filtro de jogos:', error);
            }
        }
        
        // Função para filtrar usuários
        function filterUsers(searchTerm) {
            try {
                const tableRows = document.querySelectorAll('tbody tr');
                let visibleCount = 0;
                
                tableRows.forEach(row => {
                    const login = row.querySelector('td:nth-child(1) .user-info div:first-child')?.textContent?.toLowerCase() || '';
                    const email = row.querySelector('td:nth-child(1) .user-email')?.textContent?.toLowerCase() || '';
                    const status = row.querySelector('td:nth-child(2) .status')?.textContent?.toLowerCase() || '';
                    const role = row.querySelector('td:nth-child(3) .role-badge')?.textContent?.toLowerCase() || '';
                    const mac = row.querySelector('td:nth-child(5)')?.textContent?.toLowerCase() || '';
                    
                    const searchableText = `${login} ${email} ${status} ${role} ${mac}`;
                    
                    if (searchTerm === '' || searchableText.includes(searchTerm)) {
                        row.classList.remove('table-row-hidden');
                        visibleCount++;
                    } else {
                        row.classList.add('table-row-hidden');
                    }
                });
                
                updateSearchResults(visibleCount, tableRows.length, searchTerm);
            } catch (error) {
                console.error('Erro no filtro de usuários:', error);
            }
        }
        
        // Função para atualizar resultados da pesquisa
        function updateSearchResults(visible, total, searchTerm) {
            if (!searchResults) return;
            
            if (searchTerm === '') {
                searchResults.style.display = 'none';
                return;
            }
            
            searchResults.style.display = 'block';
            if (visible === 0) {
                searchResults.innerHTML = '❌ Nenhum usuário encontrado';
                searchResults.style.color = 'var(--danger)';
            } else {
                searchResults.innerHTML = `✅ ${visible} de ${total} usuários`;
                searchResults.style.color = 'var(--success)';
            }
            
            setTimeout(() => {
                if (searchResults.style.display === 'block') {
                    searchResults.style.display = 'none';
                }
            }, 3000);
        }
        
        // Função para toggle de acesso a jogo individual
        function toggleGameAccess(gameId, hasAccess) {
            if (!gameId || !currentUserId) {
                console.error('Game ID ou User ID inválido');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'toggle_game_access');
            formData.append('user_id', currentUserId);
            formData.append('jogo_id', gameId);
            formData.append('status', hasAccess ? 'grant' : 'revoke');
            
            fetch('', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (!data || !data.success) {
                        console.error('Erro ao atualizar jogo:', data?.message || 'Erro desconhecido');
                        // Reverter checkbox se houver erro
                        const checkbox = document.querySelector(`input[data-gameid="${gameId}"]`);
                        if (checkbox) {
                            checkbox.checked = !hasAccess;
                        }
                    }
                })
                .catch(error => {
                    console.error('Erro de conexão:', error);
                    // Reverter checkbox se houver erro
                    const checkbox = document.querySelector(`input[data-gameid="${gameId}"]`);
                    if (checkbox) {
                        checkbox.checked = !hasAccess;
                    }
                });
        }
        
        // Função para toggle em massa
        function bulkToggleGames(grantAll) {
            if (!currentUserId) {
                console.error('User ID não disponível');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'bulk_toggle_games');
            formData.append('user_id', currentUserId);
            formData.append('grant_all', grantAll.toString());
            
            // Mostrar loading nos botões
            const buttons = document.querySelectorAll('.bulk-btn');
            buttons.forEach(btn => {
                btn.disabled = true;
                btn.style.opacity = '0.6';
            });
            
            fetch('', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data && data.success) {
                        // Atualizar apenas checkboxes visíveis
                        const visibleCheckboxes = modalGameList.querySelectorAll('label:not(.game-item-hidden) input[type="checkbox"]');
                        visibleCheckboxes.forEach(cb => cb.checked = grantAll);
                    } else {
                        console.error('Erro em ação em massa:', data?.message || 'Erro desconhecido');
                    }
                })
                .catch(error => {
                    console.error('Erro de conexão:', error);
                })
                .finally(() => {
                    // Restaurar botões
                    buttons.forEach(btn => {
                        btn.disabled = false;
                        btn.style.opacity = '1';
                    });
                });
        }
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        }
        
        // Event Listeners
        document.querySelectorAll('.btn-library').forEach(btn => {
            btn.addEventListener('click', () => openLibraryModal(btn));
        });
        
        if (modalCloseBtn) {
            modalCloseBtn.addEventListener('click', () => {
                modalOverlay.style.display = 'none';
                if (gameSearchInput) gameSearchInput.value = '';
            });
        }
        
        if (modalOverlay) {
            modalOverlay.addEventListener('click', e => {
                if (e.target === modalOverlay) {
                    modalOverlay.style.display = 'none';
                    if (gameSearchInput) gameSearchInput.value = '';
                }
            });
        }
        
        if (selectAllBtn) {
            selectAllBtn.addEventListener('click', () => {
                bulkToggleGames(true);
            });
        }
        
        if (deselectAllBtn) {
            deselectAllBtn.addEventListener('click', () => {
                bulkToggleGames(false);
            });
        }
        
        if (userSearchInput) {
            userSearchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase().trim();
                filterUsers(searchTerm);
            });
        }
        
        if (gameSearchInput) {
            gameSearchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase().trim();
                filterGames(searchTerm);
            });
        }
        
        document.querySelectorAll('.reset-password-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const userId = this.dataset.userid;
                const userName = this.dataset.username || 'Usuário';
                
                if (!confirm(`🔑 Resetar a senha de '${userName}'?`)) return;
                
                const originalContent = this.innerHTML;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...';
                this.disabled = true;
                
                fetch(`?action=reset_password&user_id=${userId}`, { method: 'POST' })
                    .then(response => response.json())
                    .then(data => {
                        if (data && data.success) {
                            alert(`✅ Senha resetada!\n\nUsuário: ${userName}\nNova Senha: ${data.new_password}\n\n📋 Copie esta senha e envie para o usuário.`);
                        } else {
                            alert(`❌ Erro: ${data.message || 'Erro desconhecido'}`);
                        }
                    })
                    .catch(() => {
                        alert('❌ Erro de conexão');
                    })
                    .finally(() => {
                        this.innerHTML = originalContent;
                        this.disabled = false;
                    });
            });
        });
        
        console.log('✅ Painel administrativo carregado com sucesso!');
    </script>
</body>
</html>