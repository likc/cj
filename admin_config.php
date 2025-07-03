<?php
// admin_config.php
$senha_plana_para_configurar = 'admin123'; // Defina sua senha inicial aqui

// ====================================================================
$config_file = __DIR__ . '/admin_password.json';
function get_password_hash_from_config($file) {
    if (!file_exists($file)) return false;
    $config = json_decode(file_get_contents($file), true);
    return $config['password_hash'] ?? false;
}
function set_password_hash_in_config($file, $plain_password) {
    $hash = password_hash($plain_password, PASSWORD_DEFAULT);
    file_put_contents($file, json_encode(['password_hash' => $hash]));
    return $hash;
}
if (!file_exists($config_file)) {
    set_password_hash_in_config($config_file, $senha_plana_para_configurar);
}
?>