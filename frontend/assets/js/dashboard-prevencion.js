/**
 * Panel de Prevención.
 *
 * v10.21 (pedido explícito del usuario, tras el piloto del 16-09):
 * Prevención tiene DOS check, uno por día, ambos con un casillero de
 * confirmación (ya no hay catálogo de cursos en esta pantalla; las tablas
 * y endpoints de cursos siguen existiendo en la base, ocultos):
 *
 *   1) Inducción -- día de postulación, después de que el JAO verificó a
 *      la persona. Termina su día y recibe el correo "preséntate mañana
 *      a las 8 am" (ver prevencion/marcar_induccion.php).
 *   2) IRL -- día de contratación, después de que el JAO firmó su
 *      contrato. Habilita a Bodega para entregar el kit (ver
 *      prevencion/marcar_irl.php).
 */
(async () => {
  const usuario = await protegerDashboard('Prevencionista');
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
    pintarTabla('tbody-postulaciones', 'vacio', data.postulaciones || [], 'Inducción realizada', 'marcarInduccion');
    pintarTabla('tbody-irl', 'vacio-irl', data.irl_pendientes || [], 'IRL realizada', 'marcarIrl');
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
  return confirmarCheck(checkbox, id, '/prevencion/marcar_induccion.php', '¿Confirmas que la inducción quedó realizada para');
}

function marcarIrl(checkbox, id) {
  return confirmarCheck(checkbox, id, '/prevencion/marcar_irl.php', '¿Confirmas que la IRL quedó realizada para');
}
