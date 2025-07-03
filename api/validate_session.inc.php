<?php
// api/validate_session.inc.php

function validate_session() {
    $db_host = 'localhost';
    $db_name = 'minec761_comprejogos';
    $db_user = 'minec761_comprejogos';
    $db_pass = 'pr9n0xz5zxk2';

    $session_token = $_POST['session_token'] ?? '';
    $user_id = $_POST['user_id'] ?? '';
    $mac_address = $_POST['mac_address'] ?? '';

    if (empty($session_token) || empty($user_id) || empty($mac_address)) {
        return ['success' => false, 'message' => 'Parâmetros de sessão ausentes.'];
    }

    try {
        $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->prepare("
            SELECT usuario_id FROM user_sessions
            WHERE session_token = ? AND usuario_id = ? AND mac_address = ? AND expires_at > NOW()
        ");
        $stmt->execute([$session_token, $user_id, $mac_address]);
        $valid_user_id = $stmt->fetchColumn();

        if ($valid_user_id) {
            return ['success' => true, 'user_id' => $valid_user_id];
        } else {
            return ['success' => false, 'message' => 'Sessão inválida ou expirada.'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Erro de validação no servidor.'];
    }
}