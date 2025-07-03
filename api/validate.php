<?php
// api/validate.php

header('Content-Type: application/json; charset=utf-8');

// Configurações do banco
$db_host = 'localhost';
$db_name = 'minec761_comprejogos';
$db_user = 'minec761_comprejogos';
$db_pass = 'pr9n0xz5zxk2';

// Obter dados
$session_token = $_POST['session_token'] ?? '';
$user_id = (int)($_POST['user_id'] ?? 0);
$mac_address = $_POST['mac_address'] ?? '';

if (empty($session_token) || empty($user_id) || empty($mac_address)) {
    echo json_encode(['success' => false, 'message' => 'Parâmetros ausentes.']);
    exit;
}

try {
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("SELECT u.id FROM user_sessions us JOIN usuarios u ON us.usuario_id = u.id WHERE us.session_token = ? AND us.usuario_id = ? AND us.mac_address = ? AND us.expires_at > NOW() AND u.ativo = 1 AND u.is_client = 1");
    $stmt->execute([$session_token, $user_id, $mac_address]);
    
    if ($stmt->fetch()) {
        echo json_encode(['success' => true, 'is_client' => true, 'message' => 'Sessão válida.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Sessão inválida ou expirada.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro interno do servidor.']);
}
?>