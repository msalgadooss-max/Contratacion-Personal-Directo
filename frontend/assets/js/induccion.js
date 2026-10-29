/**
 * v9 - Catálogo de cursos de Prevención (evolución del módulo de
 * "videos de inducción" de v6.9, reunión Ricardo 31-ago), inspirado en
 * academiamlp.cl: cursos agrupados por categoría, cada uno con su video
 * y una evaluación de preguntas abiertas. El video se marca "visto"
 * solo al terminar de verlo (evento `ended`); la evaluación la envía el
 * propio postulante en texto libre, y Prevención es quien la aprueba o
 * reprueba a mano (sin corrección automática) -- ver dashboard-prevencion.js.
 */
const form = document.getElementById('form-induccion');
const rutInput = document.getElementById('rut');
const resultadoDiv = document.getElementById('resultado');
const alertaDiv = document.getElementById('alerta');

formatearRutInput(rutInput);

const params = new URLSearchParams(window.location.search);
if (params.get('rut')) rutInput.value = params.get('rut');
if (params.get('codigo')) document.getElementById('codigo').value = params.get('codigo');

let ULTIMO_RUT = '';
let ULTIMO_CODIGO = '';

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  await cargarCursos();
});

async function cargarCursos() {
  alertaDiv.innerHTML = '';
  resultadoDiv.innerHTML = '';

  const codigo = document.getElementById('codigo').value.trim().toUpperCase();
  ULTIMO_RUT = rutInput.value;
  ULTIMO_CODIGO = codigo;

  try {
    const data = await apiFetch('/public/induccion_listar.php', {
      method: 'POST',
      body: { rut: rutInput.value, codigo_seguimiento: codigo },
    });
    renderCatalogo(data);
  } catch (err) {
    alertaDiv.innerHTML = `<div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3">${esc(err.message)}</div>`;
  }
}

const ESTILO_BADGE = {
  Aprobado: 'bg-green-50 text-green-700',
  Reprobado: 'bg-red-50 text-red-700',
  en_revision: 'bg-blue-50 text-blue-700',
  visto: 'bg-amber-50 text-amber-700',
  pendiente: 'bg-gray-100 text-gray-500',
};

function estadoVisual(c) {
  if (c.estado === 'Aprobado') return { texto: '✓ Aprobado', clase: ESTILO_BADGE.Aprobado };
  if (c.estado === 'Reprobado') return { texto: '✕ Reprobado', clase: ESTILO_BADGE.Reprobado };
  if (c.enviado) return { texto: 'En revisión', clase: ESTILO_BADGE.en_revision };
  if (c.visto) return { texto: 'Falta enviar evaluación', clase: ESTILO_BADGE.visto };
  return { texto: 'Pendiente', clase: ESTILO_BADGE.pendiente };
}

function renderCatalogo(data) {
  const cursos = data.cursos;
  const aprobados = cursos.filter(c => c.estado === 'Aprobado').length;
  const total = cursos.length;

  // Agrupa por categoría, en el mismo orden en que llegan del backend.
  const categorias = [];
  cursos.forEach(c => {
    let grupo = categorias.find(g => g.categoria === c.categoria);
    if (!grupo) {
      grupo = { categoria: c.categoria, cursos: [] };
      categorias.push(grupo);
    }
    grupo.cursos.push(c);
  });

  resultadoDiv.innerHTML = `
    <div class="bg-white shadow-sm rounded-xl p-4">
      <p class="font-bold text-gray-900">${esc(data.nombre_completo)}</p>
      <p class="text-sm ${aprobados === total ? 'text-green-600 font-semibold' : 'text-gray-500'}">${aprobados} de ${total} cursos aprobados</p>
    </div>
    ${categorias.map(g => `
      <div class="mt-5">
        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">${esc(g.categoria)}</p>
        <div class="space-y-4">${g.cursos.map(cursoHtml).join('')}</div>
      </div>`).join('')}
  `;

  cursos.forEach(c => {
    if (Array.isArray(c.respuestas)) {
      c.respuestas.forEach((r, i) => {
        const ta = document.getElementById(`respuesta-${c.id}-${i}`);
        if (ta) ta.value = r;
      });
    }
  });
}

function cursoHtml(c) {
  const badge = estadoVisual(c);
  return `
    <div class="bg-white shadow-sm rounded-xl p-4">
      <div class="flex items-center justify-between mb-2 gap-2">
        <p class="font-semibold text-gray-900 text-sm">${esc(c.titulo)}${c.duracion_estimada ? ` <span class="text-gray-400 font-normal">· ${esc(c.duracion_estimada)}</span>` : ''}</p>
        <span class="shrink-0 text-xs font-medium px-2 py-1 rounded-md ${badge.clase}">${esc(badge.texto)}</span>
      </div>
      ${c.descripcion ? `<p class="text-xs text-gray-500 mb-2">${esc(c.descripcion)}</p>` : ''}
      <video controls class="w-full rounded-lg" onended="marcarVisto(${c.id})">
        <source src="${esc(c.url)}" type="video/mp4">
        Tu navegador no puede reproducir este video.
      </video>
      ${bloqueEvaluacion(c)}
    </div>`;
}

function bloqueEvaluacion(c) {
  if (c.estado === 'Aprobado') {
    return `<div class="mt-3 bg-green-50 border border-green-200 rounded-lg px-3 py-2 text-xs text-green-700">Evaluación aprobada${c.comentario_evaluador ? ` -- "${esc(c.comentario_evaluador)}"` : ''}.</div>`;
  }
  if (!c.visto) {
    return `<p class="mt-3 text-xs text-gray-400">Termina de ver el video para habilitar la evaluación.</p>`;
  }
  if (c.enviado && c.estado === 'Pendiente') {
    return `<div class="mt-3 bg-blue-50 border border-blue-200 rounded-lg px-3 py-2 text-xs text-blue-700">Ya enviaste tus respuestas -- esperando que Prevención las revise.</div>`;
  }
  const preguntas = c.preguntas || [];
  return `
    <div class="mt-3 border-t border-gray-100 pt-3">
      ${c.estado === 'Reprobado' && c.comentario_evaluador ? `
        <div class="bg-red-50 border border-red-200 rounded-lg px-3 py-2 text-xs text-red-700 mb-3">
          Prevención te pidió corregir: "${esc(c.comentario_evaluador)}"
        </div>` : ''}
      <p class="text-xs font-semibold text-gray-600 mb-2">Evaluación</p>
      <div class="space-y-2">
        ${preguntas.map((p, i) => `
          <div>
            <label class="block text-xs text-gray-600 mb-1">${p}</label>
            <textarea id="respuesta-${c.id}-${i}" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
          </div>`).join('')}
      </div>
      <button class="mt-2 w-full bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg py-2" onclick="enviarEvaluacion(${c.id}, ${preguntas.length})">
        ${c.estado === 'Reprobado' ? 'Reenviar evaluación' : 'Enviar evaluación'}
      </button>
    </div>`;
}

async function marcarVisto(cursoId) {
  try {
    await apiFetch('/public/induccion_marcar_visto.php', {
      method: 'POST',
      body: { rut: ULTIMO_RUT, codigo_seguimiento: ULTIMO_CODIGO, curso_id: cursoId },
    });
    await cargarCursos();
  } catch (err) {
    // silencioso: no queremos interrumpir al postulante si esto falla
  }
}

async function enviarEvaluacion(cursoId, totalPreguntas) {
  const respuestas = [];
  for (let i = 0; i < totalPreguntas; i++) {
    respuestas.push(document.getElementById(`respuesta-${cursoId}-${i}`).value.trim());
  }
  if (respuestas.every(r => r === '')) {
    alertaDiv.innerHTML = `<div class="bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-lg px-4 py-3">Responde al menos una pregunta.</div>`;
    return;
  }
  try {
    const data = await apiFetch('/public/induccion_enviar_evaluacion.php', {
      method: 'POST',
      body: { rut: ULTIMO_RUT, codigo_seguimiento: ULTIMO_CODIGO, curso_id: cursoId, respuestas },
    });
    alertaDiv.innerHTML = `<div class="bg-green-50 border border-green-200 text-green-700 text-sm rounded-lg px-4 py-3">${esc(data.mensaje)}</div>`;
    await cargarCursos();
  } catch (err) {
    alertaDiv.innerHTML = `<div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3">${esc(err.message)}</div>`;
  }
}
