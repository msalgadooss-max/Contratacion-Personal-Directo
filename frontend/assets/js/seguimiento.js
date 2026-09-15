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
  Induccion_ok: '🔍 Estamos revisando tus documentos',
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
    alertaDiv.innerHTML = `<div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3">${err.message}</div>`;
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
      <p class="text-sm text-blue-800 mb-2">${data.mensaje}</p>
      <a href="${data.url_etapa2}" class="inline-block bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-4 py-2 rounded-lg">Continuar completando mis datos</a>`;
  } catch (err) {
    zona.innerHTML = `<p class="text-sm text-red-600">${err.message}</p>`;
  }
}

function renderResultado(p) {
  if (p.en_banco) {
    resultadoDiv.innerHTML = `
      <div class="bg-blue-50 border border-blue-200 rounded-xl p-6 text-center">
        <p class="text-blue-700 font-bold text-lg">Estás en nuestro Banco de Postulantes</p>
        <p class="text-sm text-gray-600 mt-1">${p.nombre_completo} · interés en ${p.cargo}</p>
        <p class="text-sm text-gray-600 mt-3">Ahora mismo no hay cupos para ese cargo. Te contactaremos apenas se abra uno.</p>
        <p class="text-xs text-gray-400 mt-3">Tus datos se conservan hasta el ${p.retencion_hasta}.</p>
      </div>`;
    return;
  }

  if (p.rechazado) {
    resultadoDiv.innerHTML = `
      <div class="bg-red-50 border border-red-200 rounded-xl p-6 text-center">
        <p class="text-red-700 font-bold text-lg">Postulación no continúa en el proceso</p>
        <p class="text-sm text-red-600 mt-1">${p.nombre_completo} · ${p.cargo}</p>
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
        <p class="text-sm mt-2 opacity-90">${p.nombre_completo} · ${p.cargo}</p>
        <div id="qr" class="bg-white inline-block p-3 rounded-lg mt-4"></div>
        <p class="text-xs mt-2 opacity-80">${pie}</p>
      </div>
    `;
    // El QR apunta a una página HTML de resultado (no directo a la API)
    // para que el guardia vea una credencial legible, no un JSON crudo.
    const urlValidacion = `${window.location.origin}${window.location.pathname.replace('seguimiento.html', 'porteria_resultado.html')}?rut=${encodeURIComponent(p.rut)}&codigo=${encodeURIComponent(p.codigo_seguimiento)}`;
    // eslint-disable-next-line no-undef
    new QRCode(document.getElementById('qr'), { text: urlValidacion, width: 160, height: 160 });
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
          <a href="${p.url_etapa2}" class="inline-block bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-4 py-2 rounded-lg">Continuar completando mis datos</a>
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
      <p class="font-bold text-gray-900">${p.nombre_completo}</p>
      <p class="text-sm text-gray-500 mb-4">${p.cargo}</p>
      ${bloqueEtapa2}
      ${bloqueInduccion}
      ${p.documento_observado ? `
        <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 mb-4 text-sm text-amber-800">
          ⚠ Hay una observación en uno de tus documentos. Revisa tu correo para ver el detalle y el link para corregirlo.
        </div>` : ''}
      ${faseHtml(p)}
    </div>`;
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
