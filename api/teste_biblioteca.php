<?php
// api/teste_biblioteca.php - Ferramenta de Diagnóstico

echo "<style>body { font-family: sans-serif; padding: 20px; } .success { color: green; } .error { color: red; }</style>";
echo "<h1>Diagnóstico da API de Biblioteca</h1>";

// --- CONFIGURAÇÕES ---
$db_host = 'localhost';
$db_name = 'minec761_comprejogos';
$db_user = 'minec761_comprejogos';
$db_pass = 'pr9n0xz5zxk2';

try {
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("<p class='error'>Falha na conexão com o banco de dados: " . $e->getMessage() . "</p>");
}

// 1. Pega a sessão mais recente do usuário 'likc' para o teste
$stmt = $pdo->prepare("SELECT s.* FROM user_sessions s JOIN usuarios u ON s.usuario_id = u.id WHERE u.login = 'likc' ORDER BY s.created_at DESC LIMIT 1");
$stmt->execute();
$session_data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session_data) {
    die("<p class='error'>Nenhuma sessão ativa encontrada para o usuário 'likc'. Faça login pelo menos uma vez com o cliente .exe para criar uma sessão de teste.</p>");
}

echo "<p><b>Dados de teste encontrados para o usuário 'likc':</b></p>";
echo "<ul>";
echo "<li><b>ID do Usuário:</b> " . htmlspecialchars($session_data['usuario_id']) . "</li>";
echo "<li><b>MAC Address:</b> " . htmlspecialchars($session_data['mac_address']) . "</li>";
echo "<li><b>Session Token:</b> " . htmlspecialchars(substr($session_data['session_token'], 0, 15)) . "...</li>";
echo "</ul>";

// 2. Simula a chamada que o cliente .exe faz
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://likc.net/comprejogos/api/get_user_games.php"); // URL da sua API
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'user_id' => $session_data['usuario_id'],
    'mac_address' => $session_data['mac_address'],
    'session_token' => $session_data['session_token']
]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// 3. Exibe o resultado
echo "<h2>Resultado do Teste:</h2>";
if ($response === false) {
    echo "<p class='error'>Falha na chamada cURL. O servidor pode estar bloqueando requisições locais.</p>";
} else {
    echo "<p><b>Status da Resposta HTTP:</b> " . $http_code . "</p>";
    echo "<p><b>Resposta Bruta do Servidor:</b></p>";
    echo "<pre>" . htmlspecialchars($response) . "</pre>";

    $data = json_decode($response, true);
    if (json_last_error() === JSON_ERROR_NONE && isset($data['success'])) {
        if ($data['success'] === true) {
            $game_count = count($data['games']);
            echo "<h3 class='success'>SUCESSO! A API retornou {$game_count} jogo(s) corretamente.</h3>";
        } else {
            echo "<h3 class='error'>FALHA. A API retornou um erro: " . htmlspecialchars($data['message']) . "</h3>";
        }
    } else {
        echo "<h3 class='error'>FALHA. A resposta do servidor não é um JSON válido. Verifique o arquivo get_user_games.php por erros.</h3>";
    }
}
?>