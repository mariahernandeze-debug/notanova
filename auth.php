<?php
/**
 * NotaNova - Autenticación y control de acceso
 *
 * Proporciona inicio/cierre de sesión, guards por rol, utilidades de
 * contraseña (bcrypt) y protección CSRF para los formularios.
 */

if (session_status() === PHP_SESSION_NONE) {
    // Cookies de sesión más seguras
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// -----------------------------------------------------------------------------
// Utilidades de contraseña
// -----------------------------------------------------------------------------

function hash_contrasena(string $plain): string
{
    return password_hash($plain, PASSWORD_DEFAULT);
}

function verificar_contrasena(string $plain, string $hash): bool
{
    return password_verify($plain, $hash);
}

// -----------------------------------------------------------------------------
// Protección CSRF
// -----------------------------------------------------------------------------

function token_csrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function campo_csrf(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(token_csrf()) . '">';
}

/**
 * Valida el token CSRF del POST actual. Sale con mensaje si es inválido.
 */
function verificar_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        die('Solicitud no válida (token CSRF). Vuelve atrás e inténtalo de nuevo.');
    }
}

// -----------------------------------------------------------------------------
// Sesión
// -----------------------------------------------------------------------------

/**
 * Registra la sesión de un usuario. Regenera el ID para evitar session fixation.
 * Espera un arreglo con: id, usuario, rol, user_id (estudiante/docente enlazado) y nombre.
 */
function iniciar_sesion(array $usuario): void
{
    session_regenerate_id(true);

    $_SESSION['usuario_id'] = $usuario['id'];
    $_SESSION['usuario']    = $usuario['usuario'];
    $_SESSION['rol']        = $usuario['rol'];
    $_SESSION['user_id']    = $usuario['user_id'] ?? null;
    $_SESSION['nombre']     = $usuario['nombre'] ?? $usuario['usuario'];
    // Se limpia el token CSRF al cambiar de identidad
    unset($_SESSION['csrf_token']);
}

function cerrar_sesion(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }

    session_destroy();
}

function usuario_logueado(): bool
{
    return isset($_SESSION['usuario_id'], $_SESSION['rol']);
}

function sesion_rol(): ?string
{
    return $_SESSION['rol'] ?? null;
}

function sesion_user_id(): ?int
{
    return isset($_SESSION['user_id']) && $_SESSION['user_id'] !== null
        ? (int) $_SESSION['user_id']
        : null;
}

function sesion_nombre(): string
{
    return $_SESSION['nombre'] ?? '';
}

// -----------------------------------------------------------------------------
// Guards
// -----------------------------------------------------------------------------

/**
 * Exige sesión activa. Si no la hay, envía al portal de bienvenida.
 */
function requiere_login(): void
{
    if (!usuario_logueado()) {
        header('Location: index.php');
        exit;
    }
}

/**
 * Exige sesión activa con un rol específico. Si el rol no coincide,
 * devuelve al usuario a su portal (index.php enruta según el rol).
 */
function requiere_rol(string $rol): void
{
    requiere_login();

    if (sesion_rol() !== $rol) {
        header('Location: index.php');
        exit;
    }
}

// -----------------------------------------------------------------------------
// Flash messages (mensajes tras redirección)
// -----------------------------------------------------------------------------

function flash_exito(string $mensaje): void
{
    $_SESSION['flash_exito'] = $mensaje;
}

function flash_error(string $mensaje): void
{
    $_SESSION['flash_error'] = $mensaje;
}

/**
 * Devuelve y limpia el mensaje flash de éxito (si existe).
 */
function tomar_flash_exito(): ?string
{
    $m = $_SESSION['flash_exito'] ?? null;
    unset($_SESSION['flash_exito']);
    return $m;
}

/**
 * Devuelve y limpia el mensaje flash de error (si existe).
 */
function tomar_flash_error(): ?string
{
    $m = $_SESSION['flash_error'] ?? null;
    unset($_SESSION['flash_error']);
    return $m;
}
