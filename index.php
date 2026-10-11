<?php
/**
 * NotaNova - Punto de entrada único
 *
 *  * Sin sesión  -> portal de bienvenida (público).
 *  * Con sesión  -> enruta al portal del rol correspondiente.
 */

require_once __DIR__ . '/auth.php';

// Si hay sesión activa, el portal correspondiente toma el control.
if (usuario_logueado()) {
    $rol = sesion_rol();

    if ($rol === 'admin') {
        require __DIR__ . '/views/admin.php';
    } elseif ($rol === 'docente') {
        require __DIR__ . '/views/docente.php';
    } elseif ($rol === 'estudiante') {
        require __DIR__ . '/views/estudiante.php';
    } else {
        // Rol desconocido: cerrar sesión por seguridad
        cerrar_sesion();
        header('Location: login.php');
        exit;
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NotaNova | Unified Academic Ecosystem</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Fuentes Google -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,600;0,700;0,800;1,800;1,900&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        /* Efecto de resplandor Neón suave para el título */
        .neon-glow {
            text-shadow: 0 0 20px rgba(255, 255, 255, 0.35),
                         0 0 40px rgba(99, 102, 241, 0.3);
        }

        .neon-btn {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #a855f7 100%);
            box-shadow: 0 0 20px rgba(139, 92, 246, 0.4);
        }
        .neon-btn:hover { box-shadow: 0 0 30px rgba(168, 85, 247, 0.65); }
    </style>
</head>
<body class="bg-[#0b0e17] text-slate-100 min-h-screen flex flex-col relative overflow-x-hidden m-0 p-6 selection:bg-indigo-500 selection:text-white">

    <!-- Luz ambiental sutil de fondo -->
    <div class="absolute top-1/4 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[360px] bg-indigo-600/10 blur-[130px] pointer-events-none rounded-full"></div>

    <!-- Contenido Principal -->
    <div class="relative z-10 max-w-6xl w-full mx-auto flex-1 flex flex-col justify-center py-10">

        <!-- Cabecera de marca (permanece visible en ambas pantallas) -->
        <div class="text-center mb-10">
            <a href="index.php" onclick="volverBienvenida(); return false;" class="inline-block cursor-pointer" title="Volver al inicio">
                <span class="inline-flex items-center gap-2 text-[10px] font-black tracking-[0.3em] uppercase text-indigo-300/80 bg-indigo-500/5 border border-indigo-500/20 px-4 py-1.5 rounded-full mb-6">
                    <i class="fa-solid fa-sparkles text-indigo-400"></i>
                    Plataforma académica
                </span>

                <h1 class="text-5xl md:text-8xl font-black italic tracking-wider text-white neon-glow uppercase leading-none mb-4">
                    NOTANOVA
                </h1>

                <span class="text-[#818cf8] text-xs font-black tracking-[0.35em] uppercase block">
                    UNIFIED ACADEMIC ECOSYSTEM
                </span>
            </a>
        </div>

        <!-- Pantalla 1: Bienvenida + botón de ingreso -->
        <div id="bienvenida" class="text-center transition-all duration-300">
            <h2 class="text-2xl md:text-3xl font-extrabold text-slate-200 tracking-tight mb-3">
                Bienvenido a NotaNova
            </h2>

            <p class="text-slate-400 font-medium max-w-2xl mx-auto leading-relaxed text-sm md:text-base">
                Gestión académica unificada para administradores, docentes y estudiantes.
                Control de matrículas, planillas de calificaciones y seguimiento de progreso en un solo lugar.
            </p>

            <button type="button" onclick="mostrarPortales()"
               class="neon-btn inline-flex items-center gap-3 text-white px-10 py-4 rounded-full font-black text-sm uppercase tracking-wider transition-all transform hover:-translate-y-1 mt-9 cursor-pointer">
                <i class="fa-solid fa-right-to-bracket"></i> INGRESAR AL SISTEMA
            </button>
        </div>

        <!-- Pantalla 2: Selección de perfil (oculta al inicio) -->
        <section id="portales" class="hidden opacity-0 transition-all duration-300 w-full max-w-5xl mx-auto">
            <div class="text-center mb-8">
                <span class="inline-flex items-center gap-2 text-[10px] font-black tracking-[0.3em] uppercase text-slate-400 bg-white/5 border border-white/10 px-4 py-1.5 rounded-full">
                    <i class="fa-solid fa-hand-pointer text-indigo-400"></i>
                    Selecciona tu perfil
                </span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 w-full">

            <!-- Rol Admin -->
            <a href="login.php?rol=admin" class="group relative bg-[#141724] border border-slate-800/80 hover:border-indigo-500/50 rounded-3xl p-8 flex flex-col text-center transition-all duration-300 transform hover:-translate-y-1.5 shadow-2xl hover:shadow-indigo-500/15 cursor-pointer">
                <div class="w-16 h-16 rounded-2xl bg-indigo-500/10 text-indigo-400 flex items-center justify-center text-2xl mx-auto mb-6 group-hover:scale-110 transition-transform duration-300 border border-indigo-500/20 shadow-[0_0_15px_rgba(99,102,241,0.2)]">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
                <h3 class="text-2xl font-bold text-white mb-2 group-hover:text-indigo-400 transition-colors">
                    Admin
                </h3>
                <p class="text-sm text-slate-400 font-medium leading-relaxed">
                    Control total, gestión de usuarios y matrículas.
                </p>
                <span class="mt-5 inline-block text-[10px] font-black uppercase tracking-widest text-indigo-400/80 bg-indigo-500/5 border border-indigo-500/20 px-3 py-1 rounded-full mx-auto">
                    Central de operaciones
                </span>
            </a>

            <!-- Rol Docente -->
            <a href="login.php?rol=docente" class="group relative bg-[#141724] border border-slate-800/80 hover:border-emerald-500/50 rounded-3xl p-8 flex flex-col text-center transition-all duration-300 transform hover:-translate-y-1.5 shadow-2xl hover:shadow-emerald-500/15 cursor-pointer">
                <div class="w-16 h-16 rounded-2xl bg-emerald-500/10 text-emerald-400 flex items-center justify-center text-2xl mx-auto mb-6 group-hover:scale-110 transition-transform duration-300 border border-emerald-500/20 shadow-[0_0_15px_rgba(16,185,129,0.2)]">
                    <i class="fa-solid fa-layer-group"></i>
                </div>
                <h3 class="text-2xl font-bold text-white mb-2 group-hover:text-emerald-400 transition-colors">
                    Docente
                </h3>
                <p class="text-sm text-slate-400 font-medium leading-relaxed">
                    Evaluación, planillas de control y seguimiento.
                </p>
                <span class="mt-5 inline-block text-[10px] font-black uppercase tracking-widest text-emerald-400/80 bg-emerald-500/5 border border-emerald-500/20 px-3 py-1 rounded-full mx-auto">
                    Portal docente
                </span>
            </a>

            <!-- Rol Estudiante -->
            <a href="login.php?rol=estudiante" class="group relative bg-[#141724] border border-slate-800/80 hover:border-amber-500/50 rounded-3xl p-8 flex flex-col text-center transition-all duration-300 transform hover:-translate-y-1.5 shadow-2xl hover:shadow-amber-500/15 cursor-pointer">
                <div class="w-16 h-16 rounded-2xl bg-amber-500/10 text-amber-400 flex items-center justify-center text-2xl mx-auto mb-6 group-hover:scale-110 transition-transform duration-300 border border-amber-500/20 shadow-[0_0_15px_rgba(245,158,11,0.2)]">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
                <h3 class="text-2xl font-bold text-white mb-2 group-hover:text-amber-400 transition-colors">
                    Estudiante
                </h3>
                <p class="text-sm text-slate-400 font-medium leading-relaxed">
                    Progreso académico y calificaciones.
                </p>
                <span class="mt-5 inline-block text-[10px] font-black uppercase tracking-widest text-amber-400/80 bg-amber-500/5 border border-amber-500/20 px-3 py-1 rounded-full mx-auto">
                    Portal estudiante
                </span>
            </a>

            </div>
        </section>

        <!-- Footer / Badge -->
        <div class="mt-14 flex items-center justify-center gap-2 text-xs font-mono text-amber-400/90 bg-amber-400/5 border border-amber-400/20 px-4 py-2 rounded-full shadow-inner w-fit mx-auto">
            <i class="fa-solid fa-bolt text-amber-400"></i>
            <span>PREVIEW INTERACTIVO &mdash; Datos de demostración</span>
        </div>

    </div>

    <script>
        // Pantalla 1 -> Pantalla 2: la bienvenida se va y aparece la selección de perfil.
        function mostrarPortales() {
            const bienvenida = document.getElementById('bienvenida');
            const portales = document.getElementById('portales');
            // Si portales ya está visible (o faltan elementos), no hacer nada.
            if (!portales || !portales.classList.contains('hidden')) return;

            portales.classList.remove('hidden');
            portales.classList.add('opacity-0');
            void portales.offsetWidth; // reflow para que la transición se anime
            portales.classList.remove('opacity-0');
            portales.classList.add('opacity-100');

            bienvenida.classList.add('opacity-0');
            setTimeout(() => {
                bienvenida.classList.add('hidden');
                bienvenida.classList.remove('opacity-0');
            }, 300);
        }

        // Pantalla 2 -> Pantalla 1: se regresa a la bienvenida.
        function volverBienvenida() {
            const bienvenida = document.getElementById('bienvenida');
            const portales = document.getElementById('portales');
            // Si la bienvenida ya está visible, no hacer nada.
            if (!bienvenida || !bienvenida.classList.contains('hidden')) return;

            bienvenida.classList.remove('hidden');
            bienvenida.classList.add('opacity-0');
            void bienvenida.offsetWidth;
            bienvenida.classList.remove('opacity-0');
            bienvenida.classList.add('opacity-100');

            portales.classList.remove('opacity-100');
            portales.classList.add('opacity-0');
            setTimeout(() => {
                portales.classList.add('hidden');
                portales.classList.remove('opacity-0');
            }, 300);
        }

        // Si llegamos con #portales (desde "Volver a portales" del login),
        // mostramos la selección de perfil automáticamente.
        if (window.location.hash === '#portales') {
            mostrarPortales();
        }
    </script>

</body>
</html>
