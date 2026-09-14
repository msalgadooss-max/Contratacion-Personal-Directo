(async () => {
  const usuario = await protegerDashboard('Jefe_Bodega');
  if (!usuario) return;
  await cargarLista();
  iniciarEstadoVivo();
})();

// v10.13 (pedido explícito del usuario): botón "🔄 Actualizar" en el
// header -- por si el proceso "parece pegado", refresca sin recargar
// la página ni salir del panel.
function actualizarTodo() {
  cargarLista();
  mostrarAlerta('alerta', 'Actualizado.', 'exito');
}

async function cargarLista() {
  const tbody = document.getElementById('tbody-postulaciones');
  const vacio = document.getElementById('vacio');
  try {
    const data = await apiFetch('/bodega/listar.php');
    if (!data.postulaciones.length) {
      tbody.innerHTML = '';
      vacio.classList.remove('hidden');
      return;
    }
    vacio.classList.add('hidden');
    tbody.innerHTML = data.postulaciones.map(p => `
      <tr class="border-t">
        <td class="px-4 py-3 font-mono">${p.rut}</td>
        <td class="px-4 py-3">${p.nombre_completo}</td>
        <td class="px-4 py-3">${p.nombre_cargo}</td>
        <td class="px-4 py-3">${p.talla_calzado}</td>
        <td class="px-4 py-3">${p.talla_overol}</td>
        <td class="px-4 py-3">${p.talla_pantalon}</td>
        <td class="px-4 py-3">${p.talla_polera}</td>
        <td class="px-4 py-3 text-right">
          ${p.puede_entregar
            ? `<button class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold px-3 py-1.5 rounded-lg" onclick="marcarEpp(${p.id})">Entregar EPP</button>`
            : `<span class="text-xs text-gray-400" title="El JAO todavía no firma el contrato de esta persona (día 2)">⏳ Esperando firma de contrato</span>`}
        </td>
      </tr>`).join('');
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

async function marcarEpp(id) {
  if (!confirm('¿Entregar el kit de EPP? Esto cierra la contratación y descuenta el cupo del cargo.')) return;
  try {
    const data = await apiFetch('/bodega/marcar_epp.php', { method: 'POST', body: { postulacion_id: id } });
    mostrarAlerta('alerta', data.mensaje, 'exito');
    await cargarLista();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}
