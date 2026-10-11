<?php
/**
 * NotaNova - Cambio de contraseña propio
 *
 * Procesa el formulario "Cambiar contraseña" que comparten los tres portales.
 * Valida la contraseña actual, aplica la nueva y redirige al portal con un
 * mensaje flash.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

requiere_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

verificar_csrf();

$actual   = $_POST['password_actual'] ?? '';
$nueva    = $_POST['password_nueva'] ?? '';
$confirmar = $_POST['password_confirmar'] ?? '';

// --- Validaciones de la nueva contraseña ---
if ($actual === '' || $nueva === '' || $confirmar === '') {
    flash_error('Debes completar todos los campos.');
    header('Location: index.php');
    exit;
}

if (strlen($nueva) < 6) {
    flash_error('La nueva contraseña debe tener al menos 6 caracteres.');
    header('Location: index.php');
    exit;
}

if ($nueva !== $confirmar) {
    flash_error('La confirmación no coincide con la nueva contraseña.');
    header('Location: index.php');
    exit;
}

// --- Verificar la contraseña actual ---
try {
    $stmt = $pdo->prepare("SELECT id, password_hash FROM usuarios WHERE id = ?");
    $stmt->execute([$_SESSION['usuario_id']]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    flash_error('Error al consultar la cuenta.');
    header('Location: index.php');
    exit;
}

if (!$u || !verificar_contrasena($actual, $u['password_hash'])) {
    flash_error('La contraseña actual es incorrecta.');
    header('Location: index.php');
    exit;
}

// --- Guardar la nueva contraseña ---
try {
    $stmt = $pdo->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?");
    $stmt->execute([hash_contrasena($nueva), $u['id']]);

    flash_exito('Contraseña actualizada correctamente.');
} catch (PDOException $e) {
    flash_error('No se pudo actualizar la contraseña.');
}

header('Location: index.php');
exit;
