<div class="min-h-[85vh] flex flex-col items-center justify-center px-4">
    
    <!-- Título Principal -->
    <h1 class="text-6xl md:text-7xl font-black text-white tracking-wider mb-3 text-center">
        NOTANOVA
    </h1>

    <!-- Subtítulo -->
    <p class="text-[#6366f1] text-xs md:text-sm font-bold tracking-[0.3em] uppercase mb-16 text-center">
        UNIFIED ACADEMIC ECOSYSTEM
    </p>

    <!-- Tarjetas de Roles -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-5xl w-full px-4">
        
        <!-- Admin -->
        <a href="index.php?role=admin" class="bg-[#131b2e]/80 hover:bg-[#182238] border border-slate-800/60 rounded-[2.5rem] p-10 flex flex-col items-center text-center transition-all duration-300 hover:-translate-y-1 shadow-lg">
            <div class="w-20 h-20 rounded-2xl bg-[#232d4b] text-[#818cf8] flex items-center justify-center text-3xl mb-8">
                <i class="fa-solid fa-shield-halved"></i>
            </div>
            <h2 class="text-2xl font-bold text-white mb-3">Admin</h2>
            <p class="text-slate-400 text-sm font-normal">Control total y matrículas.</p>
        </a>

        <!-- Docente -->
        <a href="index.php?role=docente" class="bg-[#131b2e]/80 hover:bg-[#182238] border border-slate-800/60 rounded-[2.5rem] p-10 flex flex-col items-center text-center transition-all duration-300 hover:-translate-y-1 shadow-lg">
            <div class="w-20 h-20 rounded-2xl bg-[#0f3832] text-[#34d399] flex items-center justify-center text-3xl mb-8">
                <i class="fa-solid fa-layer-group"></i>
            </div>
            <h2 class="text-2xl font-bold text-white mb-3">Docente</h2>
            <p class="text-slate-400 text-sm font-normal">Evaluación y seguimiento.</p>
        </a>

        <!-- Estudiante -->
        <a href="index.php?role=estudiante" class="bg-[#131b2e]/80 hover:bg-[#182238] border border-slate-800/60 rounded-[2.5rem] p-10 flex flex-col items-center text-center transition-all duration-300 hover:-translate-y-1 shadow-lg">
            <div class="w-20 h-20 rounded-2xl bg-[#3d2e19] text-[#fbbf24] flex items-center justify-center text-3xl mb-8">
                <i class="fa-solid fa-graduation-cap"></i>
            </div>
            <h2 class="text-2xl font-bold text-white mb-3">Estudiante</h2>
            <p class="text-slate-400 text-sm font-normal">Progreso y calificaciones.</p>
        </a>

    </div>

</div>