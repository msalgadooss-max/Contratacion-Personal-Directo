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
  cargarEstadoSistema();
  inicializarDatosPrueba();
  cargarTiempos();
  document.getElementById('tiempos-dias').addEventListener('change', cargarTiempos);
  document.getElementById('tiempos-origen').addEventListener('change', cargarTiempos);
  configurarTabs();
  mostrarQr();
  mostrarQrEncuesta();
})();

// v10.14 (pedido explícito del usuario): QR de la encuesta del piloto,
// reutilizando el mismo generador de imagen que ya arma el QR de
// portería (qr_imagen.php) -- no hace falta la librería QRCode.js para
// este, es una imagen normal.
function mostrarQrEncuesta() {
  const urlEncuesta = `${window.location.origin}${OBRA_PREFIJO}/frontend/public/encuesta.html`;
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
  const url = `${window.location.origin}${OBRA_PREFIJO}/frontend/public/index.html`;
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
  document.getElementById('usr-rol').addEventListener('change', actualizarCamposCapataz);
  document.getElementById('usr-rol').innerHTML = ROLES_ADMINISTRABLES
    .map(r => `<option value="${r}">${ETIQUETAS_ROL[r] || r}</option>`).join('');
  document.getElementById('usr-generar').addEventListener('click', () => {
    document.getElementById('usr-clave').value = generarClaveAleatoria();
  });
  document.getElementById('usr-cancelar').addEventListener('click', salirEdicionUsuario);
  document.getElementById('form-usuario').addEventListener('submit', guardarUsuario);
  cargarUsuariosAdmin();
  cargarOrganigrama();
}

async function cargarUsuariosAdmin() {
  try {
    const data = await apiFetch('/dev/usuarios_listar.php');
    USUARIOS_ADMIN = data.usuarios;
    poblarSelectoresCapataz();
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
          <td class="py-2 pr-3 text-gray-600">${esc(ETIQUETAS_ROL[u.rol] || u.rol)}${u.jefe_terreno_nombre ? `<span class="block text-[11px] text-gray-400">a cargo de ${esc(u.jefe_terreno_nombre)}${u.frente ? ' · ' + esc(u.frente) : ''}</span>` : (u.frente ? `<span class="block text-[11px] text-gray-400">${esc(u.frente)}</span>` : '')}</td>
          <td class="py-2 pr-3">${u.activo
            ? '<span class="text-[11px] font-semibold bg-green-50 text-green-700 border border-green-200 rounded-full px-2 py-0.5">Activo</span>'
            + (u.activo && u.clave_temporal ? ' <span class="text-[11px] font-semibold bg-amber-50 text-amber-800 border border-amber-200 rounded-full px-2 py-0.5" title="Todavía no cambió su clave temporal">Clave temporal</span>' : '')
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
  actualizarCamposCapataz();
}

function editarUsuario(id) {
  const u = USUARIOS_ADMIN.find(x => x.id === id);
  if (!u) return;
  document.getElementById('usr-id').value = u.id;
  document.getElementById('usr-nombre').value = u.nombre;
  document.getElementById('usr-correo').value = u.correo;
  document.getElementById('usr-rol').value = u.rol;
  document.getElementById('usr-jefe').value = u.jefe_terreno_id ? String(u.jefe_terreno_id) : '';
  document.getElementById('usr-frente').value = u.frente || '';
  actualizarCamposCapataz();
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
  if (base.rol === 'Capataz') {
    base.frente = document.getElementById('usr-frente').value.trim();
    base.jefe_terreno_id = document.getElementById('usr-jefe').value ? Number(document.getElementById('usr-jefe').value) : null;
  }
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
  cargarOrganigrama();
}

// --- v10.39: organigrama de terreno y frente de cada Capataz -------------------
function actualizarCamposCapataz() {
  document.getElementById('usr-capataz-wrap').classList.toggle('hidden', document.getElementById('usr-rol').value !== 'Capataz');
}

function poblarSelectoresCapataz() {
  const sel = document.getElementById('usr-jefe');
  const actual = sel.value;
  sel.innerHTML = '<option value="">Sin asignar</option>' + USUARIOS_ADMIN.filter(u => u.rol === 'Jefe_Terreno' && u.activo)
    .map(u => `<option value="${u.id}">${esc(u.nombre)}</option>`).join('');
  sel.value = actual;
  const frentes = [...new Set(USUARIOS_ADMIN.map(u => u.frente).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'es', { numeric: true }));
  document.getElementById('usr-frentes-lista').innerHTML = frentes.map(f => `<option value="${esc(f)}">`).join('');
}

function tarjetaFrente(g) {
  const sinFrente = !g.frente;
  const chips = g.capataces.map(c => `
    <li class="flex items-center justify-between gap-2 bg-white border border-gray-200 rounded-lg px-2.5 py-1.5" title="${esc(c.correo)}">
      <span class="text-[13px] text-gray-800">${esc(c.nombre)}</span>
      ${c.esperando ? `<span class="text-[10px] font-semibold bg-amber-100 text-amber-800 rounded-full px-1.5 py-0.5 shrink-0">${c.esperando} esperando</span>` : ''}
    </li>`).join('');
  return `
    <div class="org-rama">
      <div class="rounded-xl border ${sinFrente ? 'border-dashed border-gray-300 bg-gray-50' : 'border-orange-200 bg-orange-50/60'} p-3">
        <p class="text-xs font-bold ${sinFrente ? 'text-gray-500' : 'text-orange-800'} mb-2">${sinFrente ? 'Sin frente asignado' : esc(g.frente)}
          <span class="ml-1 font-semibold text-gray-400">· ${g.capataces.length} ${g.capataces.length === 1 ? 'capataz' : 'capataces'}</span></p>
        <ul class="grid grid-cols-1 lg:grid-cols-2 gap-1.5">${chips}</ul>
      </div>
    </div>`;
}

async function cargarOrganigrama() {
  const cont = document.getElementById('organigrama');
  try {
    const data = await apiFetch('/dev/organigrama.php');
    if (!data.profesionales.length && !data.total_sin_profesional) {
      cont.innerHTML = '<p class="text-sm text-gray-400">Todavía no hay Profesionales de Terreno ni Capataces.</p>';
      return;
    }
    const pts = data.profesionales.map(pt => `
      <div class="org-pt flex items-center gap-0">
        <div class="shrink-0 w-full sm:w-56 bg-gray-900 text-white rounded-xl px-4 py-3">
          <p class="text-[10px] uppercase tracking-wide text-orange-300 font-semibold">Profesional de Terreno</p>
          <p class="font-bold text-sm leading-snug mt-0.5">${esc(pt.nombre)}</p>
          <p class="text-[11px] text-gray-400 mt-1">${pt.total_capataces} ${pt.total_capataces === 1 ? 'capataz' : 'capataces'} · ${pt.frentes.filter(f => f.frente).length} frentes</p>
        </div>
        <div class="org-ramas">${pt.frentes.length ? pt.frentes.map(tarjetaFrente).join('') : '<p class="text-xs text-gray-400 pl-2">Sin capataces asignados.</p>'}</div>
      </div>`).join('');
    const sin = data.total_sin_profesional ? `
      <div class="org-pt flex items-center gap-0">
        <div class="shrink-0 w-full sm:w-56 border-2 border-dashed border-gray-300 rounded-xl px-4 py-3">
          <p class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold">Sin Profesional de Terreno</p>
          <p class="text-[11px] text-gray-400 mt-1">${data.total_sin_profesional} capataces por asignar</p>
        </div>
        <div class="org-ramas">${data.sin_profesional.map(tarjetaFrente).join('')}</div>
      </div>` : '';
    cont.innerHTML = pts + sin;
  } catch (err) {
    cont.innerHTML = `<p class="text-sm text-red-600">${esc(err.message)}</p>`;
  }
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
    const origen = document.getElementById('tiempos-origen').value;
    const data = await apiFetch(`/dev/tiempos_resumen.php?dias=${encodeURIComponent(dias)}&origen=${encodeURIComponent(origen)}`);
    const total = data.proceso_completo;
    resumen.innerHTML = `${data.postulaciones_consideradas} postulaciones consideradas · Proceso completo (selección → retiro): ` +
      (total.n ? `<strong class="text-gray-800">${formatearDuracion(total.mediana)}</strong> de tiempo típico (${total.n} casos)` : 'todavía sin casos terminados');

    document.getElementById('tiempos-etapas').innerHTML = `
      <table class="w-full text-sm">
        <thead><tr class="text-left text-xs text-gray-500 border-b border-gray-200">
          <th class="py-2 pr-3 font-semibold">Etapa</th><th class="py-2 pr-3 font-semibold">Rol</th>
          <th class="py-2 pr-3 font-semibold text-right">Tiempo típico</th><th class="py-2 pr-3 font-semibold text-right">Promedio</th>
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
          <th class="py-2 pr-3 font-semibold text-right">Tiempo típico</th><th class="py-2 pr-3 font-semibold text-right">Promedio</th>
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

// --- v10.37: regenerar el enlace de Etapa 2 (sin usar el Web Shell) ------------
async function generarEnlaceEtapa2(enviarCorreo) {
  try {
    const data = await apiFetch('/terreno/enlace_etapa2.php', {
      method: 'POST',
      body: { rut: document.getElementById('etapa2-rut').value, enviar_correo: enviarCorreo },
    });
    mostrarEnlaceEtapa2(data);
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}
document.getElementById('form-enlace-etapa2').addEventListener('submit', (e) => { e.preventDefault(); generarEnlaceEtapa2(false); });
document.getElementById('btn-etapa2-correo').addEventListener('click', () => generarEnlaceEtapa2(true));

// --- v10.33: aviso de "modo prueba" de correo --------------------------------
async function cargarEstadoSistema() {
  try {
    const e = await apiFetch('/dev/estado_sistema.php');
    const b = document.getElementById('banner-correo-prueba');
    if (e.correo_redirigiendo) {
      b.textContent = `⚠ MODO PRUEBA DE CORREO ACTIVO: todos los correos del sistema (de todas las obras) se están enviando a ${e.correo_destino}, NO a las personas reales. Si hay postulantes reales, apágalo en Render (variable EMAIL_MODO_PRUEBA).`;
      b.classList.remove('hidden');
    } else if (e.correo_modo_sin_destino) {
      b.textContent = '⚠ EMAIL_MODO_PRUEBA está en 1 pero sin EMAIL_PRUEBA_DESTINO: se ignora y los correos salen a las personas reales. Si querías probar, escribe tu correo en EMAIL_PRUEBA_DESTINO; si no, pon EMAIL_MODO_PRUEBA en 0.';
      b.classList.remove('hidden');
    } else {
      b.classList.add('hidden');
    }
    document.getElementById('dp-obra').textContent = e.obra_nombre;
  } catch (err) { /* sin aviso: no es crítico para el resto del panel */ }
}

// --- v10.33: borrar datos de prueba (buscar -> revisar -> confirmar) ---------
let DP_RESULTADO = null;

function inicializarDatosPrueba() {
  document.getElementById('dp-buscar').addEventListener('click', buscarDatosPrueba);
}

async function buscarDatosPrueba() {
  const cont = document.getElementById('dp-resultado');
  DP_RESULTADO = null;
  cont.innerHTML = '<p class="text-sm text-gray-500">Buscando...</p>';
  try {
    const ruts = document.getElementById('dp-ruts').value;
    const data = await apiFetch('/dev/datos_prueba_buscar.php', { method: 'POST', body: { ruts } });
    DP_RESULTADO = { ruts, ids: data.postulaciones.map(p => p.id) };
    const filas = data.postulaciones.map(p => `
      <tr class="border-b border-gray-100">
        <td class="py-2 px-3 font-mono text-xs">${esc(p.rut)}</td>
        <td class="py-2 px-3">${esc(p.nombre_completo)}<span class="block text-[11px] text-gray-500">${esc(p.correo)}</span></td>
        <td class="py-2 px-3 text-xs">${esc(p.estado)}</td>
        <td class="py-2 px-3 text-xs">${p.ocupa_cupo == 1 ? esc(p.nombre_cargo) + ' <span class="text-gray-400">(cupo reservado)</span>' : '<span class="text-gray-400">sin cargo aún</span>'}</td>
      </tr>`).join('');
    const faltan = data.no_encontrados.length
      ? `<p class="text-xs text-gray-500 mt-2">No encontrados en esta obra: ${esc(data.no_encontrados.join(', '))}</p>` : '';
    if (!data.postulaciones.length) {
      cont.innerHTML = `<p class="text-sm text-gray-700">No hay postulaciones con esos RUT en <strong>${esc(data.obra)}</strong>. No hay nada que borrar.</p>${faltan}`;
      return;
    }
    cont.innerHTML = `
      <p class="text-sm text-gray-800 mb-2">Se borrarían <strong>${data.postulaciones.length}</strong> postulaciones de <strong>${esc(data.obra)}</strong> y se devolverían <strong>${data.cupos_a_devolver}</strong> cupos:</p>
      <div class="overflow-x-auto border border-gray-200 rounded-lg">
        <table class="w-full text-sm"><thead class="text-left text-xs text-gray-500 border-b border-gray-200">
          <tr><th class="py-2 px-3 font-semibold">RUT</th><th class="py-2 px-3 font-semibold">Persona</th><th class="py-2 px-3 font-semibold">Estado</th><th class="py-2 px-3 font-semibold">Cargo</th></tr>
        </thead><tbody>${filas}</tbody></table>
      </div>
      ${faltan}
      <div class="mt-4 flex flex-wrap items-center gap-3">
        <input id="dp-confirmar" type="text" placeholder="Escribe BORRAR" autocomplete="off" class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-40">
        <button id="dp-borrar" disabled class="bg-red-600 hover:bg-red-700 disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm font-semibold px-4 py-2 rounded-lg">Borrar definitivamente</button>
      </div>`;
    const inp = document.getElementById('dp-confirmar');
    const btn = document.getElementById('dp-borrar');
    inp.addEventListener('input', () => { btn.disabled = inp.value.trim() !== 'BORRAR'; });
    btn.addEventListener('click', borrarDatosPrueba);
  } catch (err) {
    cont.innerHTML = '';
    mostrarAlerta('alerta', err.message);
  }
}

async function borrarDatosPrueba() {
  if (!DP_RESULTADO) return;
  const btn = document.getElementById('dp-borrar');
  btn.disabled = true;
  btn.textContent = 'Borrando...';
  try {
    const data = await apiFetch('/dev/datos_prueba_borrar.php', {
      method: 'POST',
      body: { ruts: DP_RESULTADO.ruts, ids: DP_RESULTADO.ids, confirmacion: document.getElementById('dp-confirmar').value.trim() },
    });
    mostrarAlerta('alerta', data.mensaje, 'exito');
    document.getElementById('dp-resultado').innerHTML = '';
    DP_RESULTADO = null;
  } catch (err) {
    mostrarAlerta('alerta', err.message);
    btn.textContent = 'Borrar definitivamente';
    btn.disabled = false;
  }
}
