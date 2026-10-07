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

function esc(valor) {
  const d = document.createElement('div');
  d.textContent = valor == null ? '' : String(valor);
  return d.innerHTML;
}

let LIBERADOS_PENDIENTES = 0;

async function cargarLista() {
  const tbody = document.getElementById('tbody-postulaciones');
  const vacio = document.getElementById('vacio');
  try {
    const data = await apiFetch('/bodega/listar.php');
    renderNomina(data.liberados_pendientes || []);
    if (!data.postulaciones.length) {
      tbody.innerHTML = '';
      vacio.classList.remove('hidden');
      return;
    }
    vacio.classList.add('hidden');
    tbody.innerHTML = data.postulaciones.map(p => `
      <tr class="border-t">
        <td class="px-4 py-3 font-mono">${esc(p.rut)}</td>
        <td class="px-4 py-3">${esc(p.nombre_completo)}</td>
        <td class="px-4 py-3">${esc(p.nombre_cargo)}</td>
        <td class="px-4 py-3">${esc(p.talla_calzado)}</td>
        <td class="px-4 py-3">${esc(p.talla_overol)}</td>
        <td class="px-4 py-3 text-right">
          ${p.puede_entregar
            ? `<button class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold px-3 py-1.5 rounded-lg" onclick="marcarEpp(${Number(p.id)})">Entregar EPP</button>`
            : (p.espera === 'irl'
                ? `<span class="text-xs text-gray-400" title="Prevención todavía no registra la IRL de esta persona">⏳ Esperando IRL (Prevención)</span>`
                : `<span class="text-xs text-gray-400" title="El JAO todavía no firma el contrato de esta persona">⏳ Esperando firma de contrato (JAO)</span>`)}
        </td>
      </tr>`).join('');
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

async function marcarEpp(id) {
  if (!confirm('¿Entregar el kit de EPP? Esto cierra la contratación y deja a la persona liberada.')) return;
  try {
    const data = await apiFetch('/bodega/marcar_epp.php', { method: 'POST', body: { postulacion_id: id } });
    mostrarAlerta('alerta', data.mensaje, 'exito');
    await cargarLista();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

// --- v10.21: nómina de liberados (un solo correo con tabla) ---------------
function horaCorta(fechaHoraSql) {
  if (!fechaHoraSql) return '-';
  const d = new Date(String(fechaHoraSql).replace(' ', 'T'));
  return isNaN(d) ? '-' : d.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' });
}

function renderNomina(liberados) {
  const cont = document.getElementById('nomina-liberados');
  LIBERADOS_PENDIENTES = liberados.length;
  if (!liberados.length) {
    cont.classList.add('hidden');
    cont.innerHTML = '';
    return;
  }
  cont.classList.remove('hidden');
  cont.innerHTML = `
    <div class="flex items-start justify-between gap-4 flex-wrap">
      <div>
        <p class="text-sm font-bold text-gray-900">Nómina de liberados
          <span class="ml-2 text-xs font-semibold bg-amber-100 text-amber-800 px-2 py-0.5 rounded-full">${liberados.length} sin avisar</span>
        </p>
        <p class="text-xs text-gray-500 mt-1 max-w-xl">Un solo correo con esta tabla para Jefe de Terreno, Capataces, Administrador de Contrato, JAO y Prevención. Envíala cuando termines de entregar el grupo.</p>
      </div>
      <button id="btn-enviar-nomina" onclick="enviarNomina()" class="bg-gray-900 hover:bg-gray-800 text-white text-sm font-semibold px-4 py-2 rounded-lg disabled:opacity-50">
        ✉ Enviar nómina por correo
      </button>
    </div>
    <div class="overflow-x-auto mt-4">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500 text-left">
          <tr>
            <th class="px-3 py-2">Nombre</th>
            <th class="px-3 py-2">RUT</th>
            <th class="px-3 py-2">Cargo</th>
            <th class="px-3 py-2">Liberado</th>
            <th class="px-3 py-2">Seleccionado por</th>
          </tr>
        </thead>
        <tbody>
          ${liberados.map(l => `
            <tr class="border-t">
              <td class="px-3 py-2 font-medium">${esc(l.nombre_completo)}</td>
              <td class="px-3 py-2 font-mono">${esc(l.rut)}</td>
              <td class="px-3 py-2">${esc(l.nombre_cargo)}</td>
              <td class="px-3 py-2">${horaCorta(l.liberado_at)}</td>
              <td class="px-3 py-2 text-gray-600">${esc(l.seleccionado_por || '-')}</td>
            </tr>`).join('')}
        </tbody>
      </table>
    </div>`;
}

async function enviarNomina() {
  const n = LIBERADOS_PENDIENTES;
  if (!confirm(`¿Enviar por correo la nómina con ${n} ${n === 1 ? 'trabajador liberado' : 'trabajadores liberados'}?`)) return;
  const boton = document.getElementById('btn-enviar-nomina');
  boton.disabled = true;
  boton.textContent = 'Enviando...';
  try {
    const data = await apiFetch('/bodega/enviar_nomina.php', { method: 'POST', body: {} });
    mostrarAlerta('alerta', data.mensaje, 'exito');
    await cargarLista();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
    boton.disabled = false;
    boton.textContent = '✉ Enviar nómina por correo';
  }
}
