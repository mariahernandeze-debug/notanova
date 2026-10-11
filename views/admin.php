<?php
// Esta vista solo debe cargarse desde index.php (evita acceso directo)
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    http_response_code(403);
    die('Acceso directo no permitido.');
}

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

requiere_rol('admin');

// Vista actual
$view = $_GET['view'] ?? 'estudiantes';
$action = $_GET['action'] ?? null;
$id = $_GET['id'] ?? null;
$gestion_id = $_GET['gestion_id'] ?? null;

$error_msg = null;
$success_msg = null;

// Mensajes flash (tras redirección)
$flash_exito = tomar_flash_exito();
$flash_error = tomar_flash_error();

// --- PROCESAMIENTO DE ACCIONES GET ---

// 1. Eliminar Registro General (Estudiante, Docente, Curso)
if ($action === 'delete' && $id) {
    $tablas_permitidas = [
        'estudiantes' => 'estudiantes',
        'docentes'    => 'docentes',
        'cursos'      => 'cursos'
    ];

    if (array_key_exists($view, $tablas_permitidas)) {
        $tabla = $tablas_permitidas[$view];
        $stmt = $pdo->prepare("DELETE FROM {$tabla} WHERE id = ?");
        $stmt->execute([$id]);
    }
    
    header("Location: index.php?view=" . urlencode($view));
    exit;
}

// 2. Desmatricular Estudiante de un Curso Específico
if ($action === 'desmatricular' && isset($_GET['estudiante_id']) && $gestion_id) {
    $est_id = intval($_GET['estudiante_id']);
    try {
        $stmt_del_mat = $pdo->prepare("DELETE FROM curso_estudiante WHERE curso_id = ? AND estudiante_id = ?");
        $stmt_del_mat->execute([$gestion_id, $est_id]);
    } catch (PDOException $e) {}

    header("Location: index.php?view=cursos&gestion_id=" . $gestion_id);
    exit;
}

// --- PROCESAMIENTO DE FORMULARIOS POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // =====================================================================
        // GESTIÓN DE USUARIOS (nueva sección, con protección CSRF)
        // =====================================================================

        // CREAR USUARIO
        if (isset($_POST['action_type']) && $_POST['action_type'] === 'crear_usuario') {
            verificar_csrf();

            $rol_nuevo   = $_POST['rol_nuevo'] ?? '';
            $persona_id  = !empty($_POST['persona_id']) ? intval($_POST['persona_id']) : null;
            $pass_nueva  = $_POST['password_nueva'] ?? '';
            $usuario_admin = trim($_POST['usuario_admin'] ?? '');

            if (!in_array($rol_nuevo, ['admin', 'docente', 'estudiante'], true)) {
                $error_msg = "Debes seleccionar un rol válido.";
            } elseif (strlen($pass_nueva) < 6) {
                $error_msg = "La contraseña debe tener al menos 6 caracteres.";
            } else {
                $est_id = null;
                $doc_id = null;

                if ($rol_nuevo === 'admin') {
                    if ($usuario_admin === '') {
                        $error_msg = "Debes indicar un nombre de usuario para el administrador.";
                    } else {
                        $usuario = $usuario_admin;
                    }
                } else {
                    // Resolver la persona seleccionada y su cédula
                    $tabla = ($rol_nuevo === 'docente') ? 'docentes' : 'estudiantes';
                    $stmt_p = $pdo->prepare("SELECT id, documento_id, nombre FROM {$tabla} WHERE id = ?");
                    $stmt_p->execute([$persona_id]);
                    $persona = $stmt_p->fetch(PDO::FETCH_ASSOC);

                    if (!$persona_id || !$persona) {
                        $error_msg = "Debes seleccionar una persona válida.";
                    } else {
                        $usuario = $persona['documento_id'];
                        if ($rol_nuevo === 'docente') { $doc_id = (int) $persona['id']; }
                        else { $est_id = (int) $persona['id']; }
                    }
                }

                if (empty($error_msg)) {
                    $stmt_ins = $pdo->prepare("
                        INSERT INTO usuarios (usuario, password_hash, rol, estudiante_id, docente_id, activo)
                        VALUES (?, ?, ?, ?, ?, 1)
                    ");
                    $stmt_ins->execute([$usuario, hash_contrasena($pass_nueva), $rol_nuevo, $est_id, $doc_id]);

                    flash_exito("Usuario '{$usuario}' creado correctamente.");
                    header("Location: index.php?view=usuarios");
                    exit;
                }
            }
        }

        // EDITAR USUARIO (cambiar contraseña y/o activar-desactivar)
        if (isset($_POST['action_type']) && $_POST['action_type'] === 'editar_usuario') {
            verificar_csrf();

            $u_id      = intval($_POST['usuario_id'] ?? 0);
            $activo    = isset($_POST['activo']) ? 1 : 0;
            $pass_edit = $_POST['password_edit'] ?? '';

            $stmt_u = $pdo->prepare("SELECT id, usuario, rol FROM usuarios WHERE id = ?");
            $stmt_u->execute([$u_id]);
            $u_edit = $stmt_u->fetch(PDO::FETCH_ASSOC);

            if (!$u_edit) {
                $error_msg = "El usuario no existe.";
            } elseif ((int) $u_edit['id'] === (int) $_SESSION['usuario_id'] && $activo === 0) {
                $error_msg = "No puedes desactivar tu propia cuenta.";
            } elseif ($pass_edit !== '' && strlen($pass_edit) < 6) {
                $error_msg = "La nueva contraseña debe tener al menos 6 caracteres.";
            } else {
                $sets   = ["activo = ?"];
                $params = [$activo];

                if ($pass_edit !== '') {
                    $sets[]   = "password_hash = ?";
                    $params[] = hash_contrasena($pass_edit);
                }

                $params[] = $u_id;
                $stmt_upd = $pdo->prepare("UPDATE usuarios SET " . implode(', ', $sets) . " WHERE id = ?");
                $stmt_upd->execute($params);

                flash_exito("Usuario '{$u_edit['usuario']}' actualizado correctamente.");
                header("Location: index.php?view=usuarios");
                exit;
            }
        }

        // TOGGLE USUARIO (activar/desactivar desde el interruptor de la lista)
        if (isset($_POST['action_type']) && $_POST['action_type'] === 'toggle_usuario') {
            verificar_csrf();

            $u_id = intval($_POST['usuario_id'] ?? 0);

            $stmt_u = $pdo->prepare("SELECT id, usuario, activo FROM usuarios WHERE id = ?");
            $stmt_u->execute([$u_id]);
            $u_tog = $stmt_u->fetch(PDO::FETCH_ASSOC);

            if (!$u_tog) {
                $error_msg = "El usuario no existe.";
            } elseif ((int) $u_tog['id'] === (int) $_SESSION['usuario_id'] && (int) $u_tog['activo'] === 1) {
                // Está activo y es la propia cuenta: no permitir desactivarse
                $error_msg = "No puedes desactivar tu propia cuenta.";
            } else {
                $nuevo = ((int) $u_tog['activo'] === 1) ? 0 : 1;
                $pdo->prepare("UPDATE usuarios SET activo = ? WHERE id = ?")->execute([$nuevo, $u_id]);

                $estado = ($nuevo === 1) ? 'activada' : 'desactivada';
                flash_exito("La cuenta '{$u_tog['usuario']}' ha sido {$estado}.");
                header("Location: index.php?view=usuarios");
                exit;
            }
        }

        // ACCIÓN: ACTUALIZAR DOCENTE DEL CURSO (Vista Gestión)
        if (isset($_POST['action_type']) && $_POST['action_type'] === 'actualizar_docente') {
            $c_id = intval($_POST['curso_id']);
            $doc_id = !empty($_POST['docente_id']) ? intval($_POST['docente_id']) : null;

            $stmt_upd_doc = $pdo->prepare("UPDATE cursos SET docente_id = ? WHERE id = ?");
            $stmt_upd_doc->execute([$doc_id, $c_id]);

            header("Location: index.php?view=cursos&gestion_id=" . $c_id);
            exit;
        }

        // ACCIÓN: MATRICULAR ESTUDIANTE EN CURSO (Vista Gestión)
        if (isset($_POST['action_type']) && $_POST['action_type'] === 'matricular_estudiante') {
            $c_id = intval($_POST['curso_id']);
            $est_id = intval($_POST['estudiante_id']);

            if ($c_id && $est_id) {
                $stmt_mat = $pdo->prepare("INSERT INTO curso_estudiante (curso_id, estudiante_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE estudiante_id = estudiante_id");
                $stmt_mat->execute([$c_id, $est_id]);
            }

            header("Location: index.php?view=cursos&gestion_id=" . $c_id);
            exit;
        }

        // FORMULARIOS CRUD GENERAL
        if ($view === 'estudiantes') {
            $tipo_doc        = trim($_POST['tipo_documento'] ?? 'CC');
            $doc_id          = trim($_POST['documento_id'] ?? '');
            $nombres         = trim($_POST['nombres'] ?? '');
            $apellidos       = trim($_POST['apellidos'] ?? '');
            $nombre_completo = trim($nombres . ' ' . $apellidos);
            $email           = trim($_POST['email'] ?? '');
            $fecha_nac       = !empty($_POST['fecha_nacimiento']) ? $_POST['fecha_nacimiento'] : null;
            $telefono        = trim($_POST['telefono'] ?? '');
            $direccion       = trim($_POST['direccion'] ?? '');
            $acudiente_nom   = trim($_POST['acudiente_nombre'] ?? '');
            $acudiente_tel   = trim($_POST['acudiente_telefono'] ?? '');

            if (!empty($_POST['id'])) {
                $stmt = $pdo->prepare("
                    UPDATE estudiantes 
                    SET tipo_documento = ?, documento_id = ?, nombre = ?, email = ?, fecha_nacimiento = ?, 
                        telefono = ?, direccion = ?, acudiente_nombre = ?, acudiente_telefono = ? 
                    WHERE id = ?
                ");
                $stmt->execute([
                    $tipo_doc, $doc_id, $nombre_completo, $email, $fecha_nac, 
                    $telefono, $direccion, $acudiente_nom, $acudiente_tel, $_POST['id']
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO estudiantes 
                    (tipo_documento, documento_id, nombre, email, fecha_nacimiento, telefono, direccion, acudiente_nombre, acudiente_telefono) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $tipo_doc, $doc_id, $nombre_completo, $email, $fecha_nac, 
                    $telefono, $direccion, $acudiente_nom, $acudiente_tel
                ]);
            }

        } elseif ($view === 'docentes') {
            $tipo_doc        = trim($_POST['tipo_documento'] ?? 'CC');
            $doc_id          = trim($_POST['documento_id'] ?? '');
            $nombres         = trim($_POST['nombres'] ?? '');
            $apellidos       = trim($_POST['apellidos'] ?? '');
            $nombre_completo = trim($nombres . ' ' . $apellidos);
            $email           = trim($_POST['email'] ?? '');
            $telefono        = trim($_POST['telefono'] ?? '');
            $especialidad    = trim($_POST['especialidad'] ?? '');

            if (!empty($_POST['id'])) {
                $stmt = $pdo->prepare("
                    UPDATE docentes 
                    SET tipo_documento = ?, documento_id = ?, nombre = ?, email = ?, telefono = ?, especialidad = ? 
                    WHERE id = ?
                ");
                $stmt->execute([$tipo_doc, $doc_id, $nombre_completo, $email, $telefono, $especialidad, $_POST['id']]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO docentes 
                    (tipo_documento, documento_id, nombre, email, telefono, especialidad) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$tipo_doc, $doc_id, $nombre_completo, $email, $telefono, $especialidad]);
            }

        } elseif ($view === 'cursos') {
            $codigo_input = strtoupper(trim($_POST['codigo'] ?? ''));
            $nombre_curso = trim($_POST['nombres'] ?? '');
            $docente_id   = !empty($_POST['docente_id']) ? intval($_POST['docente_id']) : null;

            if (empty($codigo_input)) {
                $codigo_input = 'CUR-' . strtoupper(substr(uniqid(), -4));
            }

            if (!empty($_POST['id'])) {
                $stmt = $pdo->prepare("UPDATE cursos SET codigo_curso = ?, nombre = ?, docente_id = ? WHERE id = ?");
                $stmt->execute([$codigo_input, $nombre_curso, $docente_id, intval($_POST['id'])]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO cursos (codigo_curso, nombre, docente_id) VALUES (?, ?, ?)");
                $stmt->execute([$codigo_input, $nombre_curso, $docente_id]);
            }
        }

        header("Location: index.php?view=" . urlencode($view));
        exit;

    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            $error_msg = "El código asignado ya existe en el sistema. Utiliza uno diferente.";
        } else {
            $error_msg = "Error en la base de datos: " . $e->getMessage();
        }
    }
}

// --- CONSULTA DE DATOS SEGÚN LA VISTA Y MODO GESTIÓN ---
$curso_gestion = null;
$estudiantes_matriculados = [];
$estudiantes_disponibles = [];
// Listas para el modal de creación de usuarios (solo se llenan en view=usuarios)
$docentes_sin_cuenta = [];
$estudiantes_sin_cuenta = [];
// Lista de docentes para el modal CRUD genérico (cursos)
$docentes_list = [];

if ($view === 'cursos' && $gestion_id) {
    // Modo Vista Detallada de Gestión del Curso
    $stmt_c = $pdo->prepare("SELECT c.*, d.nombre as docente_nombre, d.email as docente_email 
                            FROM cursos c 
                            LEFT JOIN docentes d ON c.docente_id = d.id 
                            WHERE c.id = ?");
    $stmt_c->execute([$gestion_id]);
    $curso_gestion = $stmt_c->fetch(PDO::FETCH_ASSOC);

    if ($curso_gestion) {
        // Estudiantes matriculados en este curso
        $stmt_m = $pdo->prepare("SELECT e.* 
                                FROM estudiantes e 
                                INNER JOIN curso_estudiante ce ON e.id = ce.estudiante_id 
                                WHERE ce.curso_id = ? 
                                ORDER BY e.nombre ASC");
        $stmt_m->execute([$gestion_id]);
        $estudiantes_matriculados = $stmt_m->fetchAll(PDO::FETCH_ASSOC);

        // Estudiantes que no están matriculados todavía
        $stmt_disp = $pdo->prepare("SELECT e.* 
                                    FROM estudiantes e 
                                    WHERE e.id NOT IN (
                                        SELECT estudiante_id FROM curso_estudiante WHERE curso_id = ?
                                    ) 
                                    ORDER BY e.nombre ASC");
        $stmt_disp->execute([$gestion_id]);
        $estudiantes_disponibles = $stmt_disp->fetchAll(PDO::FETCH_ASSOC);
    }
    $docentes_list = $pdo->query("SELECT id, nombre, email FROM docentes ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
    $modal_titulo_singular = "CURSO";

} elseif ($view === 'docentes') {
    $stmt = $pdo->query("SELECT * FROM docentes ORDER BY id DESC");
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $titulo_vista = "LISTA DE DOCENTES";
    $modal_titulo_singular = "DOCENTE";
} elseif ($view === 'cursos') {
    $stmt = $pdo->query("
        SELECT c.*, d.nombre as docente_nombre 
        FROM cursos c 
        LEFT JOIN docentes d ON c.docente_id = d.id 
        ORDER BY c.id DESC
    ");
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $docentes_list = $pdo->query("SELECT id, nombre FROM docentes ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
    $titulo_vista = "LISTA DE CURSOS";
    $modal_titulo_singular = "CURSO";
} elseif ($view === 'usuarios') {
    // Listado de cuentas con su persona enlazada
    $stmt = $pdo->query("
        SELECT u.*, e.nombre AS nombre_estudiante, d.nombre AS nombre_docente
        FROM usuarios u
        LEFT JOIN estudiantes e ON u.estudiante_id = e.id
        LEFT JOIN docentes    d ON u.docente_id    = d.id
        ORDER BY u.id ASC
    ");
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $titulo_vista = "LISTA DE USUARIOS";
    $modal_titulo_singular = "USUARIO";

    // Personas sin cuenta, para el formulario de creación
    $docentes_sin_cuenta = $pdo->query("
        SELECT d.id, d.nombre, d.documento_id
        FROM docentes d
        LEFT JOIN usuarios u ON u.docente_id = d.id
        WHERE u.id IS NULL
        ORDER BY d.nombre ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $estudiantes_sin_cuenta = $pdo->query("
        SELECT e.id, e.nombre, e.documento_id
        FROM estudiantes e
        LEFT JOIN usuarios u ON u.estudiante_id = e.id
        WHERE u.id IS NULL
        ORDER BY e.nombre ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $view = 'estudiantes';
    $stmt = $pdo->query("SELECT * FROM estudiantes ORDER BY id DESC");
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $titulo_vista = "LISTA DE ESTUDIANTES";
    $modal_titulo_singular = "ESTUDIANTE";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NotaNova | Central de Operaciones</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,600;0,700;0,800;1,800;1,900&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .neon-text { color: #00f0ff; text-shadow: 0 0 10px rgba(0, 240, 255, 0.5); }
        .neon-title {
            font-style: italic; font-weight: 900; letter-spacing: -0.03em;
            background: linear-gradient(135deg, #0f172a 0%, #6366f1 50%, #d946ef 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }
        .neon-btn {
            background: linear-gradient(135deg, #6366f1 0%, #a855f7 50%, #ec4899 100%);
            box-shadow: 0 0 20px rgba(168, 85, 247, 0.4);
        }
        .neon-btn:hover { box-shadow: 0 0 30px rgba(236, 72, 153, 0.6); }
        .custom-scroll::-webkit-scrollbar { width: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    </style>
</head>
<body class="min-h-screen flex flex-col text-slate-800">

    <!-- Header Navbar -->
    <header class="bg-[#0f172a] text-white px-10 py-5 flex items-center justify-between shadow-lg border-b border-indigo-500/20">
        <div class="flex items-center gap-2">
            <h1 class="text-2xl font-black italic tracking-tight neon-text">NotaNova</h1>
        </div>

        <nav class="flex items-center gap-3">
            <a href="index.php?view=estudiantes" 
               class="px-6 py-2.5 rounded-full font-bold text-sm transition-all duration-300 <?php echo ($view === 'estudiantes' && !$gestion_id) ? 'neon-btn text-white' : 'text-slate-300 hover:text-white hover:bg-white/10'; ?>">
                Estudiantes
            </a>

            <a href="index.php?view=docentes" 
               class="px-6 py-2.5 rounded-full font-bold text-sm transition-all duration-300 <?php echo ($view === 'docentes') ? 'neon-btn text-white' : 'text-slate-300 hover:text-white hover:bg-white/10'; ?>">
                Docentes
            </a>

            <a href="index.php?view=cursos" 
               class="px-6 py-2.5 rounded-full font-bold text-sm transition-all duration-300 <?php echo ($view === 'cursos') ? 'neon-btn text-white' : 'text-slate-300 hover:text-white hover:bg-white/10'; ?>">
                Cursos
            </a>

            <a href="index.php?view=usuarios" 
               class="px-6 py-2.5 rounded-full font-bold text-sm transition-all duration-300 <?php echo ($view === 'usuarios') ? 'neon-btn text-white' : 'text-slate-300 hover:text-white hover:bg-white/10'; ?>">
                Usuarios
            </a>
        </nav>

        <div class="flex items-center gap-3">
            <span class="hidden lg:block text-xs font-bold text-slate-400 border border-white/10 bg-white/5 px-4 py-2 rounded-full">
                <i class="fa-solid fa-user-shield mr-1.5 text-indigo-400"></i><?php echo htmlspecialchars(sesion_nombre()); ?>
            </span>

            <button onclick="abrirModalPassword()" title="Cambiar contraseña" 
                    class="w-10 h-10 rounded-2xl bg-white/10 hover:bg-indigo-500/20 hover:text-indigo-400 border border-white/10 flex items-center justify-center text-white transition-all">
                <i class="fa-solid fa-key text-sm"></i>
            </button>

            <a href="logout.php" title="Cerrar Sesión" class="w-10 h-10 rounded-2xl bg-white/10 hover:bg-rose-500/20 hover:text-rose-400 border border-white/10 flex items-center justify-center text-white transition-all">
                <i class="fa-solid fa-right-from-bracket text-sm"></i>
            </a>
        </div>
    </header>

    <main class="max-w-7xl w-full mx-auto p-8 md:p-12 flex-1">
        
        <?php if (!empty($error_msg)): ?>
            <div class="mb-6 p-4 bg-rose-50 border-l-4 border-rose-500 text-rose-700 font-bold rounded-r-2xl text-sm shadow-sm">
                <i class="fa-solid fa-triangle-exclamation mr-2"></i> <?php echo htmlspecialchars($error_msg); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($flash_exito)): ?>
            <div class="mb-6 p-4 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-700 font-bold rounded-r-2xl text-sm shadow-sm flex items-center justify-between">
                <span><i class="fa-solid fa-circle-check mr-2"></i> <?php echo htmlspecialchars($flash_exito); ?></span>
                <button onclick="this.parentElement.remove()" class="text-emerald-400 hover:text-emerald-700"><i class="fa-solid fa-xmark"></i></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($flash_error)): ?>
            <div class="mb-6 p-4 bg-rose-50 border-l-4 border-rose-500 text-rose-700 font-bold rounded-r-2xl text-sm shadow-sm flex items-center justify-between">
                <span><i class="fa-solid fa-triangle-exclamation mr-2"></i> <?php echo htmlspecialchars($flash_error); ?></span>
                <button onclick="this.parentElement.remove()" class="text-rose-400 hover:text-rose-700"><i class="fa-solid fa-xmark"></i></button>
            </div>
        <?php endif; ?>

        <?php if ($view === 'cursos' && $gestion_id && $curso_gestion): ?>
            
            <!-- ============================================================================== -->
            <!-- VISTA DE GESTIÓN DEL CURSO (MATRICULACIÓN Y ASIGNACIÓN DE DOCENTE)             -->
            <!-- ============================================================================== -->
            <?php 
                $nombre_c = $curso_gestion['nombre'] ?? $curso_gestion['nombre_curso'] ?? $curso_gestion['titulo'] ?? 'Curso'; 
            ?>
            <div class="flex items-center gap-4 mb-8">
                <a href="index.php?view=cursos" class="w-11 h-11 rounded-2xl bg-white border border-slate-200 text-slate-600 hover:bg-slate-100 flex items-center justify-center shadow-sm transition-all">
                    <i class="fa-solid fa-arrow-left"></i>
                </a>
                <h2 class="text-3xl md:text-4xl font-black italic text-slate-900 tracking-tight uppercase">
                    <?php echo htmlspecialchars($nombre_c); ?> <span class="text-indigo-600">/ GESTIÓN</span>
                </h2>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
                
                <!-- TARJETA DOCENTE ENCARGADO -->
                <div class="lg:col-span-4 bg-white border border-slate-200/80 rounded-[36px] p-8 shadow-xl flex flex-col justify-between h-fit space-y-6">
                    <div>
                        <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-4">
                            DOCENTE ENCARGADO
                        </span>

                        <div class="flex items-center gap-4 p-4 bg-slate-50 border border-slate-100 rounded-3xl mb-6">
                            <div class="w-14 h-14 rounded-2xl bg-emerald-100 text-emerald-600 flex items-center justify-center text-2xl shrink-0">
                                <i class="fa-solid fa-user-tie"></i>
                            </div>
                            <div class="overflow-hidden">
                                <h3 class="font-extrabold text-slate-900 uppercase text-base truncate">
                                    <?php echo htmlspecialchars($curso_gestion['docente_nombre'] ?? 'SIN ASIGNAR'); ?>
                                </h3>
                                <p class="text-xs text-slate-400 font-semibold truncate">
                                    <?php echo htmlspecialchars($curso_gestion['docente_email'] ?? 'sin.docente@notanova.edu'); ?>
                                </p>
                            </div>
                        </div>

                        <!-- Formulario para reasignar docente -->
                        <form method="POST" action="index.php?view=cursos&gestion_id=<?php echo $gestion_id; ?>" class="space-y-4">
                            <input type="hidden" name="action_type" value="actualizar_docente">
                            <input type="hidden" name="curso_id" value="<?php echo $gestion_id; ?>">

                            <select name="docente_id" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">Seleccionar docente...</option>
                                <?php foreach ($docentes_list as $d): ?>
                                    <option value="<?php echo $d['id']; ?>" <?php echo ($curso_gestion['docente_id'] == $d['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($d['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <button type="submit" class="w-full bg-[#0f172a] hover:bg-slate-800 text-white font-extrabold py-4 rounded-2xl text-xs uppercase tracking-wider transition-all shadow-md">
                                ACTUALIZAR DOCENTE
                            </button>
                        </form>
                    </div>
                </div>

                <!-- LISTA DE ESTUDIANTES MATRICULADOS -->
                <div class="lg:col-span-8 bg-white border border-slate-200/80 rounded-[36px] p-8 shadow-xl space-y-6">
                    <div class="flex items-center justify-between gap-4">
                        <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest block">
                            ESTUDIANTES MATRICULADOS (<?php echo count($estudiantes_matriculados); ?>)
                        </span>

                        <button onclick="abrirModalMatricular()" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-600 px-6 py-3 rounded-2xl font-black text-xs uppercase tracking-wider transition-all">
                            MATRICULAR ALUMNO
                        </button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="border-b border-slate-100 text-[10px] font-extrabold text-slate-400 uppercase tracking-wider">
                                    <th class="pb-4">DOCUMENTO</th>
                                    <th class="pb-4">ESTUDIANTE</th>
                                    <th class="pb-4 text-right">ACCIÓN</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-sm">
                                <?php if (empty($estudiantes_matriculados)): ?>
                                    <tr>
                                        <td colspan="3" class="py-12 text-center text-slate-400 font-bold">
                                            No hay estudiantes matriculados en este curso.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($estudiantes_matriculados as $est): ?>
                                        <tr class="hover:bg-slate-50/60 transition-colors">
                                            <td class="py-5 font-bold text-slate-400 text-xs">
                                                <?php echo htmlspecialchars($est['documento_id'] ?? $est['id']); ?>
                                            </td>
                                            <td class="py-5 font-black text-slate-900 uppercase italic">
                                                <?php echo htmlspecialchars($est['nombre']); ?>
                                            </td>
                                            <td class="py-5 text-right">
                                                <a href="index.php?view=cursos&gestion_id=<?php echo $gestion_id; ?>&action=desmatricular&estudiante_id=<?php echo $est['id']; ?>" 
                                                   onclick="return confirm('¿Desmatricular a este estudiante del curso?');" 
                                                   class="w-9 h-9 rounded-xl bg-rose-50 text-rose-400 hover:bg-rose-500 hover:text-white transition-all inline-flex items-center justify-center">
                                                    <i class="fa-solid fa-user-minus text-xs"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <!-- MODAL MATRICULAR ESTUDIANTE -->
            <div id="modalMatricular" class="fixed inset-0 bg-[#0f172a]/70 backdrop-blur-md flex items-center justify-center hidden z-50 p-4">
                <div class="bg-white rounded-[36px] w-full max-w-md overflow-hidden shadow-2xl relative border border-indigo-500/20">
                    <div class="bg-[#0f172a] px-8 py-6 flex items-center justify-between border-b border-indigo-500/20">
                        <h3 class="text-xl font-black italic text-white uppercase tracking-tight">MATRICULAR ALUMNO</h3>
                        <button onclick="cerrarModalMatricular()" class="text-slate-400 hover:text-rose-400 font-extrabold text-lg">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>

                    <form method="POST" action="index.php?view=cursos&gestion_id=<?php echo $gestion_id; ?>" class="p-8 space-y-5 bg-white">
                        <input type="hidden" name="action_type" value="matricular_estudiante">
                        <input type="hidden" name="curso_id" value="<?php echo $gestion_id; ?>">

                        <div>
                            <label class="block text-[11px] font-black text-slate-400 uppercase mb-2 ml-1">SELECCIONA UN ESTUDIANTE</label>
                            <select name="estudiante_id" required class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">Seleccionar...</option>
                                <?php foreach ($estudiantes_disponibles as $est_disp): ?>
                                    <option value="<?php echo $est_disp['id']; ?>">
                                        <?php echo htmlspecialchars($est_disp['nombre']) . ' (' . htmlspecialchars($est_disp['documento_id'] ?? $est_disp['id']) . ')'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="pt-2 grid grid-cols-2 gap-3">
                            <button type="button" onclick="cerrarModalMatricular()" class="w-full bg-slate-100 text-slate-600 font-extrabold py-4 rounded-2xl text-xs uppercase">
                                CANCELAR
                            </button>
                            <button type="submit" class="w-full neon-btn text-white font-extrabold py-4 rounded-2xl text-xs uppercase shadow-md">
                                MATRICULAR
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
                function abrirModalMatricular() { document.getElementById('modalMatricular').classList.remove('hidden'); }
                function cerrarModalMatricular() { document.getElementById('modalMatricular').classList.add('hidden'); }
            </script>

        <?php else: ?>

            <!-- ============================================================================== -->
            <!-- TABLAS PRINCIPALES (ESTUDIANTES, DOCENTES, CURSOS LISTA GENERAL)              -->
            <!-- ============================================================================== -->
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-10">
                <div>
                    <span class="text-[11px] font-extrabold text-fuchsia-600 uppercase tracking-widest block mb-1">
                        CENTRAL DE OPERACIONES
                    </span>
                    <h2 class="text-4xl md:text-5xl font-black neon-title uppercase">
                        <?php echo $titulo_vista; ?>
                    </h2>
                </div>

                <button onclick="<?php echo $view === 'usuarios' ? 'abrirModalCrearUsuario()' : 'abrirModalCrear()'; ?>" 
                        class="neon-btn text-white px-8 py-3.5 rounded-full font-extrabold text-sm transition-all transform hover:-translate-y-0.5 flex items-center justify-center gap-2.5 self-start md:self-auto">
                    <i class="fa-solid fa-plus text-xs"></i> <?php echo $view === 'usuarios' ? 'NUEVO USUARIO' : 'AGREGAR NUEVO'; ?>
                </button>
            </div>

            <div class="bg-white border border-slate-200/80 rounded-[32px] overflow-hidden shadow-xl shadow-slate-200/50">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-50/80 border-b border-slate-100 text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                                <?php if ($view === 'cursos'): ?>
                                    <th class="p-6 pl-10">CÓDIGO</th>
                                    <th class="p-6">NOMBRE DEL CURSO</th>
                                    <th class="p-6">DOCENTE TITULAR</th>
                                    <th class="p-6 text-right pr-10">GESTIÓN</th>
                                <?php elseif ($view === 'docentes'): ?>
                                    <th class="p-6 pl-10">DOCUMENTO</th>
                                    <th class="p-6">DOCENTE</th>
                                    <th class="p-6">ESPECIALIDAD</th>
                                    <th class="p-6">CONTACTO</th>
                                    <th class="p-6 text-right pr-10">GESTIÓN</th>
                                <?php elseif ($view === 'usuarios'): ?>
                                    <th class="p-6 pl-10">USUARIO</th>
                                    <th class="p-6">ROL</th>
                                    <th class="p-6">VINCULADO A</th>
                                    <th class="p-6">ESTADO</th>
                                    <th class="p-6 text-right pr-10">GESTIÓN</th>
                                <?php else: ?>
                                    <th class="p-6 pl-10">DOCUMENTO</th>
                                    <th class="p-6">ESTUDIANTE</th>
                                    <th class="p-6">CONTACTO</th>
                                    <th class="p-6">ACUDIENTE</th>
                                    <th class="p-6 text-right pr-10">GESTIÓN</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-sm">
                            <?php if (empty($registros)): ?>
                                <tr>
                                    <td colspan="5" class="p-12 text-center text-slate-400 font-semibold">
                                        No hay registros disponibles en esta sección.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($registros as $reg): ?>
                                    <tr class="hover:bg-slate-50/80 transition-colors">
                                        <?php if ($view === 'cursos'): ?>
                                            <?php 
                                                $nombre_curso_mostrar = $reg['nombre'] ?? $reg['nombre_curso'] ?? $reg['titulo'] ?? $reg['curso'] ?? ''; 
                                                $codigo_mostrar = $reg['codigo_curso'] ?? $reg['codigo'] ?? ('CUR-' . str_pad($reg['id'], 3, '0', STR_PAD_LEFT));
                                            ?>
                                            <td class="p-6 pl-10 font-bold text-indigo-600 text-sm">
                                                <?php echo htmlspecialchars($codigo_mostrar); ?>
                                            </td>
                                            <td class="p-6 font-extrabold italic text-slate-900 uppercase text-base tracking-tight">
                                                <?php echo htmlspecialchars($nombre_curso_mostrar); ?>
                                            </td>
                                            <td class="p-6">
                                                <span class="inline-block bg-emerald-50 text-emerald-600 border border-emerald-200 px-5 py-2 rounded-full text-xs font-black uppercase tracking-wider shadow-sm">
                                                    <?php echo htmlspecialchars($reg['docente_nombre'] ?? 'SIN ASIGNAR'); ?>
                                                </span>
                                            </td>
                                            <td class="p-6 text-right pr-10 space-x-2">
                                                <!-- Botón para ir a la vista de gestión de alumnos del curso -->
                                                <a href="index.php?view=cursos&gestion_id=<?php echo $reg['id']; ?>" 
                                                   title="Gestionar Alumnos y Docente"
                                                   class="w-10 h-10 rounded-2xl bg-indigo-50 text-indigo-600 hover:bg-indigo-600 hover:text-white transition-all inline-flex items-center justify-center shadow-sm">
                                                    <i class="fa-solid fa-users-gear text-xs"></i>
                                                </a>
                                                <button onclick='editarRegistro(<?php echo json_encode($reg); ?>)' 
                                                        title="Editar Datos"
                                                        class="w-10 h-10 rounded-2xl bg-slate-100 text-slate-600 hover:bg-slate-200 transition-all inline-flex items-center justify-center shadow-sm">
                                                    <i class="fa-solid fa-pen text-xs"></i>
                                                </button>
                                                <a href="index.php?view=cursos&action=delete&id=<?php echo $reg['id']; ?>" 
                                                   onclick="return confirm('¿Deseas eliminar este curso?');" 
                                                   class="w-10 h-10 rounded-2xl bg-rose-50 text-rose-500 hover:bg-rose-500 hover:text-white transition-all inline-flex items-center justify-center shadow-sm">
                                                    <i class="fa-solid fa-trash-can text-xs"></i>
                                                </a>
                                            </td>

                                        <?php elseif ($view === 'docentes'): ?>
                                            <td class="p-6 pl-10 font-bold text-slate-400">
                                                <span class="text-xs bg-slate-100 text-slate-600 px-2.5 py-1 rounded-lg font-bold mr-1.5 border border-slate-200">
                                                    <?php echo htmlspecialchars($reg['tipo_documento'] ?? 'CC'); ?>
                                                </span>
                                                <?php echo htmlspecialchars($reg['documento_id'] ?? '100' . str_pad($reg['id'], 6, '0', STR_PAD_LEFT)); ?>
                                            </td>
                                            <td class="p-6">
                                                <div class="font-extrabold italic text-slate-900 uppercase text-base"><?php echo htmlspecialchars($reg['nombre']); ?></div>
                                                <div class="text-xs text-slate-400 font-medium"><?php echo htmlspecialchars($reg['email'] ?? '-'); ?></div>
                                            </td>
                                            <td class="p-6 font-bold text-slate-600">
                                                <?php echo htmlspecialchars($reg['especialidad'] ?? 'Docente'); ?>
                                            </td>
                                            <td class="p-6 text-slate-500 font-medium text-xs">
                                                <i class="fa-solid fa-phone text-slate-400 mr-1"></i> <?php echo htmlspecialchars($reg['telefono'] ?? '-'); ?>
                                            </td>
                                            <td class="p-6 text-right pr-10 space-x-2">
                                                <button onclick='editarRegistro(<?php echo json_encode($reg); ?>)' 
                                                        class="w-10 h-10 rounded-2xl bg-indigo-50 text-indigo-600 hover:bg-indigo-600 hover:text-white transition-all inline-flex items-center justify-center shadow-sm">
                                                    <i class="fa-solid fa-pen text-xs"></i>
                                                </button>
                                                <a href="index.php?view=docentes&action=delete&id=<?php echo $reg['id']; ?>" 
                                                   onclick="return confirm('¿Deseas eliminar este docente?');" 
                                                   class="w-10 h-10 rounded-2xl bg-rose-50 text-rose-500 hover:bg-rose-500 hover:text-white transition-all inline-flex items-center justify-center shadow-sm">
                                                    <i class="fa-solid fa-trash-can text-xs"></i>
                                                </a>
                                            </td>

                                        <?php elseif ($view === 'usuarios'): ?>
                                            <td class="p-6 pl-10 font-bold text-slate-600">
                                                <div class="flex items-center gap-2">
                                                    <?php if ($reg['rol'] === 'admin'): ?>
                                                        <i class="fa-solid fa-shield-halved text-indigo-500"></i>
                                                    <?php elseif ($reg['rol'] === 'docente'): ?>
                                                        <i class="fa-solid fa-user-tie text-emerald-500"></i>
                                                    <?php else: ?>
                                                        <i class="fa-solid fa-user-graduate text-amber-500"></i>
                                                    <?php endif; ?>
                                                    <?php echo htmlspecialchars($reg['usuario']); ?>
                                                </div>
                                                <div class="text-[11px] text-slate-400 font-medium mt-0.5">
                                                    Creado: <?php echo date('d/m/Y', strtotime($reg['created_at'])); ?>
                                                </div>
                                            </td>
                                            <td class="p-6">
                                                <?php
                                                    $rol_badge = [
                                                        'admin'      => ['bg-indigo-100 text-indigo-700 border-indigo-200', 'ADMIN'],
                                                        'docente'    => ['bg-emerald-100 text-emerald-700 border-emerald-200', 'DOCENTE'],
                                                        'estudiante' => ['bg-amber-100 text-amber-700 border-amber-200', 'ESTUDIANTE'],
                                                    ];
                                                    $rb = $rol_badge[$reg['rol']] ?? ['bg-slate-100 text-slate-600 border-slate-200', strtoupper($reg['rol'])];
                                                ?>
                                                <span class="inline-block <?php echo $rb[0]; ?> border px-4 py-1.5 rounded-full text-[11px] font-black uppercase tracking-wider">
                                                    <?php echo $rb[1]; ?>
                                                </span>
                                            </td>
                                            <td class="p-6">
                                                <?php
                                                    $nombre_vinculo = $reg['nombre_docente'] ?? $reg['nombre_estudiante'] ?? null;
                                                    if ($reg['rol'] === 'admin'): ?>
                                                        <span class="text-xs text-slate-300 italic">Cuenta del sistema</span>
                                                    <?php elseif ($nombre_vinculo): ?>
                                                        <div class="font-bold text-xs text-slate-700 uppercase"><?php echo htmlspecialchars($nombre_vinculo); ?></div>
                                                        <div class="text-[11px] text-slate-400">Sin registro enlazado</div>
                                                    <?php else: ?>
                                                        <span class="text-xs text-rose-500 font-bold italic">
                                                            <i class="fa-solid fa-triangle-exclamation mr-1"></i>Sin registro enlazado
                                                        </span>
                                                    <?php endif; ?>
                                            </td>
                                            <td class="p-6">
                                                <?php $es_activo = ((int) $reg['activo'] === 1); ?>
                                                <form method="POST" action="index.php?view=usuarios" class="flex items-center gap-3"
                                                      onsubmit='return confirm("¿Cambiar el estado de la cuenta <?php echo htmlspecialchars($reg['usuario'], ENT_QUOTES); ?>?");'>
                                                    <input type="hidden" name="action_type" value="toggle_usuario">
                                                    <?php echo campo_csrf(); ?>
                                                    <input type="hidden" name="usuario_id" value="<?php echo (int) $reg['id']; ?>">

                                                    <button type="submit"
                                                            title="Clic para <?php echo $es_activo ? 'desactivar' : 'activar'; ?> la cuenta"
                                                            class="relative inline-flex items-center h-7 w-14 shrink-0 rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 <?php echo $es_activo ? 'bg-emerald-500 focus:ring-emerald-500' : 'bg-slate-300 focus:ring-slate-400'; ?> cursor-pointer">
                                                        <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition-transform <?php echo $es_activo ? 'translate-x-8' : 'translate-x-1'; ?>"></span>
                                                    </button>

                                                    <span class="text-[11px] font-black uppercase <?php echo $es_activo ? 'text-emerald-600' : 'text-slate-400'; ?>">
                                                        <?php echo $es_activo ? 'Activo' : 'Inactivo'; ?>
                                                    </span>
                                                </form>
                                            </td>
                                            <td class="p-6 text-right pr-10 space-x-2">
                                                <button onclick='editarUsuario(<?php echo json_encode([
                                                    'id'       => (int) $reg['id'],
                                                    'usuario'  => $reg['usuario'],
                                                    'rol'      => $reg['rol'],
                                                    'activo'   => (int) $reg['activo'],
                                                    'vinculo'  => $nombre_vinculo ?? 'Cuenta del sistema'
                                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                                        title="Editar / Resetear contraseña"
                                                        class="w-10 h-10 rounded-2xl bg-indigo-50 text-indigo-600 hover:bg-indigo-600 hover:text-white transition-all inline-flex items-center justify-center shadow-sm">
                                                    <i class="fa-solid fa-key text-xs"></i>
                                                </button>
                                            </td>

                                        <?php else: ?>
                                            <td class="p-6 pl-10 font-bold text-slate-400">
                                                <span class="text-xs bg-slate-100 text-slate-600 px-2.5 py-1 rounded-lg font-bold mr-1.5 border border-slate-200">
                                                    <?php echo htmlspecialchars($reg['tipo_documento'] ?? 'TI'); ?>
                                                </span>
                                                <?php echo htmlspecialchars($reg['documento_id'] ?? '100' . str_pad($reg['id'], 6, '0', STR_PAD_LEFT)); ?>
                                            </td>
                                            <td class="p-6">
                                                <div class="font-extrabold italic text-slate-900 uppercase text-base"><?php echo htmlspecialchars($reg['nombre']); ?></div>
                                                <div class="text-xs text-slate-400 font-medium"><?php echo htmlspecialchars($reg['email'] ?? '-'); ?></div>
                                            </td>
                                            <td class="p-6 text-slate-500 font-medium text-xs">
                                                <div><i class="fa-solid fa-phone text-slate-400 mr-1"></i> <?php echo htmlspecialchars($reg['telefono'] ?? '-'); ?></div>
                                                <div class="text-[11px] text-slate-400"><i class="fa-solid fa-location-dot mr-1"></i> <?php echo htmlspecialchars($reg['direccion'] ?? '-'); ?></div>
                                            </td>
                                            <td class="p-6">
                                                <?php if (!empty($reg['acudiente_nombre'])): ?>
                                                    <div class="font-bold text-xs text-slate-700"><?php echo htmlspecialchars($reg['acudiente_nombre']); ?></div>
                                                    <div class="text-[11px] text-slate-400"><i class="fa-solid fa-phone mr-1"></i> <?php echo htmlspecialchars($reg['acudiente_telefono'] ?? '-'); ?></div>
                                                <?php else: ?>
                                                    <span class="text-xs text-slate-300 italic">Sin datos</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-6 text-right pr-10 space-x-2">
                                                <button onclick='editarRegistro(<?php echo json_encode($reg); ?>)' 
                                                        class="w-10 h-10 rounded-2xl bg-indigo-50 text-indigo-600 hover:bg-indigo-600 hover:text-white transition-all inline-flex items-center justify-center shadow-sm">
                                                    <i class="fa-solid fa-pen text-xs"></i>
                                                </button>
                                                <a href="index.php?view=estudiantes&action=delete&id=<?php echo $reg['id']; ?>" 
                                                   onclick="return confirm('¿Deseas eliminar este estudiante?');" 
                                                   class="w-10 h-10 rounded-2xl bg-rose-50 text-rose-500 hover:bg-rose-500 hover:text-white transition-all inline-flex items-center justify-center shadow-sm">
                                                    <i class="fa-solid fa-trash-can text-xs"></i>
                                                </a>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php endif; ?>

    </main>

    <!-- Modal Formulario CRUD General -->
    <div id="modalForm" class="fixed inset-0 bg-[#0f172a]/70 backdrop-blur-md flex items-center justify-center hidden z-50 p-4">
        <div class="bg-white rounded-[36px] w-full max-w-md overflow-hidden shadow-2xl relative transition-all border border-indigo-500/20 max-h-[90vh] flex flex-col">
            
            <div class="bg-[#0f172a] px-8 py-7 flex items-center justify-between shrink-0 border-b border-indigo-500/20">
                <h3 id="modalTitle" class="text-2xl font-black italic text-white uppercase tracking-tight">
                    NUEVO <?php echo $modal_titulo_singular; ?>
                </h3>
                <button type="button" onclick="cerrarModal()" class="text-slate-400 hover:text-rose-400 transition-colors text-xl font-extrabold">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            
            <form method="POST" action="index.php?view=<?php echo $view; ?>" class="p-8 space-y-4 overflow-y-auto custom-scroll bg-white">
                <input type="hidden" name="id" id="form_id">

                <?php if ($view === 'estudiantes'): ?>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <select name="tipo_documento" id="form_tipo_doc" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-4 text-slate-600 font-bold focus:outline-none focus:ring-2 focus:ring-fuchsia-500">
                                <option value="TI">TI</option>
                                <option value="CC">CC</option>
                                <option value="CE">CE</option>
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <input type="text" name="documento_id" id="form_documento_id" required placeholder="Número Documento ID" 
                                   class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 text-slate-700 font-semibold placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-fuchsia-500">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <input type="text" name="nombres" id="form_nombres" required placeholder="Nombres" 
                               class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 text-slate-700 font-semibold placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-fuchsia-500">
                        <input type="text" name="apellidos" id="form_apellidos" required placeholder="Apellidos" 
                               class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 text-slate-700 font-semibold placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-fuchsia-500">
                    </div>

                    <div>
                        <input type="email" name="email" id="form_email" required placeholder="Email Institucional" 
                               class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 text-slate-700 font-semibold placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-fuchsia-500">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1 ml-2">Fecha Nacimiento</label>
                            <input type="date" name="fecha_nacimiento" id="form_fecha_nac" 
                                   class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3.5 text-slate-700 font-semibold focus:outline-none focus:ring-2 focus:ring-fuchsia-500">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1 ml-2">Teléfono Alumno</label>
                            <input type="text" name="telefono" id="form_telefono" placeholder="Teléfono" 
                                   class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-3.5 text-slate-700 font-semibold placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-fuchsia-500">
                        </div>
                    </div>

                    <div>
                        <input type="text" name="direccion" id="form_direccion" placeholder="Dirección de Residencia" 
                               class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 text-slate-700 font-semibold placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-fuchsia-500">
                    </div>

                    <div class="pt-2 border-t border-slate-100">
                        <span class="text-[10px] font-bold text-fuchsia-600 uppercase tracking-wider block mb-2">Datos del Acudiente / Padre</span>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <input type="text" name="acudiente_nombre" id="form_acudiente_nombre" placeholder="Nombre Completo" 
                                   class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-3.5 text-slate-700 font-semibold placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-fuchsia-500">
                            <input type="text" name="acudiente_telefono" id="form_acudiente_telefono" placeholder="Teléfono Acudiente" 
                                   class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-3.5 text-slate-700 font-semibold placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-fuchsia-500">
                        </div>
                    </div>

                <?php elseif ($view === 'docentes'): ?>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <select name="tipo_documento" id="form_tipo_doc" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-4 text-slate-600 font-bold focus:outline-none focus:ring-2 focus:ring-fuchsia-500">
                                <option value="CC">CC</option>
                                <option value="CE">CE</option>
                                <option value="PAS">PAS</option>
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <input type="text" name="documento_id" id="form_documento_id" required placeholder="Número Documento ID" 
                                   class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 text-slate-700 font-semibold placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-fuchsia-500">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <input type="text" name="nombres" id="form_nombres" required placeholder="Nombres" 
                               class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 text-slate-700 font-semibold placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-fuchsia-500">
                        <input type="text" name="apellidos" id="form_apellidos" required placeholder="Apellidos" 
                               class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 text-slate-700 font-semibold placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-fuchsia-500">
                    </div>

                    <div>
                        <input type="email" name="email" id="form_email" required placeholder="Email Institucional" 
                               class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 text-slate-700 font-semibold placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-fuchsia-500">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <input type="text" name="especialidad" id="form_especialidad" placeholder="Especialidad / Título" 
                               class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 text-slate-700 font-semibold placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-fuchsia-500">
                        <input type="text" name="telefono" id="form_telefono" placeholder="Teléfono de Contacto" 
                               class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 text-slate-700 font-semibold placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-fuchsia-500">
                    </div>

                <?php else: ?>
                    <div>
                        <input type="text" name="codigo" id="form_codigo" placeholder="Código (ej: INF-101)" 
                               class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-6 py-4 text-slate-700 font-bold placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-fuchsia-500">
                    </div>
                    <div>
                        <input type="text" name="nombres" id="form_nombres" required placeholder="Nombre de la Materia" 
                               class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-6 py-4 text-slate-700 font-bold placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-fuchsia-500">
                    </div>
                    <div>
                        <select name="docente_id" id="form_docente_id" 
                                class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-6 py-4 text-slate-700 font-bold focus:outline-none focus:ring-2 focus:ring-fuchsia-500">
                            <option value="">Asignar docente (opcional)...</option>
                            <?php foreach ($docentes_list as $doc): ?>
                                <option value="<?php echo $doc['id']; ?>"><?php echo htmlspecialchars($doc['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="pt-4">
                    <button type="submit" 
                            class="w-full neon-btn text-white py-4 rounded-2xl font-black uppercase text-sm tracking-wider transition-all transform active:scale-95">
                        REGISTRAR
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===================================================================== -->
    <!-- MODAL: CREAR USUARIO                                                 -->
    <!-- ===================================================================== -->
    <div id="modalCrearUsuario" class="fixed inset-0 bg-[#0f172a]/70 backdrop-blur-md flex items-center justify-center hidden z-50 p-4">
        <div class="bg-white rounded-[36px] w-full max-w-md overflow-hidden shadow-2xl relative border border-indigo-500/20 max-h-[90vh] flex flex-col">

            <div class="bg-[#0f172a] px-8 py-6 flex items-center justify-between border-b border-indigo-500/20 shrink-0">
                <div>
                    <span class="text-[10px] font-extrabold text-indigo-400 uppercase tracking-widest block mb-1">ACCESO AL SISTEMA</span>
                    <h3 class="text-2xl font-black italic text-white uppercase tracking-tight">NUEVO USUARIO</h3>
                </div>
                <button type="button" onclick="cerrarModalCrearUsuario()" class="w-10 h-10 rounded-2xl bg-white/10 hover:bg-rose-500/20 text-white hover:text-rose-400 flex items-center justify-center transition-all">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>

            <form method="POST" action="index.php?view=usuarios" class="p-8 space-y-5 bg-white overflow-y-auto custom-scroll">
                <input type="hidden" name="action_type" value="crear_usuario">
                <?php echo campo_csrf(); ?>

                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase mb-2 ml-1">1. ROL DEL USUARIO</label>
                    <select name="rol_nuevo" id="cu_rol" onchange="cambiarTipoPersona()" required
                            class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <option value="estudiante">ESTUDIANTE</option>
                        <option value="docente">DOCENTE</option>
                        <option value="admin">ADMINISTRADOR</option>
                    </select>
                </div>

                <!-- Persona enlazada (docente/estudiante) -->
                <div id="cu_seccion_persona">
                    <label class="block text-[11px] font-black text-slate-400 uppercase mb-2 ml-1">2. SELECCIONA LA PERSONA</label>
                    <select name="persona_id" id="cu_persona" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <optgroup label="Estudiantes sin cuenta" id="cu_grupo_est">
                            <?php foreach ($estudiantes_sin_cuenta as $p): ?>
                                <option value="<?php echo (int) $p['id']; ?>">
                                    <?php echo htmlspecialchars($p['nombre'] . ' (' . $p['documento_id'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if (empty($estudiantes_sin_cuenta)): ?>
                                <option value="" disabled>Todos los estudiantes ya tienen cuenta</option>
                            <?php endif; ?>
                        </optgroup>
                        <optgroup label="Docentes sin cuenta" id="cu_grupo_doc">
                            <?php foreach ($docentes_sin_cuenta as $p): ?>
                                <option value="<?php echo (int) $p['id']; ?>">
                                    <?php echo htmlspecialchars($p['nombre'] . ' (' . $p['documento_id'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if (empty($docentes_sin_cuenta)): ?>
                                <option value="" disabled>Todos los docentes ya tienen cuenta</option>
                            <?php endif; ?>
                        </optgroup>
                    </select>
                    <p class="text-[11px] text-slate-400 font-bold mt-2 ml-1">
                        <i class="fa-solid fa-circle-info text-indigo-400 mr-1"></i>
                        El usuario será la cédula de la persona seleccionada.
                    </p>
                </div>

                <!-- Usuario manual (solo admin) -->
                <div id="cu_seccion_admin" class="hidden">
                    <label class="block text-[11px] font-black text-slate-400 uppercase mb-2 ml-1">2. NOMBRE DE USUARIO</label>
                    <input type="text" name="usuario_admin" id="cu_usuario_admin" placeholder="Ej: admin"
                           class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 font-bold text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>

                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase mb-2 ml-1">3. CONTRASEÑA INICIAL</label>
                    <input type="password" name="password_nueva" id="cu_password" required minlength="6" placeholder="Mínimo 6 caracteres"
                           class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 font-bold text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>

                <div class="pt-2 grid grid-cols-2 gap-4">
                    <button type="button" onclick="cerrarModalCrearUsuario()"
                            class="w-full bg-white border border-slate-200 text-slate-600 font-extrabold py-4 rounded-2xl text-xs uppercase tracking-wider hover:bg-slate-50 transition-all">
                        CANCELAR
                    </button>
                    <button type="submit"
                            class="w-full neon-btn text-white font-extrabold py-4 rounded-2xl text-xs uppercase tracking-wider transition-all shadow-lg">
                        CREAR USUARIO
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===================================================================== -->
    <!-- MODAL: EDITAR USUARIO (contraseña y estado)                           -->
    <!-- ===================================================================== -->
    <div id="modalEditarUsuario" class="fixed inset-0 bg-[#0f172a]/70 backdrop-blur-md flex items-center justify-center hidden z-50 p-4">
        <div class="bg-white rounded-[36px] w-full max-w-sm overflow-hidden shadow-2xl relative border border-indigo-500/20">

            <div class="bg-[#0f172a] px-8 py-6 flex items-center justify-between border-b border-indigo-500/20">
                <div>
                    <span class="text-[10px] font-extrabold text-indigo-400 uppercase tracking-widest block mb-1">GESTIÓN DE CUENTA</span>
                    <h3 class="text-xl font-black italic text-white uppercase tracking-tight">EDITAR USUARIO</h3>
                </div>
                <button type="button" onclick="cerrarModalEditarUsuario()" class="w-10 h-10 rounded-2xl bg-white/10 hover:bg-rose-500/20 text-white hover:text-rose-400 flex items-center justify-center transition-all">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>

            <form method="POST" action="index.php?view=usuarios" class="p-8 space-y-5 bg-white">
                <input type="hidden" name="action_type" value="editar_usuario">
                <input type="hidden" name="usuario_id" id="eu_id">
                <?php echo campo_csrf(); ?>

                <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4">
                    <div class="text-[10px] font-black text-slate-400 uppercase">USUARIO</div>
                    <div class="font-black text-slate-800" id="eu_usuario">-</div>
                    <div class="text-[11px] text-slate-500 font-bold mt-1" id="eu_vinculo">-</div>
                </div>

                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase mb-2 ml-1">NUEVA CONTRASEÑA</label>
                    <input type="password" name="password_edit" id="eu_password" minlength="6" placeholder="Dejar en blanco para no cambiar"
                           class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 font-bold text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <p class="text-[11px] text-slate-400 font-bold mt-2 ml-1">
                        <i class="fa-solid fa-circle-info text-indigo-400 mr-1"></i>
                        Úsalo para resetear la contraseña del usuario.
                    </p>
                </div>

                <label class="flex items-center gap-3 p-4 bg-slate-50 border border-slate-200 rounded-2xl cursor-pointer hover:bg-slate-100">
                    <input type="checkbox" name="activo" id="eu_activo" class="w-4 h-4 text-indigo-600 rounded focus:ring-indigo-500">
                    <div>
                        <span class="block font-black text-slate-800 text-xs">CUENTA ACTIVA</span>
                        <span class="block text-[10px] text-slate-400 font-bold">Si se desmarca, el usuario no podrá iniciar sesión</span>
                    </div>
                </label>

                <div class="pt-2 grid grid-cols-2 gap-4">
                    <button type="button" onclick="cerrarModalEditarUsuario()"
                            class="w-full bg-white border border-slate-200 text-slate-600 font-extrabold py-4 rounded-2xl text-xs uppercase">
                        CANCELAR
                    </button>
                    <button type="submit"
                            class="w-full neon-btn text-white font-extrabold py-4 rounded-2xl text-xs uppercase shadow-md">
                        GUARDAR
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===================================================================== -->
    <!-- MODAL: CAMBIAR MI CONTRASEÑA                                         -->
    <!-- ===================================================================== -->
    <div id="modalPassword" class="fixed inset-0 bg-[#0f172a]/70 backdrop-blur-md flex items-center justify-center hidden z-50 p-4">
        <div class="bg-white rounded-[36px] w-full max-w-sm overflow-hidden shadow-2xl relative border border-indigo-500/20">

            <div class="bg-[#0f172a] px-8 py-6 flex items-center justify-between border-b border-indigo-500/20">
                <div>
                    <span class="text-[10px] font-extrabold text-indigo-400 uppercase tracking-widest block mb-1">SEGURIDAD</span>
                    <h3 class="text-xl font-black italic text-white uppercase tracking-tight">CAMBIAR CONTRASEÑA</h3>
                </div>
                <button type="button" onclick="cerrarModalPassword()" class="w-10 h-10 rounded-2xl bg-white/10 hover:bg-rose-500/20 text-white hover:text-rose-400 flex items-center justify-center transition-all">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>

            <form method="POST" action="cambiar_contrasena.php" class="p-8 space-y-4 bg-white">
                <?php echo campo_csrf(); ?>

                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase mb-2 ml-1">CONTRASEÑA ACTUAL</label>
                    <input type="password" name="password_actual" required
                           class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>

                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase mb-2 ml-1">NUEVA CONTRASEÑA</label>
                    <input type="password" name="password_nueva" required minlength="6"
                           class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>

                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase mb-2 ml-1">CONFIRMAR NUEVA</label>
                    <input type="password" name="password_confirmar" required minlength="6"
                           class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>

                <div class="pt-2 grid grid-cols-2 gap-4">
                    <button type="button" onclick="cerrarModalPassword()"
                            class="w-full bg-white border border-slate-200 text-slate-600 font-extrabold py-4 rounded-2xl text-xs uppercase">
                        CANCELAR
                    </button>
                    <button type="submit"
                            class="w-full neon-btn text-white font-extrabold py-4 rounded-2xl text-xs uppercase shadow-md">
                        ACTUALIZAR
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function abrirModalCrear() {
            document.getElementById('modalTitle').innerText = 'NUEVO <?php echo $modal_titulo_singular; ?>';
            document.getElementById('form_id').value = '';

            if (document.getElementById('form_tipo_doc')) document.getElementById('form_tipo_doc').value = 'CC';
            if (document.getElementById('form_documento_id')) document.getElementById('form_documento_id').value = '';
            if (document.getElementById('form_codigo')) document.getElementById('form_codigo').value = '';
            if (document.getElementById('form_nombres')) document.getElementById('form_nombres').value = '';
            if (document.getElementById('form_apellidos')) document.getElementById('form_apellidos').value = '';
            if (document.getElementById('form_email')) document.getElementById('form_email').value = '';
            if (document.getElementById('form_fecha_nac')) document.getElementById('form_fecha_nac').value = '';
            if (document.getElementById('form_telefono')) document.getElementById('form_telefono').value = '';
            if (document.getElementById('form_direccion')) document.getElementById('form_direccion').value = '';
            if (document.getElementById('form_acudiente_nombre')) document.getElementById('form_acudiente_nombre').value = '';
            if (document.getElementById('form_acudiente_telefono')) document.getElementById('form_acudiente_telefono').value = '';
            if (document.getElementById('form_especialidad')) document.getElementById('form_especialidad').value = '';
            if (document.getElementById('form_docente_id')) document.getElementById('form_docente_id').value = '';

            document.getElementById('modalForm').classList.remove('hidden');
        }

        function editarRegistro(data) {
            document.getElementById('modalTitle').innerText = 'EDITAR <?php echo $modal_titulo_singular; ?>';
            document.getElementById('form_id').value = data.id;

            <?php if ($view === 'estudiantes'): ?>
                if (document.getElementById('form_tipo_doc')) document.getElementById('form_tipo_doc').value = data.tipo_documento || 'TI';
                if (document.getElementById('form_documento_id')) document.getElementById('form_documento_id').value = data.documento_id || '';
                
                let partes = (data.nombre || '').split(' ');
                if (document.getElementById('form_nombres')) document.getElementById('form_nombres').value = partes[0] || '';
                if (document.getElementById('form_apellidos')) document.getElementById('form_apellidos').value = partes.slice(1).join(' ') || '';

                if (document.getElementById('form_email')) document.getElementById('form_email').value = data.email || '';
                if (document.getElementById('form_fecha_nac')) document.getElementById('form_fecha_nac').value = data.fecha_nacimiento || '';
                if (document.getElementById('form_telefono')) document.getElementById('form_telefono').value = data.telefono || '';
                if (document.getElementById('form_direccion')) document.getElementById('form_direccion').value = data.direccion || '';
                if (document.getElementById('form_acudiente_nombre')) document.getElementById('form_acudiente_nombre').value = data.acudiente_nombre || '';
                if (document.getElementById('form_acudiente_telefono')) document.getElementById('form_acudiente_telefono').value = data.acudiente_telefono || '';

            <?php elseif ($view === 'docentes'): ?>
                if (document.getElementById('form_tipo_doc')) document.getElementById('form_tipo_doc').value = data.tipo_documento || 'CC';
                if (document.getElementById('form_documento_id')) document.getElementById('form_documento_id').value = data.documento_id || '';

                let partes = (data.nombre || '').split(' ');
                if (document.getElementById('form_nombres')) document.getElementById('form_nombres').value = partes[0] || '';
                if (document.getElementById('form_apellidos')) document.getElementById('form_apellidos').value = partes.slice(1).join(' ') || '';

                if (document.getElementById('form_email')) document.getElementById('form_email').value = data.email || '';
                if (document.getElementById('form_telefono')) document.getElementById('form_telefono').value = data.telefono || '';
                if (document.getElementById('form_especialidad')) document.getElementById('form_especialidad').value = data.especialidad || '';

            <?php else: ?>
                let cod = data.codigo_curso || data.codigo || ('CUR-' + String(data.id).padStart(3, '0'));
                if (document.getElementById('form_codigo')) document.getElementById('form_codigo').value = cod;
                if (document.getElementById('form_nombres')) document.getElementById('form_nombres').value = data.nombre || data.nombre_curso || data.titulo || data.curso || '';
                if (document.getElementById('form_docente_id')) document.getElementById('form_docente_id').value = data.docente_id || '';
            <?php endif; ?>

            document.getElementById('modalForm').classList.remove('hidden');
        }

        function cerrarModal() {
            document.getElementById('modalForm').classList.add('hidden');
        }

        // =====================================================================
        // GESTIÓN DE USUARIOS
        // =====================================================================

        function abrirModalCrearUsuario() {
            document.getElementById('modalCrearUsuario').classList.remove('hidden');
            cambiarTipoPersona();
        }

        function cerrarModalCrearUsuario() {
            document.getElementById('modalCrearUsuario').classList.add('hidden');
        }

        // Alterna entre el selector de personas (docente/estudiante) y el
        // campo de usuario manual (solo para el rol administrador).
        function cambiarTipoPersona() {
            const rol = document.getElementById('cu_rol').value;
            const seccionPersona = document.getElementById('cu_seccion_persona');
            const seccionAdmin = document.getElementById('cu_seccion_admin');
            const grupoEst = document.getElementById('cu_grupo_est');
            const grupoDoc = document.getElementById('cu_grupo_doc');
            const selectPersona = document.getElementById('cu_persona');

            if (rol === 'admin') {
                seccionPersona.classList.add('hidden');
                seccionAdmin.classList.remove('hidden');
                selectPersona.removeAttribute('required');
            } else {
                seccionAdmin.classList.add('hidden');
                seccionPersona.classList.remove('hidden');
                selectPersona.setAttribute('required', 'required');

                // Mostrar solo el grupo correspondiente
                if (rol === 'estudiante') {
                    grupoEst.style.display = '';
                    grupoDoc.style.display = 'none';
                } else {
                    grupoEst.style.display = 'none';
                    grupoDoc.style.display = '';
                }
                selectPersona.selectedIndex = 0;
            }
        }

        function editarUsuario(data) {
            document.getElementById('eu_id').value = data.id;
            document.getElementById('eu_usuario').innerText = data.usuario;
            document.getElementById('eu_vinculo').innerText = data.rol.toUpperCase() + ' — ' + data.vinculo;
            document.getElementById('eu_password').value = '';
            document.getElementById('eu_activo').checked = (data.activo === 1);

            document.getElementById('modalEditarUsuario').classList.remove('hidden');
        }

        function cerrarModalEditarUsuario() {
            document.getElementById('modalEditarUsuario').classList.add('hidden');
        }

        function abrirModalPassword() {
            document.getElementById('modalPassword').classList.remove('hidden');
        }

        function cerrarModalPassword() {
            document.getElementById('modalPassword').classList.add('hidden');
        }
    </script>
</body>
</html>