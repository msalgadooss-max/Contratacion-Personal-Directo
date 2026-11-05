/**
 * Fase 6 incluida aquí: cuando el estado llega a EPP_listo, Contratado
 * o Proceso_completo, esta pantalla cambia a verde y genera el QR de
 * acceso para portería, codificando la URL pública de validación
 * (backend/api/porteria/validar.php) con rut + codigo_seguimiento.
 *
 * v10.14 (pedido explícito del usuario, item 13 de la lista post-prueba):
 * se quita la línea de tiempo detallada por estado interno (el
 * postulante no tiene por qué saber que lo aprueba "el Administrador"
 * o "el Jefe de Terreno") -- se muestra en cambio una sola fase breve
 * y general, en FASES_POSTULANTE más abajo.
 */
const FASES_POSTULANTE = {
  Pendiente: '📋 Tu postulación está en revisión',
  Pre_aprobado_terreno: '📝 Completa tus datos para avanzar',
  Aprobado_admin: '🔍 Estamos revisando tus documentos',
  // v10.24: terminado su día de postulación (JAO y Prevención ya lo
  // verificaron) -- antes seguía diciendo "revisando tus documentos".
  Induccion_ok: '✅ Documentos revisados · Preséntate a las 08:00 en la obra, según te indicamos por correo',
};

const form = document.getElementById('form-seguimiento');
const rutInput = document.getElementById('rut');
const resultadoDiv = document.getElementById('resultado');
const alertaDiv = document.getElementById('alerta');

formatearRutInput(rutInput);

// v10.15 (pedido explícito del usuario, item 1 de la lista post-prueba):
// mismo selector de dominio de correo que la Etapa 1 (postulacion.js),
// para evitar los mismos errores de tilde/@ acá también.
const correoUsuarioInput = document.getElementById('correo_usuario');
const correoDominioSelect = document.getElementById('correo_dominio');
const correoDominioOtroInput = document.getElementById('correo_dominio_otro');
const correoHidden = document.getElementById('correo');
const DOMINIOS_CONOCIDOS = ['@gmail.com', '@hotmail.com', '@outlook.com', '@yahoo.com', '@icloud.com'];

correoDominioSelect.addEventListener('change', () => {
  correoDominioOtroInput.classList.toggle('hidden', correoDominioSelect.value !== '__otro__');
  if (correoDominioSelect.value === '__otro__') correoDominioOtroInput.focus();
});

function quitarTildes(texto) {
  return texto.normalize('NFD').replace(/[̀-ͯ]/g, '');
}

function actualizarCorreoCompuesto() {
  const sinTildes = quitarTildes(correoUsuarioInput.value);
  if (sinTildes !== correoUsuarioInput.value) {
    const posicion = correoUsuarioInput.selectionStart;
    correoUsuarioInput.value = sinTildes;
    correoUsuarioInput.setSelectionRange(posicion, posicion);
  }
  const dominio = correoDominioSelect.value === '__otro__'
    ? correoDominioOtroInput.value.trim()
    : correoDominioSelect.value;
  correoHidden.value = correoUsuarioInput.value.trim() + dominio;
}
correoUsuarioInput.addEventListener('input', actualizarCorreoCompuesto);
correoDominioSelect.addEventListener('change', actualizarCorreoCompuesto);
correoDominioOtroInput.addEventListener('input', actualizarCorreoCompuesto);

// Reparte un correo completo (que puede venir de un QR con ?correo=...)
// entre el campo de usuario y el select de dominio.
function precargarCorreo(correoCompleto) {
  const arroba = correoCompleto.indexOf('@');
  if (arroba === -1) return;
  const usuario = correoCompleto.slice(0, arroba);
  const dominio = correoCompleto.slice(arroba); // incluye el "@"
  correoUsuarioInput.value = usuario;
  if (DOMINIOS_CONOCIDOS.includes(dominio)) {
    correoDominioSelect.value = dominio;
  } else {
    correoDominioSelect.value = '__otro__';
    correoDominioOtroInput.classList.remove('hidden');
    correoDominioOtroInput.value = dominio;
  }
  actualizarCorreoCompuesto();
}

// Prefill si venimos desde el correo de confirmación (?rut=...), o de un
// QR personalizado que ya trae ambos datos (?rut=...&correo=...) -- ej.
// el que el asistente del JAO podria mostrar al entregar casco y peto.
const params = new URLSearchParams(window.location.search);
if (params.get('rut')) rutInput.value = params.get('rut');
if (params.get('correo')) precargarCorreo(params.get('correo'));

// v6.6: se guardan para poder reenviar el enlace de Etapa 2 sin pedirle
// de nuevo el RUT y el código al postulante.
// v10: el acceso ahora es RUT + correo (no código de seguimiento) --
// ULTIMO_CODIGO_CONSULTADO ya no viene de lo que el postulante tipeo,
// sino de lo que devuelve la propia consulta (seguimiento.php lo sigue
// entregando en la respuesta, aunque ya no se use para buscar). Así el
// QR de porteria, el link de induccion y "reenviar mi enlace" siguen
// funcionando exactamente igual que antes.
let ULTIMO_RUT_CONSULTADO = '';
let ULTIMO_CODIGO_CONSULTADO = '';

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  alertaDiv.innerHTML = '';
  resultadoDiv.innerHTML = '';

  const correo = document.getElementById('correo').value.trim();
  ULTIMO_RUT_CONSULTADO = rutInput.value;

  try {
    const data = await apiFetch('/public/seguimiento.php', {
      method: 'POST',
      body: { rut: rutInput.value, correo },
    });
    ULTIMO_CODIGO_CONSULTADO = data.postulacion.codigo_seguimiento || '';
    renderResultado(data.postulacion);
  } catch (err) {
    alertaDiv.innerHTML = `<div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3">${esc(err.message)}</div>`;
  }
});

async function reenviarEtapa2() {
  const btn = document.getElementById('btn-reenviar-etapa2');
  const zona = document.getElementById('zona-reenviar-etapa2');
  btn.disabled = true;
  btn.textContent = 'Generando...';
  try {
    const data = await apiFetch('/public/reenviar_etapa2.php', {
      method: 'POST',
      body: { rut: ULTIMO_RUT_CONSULTADO, codigo_seguimiento: ULTIMO_CODIGO_CONSULTADO },
    });
    zona.innerHTML = `
      <p class="text-sm text-blue-800 mb-2">${esc(data.mensaje)}</p>
      <a href="${esc(data.url_etapa2)}" class="inline-block bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-4 py-2 rounded-lg">Continuar completando mis datos</a>`;
  } catch (err) {
    zona.innerHTML = `<p class="text-sm text-red-600">${esc(err.message)}</p>`;
  }
}

// v10.29 (pedido explícito del usuario): bloques extra del seguimiento --
// (1) documentos que le faltan al postulante, con la opción de subirlos desde
// aquí (útil si postula a distancia), y (2) la encuesta de satisfacción una
// vez terminada la Etapa 2.
function bloquesExtra(p) {
  const pend = p.documentos_pendientes || [];
  const bloquePend = pend.length ? `
    <div class="bg-purple-50 border border-purple-200 rounded-lg px-4 py-3 mb-4" id="zona-pendientes">
      <p class="text-sm font-semibold text-purple-900 mb-1">⏳ Te falta entregar ${pend.length === 1 ? 'este documento' : 'estos documentos'}</p>
      <p class="text-xs text-purple-800 mb-3">No frena tu proceso. Súbelos aquí apenas los tengas (PDF, JPG o PNG, hasta 8 MB cada uno).</p>
      <form id="form-pendientes" class="space-y-3">
        ${pend.map(d => `
          <div>
            <label class="block text-sm text-gray-800 mb-1">${esc(d.etiqueta)}</label>
            <input type="file" name="${esc(d.tipo)}" accept="application/pdf,image/jpeg,image/png"
                   class="block w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-purple-600 file:px-3 file:py-2 file:text-white file:font-semibold">
          </div>`).join('')}
        <button type="submit" class="bg-purple-700 hover:bg-purple-800 text-white text-sm font-semibold px-4 py-2 rounded-lg">Enviar documentos</button>
      </form>
    </div>` : '';
  const bloqueEncuesta = p.etapa2_completada ? `
    <div class="bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 mb-4">
      <p class="text-sm font-semibold text-gray-900">¿Nos cuentas cómo te fue? (1 minuto)</p>
      <p class="text-xs text-gray-600 mt-1">Es anónima y nos ayuda a mejorar el proceso.</p>
      <a href="encuesta.html" class="inline-block mt-2 bg-gray-900 hover:bg-gray-800 text-white text-sm font-semibold px-4 py-2 rounded-lg">Responder la encuesta</a>
    </div>` : '';
  return bloquePend + bloqueEncuesta;
}

function activarFormPendientes() {
  const formPend = document.getElementById('form-pendientes');
  if (!formPend) return;
  formPend.addEventListener('submit', async (e) => {
    e.preventDefault();
    const datos = new FormData();
    datos.append('rut', ULTIMO_RUT_CONSULTADO);
    datos.append('correo', document.getElementById('correo').value.trim());
    let hayArchivo = false;
    formPend.querySelectorAll('input[type="file"]').forEach((inp) => {
      if (inp.files[0]) { datos.append(inp.name, inp.files[0]); hayArchivo = true; }
    });
    if (!hayArchivo) {
      alertaDiv.innerHTML = '<div class="bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-lg px-4 py-3">Elige al menos un archivo para enviar.</div>';
      return;
    }
    const boton = formPend.querySelector('button[type="submit"]');
    boton.disabled = true;
    boton.textContent = 'Enviando...';
    try {
      const r = await apiFetchFormData('/public/pendientes_enviar.php', datos);
      alertaDiv.innerHTML = `<div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-lg px-4 py-3">${esc(r.mensaje)}</div>`;
      // Vuelve a consultar para refrescar la lista de lo que aún falta.
      form.requestSubmit();
    } catch (err) {
      alertaDiv.innerHTML = `<div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3">${esc(err.message)}</div>`;
      boton.disabled = false;
      boton.textContent = 'Enviar documentos';
    }
  });
}

function renderResultado(p) {
  if (p.en_banco) {
    resultadoDiv.innerHTML = `
      <div class="bg-blue-50 border border-blue-200 rounded-xl p-6 text-center">
        <p class="text-blue-700 font-bold text-lg">Estás en nuestro Banco de Postulantes</p>
        <p class="text-sm text-gray-600 mt-1">${esc(p.nombre_completo)} · interés en ${esc(p.cargo)}</p>
        <p class="text-sm text-gray-600 mt-3">Ahora mismo no hay cupos para ese cargo. Te contactaremos apenas se abra uno.</p>
        <p class="text-xs text-gray-400 mt-3">Tus datos se conservan hasta el ${esc(p.retencion_hasta)}.</p>
      </div>`;
    return;
  }

  if (p.rechazado) {
    resultadoDiv.innerHTML = `
      <div class="bg-red-50 border border-red-200 rounded-xl p-6 text-center">
        <p class="text-red-700 font-bold text-lg">Postulación no continúa en el proceso</p>
        <p class="text-sm text-red-600 mt-1">${esc(p.nombre_completo)} · ${esc(p.cargo)}</p>
      </div>`;
    return;
  }

  if (p.autorizado_ingreso) {
    // v7: para cuando llega este estado, el JAO ya firmó el contrato y
    // Bodega ya entregó el kit de EPP (ambos pasos del día 2 ya
    // ocurrieron) -- lo único que falta es que el Capataz o Jefe de
    // Terreno lo vengan a buscar. 'Proceso_completo' es un paso más:
    // ya lo fueron a buscar y el ciclo completo terminó.
    const yaRecibido = p.estado === 'Proceso_completo';
    const esContratacionFinal = p.estado === 'Contratado' || yaRecibido;
    const titulo = esContratacionFinal ? 'PROCESO COMPLETADO EXITOSAMENTE' : 'CONTRATACIÓN AUTORIZADA';
    const subtitulo = yaRecibido
      ? '¡Bienvenido a ICAFAL!'
      : esContratacionFinal
        ? 'Espera a que tu Capataz o Jefe de Terreno te venga a buscar'
        : 'INGRESO PERMITIDO A LA OBRA';
    const pie = 'Muestra este código al guardia en portería.';
    resultadoDiv.innerHTML = `
      <div class="bg-green-500 rounded-xl p-6 text-center text-white shadow-lg">
        <p class="text-2xl font-extrabold">${titulo}</p>
        <p class="text-lg font-semibold mt-1">${subtitulo}</p>
        <p class="text-sm mt-2 opacity-90">${esc(p.nombre_completo)} · ${esc(p.cargo)}</p>
        <div id="qr" class="bg-white inline-block p-3 rounded-lg mt-4"></div>
        <p class="text-xs mt-2 opacity-80">${pie}</p>
      </div>
    `;
    // El QR apunta a una página HTML de resultado (no directo a la API)
    // para que el guardia vea una credencial legible, no un JSON crudo.
    const urlValidacion = `${window.location.origin}${window.location.pathname.replace('seguimiento.html', 'porteria_resultado.html')}?rut=${encodeURIComponent(p.rut)}&codigo=${encodeURIComponent(p.codigo_seguimiento)}`;
    // eslint-disable-next-line no-undef
    new QRCode(document.getElementById('qr'), { text: urlValidacion, width: 160, height: 160 });
    const extra = bloquesExtra(p);
    if (extra) {
      resultadoDiv.insertAdjacentHTML('beforeend', `<div class="bg-white shadow-sm rounded-xl p-5 mt-4">${extra}</div>`);
      activarFormPendientes();
    }
    return;
  }

  // v6.6: si ya le corresponde completar Etapa 2, se ofrece continuar
  // directamente desde aquí -- por si el correo con el enlace no llegó.
  let bloqueEtapa2 = '';
  if (p.puede_completar_etapa2) {
    if (p.url_etapa2) {
      bloqueEtapa2 = `
        <div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 mb-4">
          <p class="text-sm text-blue-800 font-medium mb-2">Avanza completando tus datos.</p>
          <a href="${esc(p.url_etapa2)}" class="inline-block bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-4 py-2 rounded-lg">Continuar completando mis datos</a>
        </div>`;
    } else {
      bloqueEtapa2 = `
        <div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 mb-4" id="zona-reenviar-etapa2">
          <p class="text-sm text-blue-800 font-medium mb-2">Avanza completando tus datos. Te enviamos un correo con el link: si no te llegó, genera uno nuevo aquí:</p>
          <button id="btn-reenviar-etapa2" onclick="reenviarEtapa2()" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-4 py-2 rounded-lg">Generar mi enlace para continuar</button>
        </div>`;
    }
  }

  // v6.9/v9: el catálogo de cursos de Prevención queda disponible apenas
  // autoriza el Administrador de Contrato, no hace falta esperar a Etapa 2.
  const bloqueInduccion = p.puede_ver_induccion ? `
    <div class="bg-indigo-50 border border-indigo-200 rounded-lg px-4 py-3 mb-4">
      <p class="text-sm text-indigo-800 font-medium mb-2">🎥 Ya puedes ver tus cursos de Prevención -- así tu charla en obra será más corta.</p>
      <a href="induccion.html?rut=${encodeURIComponent(p.rut)}&codigo=${encodeURIComponent(p.codigo_seguimiento)}" class="inline-block bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2 rounded-lg">Ver mis cursos</a>
    </div>` : '';

  resultadoDiv.innerHTML = `
    <div class="bg-white shadow-sm rounded-xl p-5">
      <p class="font-bold text-gray-900">${esc(p.nombre_completo)}</p>
      <p class="text-sm text-gray-500 mb-4">${esc(p.cargo)}</p>
      ${bloqueEtapa2}
      ${bloqueInduccion}
      ${p.documento_observado ? `
        <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 mb-4 text-sm text-amber-800">
          ⚠ Hay una observación en uno de tus documentos. Revisa tu correo para ver el detalle y el link para corregirlo.
        </div>` : ''}
      ${bloquesExtra(p)}
      ${faseHtml(p)}
    </div>`;
  activarFormPendientes();
}

// v10.14 (pedido explícito del usuario, item 13): una sola fase breve y
// general, en vez de la línea de tiempo detallada por estado interno --
// el postulante no tiene por qué saber que lo aprueba "el Administrador"
// o "el Jefe de Terreno", solo en qué anda su proceso ahora mismo.
function faseHtml(p) {
  const fase = FASES_POSTULANTE[p.estado] || '📋 Tu postulación está en proceso';
  return `
    <div class="mt-2 bg-gray-50 rounded-lg px-4 py-3 text-sm font-medium text-gray-700">
      ${fase}
    </div>`;
}
