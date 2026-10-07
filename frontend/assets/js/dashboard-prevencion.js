/**
 * Panel de Prevención.
 *
 * v10.24 (pedido explícito del usuario, 07-10): cada día va en su propia
 * pestaña con contador -- "Día 0 · Postulación" (verificación) y "Día 1 ·
 * Contratación (IRL)".
 *
 * v10.21 (pedido explícito del usuario, tras el piloto del 16-09):
 * Prevención tiene DOS check, uno por día, ambos con un casillero de
 * confirmación (ya no hay catálogo de cursos en esta pantalla; las tablas
 * y endpoints de cursos siguen existiendo en la base, ocultos):
 *
 *   1) Verificación (inducción) -- día de postulación, después de que el JAO verificó a
 *      la persona. Termina su día y recibe el correo "preséntate mañana
 *      a las 8 am" (ver prevencion/marcar_induccion.php).
 *   2) IRL -- día de contratación, después de que el JAO firmó su
 *      contrato. Habilita a Bodega para entregar el kit (ver
 *      prevencion/marcar_irl.php).
 */
(async () => {
  const usuario = await protegerDashboard('Prevencionista');
  if (!usuario) return;
  configurarTabs();
  await cargarLista();
  iniciarEstadoVivo();
})();

// --- v10.24: pestañas Día 0 / Día 1 ----------------------------------------
let TAB_ACTIVA = 'dia0';
let TAB_ELEGIDA_POR_USUARIO = false;

function configurarTabs() {
  document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      TAB_ELEGIDA_POR_USUARIO = true;
      cambiarTab(btn.dataset.tab);
    });
  });
}

function cambiarTab(tab) {
  TAB_ACTIVA = tab;
  document.querySelectorAll('.tab-btn').forEach(btn => {
    const activo = btn.dataset.tab === tab;
    btn.classList.toggle('border-blue-600', activo);
    btn.classList.toggle('text-blue-600', activo);
    btn.classList.toggle('border-transparent', !activo);
    btn.classList.toggle('text-gray-500', !activo);
  });
  document.querySelectorAll('.tab-panel').forEach(panel => {
    panel.classList.toggle('hidden', panel.id !== `panel-${tab}`);
  });
}

function pintarContador(id, n) {
  const el = document.getElementById(id);
  if (!el) return;
  el.textContent = n;
  el.className = 'ml-1 text-xs font-bold px-2 py-0.5 rounded-full ' + (n > 0 ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-500');
}

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

function filaConCheck(p, etiqueta, handler) {
  return `
    <tr class="border-t">
      <td class="px-4 py-3 font-mono">${esc(p.rut)}</td>
      <td class="px-4 py-3">${esc(p.nombre_completo)}</td>
      <td class="px-4 py-3">${esc(p.nombre_cargo)}</td>
      <td class="px-4 py-3 text-right">
        <label class="inline-flex items-center gap-2 cursor-pointer select-none">
          <input type="checkbox" class="w-6 h-6 accent-indigo-600 cursor-pointer" onchange="${handler}(this, ${Number(p.id)})">
          <span class="text-sm font-semibold text-gray-700">${etiqueta}</span>
        </label>
      </td>
    </tr>`;
}

function pintarTabla(tbodyId, vacioId, filas, etiqueta, handler) {
  const tbody = document.getElementById(tbodyId);
  const vacio = document.getElementById(vacioId);
  if (!filas.length) {
    tbody.innerHTML = '';
    vacio.classList.remove('hidden');
    return;
  }
  vacio.classList.add('hidden');
  tbody.innerHTML = filas.map(p => filaConCheck(p, etiqueta, handler)).join('');
}

async function cargarLista() {
  try {
    const data = await apiFetch('/prevencion/listar.php');
    const dia0 = data.postulaciones || [];
    const dia1 = data.irl_pendientes || [];
    pintarTabla('tbody-postulaciones', 'vacio', dia0, 'Verificación realizada', 'marcarInduccion');
    pintarTabla('tbody-irl', 'vacio-irl', dia1, 'IRL realizada', 'marcarIrl');
    pintarContador('cuenta-dia0', dia0.length);
    pintarContador('cuenta-dia1', dia1.length);
    // Si el usuario todavía no eligió pestaña, se abre la que tiene
    // pendientes (la de contratación solo si es la única con gente).
    if (!TAB_ELEGIDA_POR_USUARIO) {
      cambiarTab(dia0.length === 0 && dia1.length > 0 ? 'dia1' : 'dia0');
    }
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

// Ambos check son irreversibles una vez confirmados (no existe endpoint
// para revertirlos), así que piden confirmación mostrando el nombre -- para
// que un toque accidental en el celular no avance a la persona equivocada.
async function confirmarCheck(checkbox, id, endpoint, pregunta) {
  if (!checkbox.checked) return;
  const nombre = checkbox.closest('tr').children[1].textContent.trim();
  if (!confirm(`${pregunta} ${nombre}?\n\nEsta acción no se puede deshacer.`)) {
    checkbox.checked = false;
    return;
  }
  checkbox.disabled = true;
  try {
    const data = await apiFetch(endpoint, { method: 'POST', body: { postulacion_id: id } });
    mostrarAlerta('alerta', data.mensaje, 'exito');
    await cargarLista();
  } catch (err) {
    checkbox.checked = false;
    checkbox.disabled = false;
    mostrarAlerta('alerta', err.message);
  }
}

function marcarInduccion(checkbox, id) {
  return confirmarCheck(checkbox, id, '/prevencion/marcar_induccion.php', '¿Confirmas que la verificación quedó realizada para');
}

function marcarIrl(checkbox, id) {
  return confirmarCheck(checkbox, id, '/prevencion/marcar_irl.php', '¿Confirmas que la IRL quedó realizada para');
}
