<?php
// Esta vista solo debe cargarse desde index.php (evita acceso directo)
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    http_response_code(403);
    die('Acceso directo no permitido.');
}

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

requiere_rol('estudiante');

// Obtener ID del estudiante logueado
$estudiante_id = sesion_user_id();

// Caso límite: la cuenta no está vinculada a un estudiante activo
if (!$estudiante_id) {
    flash_error('Tu cuenta no está vinculada a un registro de estudiante activo.');
    header('Location: logout.php');
    exit;
}

$mensaje_error = null;

// Mensajes flash (tras redirección)
$flash_exito = tomar_flash_exito();
$flash_error = tomar_flash_error();

// ==============================================================================
// CARGA DE DATOS DEL ESTUDIANTE
// ==============================================================================
$estudiante = null;
try {
    $stmt_est = $pdo->prepare("SELECT * FROM estudiantes WHERE id = ?");
    $stmt_est->execute([$estudiante_id]);
    $estudiante = $stmt_est->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $mensaje_error = "Error al cargar los datos del estudiante: " . $e->getMessage();
}

// CORRECCIÓN: Cargar únicamente las asignaturas en las que el estudiante está matriculado
$mis_cursos = [];
try {
    $stmt_cursos = $pdo->prepare("
        SELECT c.* 
        FROM cursos c
        INNER JOIN curso_estudiante ce ON c.id = ce.curso_id
        WHERE ce.estudiante_id = ?
        ORDER BY c.id ASC
    ");
    $stmt_cursos->execute([$estudiante_id]);
    $mis_cursos = $stmt_cursos->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $mensaje_error = "Error al consultar las asignaturas del estudiante: " . $e->getMessage();
}

// Selección de curso activo
$curso_id = $_GET['curso_id'] ?? ($mis_cursos[0]['id'] ?? null);

$curso_actual = null;
if ($curso_id) {
    foreach ($mis_cursos as $c) {
        if ($c['id'] == $curso_id) {
            $curso_actual = $c;
            break;
        }
    }
}

// Configuración de modalidad y escala del curso actual
$modalidad = strtolower(trim($curso_actual['modalidad'] ?? $curso_actual['tipo_periodo'] ?? 'cortes'));
$escala_max = floatval($curso_actual['escala'] ?? 5.0);

$bloques = ($modalidad === 'periodos') 
    ? ['Periodo 1', 'Periodo 2', 'Periodo 3', 'Periodo 4'] 
    : ['Corte 1', 'Corte 2', 'Corte 3'];

// Pesos por defecto
$pesos_bloques = ($modalidad === 'periodos')
    ? ['Periodo 1' => 25, 'Periodo 2' => 25, 'Periodo 3' => 25, 'Periodo 4' => 25]
    : ['Corte 1' => 30, 'Corte 2' => 30, 'Corte 3' => 40];

// Cargar porcentajes personalizados desde config_evaluacion
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

function normalizarNombreBloque($texto) {
    $texto = mb_strtolower(trim((string)$texto));
    $texto = preg_replace('/\s+/', ' ', $texto);
    
    if ($texto === '1' || $texto === 'corte_1' || $texto === 'periodo_1') return 'corte 1';
    if ($texto === '2' || $texto === 'corte_2' || $texto === 'periodo_2') return 'corte 2';
    if ($texto === '3' || $texto === 'corte_3' || $texto === 'periodo_3') return 'corte 3';
    if ($texto === '4' || $texto === 'corte_4' || $texto === 'periodo_4') return 'periodo 4';

    return $texto;
}

// Cargar actividades y notas del estudiante
$actividades_por_bloque = [];

foreach ($bloques as $b) {
    $actividades_por_bloque[$b] = [];
}

if ($curso_id) {
    try {
        $stmt_act = $pdo->prepare("
            SELECT a.*, n.nota 
            FROM actividades a 
            LEFT JOIN notas n ON a.actividad_id = n.actividad_id AND n.estudiante_id = ?
            WHERE a.curso_id = ?
            ORDER BY a.actividad_id ASC
        ");
        $stmt_act->execute([$estudiante_id, $curso_id]);
        $todas_actividades = $stmt_act->fetchAll(PDO::FETCH_ASSOC);

        foreach ($todas_actividades as $act) {
            $corte_norm = normalizarNombreBloque($act['corte']);
            $encontrado = false;

            foreach ($bloques as $b) {
                if (normalizarNombreBloque($b) === $corte_norm) {
                    $actividades_por_bloque[$b][] = $act;
                    $encontrado = true;
                    break;
                }
            }

            if (!$encontrado) {
                $actividades_por_bloque[$bloques[0]][] = $act;
            }
        }
    } catch (PDOException $e) {
        $mensaje_error = "Error al cargar actividades y notas: " . $e->getMessage();
    }
}

$self_url = htmlspecialchars($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NotaNova | Portal Estudiante</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,600;0,700;0,800;1,800;1,900&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .neon-text-cyan { color: #00f0ff; text-shadow: 0 0 10px rgba(0, 240, 255, 0.5); }
        .neon-text-orange { color: #ff8c00; text-shadow: 0 0 12px rgba(255, 140, 0, 0.6); }
        .neon-btn-orange {
            background: linear-gradient(135deg, #ea580c 0%, #f97316 50%, #fb923c 100%);
            box-shadow: 0 0 20px rgba(249, 115, 22, 0.35);
        }
        .neon-btn-orange:hover { box-shadow: 0 0 30px rgba(251, 146, 60, 0.55); }
        .custom-scroll-x::-webkit-scrollbar { height: 10px; }
        .custom-scroll-x::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 10px; }
        .custom-scroll-x::-webkit-scrollbar-thumb { background: #ea580c; border-radius: 10px; }
    </style>
</head>
<body class="min-h-screen flex flex-col text-slate-800">

    <header class="bg-[#2d1202] text-white px-8 py-5 flex items-center justify-between shadow-xl border-b border-orange-500/20">
        <div class="flex items-center gap-4">
            <h1 class="text-2xl font-black italic tracking-tight neon-text-cyan">NotaNova</h1>
            <span class="bg-orange-500/20 text-orange-400 border border-orange-500/30 text-[10px] font-black px-3 py-1 rounded-full tracking-widest uppercase">
                PORTAL ESTUDIANTE
            </span>
        </div>

        <div class="flex items-center gap-3">
            <span class="hidden md:flex items-center gap-2 text-xs font-bold text-orange-200/80 border border-white/10 bg-white/5 px-4 py-2 rounded-full">
                <i class="fa-solid fa-user-graduate text-orange-400"></i><?php echo htmlspecialchars(sesion_nombre()); ?>
            </span>

            <button onclick="abrirModalPassword()" title="Cambiar contraseña"
                    class="px-4 py-2 rounded-full bg-white/10 hover:bg-orange-500/20 hover:text-orange-400 border border-white/10 flex items-center gap-2 text-white text-xs font-bold transition-all">
                <i class="fa-solid fa-key"></i>
                <span class="hidden lg:inline">Contraseña</span>
            </button>

            <a href="logout.php" title="Cerrar Sesión" class="px-5 py-2 rounded-full bg-white/10 hover:bg-rose-500/20 hover:text-rose-400 border border-white/10 flex items-center gap-2 text-white text-xs font-bold transition-all">
                <span>Salir</span>
                <i class="fa-solid fa-right-from-bracket"></i>
            </a>
        </div>
    </header>

    <main class="max-w-[1600px] w-full mx-auto p-6 md:p-10 flex-1 grid grid-cols-1 lg:grid-cols-12 gap-8">
        
        <aside class="lg:col-span-3 space-y-4">
            <div class="bg-white border border-slate-200/80 rounded-[32px] p-6 shadow-md flex items-center gap-4">
                <div class="w-14 h-14 rounded-2xl bg-orange-100 text-orange-600 flex items-center justify-center text-2xl font-black shrink-0 border border-orange-200/60">
                    <i class="fa-solid fa-user-graduate"></i>
                </div>
                <div class="overflow-hidden">
                    <span class="text-[10px] font-black text-orange-600 uppercase tracking-wider block">PERFIL DEL ESTUDIANTE</span>
                    <h2 class="text-lg font-black text-slate-900 truncate uppercase italic">
                        <?php echo htmlspecialchars($estudiante['nombre'] ?? 'ESTUDIANTE'); ?>
                    </h2>
                    <span class="text-xs text-slate-400 font-semibold block truncate">
                        ID: <?php echo htmlspecialchars($estudiante['documento_id'] ?? $estudiante_id); ?>
                    </span>
                </div>
            </div>

            <span class="text-[11px] font-black text-slate-400 uppercase tracking-widest block mb-4 ml-1">
                MIS ASIGNATURAS
            </span>

            <div class="space-y-3">
                <?php if (empty($mis_cursos)): ?>
                    <div class="p-6 bg-white rounded-3xl border border-slate-200 text-slate-400 font-bold text-xs text-center">
                        No estás matriculado en ninguna asignatura.
                    </div>
                <?php else: ?>
                    <?php foreach ($mis_cursos as $c): ?>
                        <?php 
                            $es_activo = ($curso_actual && $curso_actual['id'] == $c['id']); 
                            $codigo_c = $c['codigo_curso'] ?? $c['codigo'] ?? ('MAT-' . str_pad($c['id'], 3, '0', STR_PAD_LEFT));
                            $nombre_c = $c['nombre'] ?? $c['nombre_curso'] ?? $c['titulo'] ?? 'Curso';
                        ?>
                        <a href="<?php echo $self_url; ?>?curso_id=<?php echo $c['id']; ?>" 
                           class="block transition-all transform hover:-translate-y-1 rounded-[28px] p-6 shadow-md border <?php echo $es_activo ? 'neon-btn-orange text-white border-orange-400 shadow-orange-500/20' : 'bg-white hover:bg-slate-50 text-slate-800 border-slate-200/80'; ?>">
                            <span class="text-[10px] font-black tracking-wider uppercase opacity-80 block mb-1 <?php echo $es_activo ? 'text-orange-100' : 'text-slate-400'; ?>">
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
                <div class="p-5 bg-rose-50 border-l-4 border-rose-500 text-rose-800 font-bold rounded-r-2xl text-sm shadow-md flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-triangle-exclamation text-rose-500 text-xl"></i>
                        <span><?php echo htmlspecialchars($mensaje_error); ?></span>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-rose-400 hover:text-rose-700 font-bold"><i class="fa-solid fa-xmark"></i></button>
                </div>
            <?php endif; ?>

            <div class="bg-white border border-slate-200/80 rounded-[36px] p-8 shadow-xl flex flex-col md:flex-row md:items-center justify-between gap-6">
                <div>
                    <div class="flex items-center gap-2 mb-2 flex-wrap">
                        <span class="bg-orange-100 text-orange-900 font-extrabold text-[10px] px-4 py-1 rounded-full uppercase tracking-wider border border-orange-200">
                            ESCALA: 0.0 - <?php echo number_format($escala_max, 1); ?>
                        </span>
                        <span class="bg-slate-100 text-slate-600 font-extrabold text-[10px] px-3 py-1 rounded-full uppercase tracking-wider border border-slate-200">
                            MODALIDAD: <?php echo strtoupper($modalidad); ?>
                        </span>
                    </div>
                    <h2 class="text-3xl md:text-4xl font-black italic tracking-tight text-slate-900 uppercase">
                        <?php echo htmlspecialchars($curso_actual['nombre'] ?? $curso_actual['nombre_curso'] ?? 'SELECCIONA UN CURSO'); ?>
                    </h2>
                </div>

                <?php 
                    $definitiva_general = 0;
                    foreach ($bloques as $b) {
                        $peso_b = $pesos_bloques[$b] ?? 0;
                        $prom_b = 0;
                        if (!empty($actividades_por_bloque[$b])) {
                            foreach ($actividades_por_bloque[$b] as $act_b) {
                                $n_val = floatval($act_b['nota'] ?? 0);
                                $p_val = floatval($act_b['porcentaje'] ?? 0);
                                $prom_b += $n_val * ($p_val / 100);
                            }
                        }
                        $definitiva_general += $prom_b * ($peso_b / 100);
                    }
                ?>
                <div class="bg-[#2d1202] text-white p-6 rounded-[28px] flex items-center justify-between md:justify-end gap-6 shadow-xl border border-orange-500/30 min-w-[240px]">
                    <div>
                        <span class="block text-[10px] font-black text-orange-300 uppercase tracking-widest">NOTA DEFINITIVA</span>
                        <span class="text-xs text-orange-200/70 font-semibold">Acumulado actual</span>
                    </div>
                    <div class="text-4xl font-black italic neon-text-orange">
                        <?php echo number_format($definitiva_general, 1); ?>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($bloques as $b): ?>
                    <?php 
                        $peso_bloque = $pesos_bloques[$b] ?? 0;
                        $acts = $actividades_por_bloque[$b];
                        
                        $nota_bloque = 0;
                        foreach ($acts as $act_item) {
                            $n = floatval($act_item['nota'] ?? 0);
                            $p = floatval($act_item['porcentaje'] ?? 0);
                            $nota_bloque += $n * ($p / 100);
                        }
                    ?>
                    <div class="bg-white border border-slate-200/80 rounded-[32px] overflow-hidden shadow-lg flex flex-col justify-between">
                        <div class="p-6 bg-orange-50/40 border-b border-orange-100 flex items-center justify-between">
                            <div>
                                <h3 class="text-lg font-black text-slate-800 uppercase italic"><?php echo $b; ?></h3>
                                <span class="text-[10px] font-extrabold text-orange-700 uppercase">VALOR CURSO: <?php echo number_format($peso_bloque, 1); ?>%</span>
                            </div>
                            <div class="text-right">
                                <span class="block text-[10px] font-black text-orange-600 uppercase">NOTA BLOQUE</span>
                                <span class="text-2xl font-black text-slate-900 italic"><?php echo number_format($nota_bloque, 1); ?></span>
                            </div>
                        </div>

                        <div class="p-6 space-y-4 flex-1">
                            <?php if (empty($acts)): ?>
                                <div class="text-center py-6 text-slate-300 font-bold text-xs italic">
                                    Sin actividades registradas
                                </div>
                            <?php else: ?>
                                <?php foreach ($acts as $act): ?>
                                    <div class="flex items-center justify-between p-3.5 bg-slate-50/80 border border-slate-100 rounded-2xl">
                                        <div class="overflow-hidden pr-2">
                                            <span class="font-extrabold text-slate-800 text-xs block truncate uppercase">
                                                <?php echo htmlspecialchars($act['nombre'] ?? $act['descripcion']); ?>
                                            </span>
                                            <span class="text-[10px] text-orange-600 font-extrabold block">
                                                Peso: <?php echo number_format($act['porcentaje'], 1); ?>%
                                            </span>
                                        </div>
                                        <div class="text-right shrink-0">
                                            <span class="inline-block px-3.5 py-1 bg-white border border-slate-200 rounded-xl text-sm font-black text-slate-900 shadow-sm">
                                                <?php echo isset($act['nota']) && $act['nota'] !== null ? number_format($act['nota'], 1) : '—'; ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        </section>
    </main>

    <!-- MODAL CAMBIAR CONTRASEÑA -->
    <div id="modalPassword" class="fixed inset-0 bg-[#2d1202]/75 backdrop-blur-md flex items-center justify-center hidden z-50 p-4">
        <div class="bg-white rounded-[36px] w-full max-w-sm overflow-hidden shadow-2xl relative border border-orange-500/20">

            <div class="bg-[#2d1202] px-8 py-6 flex items-center justify-between border-b border-orange-500/20">
                <div>
                    <span class="text-[10px] font-extrabold text-orange-400 uppercase tracking-widest block mb-1">SEGURIDAD</span>
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
                           class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-orange-500">
                </div>

                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase mb-2 ml-1">NUEVA CONTRASEÑA</label>
                    <input type="password" name="password_nueva" required minlength="6"
                           class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-orange-500">
                </div>

                <div>
                    <label class="block text-[11px] font-black text-slate-400 uppercase mb-2 ml-1">CONFIRMAR NUEVA</label>
                    <input type="password" name="password_confirmar" required minlength="6"
                           class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-orange-500">
                </div>

                <div class="pt-2 grid grid-cols-2 gap-4">
                    <button type="button" onclick="cerrarModalPassword()"
                            class="w-full bg-white border border-slate-200 text-slate-600 font-extrabold py-4 rounded-2xl text-xs uppercase">
                        CANCELAR
                    </button>
                    <button type="submit"
                            class="w-full neon-btn-orange text-white font-extrabold py-4 rounded-2xl text-xs uppercase shadow-md transition-all">
                        ACTUALIZAR
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function abrirModalPassword() {
            document.getElementById('modalPassword').classList.remove('hidden');
        }
        function cerrarModalPassword() {
            document.getElementById('modalPassword').classList.add('hidden');
        }
    </script>

</body>
</html>