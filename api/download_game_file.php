<?php
// api/download_game_file.php - vFINAL CORRIGIDA

// --- CONFIGURAÇÕES ---
$db_host = 'localhost';
$db_name = 'minec761_comprejogos';
$db_user = 'minec761_comprejogos';
$db_pass = 'pr9n0xz5zxk2';
// CAMINHO ABSOLUTO para a pasta que contém as pastas dos jogos
$base_file_path = '/home2/minec761/public_html/comprejogos/game_files/';

// --- VALIDAÇÃO DE ENTRADA ---
$session_token = $_POST['session_token'] ?? '';
$user_id_from_client = (int)($_POST['user_id'] ?? 0);
$mac_address_from_client = $_POST['mac_address'] ?? '';
$appid = $_POST['appid'] ?? '';
$file_type = $_POST['file_type'] ?? ''; // 'manifest' ou 'lua'

if (empty($session_token) || empty($user_id_from_client) || empty($mac_address_from_client) || empty($appid) || empty($file_type)) {
    http_response_code(401); exit("Autenticação necessária.");
}

// --- LÓGICA PRINCIPAL ---
try {
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Valida a sessão do usuário
    $stmt_session = $pdo->prepare("SELECT usuario_id FROM user_sessions WHERE session_token = ? AND usuario_id = ? AND mac_address = ? AND expires_at > NOW()");
    $stmt_session->execute([$session_token, $user_id_from_client, $mac_address_from_client]);
    $valid_user_id = $stmt_session->fetchColumn();

    if (!$valid_user_id) {
        http_response_code(403); exit("Sessão inválida ou expirada.");
    }

    // 2. Busca o jogo e verifica a permissão
    $stmt_game = $pdo->prepare("SELECT j.nome, j.arquivo_manifest, j.arquivo_lua FROM jogos j JOIN usuario_jogos uj ON j.id = uj.jogo_id WHERE j.appid = ? AND uj.usuario_id = ?");
    $stmt_game->execute([$appid, $valid_user_id]);
    $jogo = $stmt_game->fetch(PDO::FETCH_ASSOC);

    if (!$jogo) {
        http_response_code(403); exit("Permissão negada para este jogo.");
    }

    // 3. Monta o caminho do arquivo
    $filename = ($file_type === 'manifest') ? $jogo['arquivo_manifest'] : $jogo['arquivo_lua'];
    if (!$filename) { http_response_code(404); exit("Arquivo não catalogado."); }
    
    $game_folder_name = $jogo['nome'];
    $full_path = $base_file_path . $game_folder_name . '/' . $filename;
    
    // 4. Entrega o arquivo
    if (file_exists($full_path) && is_readable($full_path)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
        header('Content-Length: ' . filesize($full_path));
        readfile($full_path);
        exit;
    } else {
        http_response_code(404);
        error_log("Arquivo não encontrado no servidor: " . $full_path);
        exit("Arquivo não encontrado.");
    }

} catch (Exception $e) {
    http_response_code(500);
    error_log("API Error (download_game_file): " . $e->getMessage());
    exit("Erro interno do servidor.");
}
?>