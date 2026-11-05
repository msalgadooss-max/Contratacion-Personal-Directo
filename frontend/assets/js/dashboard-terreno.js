/**
 * v10.14 (pedido explícito del usuario, items 5, 6 y 8 de la lista
 * post-prueba): "el Jefe de Terreno no debe aprobar, el que
 * selecciona es el Capataz... solo solicitará cupos al administrador".
 * Se retira por completo la acción de aprobar/rechazar de este panel
 * -- Jefe de Terreno pasa a ser de solo lectura (ve quién ya
 * seleccionó el Capataz y quién sigue esperando) + solicitar cupos.
 * También se retira la pestaña "Recepción" -- ese cierre ahora es un
 * botón dentro de la misma fila en "Personal Contratado".
 */
(async () => {
  const usuario = await protegerDashboard('Jefe_Terreno');
  if (!usuario) return;
  USUARIO_ACTUAL = usuario;
  configurarTabs();
  configurarArrastreEquipo();
  inicializarFiltros('en_proceso');
  await cargarHistorico('en_proceso');
  await cargarBanco();
  iniciarEstadoVivo();
})();

function actualizarTodo() {
  cargarHistorico(TAB_ACTIVA === 'contratados' ? 'contratados' : 'en_proceso');
  cargarBanco();
  cargarEstadoVivo();
  mostrarAlerta('alerta', 'Actualizado.', 'exito');
}

// --- v3.2: pestañas -------------------------------------------------------
let TAB_ACTIVA = 'en_proceso';
let CONTRATADOS_CARGADO = false;
let USUARIO_ACTUAL = null;
let INVITACION_LISTA = false;

function configurarTabs() {
  document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => cambiarTab(btn.dataset.tab));
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
  if (tab === 'en_proceso') {
    cargarHistorico('en_proceso');
  }
  if (tab === 'banco') {
    cargarBanco();
  }
  if (tab === 'contratados' && !CONTRATADOS_CARGADO) {
    CONTRATADOS_CARGADO = true;
    inicializarFiltros('contratados');
    cargarHistorico('contratados');
  }
  if (tab === 'cupos') {
    cargarCupos();
    cargarMisSolicitudes();
  }
  if (tab === 'equipo') {
    cargarEquipo();
  }
  if (tab === 'invitar' && !INVITACION_LISTA) {
    INVITACION_LISTA = true;
    iniciarInvitacion('contenedor-invitar', USUARIO_ACTUAL);
  }
}

// --- v10.14: "Banco de Postulantes" -- ahora de solo lectura, muestra a
// todos los recién llegados por el QR que el Capataz aún no selecciona.
// Reutiliza terreno/listar.php (la misma data que ve el Capataz para
// arrastrar), sin ningún botón de acción.
async function cargarBanco() {
  const tbody = document.getElementById('tbody-banco');
  const vacio = document.getElementById('banco-vacio');
  try {
    const data = await apiFetch('/terreno/listar.php');
    if (!data.postulaciones.length) {
      tbody.innerHTML = '';
      vacio.classList.remove('hidden');
      return;
    }
    vacio.classList.add('hidden');
    // v10.28: lista de Capataces para poder reasignar a un referido (si
    // este pedido falla, la tabla igual se muestra, solo sin reasignación).
    let capataces = [];
    try {
      const eq = await apiFetch('/terreno/equipo_listar.php');
      capataces = [...eq.mis_capataces, ...eq.disponibles, ...eq.de_otro_jefe];
    } catch (e) { /* sin reasignación */ }
    tbody.innerHTML = data.postulaciones.map(p => `
      <tr class="border-t">
        <td class="px-4 py-3 font-mono">${celdaDocumento(p)}</td>
        <td class="px-4 py-3">${esc(p.nombre_completo)}${p.es_referido
          ? `<span class="block text-[11px] font-semibold text-green-700">🤝 Referido por ${esc(p.referido_por_nombre || 'un compañero')}</span>` : ''}</td>
        <td class="px-4 py-3">${esc(p.comuna)}</td>
        <td class="px-4 py-3">${esc(p.telefono)}</td>
        <td class="px-4 py-3 text-gray-500">${new Date(p.creado_at).toLocaleString('es-CL')}</td>
        <td class="px-4 py-3">${p.tiene_cv
          ? `<a href="${API_BASE_URL}/terreno/ver_cv.php?postulacion_id=${p.id}" target="_blank" class="text-blue-600 font-medium underline">Ver CV</a>`
          : (p.experiencia_sin_cv
              ? `<span class="text-xs text-amber-700 bg-amber-50 px-2 py-1 rounded cursor-help" title="${esc(p.experiencia_sin_cv)}">Sin CV (ver experiencia) ⓘ</span>`
              : '<span class="text-gray-400 text-xs">Sin CV</span>')}</td>
        <td class="px-4 py-3">${capataces.length
          ? `<select class="border border-gray-300 rounded-lg px-2 py-1 text-xs max-w-[11rem]" onchange="reasignarReferido(${p.id}, this.value)">
              <option value="0" ${p.capataz_esperado_id ? '' : 'selected'}>Banco común (cualquier Capataz)</option>
              ${capataces.map(c => `<option value="${c.id}" ${Number(p.capataz_esperado_id) === c.id ? 'selected' : ''}>${esc(c.nombre)}</option>`).join('')}
            </select>`
          : esc(p.capataz_esperado_nombre || 'Banco común')}</td>
      </tr>`).join('');
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

async function reasignarReferido(postulacionId, capatazId) {
  try {
    const data = await apiFetch('/terreno/reasignar_referido.php', {
      method: 'POST',
      body: { postulacion_id: postulacionId, capataz_id: Number(capatazId) },
    });
    mostrarAlerta('alerta', data.mensaje, 'exito');
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
  cargarBanco();
}

// --- v10.28: "Mi equipo" (organigrama de Capataces) --------------------------
async function cargarEquipo() {
  try {
    const d = await apiFetch('/terreno/equipo_listar.php');
    renderEquipo(d);
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

function renderEquipo(d) {
  const hijos = d.mis_capataces.map(c => `
    <div class="org-hijo">
      <div class="w-44 bg-white border-2 border-gray-200 rounded-xl p-3 text-center cursor-grab select-none"
           draggable="true" data-capataz-id="${c.id}" data-origen="equipo">
        <p class="text-sm font-bold text-gray-900 truncate">${esc(c.nombre)}</p>
        <p class="text-[11px] text-gray-500 mb-2">Capataz</p>
        <div class="flex justify-center gap-1 flex-wrap mb-2">
          <span class="text-[11px] font-semibold bg-amber-50 text-amber-800 border border-amber-200 rounded-full px-2 py-0.5" title="Referidos esperando que él los seleccione">⏳ ${c.esperando_referidos}</span>
          <span class="text-[11px] font-semibold bg-green-50 text-green-700 border border-green-200 rounded-full px-2 py-0.5" title="Personas que seleccionó hoy">✓ ${c.seleccionados_hoy} hoy</span>
        </div>
        <button onclick="cambiarEquipo(${c.id}, 'quitar')" class="text-[11px] text-red-600 hover:underline">Quitar del equipo</button>
      </div>
    </div>`).join('');

  document.getElementById('equipo-organigrama').innerHTML = `
    <div id="equipo-zona-org" class="min-w-[260px] py-4 rounded-xl">
      <div class="w-56 mx-auto bg-gray-900 text-white rounded-xl px-4 py-3 text-center">
        <p class="text-sm font-bold truncate">${esc(d.jefe.nombre)}</p>
        <p class="text-[11px] text-gray-300">Jefe de Terreno</p>
      </div>
      ${d.mis_capataces.length
        ? `<div class="org-linea-vertical"></div>
           <div class="org-hijos ${d.mis_capataces.length === 1 ? 'org-uno' : ''}">${hijos}</div>`
        : '<p class="text-center text-sm text-gray-400 mt-4 px-4">Todavía no tienes Capataces en tu equipo. Agrégalos con la lista o arrastrándolos hasta aquí.</p>'}
    </div>`;

  const chips = (lista, arrastrable) => lista.length
    ? lista.map(c => `
        <div class="flex items-center gap-2 bg-gray-50 border border-gray-200 rounded-full pl-3 pr-1 py-1 text-sm ${arrastrable ? 'cursor-grab' : 'opacity-70'}"
             ${arrastrable ? `draggable="true" data-capataz-id="${c.id}" data-origen="disponible"` : ''}>
          <span class="truncate max-w-[10rem]">${esc(c.nombre)}</span>
          ${arrastrable
            ? `<button onclick="cambiarEquipo(${c.id}, 'agregar')" class="text-xs font-bold text-blue-700 bg-blue-50 hover:bg-blue-100 rounded-full w-6 h-6" title="Agregar a mi equipo">+</button>`
            : `<span class="text-[11px] text-gray-500 pr-2">de ${esc(c.jefe_terreno_nombre || 'otro jefe')}</span>`}
        </div>`).join('')
    : '<p class="text-sm text-gray-400">Ninguno.</p>';
  document.getElementById('equipo-disponibles').innerHTML = chips(d.disponibles, true);
  document.getElementById('equipo-otros').innerHTML = chips(d.de_otro_jefe, false);

  const select = document.getElementById('equipo-select');
  const boton = document.getElementById('equipo-agregar');
  if (d.disponibles.length) {
    select.innerHTML = d.disponibles.map(c => `<option value="${c.id}">${esc(c.nombre)}</option>`).join('');
    select.disabled = false;
    boton.disabled = false;
  } else {
    select.innerHTML = '<option value="">No hay Capataces sin asignar</option>';
    select.disabled = true;
    boton.disabled = true;
  }
  boton.onclick = () => { if (select.value) cambiarEquipo(Number(select.value), 'agregar'); };
}

async function cambiarEquipo(capatazId, accion) {
  try {
    const data = await apiFetch('/terreno/equipo_asignar.php', {
      method: 'POST',
      body: { capataz_id: capatazId, accion },
    });
    mostrarAlerta('alerta', data.mensaje, 'exito');
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
  cargarEquipo();
}

// Arrastrar y soltar (escritorio): de "sin asignar" al organigrama = agregar;
// del organigrama de vuelta a "sin asignar" = quitar. En celular se usa la
// lista desplegable o los botones, que hacen lo mismo.
function configurarArrastreEquipo() {
  const leer = (e) => { try { return JSON.parse(e.dataTransfer.getData('text/plain')); } catch (x) { return null; } };
  document.addEventListener('dragstart', (e) => {
    const el = e.target.closest ? e.target.closest('[data-capataz-id][draggable="true"]') : null;
    if (!el) return;
    e.dataTransfer.setData('text/plain', JSON.stringify({ id: Number(el.dataset.capatazId), origen: el.dataset.origen }));
    e.dataTransfer.effectAllowed = 'move';
  });
  const zona = (contenedorId, origenValido, accion, claseZona) => {
    const cont = document.getElementById(contenedorId);
    if (!cont) return;
    cont.addEventListener('dragover', (e) => { e.preventDefault(); cont.classList.add('org-zona-activa'); });
    cont.addEventListener('dragleave', (e) => { if (!cont.contains(e.relatedTarget)) cont.classList.remove('org-zona-activa'); });
    cont.addEventListener('drop', (e) => {
      e.preventDefault();
      cont.classList.remove('org-zona-activa');
      const info = leer(e);
      if (info && info.origen === origenValido) cambiarEquipo(info.id, accion);
    });
  };
  zona('equipo-organigrama', 'disponible', 'agregar');
  zona('equipo-disponibles-zona', 'equipo', 'quitar');
}

// --- v4: Solicitar Cupos ---------------------------------------------------
async function cargarCupos() {
  const tbody = document.getElementById('tbody-cupos');
  const select = document.getElementById('cupo-cargo');
  try {
    const data = await apiFetch('/terreno/cupos_listar.php');
    // v6.10: se agrega "Otro" al final para poder pedir cupos de un
    // cargo que todavía no existe en el catálogo.
    select.innerHTML = data.cargos.map(c => `<option value="${c.id}">${esc(c.nombre_cargo)}</option>`).join('')
      + '<option value="__otro__">➕ Otro (agregar cargo nuevo)</option>';
    tbody.innerHTML = data.cargos.map(c => `
      <tr class="border-t">
        <td class="px-4 py-3">${esc(c.nombre_cargo)}</td>
        <td class="px-4 py-3">${esc(c.cupos_totales)}</td>
        <td class="px-4 py-3 font-semibold ${c.cupos_activos > 0 ? 'text-green-700' : 'text-gray-400'}">${esc(c.cupos_activos)}</td>
      </tr>`).join('');
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

// --- v6.9: historial de solicitudes con su estado (Pendiente/Aprobada/ -----
// Rechazada) -- desde la reunión con Ricardo, solicitar cupos ya no los
// abre de inmediato, así que Jefe de Terreno necesita ver si el
// Administrador de Contrato ya resolvió su pedido.
const ETIQUETA_ESTADO_SOLICITUD = {
  Pendiente: '<span class="text-xs text-amber-700 bg-amber-50 px-2 py-1 rounded-md font-medium">Pendiente</span>',
  Aprobada: '<span class="text-xs text-green-700 bg-green-50 px-2 py-1 rounded-md font-medium">✓ Aprobada</span>',
  Rechazada: '<span class="text-xs text-red-700 bg-red-50 px-2 py-1 rounded-md font-medium">✕ Rechazada</span>',
};

async function cargarMisSolicitudes() {
  const tbody = document.getElementById('tbody-mis-solicitudes');
  const vacio = document.getElementById('mis-solicitudes-vacio');
  try {
    const data = await apiFetch('/terreno/mis_solicitudes.php');
    if (!data.solicitudes.length) {
      tbody.innerHTML = '';
      vacio.classList.remove('hidden');
      return;
    }
    vacio.classList.add('hidden');
    tbody.innerHTML = data.solicitudes.map(s => `
      <tr class="border-t">
        <td class="px-4 py-3">${esc(s.nombre_cargo)}${s.es_cargo_nuevo ? ' <span class="text-[10px] text-indigo-700 bg-indigo-50 px-1.5 py-0.5 rounded font-semibold align-middle">🆕 nuevo</span>' : ''}</td>
        <td class="px-4 py-3">${esc(s.cantidad)}</td>
        <td class="px-4 py-3">${ETIQUETA_ESTADO_SOLICITUD[s.estado] || s.estado}
          ${s.estado === 'Rechazada' && s.motivo_rechazo ? `<span class="block text-[11px] text-gray-400 mt-0.5">${esc(s.motivo_rechazo)}</span>` : ''}
        </td>
        <td class="px-4 py-3 text-gray-500">${new Date(s.creado_at).toLocaleString('es-CL')}</td>
      </tr>`).join('');
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

// v6.10: si elige "Otro", se muestra el campo de texto para el nombre
// del cargo nuevo en vez del listado predeterminado.
document.getElementById('cupo-cargo').addEventListener('change', (e) => {
  const esOtro = e.target.value === '__otro__';
  document.getElementById('cupo-cargo-nuevo-wrap').classList.toggle('hidden', !esOtro);
  document.getElementById('cupo-cargo-nuevo').required = esOtro;
});

document.getElementById('form-cupo').addEventListener('submit', async (e) => {
  e.preventDefault();
  const cargoSeleccionado = document.getElementById('cupo-cargo').value;
  const cantidad = Number(document.getElementById('cupo-cantidad').value);
  const esOtro = cargoSeleccionado === '__otro__';
  const cargoNuevoNombre = document.getElementById('cupo-cargo-nuevo').value.trim();

  if (esOtro && !cargoNuevoNombre) {
    mostrarAlerta('alerta', 'Escribe el nombre del cargo nuevo.');
    return;
  }

  const body = esOtro
    ? { cargo_nuevo_nombre: cargoNuevoNombre, cantidad }
    : { cargo_id: Number(cargoSeleccionado), cantidad };

  try {
    const data = await apiFetch('/terreno/solicitar_cupo.php', { method: 'POST', body });
    mostrarAlerta('alerta', data.mensaje, 'exito');
    document.getElementById('cupo-cantidad').value = '';
    document.getElementById('cupo-cargo-nuevo').value = '';
    document.getElementById('cupo-cargo-nuevo-wrap').classList.add('hidden');
    document.getElementById('cupo-cargo').value = '';
    await cargarMisSolicitudes();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
});

// --- v3.2: Histórico (Postulantes en proceso / Contratado) -----------------
const ETIQUETAS_ETAPA = {
  Pre_aprobado_terreno: 'Esperando Etapa 2 / autorización',
  Datos_completados: 'Datos completados',
  Aprobado_admin: 'En revisión JAO',
  Induccion_ok: 'Inducción realizada',
  EPP_listo: 'EPP listo',
};

function inicializarFiltros(vista) {
  const cont = document.getElementById(`filtros-${vista}`);
  cont.innerHTML = `
    <div><label class="block text-xs text-gray-600 mb-1">Desde</label>
      <input type="date" id="desde-${vista}" class="border border-gray-300 rounded-lg px-2 py-1.5 text-sm"></div>
    <div><label class="block text-xs text-gray-600 mb-1">Hasta</label>
      <input type="date" id="hasta-${vista}" class="border border-gray-300 rounded-lg px-2 py-1.5 text-sm"></div>
    <div><label class="block text-xs text-gray-600 mb-1">Seleccionado por</label>
      <select id="aprobado_por-${vista}" class="border border-gray-300 rounded-lg px-2 py-1.5 text-sm"><option value="">Todos</option></select></div>
    <button class="bg-gray-800 hover:bg-gray-900 text-white text-xs font-semibold px-3 py-2 rounded-lg" onclick="cargarHistorico('${vista}')">Filtrar</button>
    <button class="bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold px-3 py-2 rounded-lg" onclick="limpiarFiltros('${vista}')">Limpiar</button>
    <button class="ml-auto bg-gray-900 hover:bg-gray-800 text-white text-xs font-semibold px-3 py-2 rounded-lg" onclick="exportarHistoricoExcel('${vista}')">Exportar Excel</button>`;
}

async function exportarHistoricoExcel(vista) {
  const desde = document.getElementById(`desde-${vista}`).value;
  const hasta = document.getElementById(`hasta-${vista}`).value;
  const aprobadoPor = document.getElementById(`aprobado_por-${vista}`).value;
  const params = new URLSearchParams({ vista });
  if (desde) params.set('desde', desde);
  if (hasta) params.set('hasta', hasta);
  if (aprobadoPor) params.set('aprobado_por', aprobadoPor);

  try {
    const res = await apiFetch(`/terreno/exportar_excel.php?${params.toString()}`);
    const blob = await res.blob();
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${vista}.xlsx`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);
  } catch (err) {
    mostrarAlerta('alerta', err.message || 'No hay datos para exportar en ese rango.');
  }
}

function limpiarFiltros(vista) {
  document.getElementById(`desde-${vista}`).value = '';
  document.getElementById(`hasta-${vista}`).value = '';
  document.getElementById(`aprobado_por-${vista}`).value = '';
  cargarHistorico(vista);
}

async function cargarHistorico(vista) {
  const tbody = document.getElementById(`tbody-${vista}`);
  const vacio = document.getElementById(`${vista}-vacio`);
  const desde = document.getElementById(`desde-${vista}`).value;
  const hasta = document.getElementById(`hasta-${vista}`).value;
  const aprobadoPor = document.getElementById(`aprobado_por-${vista}`).value;

  const params = new URLSearchParams({ vista });
  if (desde) params.set('desde', desde);
  if (hasta) params.set('hasta', hasta);
  if (aprobadoPor) params.set('aprobado_por', aprobadoPor);

  try {
    const data = await apiFetch(`/terreno/historico.php?${params.toString()}`);

    const selectAprobadoPor = document.getElementById(`aprobado_por-${vista}`);
    if (selectAprobadoPor.options.length <= 1) {
      selectAprobadoPor.innerHTML = '<option value="">Todos</option>' +
        data.jefes_terreno.map(u => `<option value="${u.id}">${esc(u.nombre)}</option>`).join('');
      selectAprobadoPor.value = aprobadoPor;
    }

    if (!data.postulaciones.length) {
      tbody.innerHTML = '';
      vacio.classList.remove('hidden');
      return;
    }
    vacio.classList.add('hidden');

    tbody.innerHTML = data.postulaciones.map(p => {
      const fecha = p.fecha_aprobacion ? new Date(p.fecha_aprobacion).toLocaleString('es-CL') : '-';
      const aprobador = p.aprobado_por_nombre || '-';
      const filasComunes = `
        <td class="px-4 py-3 font-mono">${celdaDocumento(p)}</td>
        <td class="px-4 py-3">${esc(p.nombre_completo)}</td>
        <td class="px-4 py-3">${esc(p.nombre_cargo)}</td>`;
      if (vista === 'en_proceso') {
        return `<tr class="border-t">${filasComunes}
          <td class="px-4 py-3">${ETIQUETAS_ETAPA[p.estado] || p.estado}</td>
          <td class="px-4 py-3">${aprobador}</td>
          <td class="px-4 py-3 text-gray-500">${fecha}</td>
        </tr>`;
      }
      // v10.14 (pedido explícito del usuario, item 6): se retira la
      // pestaña "Recepción" propia -- el mismo botón de cierre ahora
      // vive acá, en la fila de "Personal Contratado", solo mientras
      // sigue en 'Contratado' (todavía no se confirmó que lo retiraron).
      const accion = p.estado === 'Contratado'
        ? `<button class="bg-green-600 hover:bg-green-700 text-white text-xs font-semibold px-3 py-1.5 rounded-lg" onclick="confirmarRecepcion(${p.id})">Ya lo retiré</button>`
        : '<span class="text-xs text-gray-400">Ya retirado</span>';
      return `<tr class="border-t">${filasComunes}
        <td class="px-4 py-3">${aprobador}</td>
        <td class="px-4 py-3 text-gray-500">${fecha}</td>
        <td class="px-4 py-3 text-right">${accion}</td>
      </tr>`;
    }).join('');
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

// v10.14 (pedido explícito del usuario, item 6): "ya lo retiré, se lo
// lleva a una cuadrilla" -- antes era su propia pestaña "Recepción",
// ahora es un botón dentro de "Personal Contratado". Reutiliza el mismo
// endpoint de siempre (terreno/recepcion_confirmar.php), solo cambia
// desde dónde se llama.
async function confirmarRecepcion(id) {
  if (!confirm('¿Confirmas que fuiste a buscar a esta persona? Esto da por terminado el proceso completo.')) return;
  try {
    const data = await apiFetch('/terreno/recepcion_confirmar.php', { method: 'POST', body: { postulacion_id: id } });
    mostrarAlerta('alerta', data.mensaje, 'exito');
    await cargarHistorico('contratados');
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}
