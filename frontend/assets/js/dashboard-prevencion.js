/**
 * v9 - Catálogo de cursos de Prevención (reunión Ricardo, 31-ago):
 * reemplaza el conteo simple de "videos vistos" por el detalle completo
 * de cada curso -- Prevención lee las respuestas que envió el
 * postulante y decide Aprobado/Reprobado curso por curso.
 *
 * v10.20 (pedido explícito del usuario, hallado dos veces en pruebas
 * reales -- 16-09 y 22-09): "Inducción Realizada" antes SOLO aparecía
 * cuando TODOS los cursos activos estaban Aprobados -- en este piloto no
 * existe una forma real de que el postulante rinda esos cursos, así que
 * el botón nunca llegaba a mostrarse ("cursos 0 de 5" quedaba pegado
 * para siempre). Ahora el botón siempre está disponible (misma
 * confirmación de "¿dictaste la charla ODI?" de antes); el detalle de
 * cursos queda solo como información de apoyo para Prevención, sin
 * bloquear nada.
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

let ULTIMA_LISTA = [];

async function cargarLista() {
  const tbody = document.getElementById('tbody-postulaciones');
  const vacio = document.getElementById('vacio');
  try {
    const data = await apiFetch('/prevencion/listar.php');
    ULTIMA_LISTA = data.postulaciones;
    if (!data.postulaciones.length) {
      tbody.innerHTML = '';
      vacio.classList.remove('hidden');
      return;
    }
    vacio.classList.add('hidden');
    tbody.innerHTML = data.postulaciones.map(p => {
      const completo = p.cursos_total > 0 && p.cursos_aprobados === p.cursos_total;
      return `
      <tr class="border-t">
        <td class="px-4 py-3 font-mono">${p.rut}</td>
        <td class="px-4 py-3">${p.nombre_completo}</td>
        <td class="px-4 py-3">${p.nombre_cargo}</td>
        <td class="px-4 py-3">
          <button class="text-xs font-medium px-2 py-1 rounded-md ${completo ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700'} hover:opacity-80" onclick="abrirCursos(${p.id})">
            ${p.cursos_aprobados}/${p.cursos_total} cursos aprobados${p.cursos_por_revisar > 0 ? ` · ${p.cursos_por_revisar} por revisar` : ''}
          </button>
        </td>
        <td class="px-4 py-3 text-right">
          <button class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold px-3 py-1.5 rounded-lg" onclick="marcarInduccion(${p.id})">Inducción Realizada</button>
        </td>
      </tr>`;
    }).join('');
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

async function marcarInduccion(id) {
  if (!confirm('¿Confirmas que dictaste la charla ODI a esta persona?')) return;
  try {
    await apiFetch('/prevencion/marcar_induccion.php', { method: 'POST', body: { postulacion_id: id } });
    mostrarAlerta('alerta', 'Inducción registrada.', 'exito');
    await cargarLista();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

// --- v9: modal de detalle de cursos ----------------------------------------
async function abrirCursos(postulacionId) {
  const modal = document.getElementById('cursos-modal');
  modal.innerHTML = `<div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full p-6">${spinnerHtml()}</div>`;
  modal.classList.remove('hidden');
  modal.onclick = (e) => { if (e.target === modal) cerrarCursos(); };

  try {
    const data = await apiFetch(`/prevencion/cursos_detalle.php?postulacion_id=${postulacionId}`);
    renderCursosModal(postulacionId, data);
  } catch (err) {
    modal.innerHTML = `<div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full p-6 text-sm text-red-600">${err.message}</div>`;
  }
}

function cerrarCursos() {
  document.getElementById('cursos-modal').classList.add('hidden');
}

const ETIQUETA_CURSO_ESTADO = {
  Aprobado: '<span class="text-xs font-medium px-2 py-1 rounded-md bg-green-50 text-green-700">✓ Aprobado</span>',
  Reprobado: '<span class="text-xs font-medium px-2 py-1 rounded-md bg-red-50 text-red-700">✕ Reprobado</span>',
};

function renderCursosModal(postulacionId, data) {
  const modal = document.getElementById('cursos-modal');
  modal.innerHTML = `
    <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full p-6 relative max-h-[85vh] overflow-y-auto">
      <button onclick="cerrarCursos()" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600 text-xl leading-none">✕</button>
      <h2 class="text-lg font-bold text-gray-900 mb-1">${data.nombre_completo}</h2>
      <p class="text-xs text-gray-400 mb-5 font-mono">${data.rut}</p>
      <div class="space-y-4">
        ${data.cursos.map(c => cursoDetalleHtml(postulacionId, c)).join('')}
      </div>
    </div>`;
}

function cursoDetalleHtml(postulacionId, c) {
  let cuerpo;
  if (!c.visto) {
    cuerpo = `<p class="text-xs text-gray-400 mt-1">Todavía no ve este curso.</p>`;
  } else if (!c.enviado) {
    cuerpo = `<p class="text-xs text-amber-700 mt-1">Vio el video, falta que envíe su evaluación.</p>`;
  } else if (c.estado === 'Pendiente') {
    cuerpo = `
      <div class="mt-2 space-y-2">
        ${(c.preguntas || []).map((p, i) => `
          <div class="text-xs">
            <p class="text-gray-500">${p}</p>
            <p class="text-gray-900 bg-gray-50 rounded-md px-2 py-1.5 mt-0.5">${(c.respuestas && c.respuestas[i]) || '<span class="text-gray-400">(sin respuesta)</span>'}</p>
          </div>`).join('')}
        <textarea id="comentario-${postulacionId}-${c.id}" placeholder="Comentario (obligatorio si repruebas)" rows="2" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-xs mt-1"></textarea>
        <div class="flex gap-2 mt-1">
          <button class="flex-1 bg-green-600 hover:bg-green-700 text-white text-xs font-semibold rounded-lg py-1.5" onclick="evaluarCurso(${postulacionId}, ${c.id}, 'Aprobado')">✓ Aprobar</button>
          <button class="flex-1 bg-red-100 hover:bg-red-200 text-red-700 text-xs font-semibold rounded-lg py-1.5" onclick="evaluarCurso(${postulacionId}, ${c.id}, 'Reprobado')">✕ Reprobar</button>
        </div>
      </div>`;
  } else {
    // Aprobado o Reprobado ya evaluado.
    cuerpo = `
      <p class="text-xs text-gray-500 mt-1">${c.evaluado_por_nombre ? `Evaluado por ${c.evaluado_por_nombre}` : ''}${c.comentario_evaluador ? ` -- "${c.comentario_evaluador}"` : ''}</p>`;
  }

  return `
    <div class="border border-gray-100 rounded-xl p-3">
      <div class="flex items-center justify-between gap-2">
        <div>
          <p class="text-[10px] uppercase tracking-wide text-gray-400">${c.categoria}</p>
          <p class="text-sm font-semibold text-gray-900">${c.titulo}</p>
        </div>
        ${ETIQUETA_CURSO_ESTADO[c.estado] || '<span class="text-xs font-medium px-2 py-1 rounded-md bg-gray-100 text-gray-500">Pendiente</span>'}
      </div>
      ${cuerpo}
    </div>`;
}

async function evaluarCurso(postulacionId, cursoId, estado) {
  const comentario = document.getElementById(`comentario-${postulacionId}-${cursoId}`)?.value.trim() || '';
  if (estado === 'Reprobado' && !comentario) {
    mostrarAlerta('alerta', 'Escribe un comentario explicando qué debe corregir.');
    return;
  }
  try {
    const data = await apiFetch('/prevencion/evaluar_curso.php', {
      method: 'POST',
      body: { postulacion_id: postulacionId, curso_id: cursoId, estado, comentario },
    });
    mostrarAlerta('alerta', data.mensaje, 'exito');
    await abrirCursos(postulacionId);
    await cargarLista();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}
