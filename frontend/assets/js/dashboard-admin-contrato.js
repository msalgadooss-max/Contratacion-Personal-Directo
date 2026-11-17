/**
 * v10.13 (pedido explícito del usuario, tras describir de nuevo el
 * proceso completo): el rol de Admin_Contrato termina al aprobar los
 * cupos de Jefe de Terreno -- ya no autoriza cada postulación una por
 * una ("el rol del administrador terminó" en sus propias palabras). Se
 * retiró la pestaña "Por Autorizar" (ver admin_contrato/autorizar.php,
 * que queda sin usar pero no se borra) y se fusionó "Personal
 * Autorizado" dentro de "Estado del proceso".
 */
(async () => {
  const usuario = await protegerDashboard('Admin_Contrato');
  if (!usuario) return;
  configurarTabs();
  cargarSolicitudesCupo(); // pestaña inicial
  iniciarEstadoVivo();
})();

// --- v3.4: pestañas --------------------------------------------------------
let TIEMPOS_CARGADOS = false;

function configurarTabs() {
  document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => cambiarTab(btn.dataset.tab));
  });
}

function cambiarTab(tab) {
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
  if (tab === 'estado_proceso') {
    renderEstadoProcesoTabla(); // pinta con lo último que ya cargó el widget "Estado en vivo"
    if (!TIEMPOS_CARGADOS) {
      TIEMPOS_CARGADOS = true;
      cargarAutorizados();
    }
  }
  if (tab === 'solicitudes_cupo') {
    cargarSolicitudesCupo();
  }
  // v10.42: el mapa de equipo es ancho; solo en esa pestaña el contenido usa casi todo el ancho de la pantalla.
  const principal = document.querySelector('main');
  principal.classList.toggle('max-w-6xl', tab !== 'equipo');
  principal.classList.toggle('max-w-[2200px]', tab === 'equipo');
  if (tab === 'equipo') {
    cargarOrganigramaAdmin();
  }
}

// --- v10.42: "Mi equipo" -- mapa general de la gente de la obra ------------
function ogGrupo(g) {
  const sin = !g.frente;
  const nombres = g.capataces.map(c => `<li class="text-[13px] text-gray-800 bg-white border border-gray-200 rounded-lg px-2.5 py-1">${esc(c.nombre)}</li>`).join('');
  return `
    <div class="og-grupo">
      <div class="rounded-xl border ${sin ? 'border-dashed border-gray-300 bg-gray-50' : 'border-orange-200 bg-orange-50/60'} p-3">
        <p class="text-xs font-bold ${sin ? 'text-gray-500' : 'text-orange-800'} mb-1.5">${sin ? 'Sin frente asignado' : esc(g.frente)}
          <span class="ml-1 font-semibold text-gray-400">· ${g.capataces.length}</span></p>
        <ul class="space-y-1">${nombres}</ul>
      </div>
    </div>`;
}

function ogColumnaPT(titulo, nombre, detalle, grupos, claseTitulo) {
  const cuerpo = grupos.length ? grupos.map(ogGrupo).join('') : '<p class="text-xs text-gray-400 py-1">Sin capataces asignados.</p>';
  return `
    <div class="og-hijo">
      <div class="${claseTitulo} rounded-xl px-4 py-3">
        <p class="text-[10px] uppercase tracking-wide font-semibold opacity-80">${titulo}</p>
        <p class="font-bold text-sm leading-snug mt-0.5">${nombre}</p>
        <p class="text-[11px] opacity-70 mt-1">${detalle}</p>
      </div>
      <div class="og-cuerpo">${cuerpo}</div>
    </div>`;
}

async function cargarOrganigramaAdmin() {
  const cont = document.getElementById('og-contenido');
  try {
    const d = await apiFetch('/admin_contrato/organigrama.php');
    const nFrentes = (pt) => pt.frentes.filter(f => f.frente).length;
    const plural = (n, uno, varios) => `${n} ${n === 1 ? uno : varios}`;
    const cols = d.profesionales.map(pt => ogColumnaPT(
      'Profesional de Terreno', esc(pt.nombre),
      `${plural(pt.total_capataces, 'capataz', 'capataces')} · ${plural(nFrentes(pt), 'frente', 'frentes')}`,
      pt.frentes, 'bg-gray-900 text-white'));
    if (d.total_sin_profesional) {
      cols.push(ogColumnaPT('Sin Profesional de Terreno', 'Por asignar', `${d.total_sin_profesional} capataces`,
        d.sin_profesional, 'border-2 border-dashed border-gray-300 text-gray-700'));
    }
    const colores = {
      Prevencionista: 'bg-green-50 border-green-200 text-green-900',
      Jefe_Administrativo: 'bg-indigo-50 border-indigo-200 text-indigo-900',
      Jefe_Bodega: 'bg-amber-50 border-amber-200 text-amber-900',
    };
    const apoyo = d.apoyo.map(a => `
      <div class="og-hijo og-apoyo">
        <div class="rounded-xl border ${colores[a.rol] || 'bg-gray-50 border-gray-200'} px-4 py-3">
          <p class="text-[10px] uppercase tracking-wide font-semibold opacity-80">${esc(a.etiqueta)}</p>
          <p class="font-bold text-sm leading-snug mt-0.5">${esc(a.nombre)}</p>
          <p class="text-[11px] opacity-70 mt-1">Sin equipo a cargo</p>
        </div>
      </div>`);
    document.getElementById('og-resumen').textContent =
      `${plural(d.profesionales.length, 'Profesional de Terreno', 'Profesionales de Terreno')} · ${d.total_capataces} capataces · ${d.apoyo.length} de apoyo (Prevención, JAO y Bodega)`;
    cont.innerHTML = `
      <div class="og-arbol">
        <div class="bg-orange-600 text-white rounded-xl px-5 py-3 text-center min-w-[240px]">
          <p class="text-[10px] uppercase tracking-wide font-semibold opacity-80">Administrador de Contrato</p>
          <p class="font-bold text-sm mt-0.5">${esc(d.administrador.nombre)}</p>
          <p class="text-[11px] opacity-80 mt-0.5">${esc(d.obra)}</p>
        </div>
        <div class="og-linea-v"></div>
        <div class="og-nivel">${cols.join('')}${apoyo.join('')}</div>
      </div>`;
  } catch (err) {
    cont.innerHTML = `<p class="text-sm text-red-600">${esc(err.message)}</p>`;
  }
}

// --- v6.9: aprobar/rechazar solicitudes de cupo de Jefe de Terreno --------
// (esto es lo que en la reunión con Ricardo se llamó "abrir la vacante").
async function cargarSolicitudesCupo() {
  const tbody = document.getElementById('tbody-solicitudes-cupo');
  const vacio = document.getElementById('solicitudes-cupo-vacio');
  try {
    const data = await apiFetch('/admin_contrato/solicitudes_cupo_listar.php');
    if (!data.solicitudes.length) {
      tbody.innerHTML = '';
      vacio.classList.remove('hidden');
      return;
    }
    vacio.classList.add('hidden');
    solicitudesCupoPorId = {};
    data.solicitudes.forEach(s => { solicitudesCupoPorId[s.id] = s; });

    tbody.innerHTML = data.solicitudes.map(s => `
      <tr class="border-t">
        <td class="px-4 py-3">${esc(s.nombre_cargo)}${s.es_cargo_nuevo ? ' <span class="text-[10px] text-indigo-700 bg-indigo-50 px-1.5 py-0.5 rounded font-semibold align-middle">🆕 cargo nuevo</span>' : ''}</td>
        <td class="px-4 py-3 font-semibold">${esc(s.cantidad)}</td>
        <td class="px-4 py-3">${esc(s.solicitado_por_nombre || '-')}${s.para_capataz_nombre ? `<span class="block text-[11px] font-semibold text-orange-700">→ para el Capataz ${esc(s.para_capataz_nombre)}</span>` : ''}</td>
        <td class="px-4 py-3 text-gray-500">${new Date(s.creado_at).toLocaleString('es-CL')}</td>
        <td class="px-4 py-3 text-right space-x-2">
          <button class="bg-green-600 hover:bg-green-700 text-white text-xs font-semibold px-3 py-1.5 rounded-lg" onclick="aprobarSolicitudCupo(${s.id})">Aprobar</button>
          <button class="bg-red-100 hover:bg-red-200 text-red-700 text-xs font-semibold px-3 py-1.5 rounded-lg" onclick="rechazarSolicitudCupo(${s.id})">Rechazar</button>
        </td>
      </tr>`).join('');
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

let solicitudesCupoPorId = {};

async function aprobarSolicitudCupo(id) {
  const solicitud = solicitudesCupoPorId[id];
  if (!solicitud) return;
  const respuesta = await pedirAprobacionCupo(solicitud.cantidad, solicitud.nombre_cargo);
  if (respuesta === null) return;
  try {
    const data = await apiFetch('/admin_contrato/solicitudes_cupo_aprobar.php', {
      method: 'POST',
      body: { solicitud_id: id, cantidad: respuesta.cantidad, observacion: respuesta.observacion },
    });
    mostrarAlerta('alerta', data.mensaje, 'exito');
    await cargarSolicitudesCupo();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

async function rechazarSolicitudCupo(id) {
  const motivo = await pedirMotivoRechazo();
  if (motivo === null) return;
  try {
    const data = await apiFetch('/admin_contrato/solicitudes_cupo_rechazar.php', { method: 'POST', body: { solicitud_id: id, motivo } });
    mostrarAlerta('alerta', data.mensaje, 'exito');
    await cargarSolicitudesCupo();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

// --- v6.5: pestaña "Estado del proceso" (cada trabajador individualizado) -
// Reutiliza la misma data que el widget "Estado en vivo" (estado-vivo.js),
// que ya se refresca solo cada 10s -- no pide un endpoint aparte.
function onEstadoVivoActualizado() {
  const panel = document.getElementById('panel-estado_proceso');
  if (panel && !panel.classList.contains('hidden')) renderEstadoProcesoTabla();
}

function renderEstadoProcesoTabla() {
  const tbody = document.getElementById('tbody-estado-proceso');
  const vacio = document.getElementById('estado-proceso-vacio');
  if (!tbody) return;
  const trabajadores = ESTADO_VIVO_ULTIMO || [];
  if (!trabajadores.length) {
    tbody.innerHTML = '';
    if (vacio) vacio.classList.remove('hidden');
    return;
  }
  if (vacio) vacio.classList.add('hidden');
  tbody.innerHTML = trabajadores.map(t => `
    <tr class="border-t ${t.pendiente_de_ti ? 'bg-amber-50' : ''}">
      <td class="px-4 py-3 font-medium text-gray-900">${esc(t.nombre_completo)}</td>
      <td class="px-4 py-3">${esc(t.nombre_cargo)}</td>
      <td class="px-4 py-3">
        <span class="text-xs font-medium ${t.contratado ? 'text-green-700' : 'text-blue-700'}">${esc(t.fase)}</span>
        ${t.pendiente_de_ti ? '<span class="ml-2 text-[11px] font-semibold text-amber-700">👉 Pendiente en tu bandeja</span>' : ''}
      </td>
      <td class="px-4 py-3 text-right space-x-3">
        <button class="text-xs font-semibold text-blue-600 underline" onclick="abrirDetalleTrabajador(${t.id})">Ver pasos</button>
        <button class="text-xs font-semibold text-indigo-600 underline" onclick="abrirDetalleTiempos(${t.id})">⏱ Ver tiempos</button>
      </td>
    </tr>`).join('');
}

// --- v10.13: Tiempos del proceso (KPI + export), dentro de "Estado del
// proceso" -- ver comentario de cabecera de este archivo. ------------------
function limpiarFiltrosAutorizados() {
  document.getElementById('desde-autorizado').value = '';
  document.getElementById('hasta-autorizado').value = '';
  cargarAutorizados();
}

function paramsRangoAutorizado() {
  const desde = document.getElementById('desde-autorizado').value; // "AAAA-MM-DDTHH:MM"
  const hasta = document.getElementById('hasta-autorizado').value;
  const params = new URLSearchParams();
  if (desde) params.set('desde', desde.replace('T', ' ') + ':00');
  if (hasta) params.set('hasta', hasta.replace('T', ' ') + ':00');
  return params;
}

async function cargarAutorizados() {
  try {
    const data = await apiFetch(`/admin_contrato/historico.php?${paramsRangoAutorizado().toString()}`);

    document.getElementById('kpi-promedio').textContent = data.kpi_promedio_total ?? '-';
    document.getElementById('kpi-cantidad').textContent = data.kpi_cantidad_contratados;
    document.getElementById('kpi-promedio-postulante').textContent = data.kpi_promedio_postulante ?? '-';
    document.getElementById('kpi-promedio-jao').textContent = data.kpi_promedio_jao ?? '-';
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

// v10.13 (pedido explícito del usuario): botón "🔄 Actualizar" en el
// header -- por si el proceso "parece pegado", refresca todo sin
// recargar la página ni salir del panel.
function actualizarTodo() {
  cargarSolicitudesCupo();
  renderEstadoProcesoTabla();
  cargarAutorizados();
  cargarEstadoVivo();
  mostrarAlerta('alerta', 'Actualizado.', 'exito');
}

async function exportarAutorizadosExcel() {
  try {
    const res = await apiFetch(`/admin_contrato/exportar_excel.php?${paramsRangoAutorizado().toString()}`);
    const blob = await res.blob();
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'personal_autorizado.xlsx';
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);
  } catch (err) {
    mostrarAlerta('alerta', err.message || 'No hay datos para exportar en ese rango.');
  }
}
