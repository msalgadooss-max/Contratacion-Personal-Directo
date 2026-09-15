// v10.15 (pedido explícito del usuario): spinner compartido para
// reemplazar los "Cargando..." de texto plano en listas/modales que
// tardan un poco -- se usa desde varios dashboards (todos cargan este
// archivo antes que su propio JS).
function spinnerHtml(texto = 'Cargando...', clases = 'py-8') {
  return `<div class="flex items-center justify-center gap-2 text-sm text-gray-400 ${clases}">
    <svg class="animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
    </svg>
    ${texto}
  </div>`;
}

/**
 * Guard de acceso compartido por todos los dashboards internos.
 * Verifica sesión + rol contra /auth/me.php (control real de todos
 * modos ocurre en el servidor en cada endpoint; esto solo evita mostrar
 * pantallas equivocadas y mejora la UX).
 */
async function protegerDashboard(rolEsperado) {
  try {
    const data = await apiFetch('/auth/me.php');
    setCsrfToken(data.csrf_token);
    if (data.usuario.rol !== rolEsperado) {
      window.location.href = 'no-autorizado.html';
      return null;
    }
    document.querySelectorAll('[data-usuario-nombre]').forEach(el => {
      el.textContent = data.usuario.nombre;
    });
    if (data.modo_desarrollador) {
      mostrarBannerDesarrollador(data.dev_nombre);
    }
    return data.usuario;
  } catch (e) {
    window.location.href = '../public/login.html';
    return null;
  }
}

/**
 * v5: cuando un Desarrollador "entró como" este rol (ver
 * dev/entrar_como.php), se muestra una franja fija arriba de cualquier
 * dashboard para que sea obvio que es una sesión prestada, con un link
 * directo para volver al panel de desarrollador.
 */
function mostrarBannerDesarrollador(devNombre) {
  const banner = document.createElement('div');
  banner.className = 'bg-amber-500 text-white text-xs font-semibold text-center py-1.5 px-4 sticky top-0 z-40';
  banner.innerHTML = `🛠 Modo desarrollador: ${devNombre || 'Desarrollador'} está viendo este dashboard prestado.
    <button onclick="volverAPanelDev()" class="underline ml-2">Volver al panel de desarrollador</button>`;
  document.body.prepend(banner);
}

async function volverAPanelDev() {
  try {
    await apiFetch('/dev/volver.php', { method: 'POST' });
  } finally {
    window.location.href = '../dashboards/dev.html';
  }
}

async function cerrarSesion() {
  try {
    await apiFetch('/auth/logout.php', { method: 'POST' });
  } finally {
    sessionStorage.removeItem('csrf_token');
    window.location.href = '../public/login.html';
  }
}

/**
 * v3: RUT + indicador visual cuando tipo_documento no es 'RUT' (persona
 * sin cédula chilena todavía -- ej. extranjero recién llegado).
 */
function celdaDocumento(p) {
  if (p.tipo_documento && p.tipo_documento !== 'RUT') {
    return `${p.rut} <span class="inline-block bg-amber-100 text-amber-700 text-[10px] font-bold px-1.5 py-0.5 rounded ml-1" title="Sin RUT chileno todavía">${p.tipo_documento}</span>`;
  }
  return p.rut;
}

// --- v6.9: motivo de rechazo estandarizado ---------------------------------
// Antes cada dashboard pedía el motivo con un prompt() de texto libre.
// Ricardo pidió (reunión 28-ago) motivos estandarizados y auditables para
// poder defender legalmente cada rechazo. Se usa en Terreno, Capataz y
// Admin_Contrato -- por eso vive en el archivo compartido por todos.
const MOTIVOS_RECHAZO_ESTANDAR = [
  'No hay cupos disponibles',
  'No cumple con los requisitos del cargo',
  'Documentación incompleta o ilegible',
  'No se presentó / no fue posible contactarlo',
  'Otro motivo',
];

function pedirMotivoRechazo() {
  return new Promise((resolve) => {
    let modal = document.getElementById('modal-motivo-rechazo');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'modal-motivo-rechazo';
      modal.className = 'fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4';
      document.body.appendChild(modal);
    }

    const cerrar = (valor) => { modal.classList.add('hidden'); resolve(valor); };

    modal.innerHTML = `
      <div class="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6">
        <h3 class="font-bold text-gray-900 mb-1">Motivo del rechazo</h3>
        <p class="text-xs text-gray-500 mb-4">Queda solo en el registro interno. El postulante recibe un mensaje genérico, nunca este motivo.</p>
        <select id="select-motivo-rechazo" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm mb-3">
          ${MOTIVOS_RECHAZO_ESTANDAR.map(m => `<option value="${m}">${m}</option>`).join('')}
        </select>
        <textarea id="detalle-motivo-rechazo" class="hidden w-full border border-gray-300 rounded-lg px-3 py-2 text-sm mb-3" rows="2" placeholder="Especifica el motivo..."></textarea>
        <div class="flex gap-3">
          <button id="btn-cancelar-motivo" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-semibold rounded-lg py-2">Cancelar</button>
          <button id="btn-confirmar-motivo" class="flex-1 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-lg py-2">Rechazar</button>
        </div>
      </div>`;
    modal.classList.remove('hidden');

    const select = document.getElementById('select-motivo-rechazo');
    const detalle = document.getElementById('detalle-motivo-rechazo');
    select.addEventListener('change', () => {
      detalle.classList.toggle('hidden', select.value !== 'Otro motivo');
    });

    document.getElementById('btn-cancelar-motivo').addEventListener('click', () => cerrar(null));
    modal.addEventListener('click', (e) => { if (e.target === modal) cerrar(null); });
    document.getElementById('btn-confirmar-motivo').addEventListener('click', () => {
      const motivo = select.value === 'Otro motivo'
        ? (detalle.value.trim() || 'Otro motivo')
        : select.value;
      cerrar(motivo);
    });
  });
}

// --- v10.3: Admin_Contrato puede ajustar la cantidad al aprobar un ------
// cupo (ej. pidieron 5, solo hay presupuesto para 3) y dejar una
// observación. Mismo patrón de modal que pedirMotivoRechazo().
function pedirAprobacionCupo(cantidadPedida, nombreCargo) {
  return new Promise((resolve) => {
    let modal = document.getElementById('modal-aprobar-cupo');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'modal-aprobar-cupo';
      modal.className = 'fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4';
      document.body.appendChild(modal);
    }

    const cerrar = (valor) => { modal.classList.add('hidden'); resolve(valor); };

    modal.innerHTML = `
      <div class="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6">
        <h3 class="font-bold text-gray-900 mb-1">Aprobar cupos de "${nombreCargo}"</h3>
        <p class="text-xs text-gray-500 mb-4">Pidieron ${cantidadPedida}. Puedes abrir esa misma cantidad, o subirla/bajarla.</p>
        <label class="block text-xs font-medium text-gray-700 mb-1">Cantidad a abrir</label>
        <input id="input-cantidad-aprobar" type="number" min="1" value="${cantidadPedida}"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm mb-3">
        <label class="block text-xs font-medium text-gray-700 mb-1">Observación (opcional)</label>
        <textarea id="input-observacion-aprobar" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm mb-3"
                  rows="2" placeholder="Ej: se aprueban 3 por presupuesto, el resto queda para el próximo mes."></textarea>
        <div class="flex gap-3">
          <button id="btn-cancelar-aprobar-cupo" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-semibold rounded-lg py-2">Cancelar</button>
          <button id="btn-confirmar-aprobar-cupo" class="flex-1 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-lg py-2">Aprobar</button>
        </div>
      </div>`;
    modal.classList.remove('hidden');

    const inputCantidad = document.getElementById('input-cantidad-aprobar');
    inputCantidad.focus();
    inputCantidad.select();

    document.getElementById('btn-cancelar-aprobar-cupo').addEventListener('click', () => cerrar(null));
    modal.addEventListener('click', (e) => { if (e.target === modal) cerrar(null); });
    document.getElementById('btn-confirmar-aprobar-cupo').addEventListener('click', () => {
      const cantidad = parseInt(inputCantidad.value, 10);
      if (!Number.isFinite(cantidad) || cantidad < 1) {
        inputCantidad.focus();
        return;
      }
      const observacion = document.getElementById('input-observacion-aprobar').value.trim();
      cerrar({ cantidad, observacion });
    });
  });
}

// --- v10.14 (pedido explícito del usuario, item 14): "Ver tiempos" ---------
// Antes vivía solo en dashboard-admin-general.js (JAO); se mueve acá para
// que Admin de Contrato y Subgerente también puedan usarlo -- cada
// dashboard que lo use necesita el mismo modal #modal-tiempos en su HTML
// (ver frontend/dashboards/admin_general.html como referencia).
async function abrirDetalleTiempos(id) {
  const modal = document.getElementById('modal-tiempos');
  const cont = document.getElementById('contenido-tiempos');
  cont.innerHTML = spinnerHtml();
  modal.classList.remove('hidden');
  try {
    const data = await apiFetch(`/admin_general/detalle_tiempos.php?postulacion_id=${id}`);
    cont.innerHTML = renderTiemposHtml(data);
  } catch (err) {
    cont.innerHTML = `<p class="text-sm text-red-600 py-8 text-center">${err.message}</p>`;
  }
}

function cerrarDetalleTiempos() {
  document.getElementById('modal-tiempos').classList.add('hidden');
}

function fechaHoraLegible(fechaHoraSql) {
  // "AAAA-MM-DD HH:MM:SS" -> "28-08-2026, 17:04:30" (con segundos, tal
  // como lo pidió el JAO -- toLocaleString por defecto los omite).
  const d = new Date(fechaHoraSql.replace(' ', 'T'));
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const yyyy = d.getFullYear();
  const hh = String(d.getHours()).padStart(2, '0');
  const mi = String(d.getMinutes()).padStart(2, '0');
  const ss = String(d.getSeconds()).padStart(2, '0');
  return `${dd}-${mm}-${yyyy}, ${hh}:${mi}:${ss}`;
}

function renderTiemposHtml(data) {
  const filas = data.hitos.map((h, idx) => `
    <div class="flex gap-3 ${idx > 0 ? 'mt-1' : ''}">
      <div class="flex flex-col items-center">
        <span class="w-2.5 h-2.5 rounded-full ${idx === data.hitos.length - 1 && !data.proceso_en_curso ? 'bg-green-500' : 'bg-blue-500'} shrink-0 mt-1"></span>
        ${idx < data.hitos.length - 1 ? '<span class="w-px flex-1 bg-gray-200 my-0.5"></span>' : ''}
      </div>
      <div class="pb-4 flex-1">
        <p class="text-sm font-semibold text-gray-900">${h.etiqueta}</p>
        <p class="text-xs text-gray-500 font-mono">${fechaHoraLegible(h.fecha_hora)}</p>
        ${h.quien ? `<p class="text-xs text-gray-400">por ${h.quien}</p>` : ''}
        ${h.duracion_desde_anterior ? `<p class="text-xs text-blue-600 font-medium mt-0.5">⏱ +${h.duracion_desde_anterior} desde el paso anterior</p>` : ''}
      </div>
    </div>`).join('');

  return `
    <div class="mb-5">
      <p class="text-xs uppercase tracking-wide text-gray-400 font-semibold mb-1">Detalle de tiempos</p>
      <h3 class="text-lg font-bold text-gray-900">${data.nombre_completo}</h3>
      <p class="text-sm text-gray-500 font-mono">${data.rut} · ${data.cargo}</p>
    </div>

    <div class="rounded-xl px-4 py-3 mb-5 text-sm font-semibold text-center ${data.proceso_en_curso ? 'bg-amber-50 text-amber-800 border border-amber-200' : 'bg-green-50 text-green-700 border border-green-200'}">
      ${data.duracion_total_desde_terreno
        ? (data.proceso_en_curso
            ? `⏱ Lleva ${data.duracion_total_desde_terreno} desde que Terreno pre-aprobó (aún en curso)`
            : `⏱ Tardó ${data.duracion_total_desde_terreno} desde que Terreno pre-aprobó hasta ${data.estado === 'Contratado' ? 'ser contratado' : 'este punto'}`)
        : 'Todavía no hay suficientes hitos para calcular una duración total.'}
    </div>

    <div>${filas}</div>`;
}

function mostrarAlerta(contenedorId, mensaje, tipo = 'error') {
  const el = document.getElementById(contenedorId);
  if (!el) return;
  const clases = tipo === 'error'
    ? 'bg-red-50 text-red-700 border-red-200'
    : 'bg-green-50 text-green-700 border-green-200';
  el.innerHTML = `<div class="border rounded-lg px-4 py-3 text-sm ${clases}">${mensaje}</div>`;
  setTimeout(() => { el.innerHTML = ''; }, 6000);
}
