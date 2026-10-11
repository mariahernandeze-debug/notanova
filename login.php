<?php
/**
 * NotaNova - Portal de acceso (por rol)
 *
 * El rol se elige en el portal de bienvenida (tarjeta) y llega por ?rol=.
 * El formulario se tiñe con el color del rol y, al enviar, se valida que la
 * cuenta pertenezca realmente a ese portal.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

// Si ya hay sesión, directo al portal correspondiente
if (usuario_logueado()) {
    header('Location: index.php');
    exit;
}

// Configuración visual de cada portal
$portales = [
    'admin' => [
        'titulo'    => 'PORTAL ADMIN',
        'subtitulo' => 'CENTRAL DE OPERACIONES',
        'icono'     => 'fa-shield-halved',
        'color'     => 'indigo',
    ],
    'docente' => [
        'titulo'    => 'PORTAL DOCENTE',
        'subtitulo' => 'EVALUACIÓN Y SEGUIMIENTO',
        'icono'     => 'fa-user-tie',
        'color'     => 'emerald',
    ],
    'estudiante' => [
        'titulo'    => 'PORTAL ESTUDIANTE',
        'subtitulo' => 'PROGRESO ACADÉMICO',
        'icono'     => 'fa-user-graduate',
        'color'     => 'amber',
    ],
];

// El rol llega por GET (URL de la tarjeta) o por POST (campo oculto del form)
$rol = $_GET['rol'] ?? $_POST['rol'] ?? '';

if (!isset($portales[$rol])) {
    // Sin rol válido: hay que elegir portal primero
    header('Location: index.php');
    exit;
}

$portal = $portales[$rol];
$color  = $portal['color'];

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();

    $documento = trim($_POST['documento'] ?? '');
    $password  = $_POST['password'] ?? '';

    if ($documento === '' || $password === '') {
        $error = 'Ingresa tu documento y tu contraseña.';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT u.id, u.usuario, u.password_hash, u.rol, u.activo,
                       u.estudiante_id, u.docente_id,
                       e.nombre AS nombre_estudiante,
                       d.nombre AS nombre_docente
                FROM usuarios u
                LEFT JOIN estudiantes e ON u.estudiante_id = e.id
                LEFT JOIN docentes    d ON u.docente_id    = d.id
                WHERE u.usuario = ?
                LIMIT 1
            ");
            $stmt->execute([$documento]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$u || !verificar_contrasena($password, $u['password_hash'])) {
                $error = 'Documento o contraseña incorrectos.';
            } elseif ((int) $u['activo'] !== 1) {
                $error = 'Tu usuario se encuentra inactivo. Comunícate con la administración.';
            } elseif ($u['rol'] !== $rol) {
                // La cuenta existe pero no corresponde al portal elegido
                $titulo_real = $portales[$u['rol']]['titulo'] ?? 'otro portal';
                $error = "Esta cuenta pertenece al {$titulo_real}. Vuelve y selecciona el portal correcto.";
            } else {
                // Cuenta válida y coincide con el portal: resolver el nombre visible
                if ($u['rol'] === 'admin') {
                    $nombre = 'Administrador';
                    $userId = null;
                } elseif ($u['rol'] === 'docente') {
                    $nombre = $u['nombre_docente'] ?? 'Docente';
                    $userId = $u['docente_id'] !== null ? (int) $u['docente_id'] : null;
                } else {
                    $nombre = $u['nombre_estudiante'] ?? 'Estudiante';
                    $userId = $u['estudiante_id'] !== null ? (int) $u['estudiante_id'] : null;
                }

                // Caso límite: la persona enlazada fue borrada (FK SET NULL)
                if ($u['rol'] !== 'admin' && $userId === null) {
                    $error = 'Tu cuenta no está vinculada a un registro activo. Contacta al administrador.';
                } else {
                    iniciar_sesion([
                        'id'      => (int) $u['id'],
                        'usuario' => $u['usuario'],
                        'rol'     => $u['rol'],
                        'user_id' => $userId,
                        'nombre'  => $nombre,
                    ]);
                    header('Location: index.php');
                    exit;
                }
            }
        } catch (PDOException $e) {
            $error = 'Error al consultar las cuentas. Inténtalo más tarde.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NotaNova | <?php echo htmlspecialchars($portal['titulo']); ?></title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,600;0,700;0,800;1,800;1,900&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .neon-glow {
            text-shadow: 0 0 20px rgba(255, 255, 255, 0.35),
                         0 0 40px rgba(99, 102, 241, 0.3);
        }
        /* Gradiente y resplandor del botón según el portal */
        .neon-btn-indigo   { background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #a855f7 100%); box-shadow: 0 0 20px rgba(139, 92, 246, 0.4); }
        .neon-btn-indigo:hover   { box-shadow: 0 0 30px rgba(168, 85, 247, 0.6); }
        .neon-btn-emerald { background: linear-gradient(135deg, #10b981 0%, #14b8a6 50%, #06b6d4 100%); box-shadow: 0 0 20px rgba(16, 185, 129, 0.4); }
        .neon-btn-emerald:hover { box-shadow: 0 0 30px rgba(20, 184, 166, 0.6); }
        .neon-btn-amber   { background: linear-gradient(135deg, #f59e0b 0%, #f97316 50%, #ef4444 100%); box-shadow: 0 0 20px rgba(245, 158, 11, 0.4); }
        .neon-btn-amber:hover   { box-shadow: 0 0 30px rgba(249, 115, 22, 0.6); }
    </style>
</head>
<body class="bg-[#0b0e17] text-slate-100 min-h-screen flex flex-col items-center justify-center relative overflow-hidden m-0 p-6 selection:bg-indigo-500 selection:text-white">

    <!-- Luz ambiental de fondo (tinte del portal) -->
    <div class="absolute top-1/3 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[500px] h-[320px] bg-<?php echo $color; ?>-600/10 blur-[130px] pointer-events-none rounded-full"></div>

    <div class="relative z-10 w-full max-w-md">

        <!-- Marca -->
        <div class="text-center mb-8">
            <a href="index.php" class="inline-block">
                <h1 class="text-4xl font-black italic tracking-wider text-white neon-glow uppercase leading-none mb-2">
                    NOTANOVA
                </h1>
            </a>
            <span class="text-[#818cf8] text-[10px] font-black tracking-[0.35em] uppercase block">
                UNIFIED ACADEMIC ECOSYSTEM
            </span>
        </div>

        <!-- Tarjeta del formulario (marcada con el color del rol) -->
        <div class="bg-[#141724] border border-<?php echo $color; ?>-500/20 rounded-[32px] p-8 shadow-2xl">

            <div class="flex items-center gap-3 mb-6">
                <div class="w-11 h-11 rounded-2xl bg-<?php echo $color; ?>-500/10 text-<?php echo $color; ?>-400 flex items-center justify-center text-lg border border-<?php echo $color; ?>-500/20">
                    <i class="fa-solid <?php echo $portal['icono']; ?>"></i>
                </div>
                <div>
                    <span class="text-[10px] font-black text-<?php echo $color; ?>-400 uppercase tracking-widest block"><?php echo htmlspecialchars($portal['subtitulo']); ?></span>
                    <h2 class="text-xl font-extrabold text-white tracking-tight"><?php echo htmlspecialchars($portal['titulo']); ?></h2>
                </div>
            </div>

            <?php if (!empty($error)): ?>
                <div class="mb-5 p-4 bg-rose-500/10 border border-rose-500/30 text-rose-300 rounded-2xl text-sm font-bold flex items-start gap-3">
                    <i class="fa-solid fa-triangle-exclamation text-rose-400 mt-0.5"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php?rol=<?php echo urlencode($rol); ?>" class="space-y-5">
                <input type="hidden" name="rol" value="<?php echo htmlspecialchars($rol); ?>">
                <?php echo campo_csrf(); ?>

                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase mb-2 ml-1">DOCUMENTO / USUARIO</label>
                    <div class="relative">
                        <i class="fa-solid fa-id-card absolute left-4 top-1/2 -translate-y-1/2 text-slate-500"></i>
                        <input type="text" name="documento" required autofocus
                               value="<?php echo htmlspecialchars($_POST['documento'] ?? ''); ?>"
                               placeholder="<?php echo $rol === 'admin' ? 'admin' : 'Ej: 1001234567'; ?>"
                               class="w-full bg-[#0f1220] border border-slate-700/80 rounded-2xl pl-11 pr-5 py-4 text-slate-100 font-bold placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-<?php echo $color; ?>-500 focus:border-transparent transition-all">
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase mb-2 ml-1">CONTRASEÑA</label>
                    <div class="relative">
                        <i class="fa-solid fa-lock absolute left-4 top-1/2 -translate-y-1/2 text-slate-500"></i>
                        <input type="password" name="password" required
                               placeholder="••••••••"
                               class="w-full bg-[#0f1220] border border-slate-700/80 rounded-2xl pl-11 pr-12 py-4 text-slate-100 font-bold placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-<?php echo $color; ?>-500 focus:border-transparent transition-all">
                        <button type="button" onclick="togglePassword(this)"
                                class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-500 hover:text-<?php echo $color; ?>-400 transition-colors">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit"
                        class="neon-btn-<?php echo $color; ?> text-white w-full py-4 rounded-2xl font-black text-sm uppercase tracking-wider transition-all transform hover:-translate-y-0.5 flex items-center justify-center gap-2 mt-2 cursor-pointer">
                    <i class="fa-solid fa-right-to-bracket"></i> INGRESAR
                </button>
            </form>

            <a href="index.php#portales" class="mt-6 flex items-center justify-center gap-2 text-xs font-bold text-slate-500 hover:text-<?php echo $color; ?>-400 transition-colors">
                <i class="fa-solid fa-arrow-left"></i> Volver a portales
            </a>
        </div>

        <!-- Badge -->
        <div class="mt-8 flex items-center justify-center gap-2 text-[11px] font-mono text-amber-400/90 bg-amber-400/5 border border-amber-400/20 px-4 py-2 rounded-full">
            <i class="fa-solid fa-bolt text-amber-400"></i>
            <span>PREVIEW INTERACTIVO &mdash; Datos de demostración</span>
        </div>

    </div>

    <script>
        function togglePassword(btn) {
            const input = btn.closest('.relative').querySelector('input');
            const icon = btn.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }
    </script>
</body>
</html>
