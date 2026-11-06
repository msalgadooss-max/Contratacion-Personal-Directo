/**
 * v5 - Panel de Desarrollador: entrar a cualquier rol sin contraseña,
 * más QR/correo del link de postulación.
 */
const RUTA_POR_ROL = {
  Jefe_Terreno: 'terreno.html',
  Capataz: 'capataz.html',
  Admin_Contrato: 'admin_contrato.html',
  Prevencionista: 'prevencion.html',
  Jefe_Bodega: 'bodega.html',
  Jefe_Administrativo: 'admin_general.html',
  Gerencia: 'gerencia.html',
  Porteria: 'porteria.html',
};

const ETIQUETAS_ROL = {
  Jefe_Terreno: 'Jefe de Terreno',
  Capataz: 'Capataz',
  Admin_Contrato: 'Admin. de Contrato',
  Jefe_Administrativo: 'Jefe Administrativo (JAO)',
  Prevencionista: 'Prevencionista',
  Jefe_Bodega: 'Jefe de Bodega',
  Porteria: 'Portería',
  // v10.12 (pedido explicito del usuario): el rol interno sigue siendo
  // 'Gerencia' (columna rol en la BD, requireRol(['Gerencia']) en cada
  // endpoint) -- esto solo cambia la ETIQUETA que ve la gente.
  Gerencia: 'Subgerente',
};

(async () => {
  const usuario = await protegerDashboard('Desarrollador');
  if (!usuario) return;
  await cargarUsuarios();
  cargarUsuariosClaves();
  inicializarAdminUsuarios();
  inicializarCorreosFallidos();
  cargarTiempos();
  document.getElementById('tiempos-dias').addEventListener('change', cargarTiempos);
  configurarTabs();
  mostrarQr();
  mostrarQrEncuesta();
})();

// v10.14 (pedido explícito del usuario): QR de la encuesta del piloto,
// reutilizando el mismo generador de imagen que ya arma el QR de
// portería (qr_imagen.php) -- no hace falta la librería QRCode.js para
// este, es una imagen normal.
function mostrarQrEncuesta() {
  const urlEncuesta = `${window.location.origin}/frontend/public/encuesta.html`;
  document.getElementById('url-encuesta').textContent = urlEncuesta;
  document.getElementById('qr-encuesta').src = `${API_BASE_URL}/public/qr_imagen.php?u=${encodeURIComponent(urlEncuesta)}`;
}

async function descargarEncuestaExcel() {
  try {
    const res = await apiFetch('/dev/encuesta_exportar_excel.php');
    const blob = await res.blob();
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'encuesta_piloto.xlsx';
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);
  } catch (err) {
    mostrarAlerta('alerta', err.message || 'Todavía no hay respuestas para descargar.');
  }
}

let USUARIOS_INTERNOS = [];

async function cargarUsuarios() {
  const cont = document.getElementById('lista-usuarios');
  try {
    const data = await apiFetch('/dev/listar_usuarios.php');
    USUARIOS_INTERNOS = data.usuarios;
    cont.innerHTML = data.usuarios.map(u => `
      <button onclick="entrarComo(${u.id})"
              class="flex items-center justify-between border border-gray-200 rounded-lg px-4 py-3 text-left hover:border-blue-400 hover:bg-blue-50 transition">
        <span>
          <span class="block text-sm font-semibold text-gray-900">${ETIQUETAS_ROL[u.rol] || u.rol}</span>
          <span class="block text-xs text-gray-500">${esc(u.nombre)} · ${esc(u.correo)}</span>
        </span>
        <span class="text-blue-600 text-xs font-semibold">Entrar →</span>
      </button>`).join('');

    const selectRol = document.getElementById('rol-qr');
    selectRol.innerHTML = data.usuarios.map(u =>
      `<option value="${u.id}">${ETIQUETAS_ROL[u.rol] || u.rol} (${esc(u.nombre)})</option>`).join('');
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

async function entrarComo(usuarioId) {
  try {
    const data = await apiFetch('/dev/entrar_como.php', { method: 'POST', body: { usuario_id: usuarioId } });
    const ruta = RUTA_POR_ROL[data.usuario.rol];
    if (!ruta) {
      mostrarAlerta('alerta', 'No hay dashboard configurado para ese rol.');
      return;
    }
    window.location.href = ruta;
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

function configurarTabs() {
  document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.tab-btn').forEach(b => {
        const activo = b === btn;
        b.classList.toggle('border-blue-600', activo);
        b.classList.toggle('text-blue-600', activo);
        b.classList.toggle('border-transparent', !activo);
        b.classList.toggle('text-gray-500', !activo);
      });
      document.querySelectorAll('.tab-panel').forEach(panel => {
        panel.classList.toggle('hidden', panel.id !== `panel-${btn.dataset.tab}`);
      });
    });
  });
}

function mostrarQr() {
  const url = `${window.location.origin}/frontend/public/index.html`;
  document.getElementById('url-postulacion').textContent = url;
  // eslint-disable-next-line no-undef
  new QRCode(document.getElementById('qr'), { text: url, width: 200, height: 200 });
}

// --- v6.4: QR de acceso directo por rol (sin correo ni clave) --------------
document.getElementById('btn-generar-qr-rol').addEventListener('click', async () => {
  const usuarioId = Number(document.getElementById('rol-qr').value);
  if (!usuarioId) return;
  try {
    const data = await apiFetch('/dev/generar_qr_acceso.php', { method: 'POST', body: { usuario_id: usuarioId } });
    document.getElementById('resultado-qr-rol').classList.remove('hidden');
    document.getElementById('titulo-qr-rol').textContent = `${ETIQUETAS_ROL[data.rol] || data.rol} · ${esc(data.nombre)}`;
    document.getElementById('url-qr-rol').textContent = data.url;
    document.getElementById('qr-rol').innerHTML = '';
    // eslint-disable-next-line no-undef
    new QRCode(document.getElementById('qr-rol'), { text: data.url, width: 200, height: 200 });
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
});

// --- v10.31: correos que no pudieron salir -----------------------------------
function inicializarCorreosFallidos() {
  document.getElementById('correos-reintentar-todos').addEventListener('click', () => accionCorreo('reintentar_todos'));
  cargarCorreosFallidos();
  setInterval(cargarCorreosFallidos, 60000);
}

async function cargarCorreosFallidos() {
  try {
    const data = await apiFetch('/dev/correos_fallidos_listar.php');
    const seccion = document.getElementById('seccion-correos');
    seccion.classList.toggle('hidden', !data.correos.length);
    document.getElementById('correos-contador').textContent = data.correos.length;
    document.getElementById('correos-tabla').innerHTML = data.correos.map(c => `
      <tr class="border-b border-gray-100 align-top">
        <td class="py-2 px-3 text-gray-800">${esc(c.destinatario)}${c.nombre ? `<span class="block text-[11px] text-gray-500">${esc(c.nombre)}</span>` : ''}</td>
        <td class="py-2 px-3 text-gray-700">${esc(c.asunto)}<span class="block text-[11px] text-gray-400">${esc(new Date(c.creado_at.replace(' ', 'T')).toLocaleString('es-CL'))}</span></td>
        <td class="py-2 px-3 text-xs text-red-700 max-w-xs break-words">${esc(c.error || '—')}</td>
        <td class="py-2 px-3 text-right text-gray-600">${c.intentos}</td>
        <td class="py-2 px-3 text-right whitespace-nowrap">
          <button onclick="accionCorreo('reintentar', ${c.id})" class="text-xs font-semibold text-blue-700 hover:underline mr-3">Reintentar</button>
          <button onclick="accionCorreo('descartar', ${c.id})" class="text-xs font-semibold text-gray-500 hover:underline">Descartar</button>
        </td>
      </tr>`).join('');
  } catch (err) { /* sin aviso: no es crítico para el resto del panel */ }
}

async function accionCorreo(accion, id) {
  if (accion === 'descartar' && !confirm('¿Descartar este correo sin enviarlo?')) return;
  try {
    const data = await apiFetch('/dev/correos_fallidos_accion.php', { method: 'POST', body: { accion, id: id || 0 } });
    mostrarAlerta('alerta', data.mensaje, 'exito');
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
  cargarCorreosFallidos();
}

// --- v10.30: administración de usuarios (crear, editar, desactivar) ----------
const ROLES_ADMINISTRABLES = ['Jefe_Terreno', 'Capataz', 'Admin_Contrato', 'Jefe_Administrativo', 'Prevencionista', 'Jefe_Bodega', 'Porteria', 'Gerencia'];
let USUARIOS_ADMIN = [];

function inicializarAdminUsuarios() {
  document.getElementById('usr-rol').innerHTML = ROLES_ADMINISTRABLES
    .map(r => `<option value="${r}">${ETIQUETAS_ROL[r] || r}</option>`).join('');
  document.getElementById('usr-generar').addEventListener('click', () => {
    document.getElementById('usr-clave').value = generarClaveAleatoria();
  });
  document.getElementById('usr-cancelar').addEventListener('click', salirEdicionUsuario);
  document.getElementById('form-usuario').addEventListener('submit', guardarUsuario);
  cargarUsuariosAdmin();
}

async function cargarUsuariosAdmin() {
  try {
    const data = await apiFetch('/dev/usuarios_listar.php');
    USUARIOS_ADMIN = data.usuarios;
    document.getElementById('usr-tabla').innerHTML = data.usuarios.map(u => {
      const esDev = u.rol === 'Desarrollador';
      const acciones = esDev
        ? '<span class="text-xs text-gray-400">protegido</span>'
        : `<button onclick="editarUsuario(${u.id})" class="text-xs font-semibold text-blue-700 hover:underline mr-3">Editar</button>
           <button onclick="cambiarEstadoUsuario(${u.id}, ${u.activo ? 'false' : 'true'})" class="text-xs font-semibold ${u.activo ? 'text-red-600' : 'text-green-700'} hover:underline">${u.activo ? 'Desactivar' : 'Reactivar'}</button>`;
      return `
        <tr class="border-b border-gray-100 ${u.activo ? '' : 'opacity-60'}">
          <td class="py-2 pr-3 font-medium text-gray-800">${esc(u.nombre)}</td>
          <td class="py-2 pr-3 text-gray-600">${esc(u.correo)}</td>
          <td class="py-2 pr-3 text-gray-600">${esc(ETIQUETAS_ROL[u.rol] || u.rol)}${u.jefe_terreno_nombre ? `<span class="block text-[11px] text-gray-400">a cargo de ${esc(u.jefe_terreno_nombre)}</span>` : ''}</td>
          <td class="py-2 pr-3">${u.activo
            ? '<span class="text-[11px] font-semibold bg-green-50 text-green-700 border border-green-200 rounded-full px-2 py-0.5">Activo</span>'
            : '<span class="text-[11px] font-semibold bg-gray-100 text-gray-500 border border-gray-200 rounded-full px-2 py-0.5">Desactivado</span>'}</td>
          <td class="py-2 text-right whitespace-nowrap">${acciones}</td>
        </tr>`;
    }).join('');
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

function salirEdicionUsuario() {
  document.getElementById('form-usuario').reset();
  document.getElementById('usr-id').value = '';
  document.getElementById('usr-clave-wrap').classList.remove('hidden');
  document.getElementById('usr-clave').required = true;
  document.getElementById('usr-guardar').textContent = 'Crear usuario';
  document.getElementById('usr-cancelar').classList.add('hidden');
}

function editarUsuario(id) {
  const u = USUARIOS_ADMIN.find(x => x.id === id);
  if (!u) return;
  document.getElementById('usr-id').value = u.id;
  document.getElementById('usr-nombre').value = u.nombre;
  document.getElementById('usr-correo').value = u.correo;
  document.getElementById('usr-rol').value = u.rol;
  // En edición no se toca la clave (para eso está "Claves de los usuarios").
  document.getElementById('usr-clave-wrap').classList.add('hidden');
  document.getElementById('usr-clave').required = false;
  document.getElementById('usr-guardar').textContent = 'Guardar cambios';
  document.getElementById('usr-cancelar').classList.remove('hidden');
  document.getElementById('form-usuario').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

async function guardarUsuario(e) {
  e.preventDefault();
  const id = Number(document.getElementById('usr-id').value);
  const base = {
    nombre: document.getElementById('usr-nombre').value,
    correo: document.getElementById('usr-correo').value,
    rol: document.getElementById('usr-rol').value,
  };
  try {
    let data;
    if (id) {
      data = await apiFetch('/dev/usuario_actualizar.php', { method: 'POST', body: { usuario_id: id, ...base } });
    } else {
      const clave = document.getElementById('usr-clave').value;
      if (clave.length < 8) {
        mostrarAlerta('alerta', 'La clave inicial debe tener al menos 8 caracteres.');
        return;
      }
      data = await apiFetch('/dev/usuario_crear.php', { method: 'POST', body: { ...base, password: clave } });
    }
    mostrarAlerta('alerta', data.mensaje, 'exito');
    salirEdicionUsuario();
    recargarListasUsuarios();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

async function cambiarEstadoUsuario(id, activar) {
  const u = USUARIOS_ADMIN.find(x => x.id === id);
  if (!u) return;
  if (!activar && !confirm(`¿Desactivar a ${u.nombre}? Ya no podrá entrar. Si es Capataz, sus personas que esperan selección vuelven al banco común.`)) return;
  try {
    const data = await apiFetch('/dev/usuario_actualizar.php', { method: 'POST', body: { usuario_id: id, activo: activar } });
    mostrarAlerta('alerta', activar ? 'Usuario reactivado.' : data.mensaje, 'exito');
    recargarListasUsuarios();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

function recargarListasUsuarios() {
  cargarUsuariosAdmin();
  cargarUsuarios();        // "Entrar como..."
  cargarUsuariosClaves();  // selector de "Claves de los usuarios"
}

// --- v10.28: tiempos por rol y etapa -----------------------------------------
function formatearDuracion(segundos) {
  if (segundos === null || segundos === undefined) return '—';
  if (segundos < 60) return `${segundos} s`;
  const d = Math.floor(segundos / 86400);
  const h = Math.floor((segundos % 86400) / 3600);
  const m = Math.floor((segundos % 3600) / 60);
  if (d > 0) return `${d} d ${h} h`;
  if (h > 0) return `${h} h ${m} min`;
  return `${m} min`;
}

async function cargarTiempos() {
  const dias = document.getElementById('tiempos-dias').value;
  const resumen = document.getElementById('tiempos-resumen');
  try {
    const data = await apiFetch(`/dev/tiempos_resumen.php?dias=${encodeURIComponent(dias)}`);
    const total = data.proceso_completo;
    resumen.innerHTML = `${data.postulaciones_consideradas} postulaciones consideradas · Proceso completo (selección → retiro): ` +
      (total.n ? `<strong class="text-gray-800">${formatearDuracion(total.mediana)}</strong> de mediana (${total.n} casos)` : 'todavía sin casos terminados');

    document.getElementById('tiempos-etapas').innerHTML = `
      <table class="w-full text-sm">
        <thead><tr class="text-left text-xs text-gray-500 border-b border-gray-200">
          <th class="py-2 pr-3 font-semibold">Etapa</th><th class="py-2 pr-3 font-semibold">Rol</th>
          <th class="py-2 pr-3 font-semibold text-right">Mediana</th><th class="py-2 pr-3 font-semibold text-right">Promedio</th>
          <th class="py-2 pr-3 font-semibold text-right">Más rápido / más lento</th><th class="py-2 pr-3 font-semibold text-right">Casos</th>
          <th class="py-2 font-semibold text-right">Esperando ahora</th>
        </tr></thead>
        <tbody>${data.etapas.map(e => `
          <tr class="border-b border-gray-100 align-top">
            <td class="py-2 pr-3 font-medium text-gray-800">${esc(e.nombre)}</td>
            <td class="py-2 pr-3 text-gray-600">${esc(e.rol)}</td>
            <td class="py-2 pr-3 text-right font-semibold text-gray-900 whitespace-nowrap">${formatearDuracion(e.completadas.mediana)}</td>
            <td class="py-2 pr-3 text-right text-gray-600 whitespace-nowrap">${formatearDuracion(e.completadas.promedio)}</td>
            <td class="py-2 pr-3 text-right text-gray-500 whitespace-nowrap">${formatearDuracion(e.completadas.minimo)} / ${formatearDuracion(e.completadas.maximo)}</td>
            <td class="py-2 pr-3 text-right text-gray-600">${e.completadas.n}</td>
            <td class="py-2 text-right whitespace-nowrap">${e.en_espera.n
              ? `<span class="inline-block bg-amber-100 text-amber-800 text-xs font-semibold px-2 py-0.5 rounded-full">${e.en_espera.n} · la más antigua ${formatearDuracion(e.en_espera.mas_antigua)}</span>`
              : '<span class="text-gray-400">—</span>'}</td>
          </tr>`).join('')}
        </tbody>
      </table>`;

    const filasUsuarios = data.etapas.flatMap(e => e.por_usuario.map(u => ({ etapa: e.nombre, ...u })));
    document.getElementById('tiempos-usuarios').innerHTML = filasUsuarios.length ? `
      <h3 class="text-xs font-semibold text-gray-700 mb-2">Detalle por persona</h3>
      <div class="overflow-x-auto"><table class="w-full text-sm">
        <thead><tr class="text-left text-xs text-gray-500 border-b border-gray-200">
          <th class="py-2 pr-3 font-semibold">Persona</th><th class="py-2 pr-3 font-semibold">Etapa</th>
          <th class="py-2 pr-3 font-semibold text-right">Mediana</th><th class="py-2 pr-3 font-semibold text-right">Promedio</th>
          <th class="py-2 font-semibold text-right">Casos</th>
        </tr></thead>
        <tbody>${filasUsuarios.map(u => `
          <tr class="border-b border-gray-100">
            <td class="py-2 pr-3 text-gray-800">${esc(u.usuario)}</td>
            <td class="py-2 pr-3 text-gray-600">${esc(u.etapa)}</td>
            <td class="py-2 pr-3 text-right font-semibold text-gray-900 whitespace-nowrap">${formatearDuracion(u.mediana)}</td>
            <td class="py-2 pr-3 text-right text-gray-600 whitespace-nowrap">${formatearDuracion(u.promedio)}</td>
            <td class="py-2 text-right text-gray-600">${u.n}</td>
          </tr>`).join('')}
        </tbody></table></div>` : '';
  } catch (err) {
    resumen.textContent = '';
    mostrarAlerta('alerta', err.message);
  }
}

// --- v10.27: cambio de claves de usuarios internos ---------------------------
async function cargarUsuariosClaves() {
  const select = document.getElementById('clave-usuario');
  try {
    const data = await apiFetch('/dev/listar_usuarios.php?todos=1');
    select.innerHTML = data.usuarios.map(u =>
      `<option value="${u.id}">${ETIQUETAS_ROL[u.rol] || u.rol} (${esc(u.nombre)} · ${esc(u.correo)})</option>`).join('');
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

function generarClaveAleatoria() {
  // Sin caracteres confusos (0/O, 1/l/I) para poder dictarla por teléfono.
  const letras = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  const bytes = new Uint32Array(10);
  window.crypto.getRandomValues(bytes);
  return Array.from(bytes, b => letras[b % letras.length]).join('');
}

document.getElementById('btn-clave-generar').addEventListener('click', () => {
  document.getElementById('clave-nueva').value = generarClaveAleatoria();
});

document.getElementById('btn-clave-guardar').addEventListener('click', async () => {
  const usuarioId = Number(document.getElementById('clave-usuario').value);
  const clave = document.getElementById('clave-nueva').value;
  if (!usuarioId) return;
  if (clave.length < 8) {
    mostrarAlerta('alerta', 'La clave nueva debe tener al menos 8 caracteres.');
    return;
  }
  try {
    const data = await apiFetch('/dev/cambiar_password.php', {
      method: 'POST',
      body: { usuario_id: usuarioId, password_nueva: clave },
    });
    mostrarAlerta('alerta', data.mensaje, 'exito');
    document.getElementById('clave-nueva').value = '';
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
});

document.getElementById('form-enviar-link').addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    const data = await apiFetch('/dev/enviar_link.php', {
      method: 'POST',
      body: {
        nombre: document.getElementById('link-nombre').value,
        correo: document.getElementById('link-correo').value,
      },
    });
    mostrarAlerta('alerta', data.mensaje, 'exito');
    document.getElementById('form-enviar-link').reset();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
});
