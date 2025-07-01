<?php
require_once 'config.php';
setSecurityHeaders();

$message = '';
$message_type = '';

if (isset($_POST['cadastrar'])) {
    $login = trim($_POST['login'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $confirmar_senha = $_POST['confirmar_senha'] ?? '';
    
    // Validações
    if (empty($login) || empty($email) || empty($senha)) {
        $message = 'Todos os campos são obrigatórios!';
        $message_type = 'error';
    } elseif (strlen($login) < 3) {
        $message = 'O login deve ter pelo menos 3 caracteres!';
        $message_type = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Email inválido!';
        $message_type = 'error';
    } elseif ($senha !== $confirmar_senha) {
        $message = 'As senhas não coincidem!';
        $message_type = 'error';
    } elseif (strlen($senha) < 6) {
        $message = 'A senha deve ter pelo menos 6 caracteres!';
        $message_type = 'error';
    } else {
        try {
            $pdo = conectarBanco();
            
            if (!$pdo) {
                throw new Exception('Erro de conexão com banco de dados');
            }
            
            // Verificar se login já existe
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE login = ?");
            $stmt->execute([$login]);
            if ($stmt->fetch()) {
                $message = 'Este login já está em uso!';
                $message_type = 'error';
            } else {
                // Verificar se email já existe
                $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $message = 'Este email já está cadastrado!';
                    $message_type = 'error';
                } else {
                    // Inserir usuário
                    $stmt = $pdo->prepare("INSERT INTO usuarios (login, email, senha, ativo, created_at) VALUES (?, ?, ?, 1, NOW())");
                    if ($stmt->execute([$login, $email, $senha])) {
                        $message = 'Usuário cadastrado com sucesso! Você já pode fazer login no programa.';
                        $message_type = 'success';
                        
                        // Log do cadastro
                        logError("Novo usuário cadastrado", ['login' => $login, 'email' => $email]);
                        
                        // Limpar campos após sucesso
                        $login = $email = '';
                    } else {
                        $message = 'Erro ao cadastrar usuário!';
                        $message_type = 'error';
                    }
                }
            }
        } catch (Exception $e) {
            logError("Erro no cadastro: " . $e->getMessage(), ['login' => $login, 'email' => $email]);
            $message = 'Erro interno do servidor. Tente novamente mais tarde.';
            $message_type = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>COMPREJOGOS - Cadastro</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: rgba(255, 255, 255, 0.95);
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 450px;
            backdrop-filter: blur(10px);
        }
        
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo h1 {
            background: linear-gradient(45deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 2.5em;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .logo p {
            color: #666;
            font-size: 1.1em;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 600;
            font-size: 14px;
        }
        
        input[type="text"], input[type="email"], input[type="password"] {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e1e1e1;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }
        
        input[type="text"]:focus, input[type="email"]:focus, input[type="password"]:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .message {
            margin-top: 15px;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            font-weight: 500;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .links {
            text-align: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        
        .links a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
            margin: 0 10px;
            transition: color 0.3s ease;
        }
        
        .links a:hover {
            color: #764ba2;
            text-decoration: underline;
        }
        
        .requirements {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            color: #666;
        }
        
        .requirements h4 {
            color: #333;
            margin-bottom: 8px;
        }
        
        .requirements ul {
            margin-left: 20px;
        }
        
        .requirements li {
            margin-bottom: 3px;
        }
        
        .footer {
            text-align: center;
            margin-top: 20px;
            font-size: 12px;
            color: #888;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">
            <h1>COMPREJOGOS</h1>
            <p>Cadastro de Usuário</p>
        </div>
        
        <div class="requirements">
            <h4>📋 Requisitos:</h4>
            <ul>
                <li>Login: mínimo 3 caracteres</li>
                <li>Email: deve ser válido</li>
                <li>Senha: mínimo 6 caracteres</li>
                <li>1 conta por computador</li>
            </ul>
        </div>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="login">👤 Login:</label>
                <input type="text" id="login" name="login" value="<?php echo htmlspecialchars($login ?? ''); ?>" required maxlength="50">
            </div>
            
            <div class="form-group">
                <label for="email">📧 Email:</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email ?? ''); ?>" required maxlength="100">
            </div>
            
            <div class="form-group">
                <label for="senha">🔒 Senha:</label>
                <input type="password" id="senha" name="senha" required maxlength="100">
            </div>
            
            <div class="form-group">
                <label for="confirmar_senha">🔒 Confirmar Senha:</label>
                <input type="password" id="confirmar_senha" name="confirmar_senha" required maxlength="100">
            </div>
            
            <button type="submit" name="cadastrar" class="btn">Cadastrar</button>
        </form>
        
        <?php if (!empty($message)): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <div class="links">
            <a href="https://comprejogos.com">🏠 Site Principal</a>
            <a href="mailto:suporte@comprejogos.com">📞 Suporte</a>
        </div>
        
        <div class="footer">
            <p>&copy; 2025 COMPREJOGOS - Todos os direitos reservados</p>
        </div>
    </div>
    
    <script>
        // Validação em tempo real
        document.getElementById('confirmar_senha').addEventListener('input', function() {
            const senha = document.getElementById('senha').value;
            const confirmar = this.value;
            
            if (senha !== confirmar) {
                this.style.borderColor = '#dc3545';
            } else {
                this.style.borderColor = '#28a745';
            }
        });
        
        // Auto-hide messages após 5 segundos
        setTimeout(function() {
            const message = document.querySelector('.message');
            if (message) {
                message.style.opacity = '0';
                setTimeout(() =>