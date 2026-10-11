// Control de modales en la interfaz
function abrirModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.remove('hidden');
}

function cerrarModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.add('hidden');
}