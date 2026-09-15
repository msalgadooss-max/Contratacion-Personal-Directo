/**
 * v10.14 (pedido explícito del usuario) - Encuesta de satisfacción
 * anónima para los trabajadores de prueba del piloto: mide del 1 al 7
 * (1 = muy difícil/malo, 7 = muy fácil/bueno) varias aristas distintas
 * del proceso de postulación, más un comentario abierto opcional.
 */
const PREGUNTAS = [
  { clave: 'claridad_pasos', texto: '¿Qué tan claro fue entender qué debías hacer en cada paso del proceso?' },
  { clave: 'facilidad_datos', texto: '¿Qué tan fácil fue completar tus datos personales?' },
  { clave: 'facilidad_documentos', texto: '¿Qué tan fácil fue fotografiar y subir tus documentos?' },
  { clave: 'claridad_correos', texto: '¿Qué tan claros fueron los correos y avisos que recibiste?' },
  { clave: 'tiempo_espera', texto: '¿Qué tan razonable te pareció el tiempo de espera en cada etapa?' },
  { clave: 'claridad_seguimiento', texto: '¿Qué tan fácil fue saber en qué iba tu proceso?' },
  { clave: 'dificultad_general', texto: 'En general, ¿qué tan fácil te resultó todo el proceso?' },
  { clave: 'recomendaria', texto: '¿Qué tan probable es que recomiendes este proceso a otro postulante?' },
];

const respuestas = {};

// v10.14: las clases se recalculan completas (no con classList.toggle)
// porque un botón "seleccionado" y sus propias clases hover: chocan --
// en la hoja que genera Tailwind, un selector con :hover pesa más que
// una clase simple, así que hover:bg-blue-50 le seguía ganando al
// bg-blue-600 del botón elegido apenas el mouse quedaba encima. La
// forma segura de evitarlo es no dejar esas clases hover: puestas en
// el botón mientras está seleccionado.
function claseBoton(seleccionado) {
  const base = 'opcion-escala aspect-square rounded-lg border text-xs font-semibold transition';
  return seleccionado
    ? `${base} bg-blue-600 border-blue-600 text-white`
    : `${base} border-gray-300 text-gray-600 hover:border-blue-400 hover:bg-blue-50`;
}

function renderPreguntas() {
  const cont = document.getElementById('preguntas');
  cont.innerHTML = PREGUNTAS.map((p, i) => `
    <div class="bg-white shadow-sm rounded-xl p-4">
      <p class="text-sm font-medium text-gray-800 mb-3">${i + 1}. ${p.texto}</p>
      <div class="flex items-center gap-1.5">
        <span class="text-base leading-none shrink-0" title="Muy difícil">😞</span>
        <div class="flex-1 grid grid-cols-7 gap-1" data-grupo="${p.clave}">
          ${[1, 2, 3, 4, 5, 6, 7].map(n => `
            <button type="button" data-valor="${n}" onclick="seleccionar('${p.clave}', ${n})"
                    class="${claseBoton(false)}">${n}</button>`).join('')}
        </div>
        <span class="text-base leading-none shrink-0" title="Muy fácil">😄</span>
      </div>
    </div>`).join('');
}

function seleccionar(clave, valor) {
  respuestas[clave] = valor;
  const grupo = document.querySelector(`[data-grupo="${clave}"]`);
  grupo.querySelectorAll('.opcion-escala').forEach(btn => {
    btn.className = claseBoton(Number(btn.dataset.valor) === valor);
  });
  actualizarProgreso();
}

// v10.15 (pedido explícito del usuario): barra de progreso -- cuenta
// cuántas de las 8 preguntas ya tienen respuesta, no en cuál va el
// usuario (las 8 se ven todas juntas, se responden en cualquier orden).
function actualizarProgreso() {
  const respondidas = Object.keys(respuestas).length;
  const total = PREGUNTAS.length;
  const pct = Math.round((respondidas / total) * 100);
  document.getElementById('progreso-texto').textContent = `${respondidas} de ${total} respondidas`;
  document.getElementById('progreso-porcentaje').textContent = `${pct}%`;
  document.getElementById('progreso-barra').style.width = `${pct}%`;
}

renderPreguntas();
actualizarProgreso();

document.getElementById('form-encuesta').addEventListener('submit', async (e) => {
  e.preventDefault();
  const alertaDiv = document.getElementById('alerta');
  alertaDiv.innerHTML = '';

  const faltantes = PREGUNTAS.filter(p => !respuestas[p.clave]);
  if (faltantes.length) {
    alertaDiv.innerHTML = `<div class="bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-lg px-4 py-3 mb-4">Responde todas las preguntas antes de enviar.</div>`;
    window.scrollTo({ top: 0, behavior: 'smooth' });
    return;
  }

  // v10.14 (pedido explícito del usuario): la edad es obligatoria --
  // sirve para sacar mediciones y promedios más adelante, y no se pide
  // nombre (la encuesta queda anónima).
  const edad = document.getElementById('edad').value;
  if (!edad) {
    alertaDiv.innerHTML = `<div class="bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-lg px-4 py-3 mb-4">Indica tu edad antes de enviar.</div>`;
    window.scrollTo({ top: 0, behavior: 'smooth' });
    return;
  }

  const btn = document.getElementById('btn-enviar');
  btn.disabled = true;
  btn.textContent = 'Enviando...';

  try {
    await apiFetch('/public/encuesta_guardar.php', {
      method: 'POST',
      body: {
        ...respuestas,
        edad,
        cargo_probado: document.getElementById('cargo_probado').value,
        comentario: document.getElementById('comentario').value,
      },
    });
    document.getElementById('form-encuesta').classList.add('hidden');
    document.getElementById('resultado').classList.remove('hidden');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  } catch (err) {
    alertaDiv.innerHTML = `<div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3 mb-4">${err.message}</div>`;
    btn.disabled = false;
    btn.textContent = 'Enviar respuestas';
  }
});
