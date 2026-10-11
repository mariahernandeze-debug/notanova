<?php
// Esta vista solo debe cargarse desde index.php (evita acceso directo)
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    http_response_code(403);
    die('Acceso directo no permitido.');
}

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

requiere_rol('docente');

$docente_id = sesion_user_id();

// Caso límite: la cuenta no está vinculada a un docente activo
if (!$docente_id) {
    flash_error('Tu cuenta no está vinculada a un registro de docente activo.');
    header('Location: logout.php');
    exit;
}

// ==============================================================================
// CONSULTA DE CURSOS Y SELECCIÓN INICIAL
// ==============================================================================

$stmt_cursos = $pdo->prepare("SELECT * FROM cursos WHERE docente_id = ? ORDER BY id ASC");
$stmt_cursos->execute([$docente_id]);
$mis_cursos = $stmt_cursos->fetchAll(PDO::FETCH_ASSOC);

$curso_id = $_GET['curso_id'] ?? ($mis_cursos[0]['id'] ?? null);

function normalizarNombreBloque($texto) {
    $texto = mb_strtolower(trim((string)$texto));
    $texto = preg_replace('/\s+/', ' ', $texto);
    
    if ($texto === '1' || $texto === 'corte_1' || $texto === 'periodo_1') return 'corte 1';
    if ($texto === '2' || $texto === 'corte_2' || $texto === 'periodo_2') return 'corte 2';
    if ($texto === '3' || $texto === 'corte_3' || $texto === 'periodo_3') return 'corte 3';
    if ($texto === '4' || $texto === 'corte_4' || $texto === 'periodo_4') return 'periodo 4';

    return $texto;
}

$mensaje_error = null;
$mensaje_exito = null;
$self_url = htmlspecialchars($_SERVER['PHP_SELF']);

// Mensajes flash (tras redirección)
$flash_exito = tomar_flash_exito();
$flash_error = tomar_flash_error();

$curso_actual = null;
if ($curso_id) {
    $stmt_c = $pdo->prepare("SELECT * FROM cursos WHERE id = ?");
    $stmt_c->execute([$curso_id]);
    $curso_actual = $stmt_c->fetch(PDO::FETCH_ASSOC);
}

$escala_max = floatval($curso_actual['escala'] ?? 5.0);

// ==============================================================================
// PROCESAMIENTO DE FORMULARIOS (POST)
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // GUARDAR MODALIDAD, ESCALA Y PORCENTAJES
    if ($_POST['action'] === 'set_modalidad') {
        $nueva_modalidad = $_POST['modalidad'] ?? 'cortes';
        $nueva_escala = $_POST['escala'] ?? '5.0';
        $pesos_post = $_POST['pesos'] ?? [];

        if (!$curso_id) {
            $mensaje_error = "Error: No se ha seleccionado ningún curso válido.";
        } else {
            $bloques_nuevos = ($nueva_modalidad === 'periodos') 
                ? ['Periodo 1', 'Periodo 2', 'Periodo 3', 'Periodo 4'] 
                : ['Corte 1', 'Corte 2', 'Corte 3'];

            $suma_pesos = 0;
            $pesos_guardar = [];

            foreach ($bloques_nuevos as $b) {
                $valor_peso = floatval($pesos_post[$b] ?? 0);
                $pesos_guardar[$b] = $valor_peso;
                $suma_pesos += $valor_peso;
            }

            if (abs($suma_pesos - 100) > 0.01) {
                $mensaje_error = "La suma de los porcentajes debe ser exactamente 100%. Suma ingresada: {$suma_pesos}%.";
            } else {
                try {
                    $stmt_mod = $pdo->prepare("UPDATE cursos SET modalidad = ?, escala = ? WHERE id = ?");
                    $stmt_mod->execute([$nueva_modalidad, $nueva_escala, $curso_id]);

                    $json_data = json_encode($pesos_guardar, JSON_UNESCAPED_UNICODE);

                    $stmt_save_conf = $pdo->prepare("INSERT INTO config_evaluacion (curso_id, configuracion) 
                                                     VALUES (?, ?) 
                                                     ON DUPLICATE KEY UPDATE configuracion = VALUES(configuracion)");
                    $stmt_save_conf->execute([$curso_id, $json_data]);

                    header("Location: " . $self_url . "?curso_id=" . $curso_id);
                    exit;

                } catch (PDOException $e) {
                    $mensaje_error = "Error al actualizar la configuración: " . $e->getMessage();
                }
            }
        }
    }

    // CREAR ACTIVIDAD
    if ($_POST['action'] === 'crear_actividad') {
        $descripcion = trim($_POST['descripcion'] ?? '');
        $bloque_raw = trim($_POST['bloque'] ?? '');
        $porcentaje = floatval($_POST['porcentaje'] ?? 0);

        if (!$curso_id) {
            $mensaje_error = "Error: No hay un curso activo.";
        } else {
            $stmt_act_bloque = $pdo->prepare("SELECT corte, porcentaje FROM actividades WHERE curso_id = ?");
            $stmt_act_bloque->execute([$curso_id]);
            $actividades_existentes = $stmt_act_bloque->fetchAll(PDO::FETCH_ASSOC);

            $bloque_norm = normalizarNombreBloque($bloque_raw);
            $suma_actual = 0;
            foreach ($actividades_existentes as $act_ex) {
                if (normalizarNombreBloque($act_ex['corte']) === $bloque_norm) {
                    $suma_actual += floatval($act_ex['porcentaje']);
                }
            }

            $porcentaje_disponible = 100 - $suma_actual;

            if (($suma_actual + $porcentaje) > 100.001) {
                $mensaje_error = "No se puede agregar '{$descripcion}'. Supera el 100% de la nota interna de {$bloque_raw}. Acumulado actual: {$suma_actual}%. Disponible: {$porcentaje_disponible}%.";
            } else {
                try {
                    $stmt_act = $pdo->prepare("INSERT INTO actividades (curso_id, nombre, descripcion, corte, porcentaje) VALUES (?, ?, ?, ?, ?)");
                    $stmt_act->execute([$curso_id, $descripcion, $descripcion, $bloque_raw, $porcentaje]);
                    
                    header("Location: " . $self_url . "?curso_id=" . $curso_id);
                    exit;
                } catch (PDOException $e) {
                    $mensaje_error = "Error al guardar la actividad: " . $e->getMessage();
                }
            }
        }
    }

    // EDITAR ACTIVIDAD
    if ($_POST['action'] === 'editar_actividad') {
        $act_id = intval($_POST['actividad_id']);
        $descripcion = trim($_POST['descripcion'] ?? '');
        $bloque_raw = trim($_POST['bloque'] ?? '');
        $porcentaje = floatval($_POST['porcentaje'] ?? 0);

        try {
            $stmt_upd = $pdo->prepare("UPDATE actividades SET nombre = ?, descripcion = ?, corte = ?, porcentaje = ? WHERE actividad_id = ? AND curso_id = ?");
            $stmt_upd->execute([$descripcion, $descripcion, $bloque_raw, $porcentaje, $act_id, $curso_id]);
            
            header("Location: " . $self_url . "?curso_id=" . $curso_id);
            exit;
        } catch (PDOException $e) {
            $mensaje_error = "Error al actualizar la actividad: " . $e->getMessage();
        }
    }

    // ELIMINAR ACTIVIDAD
    if ($_POST['action'] === 'eliminar_actividad') {
        $act_id = intval($_POST['actividad_id']);
        
        try {
            $pdo->beginTransaction();

            $stmt_del_notas = $pdo->prepare("DELETE FROM notas WHERE actividad_id = ? AND curso_id = ?");
            $stmt_del_notas->execute([$act_id, $curso_id]);

            $stmt_del_act = $pdo->prepare("DELETE FROM actividades WHERE actividad_id = ? AND curso_id = ?");
            $stmt_del_act->execute([$act_id, $curso_id]);

            $pdo->commit();

            header("Location: " . $self_url . "?curso_id=" . $curso_id);
            exit;

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $mensaje_error = "Error al eliminar la actividad: " . $e->getMessage();
        }
    }

    // GUARDAR CALIFICACIONES
    if ($_POST['action'] === 'guardar_notas' && isset($_POST['notas'])) {
        try {
            $hay_error_rango = false;

            foreach ($_POST['notas'] as $est_id => $actividades_notas) {
                foreach ($actividades_notas as $act_id => $val_nota) {
                    if ($val_nota !== '') {
                        $nota_num = floatval($val_nota);

                        if ($nota_num < 0.0 || $nota_num > $escala_max) {
                            $mensaje_error = "Error: Las calificaciones deben estar entre 0.0 y " . number_format($escala_max, 1) . ".";
                            $hay_error_rango = true;
                            break 2;
                        }

                        $stmt_act_info = $pdo->prepare("SELECT nombre, corte, porcentaje FROM actividades WHERE actividad_id = ?");
                        $stmt_act_info->execute([$act_id]);
                        $act_data = $stmt_act_info->fetch(PDO::FETCH_ASSOC);

                        $nombre_act = $act_data['nombre'] ?? '';
                        $corte_nombre = $act_data['corte'] ?? 'Corte 1';
                        $porcentaje_val = floatval($act_data['porcentaje'] ?? 0);

                        $stmt_save = $pdo->prepare("INSERT INTO notas (estudiante_id, curso_id, actividad_id, actividad, corte, porcentaje, nota) 
                                                    VALUES (?, ?, ?, ?, ?, ?, ?) 
                                                    ON DUPLICATE KEY UPDATE nota = VALUES(nota)");
                        $stmt_save->execute([$est_id, $curso_id, $act_id, $nombre_act, $corte_nombre, $porcentaje_val, $nota_num]);
                    }
                }
            }
            if (!$hay_error_rango) {
                $mensaje_exito = "Calificaciones guardadas exitosamente.";
            }
        } catch (PDOException $e) {
            $mensaje_error = "Error al guardar calificaciones: " . $e->getMessage();
        }
    }
}

// ==============================================================================
// CARGA DE DATOS ACTUALIZADOS TRAS CUALQUIER OPERACIÓN
// ==============================================================================

if ($curso_id) {
    $stmt_c = $pdo->prepare("SELECT * FROM cursos WHERE id = ?");
    $stmt_c->execute([$curso_id]);
    $curso_actual = $stmt_c->fetch(PDO::FETCH_ASSOC);
}

$modalidad = strtolower(trim($curso_actual['modalidad'] ?? 'cortes')); 
$escala_max = floatval($curso_actual['escala'] ?? 5.0);

$bloques = ($modalidad === 'periodos') 
    ? ['Periodo 1', 'Periodo 2', 'Periodo 3', 'Periodo 4'] 
    : ['Corte 1', 'Corte 2', 'Corte 3'];

$pesos_bloques = ($modalidad === 'periodos')
    ? ['Periodo 1' => 25, 'Periodo 2' => 25, 'Periodo 3' => 25, 'Periodo 4' => 25]
    : ['Corte 1' => 30, 'Corte 2' => 30, 'Corte 3' => 40];

if ($curso_id) {
    try {
        $stmt_conf = $pdo->prepare("SELECT configuracion FROM config_evaluacion WHERE curso_id = ?");
        $stmt_conf->execute([$curso_id]);
        $row_conf = $stmt_conf->fetch(PDO::FETCH_ASSOC);

        if ($row_conf && !empty($row_conf['configuracion'])) {
            $json_conf = json_decode($row_conf['configuracion'], true);
            if (is_array($json_conf)) {
                foreach ($json_conf as $clave_b => $val_peso) {
                    $pesos_bloques[$clave_b] = floatval($val_peso);
                }
            }
        }
    } catch (PDOException $e) {}
}

$actividades_por_bloque = [];
$totales_por_bloque = [];

foreach ($bloques as $b) {
    $actividades_por_bloque[$b] = [];
    $totales_por_bloque[$b] = 0;
}

$todas_actividades = [];
if ($curso_id) {
    try {
        $stmt_act_list = $pdo->prepare("SELECT * FROM actividades WHERE curso_id = ? ORDER BY actividad_id ASC");
        $stmt_act_list->execute([$curso_id]);
        $todas_actividades = $stmt_act_list->fetchAll(PDO::FETCH_ASSOC);

        foreach ($todas_actividades as &$act) {
            $act['actividad_id'] = $act['actividad_id'] ?? $act['id'] ?? null;
            if (empty($act['descripcion']) && !empty($act['nombre'])) {
                $act['descripcion'] = $act['nombre'];
            }

            $corte_norm = normalizarNombreBloque($act['corte']);
            $encontrado = false;

            foreach ($bloques as $b) {
                if (normalizarNombreBloque($b) === $corte_norm) {
                    $actividades_por_bloque[$b][] = $act;
                    $totales_por_bloque[$b] += floatval($act['porcentaje']);
                    $encontrado = true;
                    break;
                }
            }

            if (!$encontrado) {
                $bloque_fallback = $bloques[0];
                $actividades_por_bloque[$bloque_fallback][] = $act;
                $totales_por_bloque[$bloque_fallback] += floatval($act['porcentaje']);
            }
        }
    } catch (PDOException $e) {
        $mensaje_error = "Error al consultar actividades: " . $e->getMessage();
    }
}

// CORRECCIÓN: CARGAR ÚNICAMENTE ESTUDIANTES MATRICULADOS EN EL CURSO ACTIVO
$estudiantes = [];
if ($curso_id) {
    try {
        $stmt_est = $pdo->prepare("
            SELECT e.* 
            FROM estudiantes e
            INNER JOIN curso_estudiante ce ON e.id = ce.estudiante_id
            WHERE ce.curso_id = ?
            ORDER BY e.nombre ASC
        ");
        $stmt_est->execute([$curso_id]);
        $estudiantes = $stmt_est->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $mensaje_error = "Error al cargar estudiantes del curso: " . $e->getMessage();
    }
}

$notas_guardadas = [];
if ($curso_id && !empty($todas_actividades)) {
    try {
        $stmt_notas = $pdo->prepare("SELECT * FROM notas WHERE curso_id = ?");
        $stmt_notas->execute([$curso_id]);
        $rows_notas = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows_notas as $rn) {
            $e_id = $rn['estudiante_id'] ?? null;
            $a_id = $rn['actividad_id'] ?? null;

            if ($e_id && $a_id) {
                $notas_guardadas[$e_id][$a_id] = $rn['nota'];
            }
        }
    } catch (PDOException $e) {}
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NotaNova | Portal Docente</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,600;0,700;0,800;1,800;1,900&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .neon-text-cyan { color: #00f0ff; text-shadow: 0 0 10px rgba(0, 240, 255, 0.5); }
        .neon-btn-emerald {
            background: linear-gradient(135deg, #059669 0%, #10b981 50%, #34d399 100%);
            box-shadow: 0 0 20px rgba(16, 185, 129, 0.35);
        }
        .neon-btn-emerald:hover { box-shadow: 0 0 30px rgba(52, 211, 153, 0.55); }
        .custom-scroll-x::-webkit-scrollbar { height: 10px; }
        .custom-scroll-x::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 10px; }
        .custom-scroll-x::-webkit-scrollbar-thumb { background: #059669; border-radius: 10px; }
    </style>
</head>
<body class="min-h-screen flex flex-col text-slate-800">

    <header class="bg-[#032e22] text-white px-8 py-5 flex items-center justify-between shadow-xl border-b border-emerald-500/20">
        <div class="flex items-center gap-4">
            <h1 class="text-2xl font-black italic tracking-tight neon-text-cyan">NotaNova</h1>
            <span class="bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 text-[10px] font-black px-3 py-1 rounded-full tracking-widest uppercase">
                PORTAL DOCENTE
            </span>
        </div>

        <div class="flex items-center gap-3">
            <span class="hidden md:flex items-center gap-2 text-xs font-bold text-emerald-200/80 border border-white/10 bg-white/5 px-4 py-2 rounded-full">
                <i class="fa-solid fa-user-tie text-emerald-400"></i><?php echo htmlspecialchars(sesion_nombre()); ?>
            </span>

            <button onclick="abrirModalPassword()" title="Cambiar contraseña"
                    class="px-4 py-2 rounded-full bg-white/10 hover:bg-emerald-500/20 hover:text-emerald-400 border border-white/10 flex items-center gap-2 text-white text-xs font-bold transition-all">
                <i class="fa-solid fa-key"></i>
                <span class="hidden lg:inline">Contraseña</span>
            </button>

            <a href="logout.php" title="Cerrar Sesión" class="px-5 py-2 rounded-full bg-white/10 hover:bg-rose-500/20 hover:text-rose-400 border border-white/10 flex items-center gap-2 text-white text-xs font-bold transition-all">
                <span>Salir</span>
                <i class="fa-solid fa-right-from-bracket"></i>
            </a>
        </div>
    </header>

    <main class="max-w-[1700px] w-full mx-auto p-6 md:p-10 flex-1 grid grid-cols-1 lg:grid-cols-12 gap-8">
        
        <aside class="lg:col-span-3 space-y-4">
            <span class="text-[11px] font-black text-slate-400 uppercase tracking-widest block mb-4 ml-1">
                MIS CURSOS ASIGNADOS
            </span>

            <div class="space-y-3">
                <?php if (empty($mis_cursos)): ?>
                    <div class="p-6 bg-white rounded-3xl border border-slate-200 text-slate-400 font-bold text-xs text-center">
                        No tienes cursos asignados.
                    </div>
                <?php else: ?>
                    <?php foreach ($mis_cursos as $c): ?>
                        <?php 
                            $es_activo = ($curso_actual && $curso_actual['id'] == $c['id']); 
                            $codigo_c = $c['codigo_curso'] ?? $c['codigo'] ?? ('MAT-' . str_pad($c['id'], 3, '0', STR_PAD_LEFT));
                            $nombre_c = $c['nombre'] ?? $c['nombre_curso'] ?? $c['titulo'] ?? 'Curso';
                        ?>
                        <a href="<?php echo $self_url; ?>?curso_id=<?php echo $c['id']; ?>" 
                           class="block transition-all transform hover:-translate-y-1 rounded-[28px] p-6 shadow-md border <?php echo $es_activo ? 'neon-btn-emerald text-white border-emerald-400 shadow-emerald-500/20' : 'bg-white hover:bg-slate-50 text-slate-800 border-slate-200/80'; ?>">
                            <span class="text-[10px] font-black tracking-wider uppercase opacity-80 block mb-1">
                                <?php echo htmlspecialchars($codigo_c); ?>
                            </span>
                            <h3 class="text-xl font-black italic tracking-tight uppercase leading-tight">
                                <?php echo htmlspecialchars($nombre_c); ?>
                            </h3>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </aside>

        <section class="lg:col-span-9 space-y-6">
            
            <?php if (!empty($flash_exito)): ?>
                <div class="p-4 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 font-bold rounded-r-2xl text-sm shadow-md flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-circle-check text-emerald-500 text-lg"></i>
                        <span><?php echo htmlspecialchars($flash_exito); ?></span>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-emerald-400 hover:text-emerald-700 font-bold"><i class="fa-solid fa-xmark"></i></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($flash_error)): ?>
                <div class="p-4 bg-rose-50 border-l-4 border-rose-500 text-rose-800 font-bold rounded-r-2xl text-sm shadow-md flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-triangle-exclamation text-rose-500 text-lg"></i>
                        <span><?php echo htmlspecialchars($flash_error); ?></span>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-rose-400 hover:text-rose-700 font-bold"><i class="fa-solid fa-xmark"></i></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($mensaje_error)): ?>
                <div class="p-5 bg-rose-50 border-l-4 border-rose-500 text-rose-800 font-bold rounded-r-2xl text-sm shadow-md flex items-start justify-between gap-4">
                    <div class="flex items-start gap-3">
                        <i class="fa-solid fa-triangle-exclamation text-rose-500 text-xl mt-0.5"></i>
                        <div>
                            <span class="block text-xs uppercase tracking-wider font-black text-rose-600 mb-0.5">ALERTA</span>
                            <?php echo htmlspecialchars($mensaje_error); ?>
                        </div>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-rose-400 hover:text-rose-700 font-bold"><i class="fa-solid fa-xmark"></i></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($mensaje_exito)): ?>
                <div class="p-4 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 font-bold rounded-r-2xl text-sm shadow-md flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-circle-check text-emerald-500 text-lg"></i>
                        <span><?php echo htmlspecialchars($mensaje_exito); ?></span>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-emerald-400 hover:text-emerald-700 font-bold"><i class="fa-solid fa-xmark"></i></button>
                </div>
            <?php endif; ?>

            <div class="bg-white border border-slate-200/80 rounded-[36px] p-8 shadow-xl flex flex-col lg:flex-row lg:items-center justify-between gap-6">
                <div>
                    <div class="flex items-center gap-2 mb-2 flex-wrap">
                        <span class="bg-emerald-100 text-emerald-800 font-extrabold text-[10px] px-4 py-1 rounded-full uppercase tracking-wider border border-emerald-200">
                            ESCALA: 0.0 - <?php echo number_format($escala_max, 1); ?>
                        </span>
                        <span class="bg-slate-100 text-slate-600 font-extrabold text-[10px] px-3 py-1 rounded-full uppercase tracking-wider border border-slate-200">
                            MODALIDAD: <?php echo strtoupper($modalidad); ?>
                        </span>
                    </div>
                    <h2 class="text-3xl md:text-4xl font-black italic tracking-tight text-slate-900 uppercase">
                        PLANILLA DE CONTROL
                    </h2>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button onclick="abrirModalConfiguracion()" 
                            class="bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 px-5 py-3.5 rounded-2xl font-black text-xs tracking-wider uppercase transition-all flex items-center gap-2">
                        <i class="fa-solid fa-gear text-slate-500"></i> CONFIGURACIÓN & PORCENTAJES
                    </button>

                    <button onclick="abrirModalCrear()" 
                            class="neon-btn-emerald text-white px-7 py-3.5 rounded-2xl font-black text-xs tracking-wider uppercase transition-all transform hover:-translate-y-0.5 flex items-center justify-center gap-2">
                        <i class="fa-solid fa-plus"></i> NUEVA ACTIVIDAD
                    </button>
                </div>
            </div>

            <form method="POST" action="<?php echo $self_url; ?>?curso_id=<?php echo $curso_id; ?>" onsubmit="return validarPlanillaAntesDeEnviar()">
                <input type="hidden" name="action" value="guardar_notas">

                <div class="bg-white border border-slate-200/80 rounded-[36px] overflow-hidden shadow-xl mb-6">
                    <div class="overflow-x-auto custom-scroll-x p-4">
                        <table class="w-full text-left border-collapse min-w-[1000px]">
                            <thead>
                                <tr class="border-b-2 border-slate-200 bg-slate-50/80 text-xs font-black text-slate-600 uppercase">
                                    <th class="p-6 min-w-[220px] bg-white sticky left-0 z-10 border-r border-slate-100">ESTUDIANTE</th>
                                    
                                    <?php foreach ($bloques as $b): ?>
                                        <?php 
                                            $act_count = count($actividades_por_bloque[$b]);
                                            $colspan = max($act_count, 1) + 1; 
                                            $total_pct = $totales_por_bloque[$b];
                                            $peso_bloque = $pesos_bloques[$b] ?? 0;
                                        ?>
                                        <th colspan="<?php echo $colspan; ?>" class="p-4 text-center border-l-2 border-slate-200/80 <?php echo $total_pct > 100 ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50/40 text-emerald-900'; ?>">
                                            <div class="flex flex-col items-center justify-center gap-1">
                                                <div class="flex items-center gap-2">
                                                    <span><?php echo strtoupper($b); ?></span>
                                                    <span class="text-[10px] px-2.5 py-0.5 rounded-full font-black bg-emerald-600 text-white shadow-sm">
                                                        VALOR: <?php echo number_format($peso_bloque, 1); ?>%
                                                    </span>
                                                </div>
                                                <span class="text-[10px] px-2 py-0.5 rounded-md font-bold border <?php echo $total_pct == 100 ? 'bg-emerald-100 text-emerald-800 border-emerald-300' : ($total_pct > 100 ? 'bg-rose-200 text-rose-900 border-rose-400' : 'bg-amber-100 text-amber-800 border-amber-300'); ?>">
                                                    Interno: <?php echo number_format($total_pct, 1); ?>% / 100%
                                                </span>
                                            </div>
                                        </th>
                                    <?php endforeach; ?>

                                    <th class="p-6 text-center border-l-2 border-slate-300 bg-indigo-50/50 text-indigo-900 font-black min-w-[120px]">
                                        DEFINITIVA
                                    </th>
                                </tr>

                                <tr class="border-b border-slate-200 text-slate-400 text-[10px] font-black uppercase tracking-wider">
                                    <th class="p-4 bg-white sticky left-0 z-10 border-r border-slate-100"></th>
                                    
                                    <?php foreach ($bloques as $b): ?>
                                        <?php if (empty($actividades_por_bloque[$b])): ?>
                                            <th class="p-4 text-center italic text-slate-300 font-normal min-w-[140px] border-l-2 border-slate-200">
                                                Sin Actividades
                                            </th>
                                        <?php else: ?>
                                            <?php foreach ($actividades_por_bloque[$b] as $idx => $act): ?>
                                                <th class="p-3 text-center min-w-[140px] <?php echo $idx === 0 ? 'border-l-2 border-slate-200' : ''; ?>">
                                                    <div class="flex items-center justify-center gap-1.5">
                                                        <span class="font-extrabold text-slate-700 uppercase" title="<?php echo htmlspecialchars($act['descripcion']); ?>">
                                                            <?php echo htmlspecialchars($act['descripcion']); ?>
                                                        </span>
                                                        
                                                        <button type="button" onclick='abrirModalEditar(<?php echo json_encode($act); ?>)' class="text-indigo-500 hover:text-indigo-700 transition-colors" title="Editar Actividad">
                                                            <i class="fa-solid fa-pen-to-square"></i>
                                                        </button>
                                                        
                                                        <button type="button" onclick='abrirModalEliminar(<?php echo json_encode($act); ?>)' class="text-rose-400 hover:text-rose-600 transition-colors" title="Eliminar Actividad">
                                                            <i class="fa-solid fa-trash-can"></i>
                                                        </button>
                                                    </div>
                                                    <div class="text-emerald-600 font-black text-[11px] mt-0.5">
                                                        <?php echo number_format($act['porcentaje'], 1); ?>%
                                                    </div>
                                                </th>
                                            <?php endforeach; ?>
                                        <?php endif; ?>

                                        <th class="p-3 text-center min-w-[100px] bg-slate-100/70 text-slate-700 font-black border-l border-slate-200">
                                            PROM.
                                        </th>
                                    <?php endforeach; ?>

                                    <th class="p-3 text-center border-l-2 border-slate-300 bg-indigo-50/50">DEFINITIVA</th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-slate-100 text-sm">
                                <?php if (empty($estudiantes)): ?>
                                    <tr>
                                        <td colspan="30" class="p-12 text-center text-slate-400 font-bold">No hay estudiantes matriculados en esta asignatura.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($estudiantes as $est): ?>
                                        <tr class="hover:bg-slate-50/60 transition-colors fila-estudiante" data-estudiante-id="<?php echo $est['id']; ?>">
                                            
                                            <td class="p-5 font-black text-slate-900 uppercase bg-white sticky left-0 z-10 border-r border-slate-100 shadow-sm">
                                                <div class="text-sm italic leading-tight"><?php echo htmlspecialchars($est['nombre']); ?></div>
                                                <div class="text-[10px] font-semibold text-slate-400 tracking-wider mt-0.5">
                                                    ID: <?php echo htmlspecialchars($est['documento_id'] ?? $est['id']); ?>
                                                </div>
                                            </td>

                                            <?php foreach ($bloques as $b): ?>
                                                <?php if (empty($actividades_por_bloque[$b])): ?>
                                                    <td class="p-4 text-center border-l-2 border-slate-200 bg-slate-50/30 text-slate-300">—</td>
                                                <?php else: ?>
                                                    <?php foreach ($actividades_por_bloque[$b] as $idx => $act): ?>
                                                        <?php 
                                                            $act_key = $act['actividad_id'];
                                                            $val_nota = $notas_guardadas[$est['id']][$act_key] ?? ''; 
                                                        ?>
                                                        <td class="p-3 text-center <?php echo $idx === 0 ? 'border-l-2 border-slate-200' : ''; ?>">
                                                            <input type="number" 
                                                                   step="0.1" min="0" max="<?php echo $escala_max; ?>" 
                                                                   placeholder="0.0" 
                                                                   name="notas[<?php echo $est['id']; ?>][<?php echo $act_key; ?>]"
                                                                   value="<?php echo $val_nota !== '' ? htmlspecialchars($val_nota) : ''; ?>"
                                                                   data-bloque="<?php echo htmlspecialchars($b); ?>"
                                                                   data-porcentaje="<?php echo floatval($act['porcentaje']); ?>"
                                                                   oninput="validarYRecalcularNota(this)"
                                                                   class="input-nota w-14 h-11 text-center bg-slate-50 border border-slate-200 rounded-xl font-extrabold text-slate-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:bg-white transition-all shadow-inner">
                                                        </td>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>

                                                <td class="p-3 text-center font-black text-slate-800 bg-slate-100/50 border-l border-slate-200">
                                                    <span class="prom-bloque text-xs bg-white border border-slate-200 px-2.5 py-1 rounded-lg" data-bloque-prom="<?php echo htmlspecialchars($b); ?>" data-peso-bloque="<?php echo floatval($pesos_bloques[$b] ?? 0); ?>">0.0</span>
                                                </td>
                                            <?php endforeach; ?>

                                            <td class="p-5 text-center font-black border-l-2 border-slate-300 bg-indigo-50/30 text-indigo-900 text-base">
                                                <span class="prom-definitiva bg-indigo-600 text-white px-3 py-1.5 rounded-xl shadow-md">0.0</span>
                                            </td>

                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="neon-btn-emerald text-white px-8 py-4 rounded-2xl font-black text-xs uppercase tracking-wider shadow-xl flex items-center gap-2 transition-all transform hover:-translate-y-1">
                        <i class="fa-solid fa-floppy-disk text-base"></i> GUARDAR NOTAS DE PLANILLA
                    </button>
                </div>
            </form>

        </section>
    </main>

    <!-- MODAL CONFIGURACIÓN CON PORCENTAJES Y ESCALA DE NOTAS -->
    <div id="modalConfiguracion" class="fixed inset-0 bg-[#031d16]/75 backdrop-blur-md flex items-center justify-center hidden z-50 p-4">
        <div class="bg-white rounded-[40px] w-full max-w-lg overflow-hidden shadow-2xl relative border border-emerald-500/20 max-h-[90vh] flex flex-col">
            <div class="bg-[#032e22] px-8 py-6 flex items-center justify-between border-b border-emerald-500/20 shrink-0">
                <div>
                    <span class="text-[10px] font-extrabold text-emerald-400 uppercase tracking-widest block mb-1">CONFIGURACIÓN DEL CURSO</span>
                    <h3 class="text-2xl font-black italic text-white uppercase tracking-tight">ESTRATEGIA DE EVALUACIÓN</h3>
                </div>
                <button type="button" onclick="cerrarModalConfiguracion()" class="w-10 h-10 rounded-2xl bg-white/10 hover:bg-rose-500/20 text-white hover:text-rose-400 flex items-center justify-center transition-all">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>

            <form method="POST" action="<?php echo $self_url; ?>?curso_id=<?php echo $curso_id; ?>" onsubmit="return validarSumaPesosConfig(event)" class="p-8 space-y-6 overflow-y-auto flex-1 bg-white">
                <input type="hidden" name="action" value="set_modalidad">

                <div id="configAlertError" class="hidden p-4 bg-rose-50 border-l-4 border-rose-500 text-rose-800 font-bold rounded-r-xl text-xs"></div>

                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase mb-3 ml-1">1. SELECCIONA EL MODO DE TRABAJO</label>
                    <div class="grid grid-cols-2 gap-3">
                        <label id="labelCortes" class="flex items-center gap-3 p-4 rounded-2xl border-2 cursor-pointer transition-all hover:bg-slate-50 <?php echo $modalidad === 'cortes' ? 'border-emerald-500 bg-emerald-50/30' : 'border-slate-200'; ?>">
                            <input type="radio" name="modalidad" value="cortes" <?php echo $modalidad === 'cortes' ? 'checked' : ''; ?> onchange="alternarCamposPesos('cortes')" class="w-4 h-4 text-emerald-600 focus:ring-emerald-500">
                            <div>
                                <span class="block font-black text-slate-800 text-xs">3 CORTES</span>
                                <span class="block text-[10px] text-slate-400 font-bold">Universidad</span>
                            </div>
                        </label>

                        <label id="labelPeriodos" class="flex items-center gap-3 p-4 rounded-2xl border-2 cursor-pointer transition-all hover:bg-slate-50 <?php echo $modalidad === 'periodos' ? 'border-emerald-500 bg-emerald-50/30' : 'border-slate-200'; ?>">
                            <input type="radio" name="modalidad" value="periodos" <?php echo $modalidad === 'periodos' ? 'checked' : ''; ?> onchange="alternarCamposPesos('periodos')" class="w-4 h-4 text-emerald-600 focus:ring-emerald-500">
                            <div>
                                <span class="block font-black text-slate-800 text-xs">4 PERIODOS</span>
                                <span class="block text-[10px] text-slate-400 font-bold">Colegios</span>
                            </div>
                        </label>
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase mb-3 ml-1">2. ESCALA DE CALIFICACIÓN</label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="flex items-center gap-3 p-3.5 rounded-2xl border-2 border-slate-200 cursor-pointer hover:bg-slate-50">
                            <input type="radio" name="escala" value="5.0" <?php echo $escala_max == 5.0 ? 'checked' : ''; ?> class="w-4 h-4 text-emerald-600 focus:ring-emerald-500">
                            <div>
                                <span class="block font-black text-slate-800 text-xs">0.0 A 5.0</span>
                                <span class="block text-[10px] text-slate-400 font-bold">Escala estándar</span>
                            </div>
                        </label>

                        <label class="flex items-center gap-3 p-3.5 rounded-2xl border-2 border-slate-200 cursor-pointer hover:bg-slate-50">
                            <input type="radio" name="escala" value="10.0" <?php echo $escala_max == 10.0 ? 'checked' : ''; ?> class="w-4 h-4 text-emerald-600 focus:ring-emerald-500">
                            <div>
                                <span class="block font-black text-slate-800 text-xs">0.0 A 10.0</span>
                                <span class="block text-[10px] text-slate-400 font-bold">Escala ampliada</span>
                            </div>
                        </label>
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-2 ml-1">
                        <label class="block text-[11px] font-black text-slate-400 uppercase">3. ASIGNA EL PORCENTAJE A CADA BLOQUE</label>
                        <span id="sumaActualBadge" class="text-[11px] font-black px-2.5 py-0.5 rounded-full bg-emerald-100 text-emerald-800">Suma: 100%</span>
                    </div>

                    <!-- CORTES (3) -->
                    <div id="seccionCortes" class="space-y-3 <?php echo $modalidad === 'periodos' ? 'hidden' : ''; ?>">
                        <?php 
                        $cortes_arr = ['Corte 1', 'Corte 2', 'Corte 3'];
                        foreach ($cortes_arr as $c_name): 
                            $val_c = $pesos_bloques[$c_name] ?? ($c_name === 'Corte 3' ? 40 : 30);
                        ?>
                            <div class="flex items-center justify-between p-3.5 bg-slate-50 border border-slate-200/80 rounded-2xl">
                                <span class="font-extrabold text-slate-700 text-xs uppercase"><?php echo $c_name; ?></span>
                                <div class="flex items-center gap-2">
                                    <input type="number" step="any" min="0" max="100" name="pesos[<?php echo $c_name; ?>]" value="<?php echo $val_c; ?>" oninput="recalcularSumaConfig()" class="input-peso-corte w-20 text-center bg-white border border-slate-200 rounded-xl py-2 font-black text-slate-800 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                    <span class="font-bold text-slate-400 text-xs">%</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- PERIODOS (4) -->
                    <div id="seccionPeriodos" class="space-y-3 <?php echo $modalidad === 'cortes' ? 'hidden' : ''; ?>">
                        <?php 
                        $periodos_arr = ['Periodo 1', 'Periodo 2', 'Periodo 3', 'Periodo 4'];
                        foreach ($periodos_arr as $p_name): 
                            $val_p = $pesos_bloques[$p_name] ?? 25;
                        ?>
                            <div class="flex items-center justify-between p-3.5 bg-slate-50 border border-slate-200/80 rounded-2xl">
                                <span class="font-extrabold text-slate-700 text-xs uppercase"><?php echo $p_name; ?></span>
                                <div class="flex items-center gap-2">
                                    <input type="number" step="any" min="0" max="100" name="pesos[<?php echo $p_name; ?>]" value="<?php echo $val_p; ?>" oninput="recalcularSumaConfig()" class="input-peso-periodo w-20 text-center bg-white border border-slate-200 rounded-xl py-2 font-black text-slate-800 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                    <span class="font-bold text-slate-400 text-xs">%</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4 pt-2 shrink-0">
                    <button type="button" onclick="cerrarModalConfiguracion()" class="w-full bg-white border border-slate-200 text-slate-600 font-extrabold py-4 rounded-2xl text-xs uppercase hover:bg-slate-50 transition-all">
                        CANCELAR
                    </button>
                    <button type="submit" class="w-full neon-btn-emerald text-white font-extrabold py-4 rounded-2xl text-xs uppercase shadow-lg transition-all">
                        GUARDAR CAMBIOS
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL CREAR / EDITAR ACTIVIDAD -->
    <div id="modalActividad" class="fixed inset-0 bg-[#031d16]/75 backdrop-blur-md flex items-center justify-center hidden z-50 p-4">
        <div class="bg-white rounded-[40px] w-full max-w-md overflow-hidden shadow-2xl relative border border-emerald-500/20">
            
            <div class="bg-[#032e22] px-8 py-7 flex items-center justify-between border-b border-emerald-500/20">
                <div>
                    <span class="text-[10px] font-extrabold text-emerald-400 uppercase tracking-widest block mb-1">PANEL DOCENTE</span>
                    <h3 id="modalTitle" class="text-2xl font-black italic text-white uppercase tracking-tight">NUEVA ACTIVIDAD</h3>
                </div>
                <button type="button" onclick="cerrarModalActividad()" class="w-10 h-10 rounded-2xl bg-white/10 hover:bg-rose-500/20 text-white hover:text-rose-400 flex items-center justify-center transition-all">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>

            <form id="formActividad" method="POST" action="<?php echo $self_url; ?>?curso_id=<?php echo $curso_id; ?>" onsubmit="return validarPorcentajeModal(event)" class="p-8 space-y-5 bg-white">
                <input type="hidden" name="action" id="form_action" value="crear_actividad">
                <input type="hidden" name="actividad_id" id="form_actividad_id" value="">

                <div id="modalAlertError" class="hidden p-4 bg-rose-50 border-l-4 border-rose-500 text-rose-800 font-bold rounded-r-xl text-xs"></div>

                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase mb-2 ml-1">DESTINO / BLOQUE</label>
                    <select name="bloque" id="form_bloque" onchange="actualizarInfoPorcentajeDisponible()" class="w-full bg-slate-50 border border-slate-200/80 rounded-2xl px-4 py-4 font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 transition-all">
                        <?php foreach ($bloques as $b): ?>
                            <option value="<?php echo htmlspecialchars($b); ?>"><?php echo htmlspecialchars($b); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase mb-2 ml-1">NOMBRE DE LA ACTIVIDAD</label>
                    <input type="text" name="descripcion" id="form_desc" required placeholder="Ej: Parcial, Talleres, Tarea 1..." 
                           class="w-full bg-slate-50 border border-slate-200/80 rounded-2xl px-5 py-4 font-bold text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-emerald-500 transition-all">
                </div>

                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase mb-2 ml-1">PORCENTAJE INTERNO %</label>
                    <input type="number" step="any" name="porcentaje" id="form_pct" required placeholder="Ej: 25" 
                           class="w-full bg-slate-50 border border-slate-200/80 rounded-2xl px-5 py-4 font-bold text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-emerald-500 transition-all">
                </div>

                <div class="text-[11px] text-slate-500 font-bold bg-slate-50 p-3 rounded-xl border border-slate-200" id="infoDisponible">
                    Cargando disponibilidad...
                </div>

                <div class="pt-2 grid grid-cols-2 gap-4">
                    <button type="button" onclick="cerrarModalActividad()" 
                            class="w-full bg-white border border-slate-200 text-slate-600 font-extrabold py-4 rounded-2xl text-xs uppercase tracking-wider hover:bg-slate-50 transition-all">
                        CANCELAR
                    </button>
                    <button type="submit" 
                            class="w-full neon-btn-emerald text-white font-extrabold py-4 rounded-2xl text-xs uppercase tracking-wider transition-all shadow-lg">
                        GUARDAR
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL ELIMINAR ACTIVIDAD -->
    <div id="modalEliminar" class="fixed inset-0 bg-[#031d16]/75 backdrop-blur-md flex items-center justify-center hidden z-50 p-4">
        <div class="bg-white rounded-[36px] w-full max-w-sm overflow-hidden shadow-2xl relative p-8 text-center border border-rose-500/20 space-y-5">
            <div class="w-16 h-16 bg-rose-100 text-rose-500 rounded-full flex items-center justify-center mx-auto text-2xl">
                <i class="fa-solid fa-trash-can"></i>
            </div>
            <div>
                <h3 class="text-xl font-black text-slate-900 uppercase">¿Eliminar Actividad?</h3>
                <p class="text-xs text-slate-500 mt-1" id="del_act_info">Esta acción quitará la columna de la planilla y borrará las notas registradas de esta actividad.</p>
            </div>

            <form method="POST" action="<?php echo $self_url; ?>?curso_id=<?php echo $curso_id; ?>" class="grid grid-cols-2 gap-3">
                <input type="hidden" name="action" value="eliminar_actividad">
                <input type="hidden" name="actividad_id" id="del_act_id" value="">

                <button type="button" onclick="cerrarModalEliminar()" class="w-full bg-slate-100 text-slate-600 font-extrabold py-3.5 rounded-2xl text-xs uppercase">
                    CANCELAR
                </button>
                <button type="submit" class="w-full bg-rose-600 hover:bg-rose-700 text-white font-extrabold py-3.5 rounded-2xl text-xs uppercase shadow-md transition-all">
                    ELIMINAR
                </button>
            </form>
        </div>
    </div>

    <!-- MODAL CAMBIAR CONTRASEÑA -->
    <div id="modalPassword" class="fixed inset-0 bg-[#031d16]/75 backdrop-blur-md flex items-center justify-center hidden z-50 p-4">
        <div class="bg-white rounded-[36px] w-full max-w-sm overflow-hidden shadow-2xl relative border border-emerald-500/20">

            <div class="bg-[#032e22] px-8 py-6 flex items-center justify-between border-b border-emerald-500/20">
                <div>
                    <span class="text-[10px] font-extrabold text-emerald-400 uppercase tracking-widest block mb-1">SEGURIDAD</span>
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
                           class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                </div>

                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase mb-2 ml-1">NUEVA CONTRASEÑA</label>
                    <input type="password" name="password_nueva" required minlength="6"
                           class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                </div>

                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase mb-2 ml-1">CONFIRMAR NUEVA</label>
                    <input type="password" name="password_confirmar" required minlength="6"
                           class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                </div>

                <div class="pt-2 grid grid-cols-2 gap-4">
                    <button type="button" onclick="cerrarModalPassword()"
                            class="w-full bg-white border border-slate-200 text-slate-600 font-extrabold py-4 rounded-2xl text-xs uppercase">
                        CANCELAR
                    </button>
                    <button type="submit"
                            class="w-full neon-btn-emerald text-white font-extrabold py-4 rounded-2xl text-xs uppercase shadow-md transition-all">
                        ACTUALIZAR
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const totalesPorBloque = <?php echo json_encode($totales_por_bloque); ?>;
        const escalaMaxJS = <?php echo $escala_max; ?>;
        let actividadEditandoId = null;
        let actividadEditandoPctOriginal = 0;
        let actividadEditandoBloqueOriginal = "";

        function normalizarTextoJS(texto) {
            if (!texto) return '';
            return texto.toString().trim().toLowerCase().replace(/\s+/g, ' ');
        }

        function alternarCamposPesos(modalidad) {
            const secCortes = document.getElementById('seccionCortes');
            const secPeriodos = document.getElementById('seccionPeriodos');
            const lblCortes = document.getElementById('labelCortes');
            const lblPeriodos = document.getElementById('labelPeriodos');

            if (modalidad === 'periodos') {
                secCortes.classList.add('hidden');
                secPeriodos.classList.remove('hidden');
                lblPeriodos.className = "flex items-center gap-3 p-4 rounded-2xl border-2 cursor-pointer transition-all border-emerald-500 bg-emerald-50/30";
                lblCortes.className = "flex items-center gap-3 p-4 rounded-2xl border-2 cursor-pointer transition-all border-slate-200 hover:bg-slate-50";
            } else {
                secPeriodos.classList.add('hidden');
                secCortes.classList.remove('hidden');
                lblCortes.className = "flex items-center gap-3 p-4 rounded-2xl border-2 cursor-pointer transition-all border-emerald-500 bg-emerald-50/30";
                lblPeriodos.className = "flex items-center gap-3 p-4 rounded-2xl border-2 cursor-pointer transition-all border-slate-200 hover:bg-slate-50";
            }
            recalcularSumaConfig();
        }

        function recalcularSumaConfig() {
            const modalidad = document.querySelector('input[name="modalidad"]:checked').value;
            const inputs = (modalidad === 'periodos') 
                ? document.querySelectorAll('.input-peso-periodo') 
                : document.querySelectorAll('.input-peso-corte');

            let suma = 0;
            inputs.forEach(i => {
                suma += parseFloat(i.value || 0);
            });

            const badge = document.getElementById('sumaActualBadge');
            badge.innerText = `Suma: ${suma.toFixed(1)}%`;

            if (Math.abs(suma - 100) < 0.01) {
                badge.className = "text-[11px] font-black px-2.5 py-0.5 rounded-full bg-emerald-100 text-emerald-800";
            } else {
                badge.className = "text-[11px] font-black px-2.5 py-0.5 rounded-full bg-rose-100 text-rose-800";
            }
        }

        function validarSumaPesosConfig(e) {
            const modalidad = document.querySelector('input[name="modalidad"]:checked').value;
            const inputs = (modalidad === 'periodos') 
                ? document.querySelectorAll('.input-peso-periodo') 
                : document.querySelectorAll('.input-peso-corte');

            let suma = 0;
            inputs.forEach(i => {
                suma += parseFloat(i.value || 0);
            });

            if (Math.abs(suma - 100) > 0.01) {
                const boxAlert = document.getElementById('configAlertError');
                boxAlert.innerHTML = `<i class="fa-solid fa-circle-xmark mr-1"></i> La suma de los porcentajes debe ser exactamente <b>100%</b>. La suma actual es <b>${suma.toFixed(1)}%</b>.`;
                boxAlert.classList.remove('hidden');
                return false;
            }
            return true;
        }

        function abrirModalConfiguracion() {
            document.getElementById('configAlertError').classList.add('hidden');
            document.getElementById('modalConfiguracion').classList.remove('hidden');
            recalcularSumaConfig();
        }
        function cerrarModalConfiguracion() {
            document.getElementById('modalConfiguracion').classList.add('hidden');
        }

        function abrirModalCrear() {
            actividadEditandoId = null;
            document.getElementById('modalTitle').innerText = 'NUEVA ACTIVIDAD';
            document.getElementById('form_action').value = 'crear_actividad';
            document.getElementById('form_actividad_id').value = '';
            document.getElementById('form_desc').value = '';
            document.getElementById('form_pct').value = '';
            document.getElementById('modalAlertError').classList.add('hidden');
            
            document.getElementById('modalActividad').classList.remove('hidden');
            actualizarInfoPorcentajeDisponible();
        }

        function abrirModalEditar(act) {
            actividadEditandoId = act.actividad_id;
            actividadEditandoPctOriginal = parseFloat(act.porcentaje);
            actividadEditandoBloqueOriginal = act.corte;

            document.getElementById('modalTitle').innerText = 'EDITAR ACTIVIDAD';
            document.getElementById('form_action').value = 'editar_actividad';
            document.getElementById('form_actividad_id').value = actividadEditandoId;
            document.getElementById('form_desc').value = act.descripcion || act.nombre || '';
            
            const selectBloque = document.getElementById('form_bloque');
            const targetNorm = normalizarTextoJS(act.corte);
            for (let i = 0; i < selectBloque.options.length; i++) {
                if (normalizarTextoJS(selectBloque.options[i].value) === targetNorm) {
                    selectBloque.selectedIndex = i;
                    break;
                }
            }

            document.getElementById('form_pct').value = act.porcentaje;
            document.getElementById('modalAlertError').classList.add('hidden');

            document.getElementById('modalActividad').classList.remove('hidden');
            actualizarInfoPorcentajeDisponible();
        }

        function cerrarModalActividad() {
            document.getElementById('modalActividad').classList.add('hidden');
        }

        function abrirModalEliminar(act) {
            document.getElementById('del_act_id').value = act.actividad_id;
            document.getElementById('del_act_info').innerText = `Se eliminará "${act.descripcion || act.nombre}" (${act.porcentaje}%) de ${act.corte}.`;
            document.getElementById('modalEliminar').classList.remove('hidden');
        }

        function cerrarModalEliminar() {
            document.getElementById('modalEliminar').classList.add('hidden');
        }

        function actualizarInfoPorcentajeDisponible() {
            const bloque = document.getElementById('form_bloque').value;
            let acumulado = totalesPorBloque[bloque] || 0;

            if (actividadEditandoId && normalizarTextoJS(bloque) === normalizarTextoJS(actividadEditandoBloqueOriginal)) {
                acumulado -= actividadEditandoPctOriginal;
            }

            const disponible = (100 - acumulado).toFixed(1);
            document.getElementById('infoDisponible').innerHTML = 
                `<i class="fa-solid fa-circle-info text-emerald-600 mr-1"></i> Acumulado en <b>${bloque}</b>: <b>${acumulado.toFixed(1)}%</b> | Disponible: <b class="text-emerald-700">${disponible}%</b>`;
        }

        function validarPorcentajeModal(e) {
            const bloque = document.getElementById('form_bloque').value;
            const pctIngresado = parseFloat(document.getElementById('form_pct').value || 0);

            if (isNaN(pctIngresado) || pctIngresado <= 0) {
                const boxAlert = document.getElementById('modalAlertError');
                boxAlert.innerHTML = `<i class="fa-solid fa-circle-xmark mr-1"></i> Ingresa un porcentaje válido mayor a 0.`;
                boxAlert.classList.remove('hidden');
                return false;
            }
            
            let acumulado = totalesPorBloque[bloque] || 0;
            if (actividadEditandoId && normalizarTextoJS(bloque) === normalizarTextoJS(actividadEditandoBloqueOriginal)) {
                acumulado -= actividadEditandoPctOriginal;
            }

            const totalSimulado = acumulado + pctIngresado;
            const disponible = 100 - acumulado;

            if (totalSimulado > 100.001) {
                const boxAlert = document.getElementById('modalAlertError');
                boxAlert.innerHTML = `<i class="fa-solid fa-circle-xmark mr-1"></i> No es posible asignar <b>${pctIngresado}%</b>. El límite para <b>${bloque}</b> es 100%. Actualmente tienes acumulado el <b>${acumulado.toFixed(1)}%</b> y solo dispones de un <b>${disponible.toFixed(1)}%</b>.`;
                boxAlert.classList.remove('hidden');
                return false;
            }

            return true;
        }

        function validarYRecalcularNota(input) {
            let val = parseFloat(input.value);

            if (!isNaN(val)) {
                if (val > escalaMaxJS) {
                    input.value = escalaMaxJS.toFixed(1);
                    alert(`La nota máxima permitida en este curso es ${escalaMaxJS.toFixed(1)}`);
                } else if (val < 0.0) {
                    input.value = "0.0";
                }
            }

            recalcularPromediosFila(input.closest('tr'));
        }

        function validarPlanillaAntesDeEnviar() {
            const inputs = document.querySelectorAll('.input-nota');
            for (let input of inputs) {
                let val = parseFloat(input.value);
                if (!isNaN(val) && (val < 0.0 || val > escalaMaxJS)) {
                    alert(`Existen calificaciones fuera del rango permitido (0.0 - ${escalaMaxJS.toFixed(1)}). Corrígelas antes de guardar.`);
                    input.focus();
                    return false;
                }
            }
            return true;
        }

        function recalcularPromediosFila(tr) {
            const inputs = tr.querySelectorAll('.input-nota');
            const acumuladosPorBloque = {};

            inputs.forEach(input => {
                const bloque = input.getAttribute('data-bloque');
                const pct = parseFloat(input.getAttribute('data-porcentaje') || 0);
                const val = parseFloat(input.value);

                if (!acumuladosPorBloque[bloque]) {
                    acumuladosPorBloque[bloque] = 0;
                }

                if (!isNaN(val) && val >= 0.0 && val <= escalaMaxJS) {
                    acumuladosPorBloque[bloque] += val * (pct / 100);
                }
            });

            let sumaDefinitivaPonderada = 0;

            tr.querySelectorAll('.prom-bloque').forEach(spanProm => {
                const bloque = spanProm.getAttribute('data-bloque-prom');
                const pesoBloque = parseFloat(spanProm.getAttribute('data-peso-bloque') || 0);
                const notaCorte = acumuladosPorBloque[bloque] || 0;
                
                spanProm.innerText = notaCorte.toFixed(1);

                sumaDefinitivaPonderada += notaCorte * (pesoBloque / 100);
            });

            const spanDefinitiva = tr.querySelector('.prom-definitiva');
            if (spanDefinitiva) {
                spanDefinitiva.innerText = sumaDefinitivaPonderada.toFixed(1);
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.fila-estudiante').forEach(tr => {
                recalcularPromediosFila(tr);
            });
        });

        function abrirModalPassword() {
            document.getElementById('modalPassword').classList.remove('hidden');
        }
        function cerrarModalPassword() {
            document.getElementById('modalPassword').classList.add('hidden');
        }
    </script>
</body>
</html>