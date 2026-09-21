/**
 * Panel de Gerencia: única pantalla que trae TODAS las postulaciones,
 * sin importar su fase. No tiene ningún botón de acción -- es
 * deliberadamente de solo lectura (el control de acceso real vive en
 * el backend: /api/gerencia/listar.php es el único endpoint de este
 * rol y no existe ningún endpoint de escritura para 'Gerencia').
 */
const ETIQUETAS_ESTADO = {
  En_banco: 'En banco',
  Pendiente: 'Pendiente',
  Pre_aprobado_terreno: 'Pre-aprobado terreno',
  Aprobado_admin: 'Aprobado admin',
  Datos_completados: 'Datos completados',
  Induccion_ok: 'Inducción OK',
  EPP_listo: 'EPP listo',
  Contratado: 'Contratado',
  Proceso_completo: 'Proceso completo',
  Rechazado: 'Rechazado',
};

const COLOR_ESTADO = {
  En_banco: 'bg-sky-100 text-sky-700',
  Pendiente: 'bg-gray-100 text-gray-700',
  Pre_aprobado_terreno: 'bg-blue-100 text-blue-700',
  Aprobado_admin: 'bg-indigo-100 text-indigo-700',
  Datos_completados: 'bg-purple-100 text-purple-700',
  Induccion_ok: 'bg-amber-100 text-amber-700',
  EPP_listo: 'bg-teal-100 text-teal-700',
  Contratado: 'bg-green-100 text-green-700',
  Proceso_completo: 'bg-emerald-100 text-emerald-700',
  Rechazado: 'bg-red-100 text-red-700',
};

let TODAS_LAS_POSTULACIONES = [];
let CHART_INGRESOS = null;
let CHART_DONUT_GERENCIA = null;

(async () => {
  const usuario = await protegerDashboard('Gerencia');
  if (!usuario) return;
  await cargarPanel();
  await cargarBitacora();
  await cargarIndicadores();
  iniciarEstadoVivo();
})();

document.getElementById('rango-indicadores').addEventListener('change', cargarIndicadores);

// v10.13 (pedido explícito del usuario): botón "🔄 Actualizar" en el
// header -- por si el proceso "parece pegado", refresca todo sin
// recargar la página ni salir del panel.
function actualizarTodo() {
  cargarPanel();
  cargarBitacora();
  cargarIndicadores();
  cargarEstadoVivo();
  mostrarAlerta('alerta', 'Actualizado.', 'exito');
}

// --- v10.16 (pedido explícito de Ricardo): indicadores de gestión ---------
async function cargarIndicadores() {
  const dias = document.getElementById('rango-indicadores').value;
  try {
    const [indicadores, donut] = await Promise.all([
      apiFetch(`/gerencia/indicadores.php?dias=${dias}`),
      apiFetch(`/admin_general/estadisticas.php?dias=${dias}`),
    ]);
    renderChartIngresos(indicadores.ingresos_por_dia);
    renderTiempoPromedio(indicadores.tiempo_contratacion);
    renderContratacionesPromedioSemanal(indicadores.contrataciones_por_semana, indicadores.contrataciones_promedio_semanal);
    renderChartDonutGerencia(donut.conteo);
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

function renderContratacionesPromedioSemanal(porSemana, promedio) {
  const el = document.getElementById('contrataciones-promedio-semanal');
  const detalle = document.getElementById('contrataciones-semanal-detalle');
  if (!porSemana.length) {
    el.textContent = 'Sin datos';
    detalle.textContent = 'Nadie fue contratado en este rango todavía.';
    return;
  }
  el.textContent = promedio;
  const totalContrataciones = porSemana.reduce((sum, s) => sum + s.total, 0);
  detalle.textContent = `${totalContrataciones} contratación(es) en ${porSemana.length} semana(s) con actividad`;
}

// v10.16 (pedido explícito de Ricardo): descarga en Excel de los mismos
// indicadores que se ven en pantalla, con el rango elegido en el selector.
async function exportarIndicadoresExcel() {
  const dias = document.getElementById('rango-indicadores').value;
  try {
    const res = await apiFetch(`/gerencia/indicadores_exportar_excel.php?dias=${dias}`);
    const blob = await res.blob();
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'indicadores_gestion.xlsx';
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);
  } catch (err) {
    mostrarAlerta('alerta', err.message || 'No hay datos para exportar en ese rango.');
  }
}

function renderChartIngresos(ingresosPorDia) {
  const ctx = document.getElementById('chart-ingresos');
  if (CHART_INGRESOS) CHART_INGRESOS.destroy();
  CHART_INGRESOS = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: ingresosPorDia.map(f => new Date(f.fecha + 'T00:00:00').toLocaleDateString('es-CL', { day: '2-digit', month: '2-digit' })),
      datasets: [{ data: ingresosPorDia.map(f => f.total), backgroundColor: '#f15922', borderRadius: 4, maxBarThickness: 28 }],
    },
    options: {
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
    },
  });
}

function renderTiempoPromedio(tiempo) {
  const el = document.getElementById('tiempo-promedio-contratacion');
  const detalle = document.getElementById('tiempo-promedio-detalle');
  if (!tiempo.muestras) {
    el.textContent = 'Sin datos';
    detalle.textContent = 'Nadie fue contratado en este rango todavía.';
    return;
  }
  el.textContent = tiempo.promedio_texto;
  detalle.textContent = `${tiempo.muestras} contratación(es) · mín. ${tiempo.minimo_texto} · máx. ${tiempo.maximo_texto}`;
}

function renderChartDonutGerencia(conteo) {
  const { Contratado, Rechazado } = conteo;
  const ctx = document.getElementById('chart-donut-gerencia');
  if (CHART_DONUT_GERENCIA) CHART_DONUT_GERENCIA.destroy();
  CHART_DONUT_GERENCIA = new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels: ['Contratado', 'Rechazado'],
      datasets: [{ data: [Contratado, Rechazado], backgroundColor: ['#16a34a', '#dc2626'], borderWidth: 0 }],
    },
    options: { plugins: { legend: { display: false } }, cutout: '65%' },
  });
  document.getElementById('leyenda-chart-gerencia').innerHTML = `
    <p><span class="inline-block w-2 h-2 rounded-full bg-green-600 mr-1.5"></span>Contratado: <strong>${Contratado}</strong></p>
    <p><span class="inline-block w-2 h-2 rounded-full bg-red-600 mr-1.5"></span>Rechazado: <strong>${Rechazado}</strong></p>`;
}

// --- v5: bitácora de actividad en lenguaje natural -------------------------
async function cargarBitacora() {
  const lista = document.getElementById('lista-bitacora');
  const vacio = document.getElementById('bitacora-vacio');
  try {
    const data = await apiFetch('/bitacora.php');
    if (!data.eventos.length) {
      lista.innerHTML = '';
      vacio.classList.remove('hidden');
      return;
    }
    vacio.classList.add('hidden');
    lista.innerHTML = data.eventos.map(e => `
      <li class="text-sm border-b border-gray-100 pb-2 last:border-0 last:pb-0">
        <span class="text-gray-900"><strong>${e.nombre_completo}</strong> (${e.nombre_cargo}): ${e.descripcion}</span>
        <span class="block text-xs text-gray-400 mt-0.5">${e.autor} · ${new Date(e.fecha_hora).toLocaleString('es-CL')}</span>
      </li>`).join('');
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

document.getElementById('filtro-estado').addEventListener('change', renderTabla);

async function cargarPanel() {
  try {
    const data = await apiFetch('/gerencia/listar.php');
    TODAS_LAS_POSTULACIONES = data.postulaciones;
    renderKpis(data.resumen_estados);
    renderCupos(data.cargos);
    renderEstadoSistema(data.cierre_remuneraciones_activo, data.modulos, data.cierre_remuneraciones_desde, data.cierre_remuneraciones_hasta);
    renderTabla();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

function renderKpis(resumen) {
  const kpisDiv = document.getElementById('kpis');
  kpisDiv.innerHTML = Object.keys(ETIQUETAS_ESTADO).map(estado => `
    <div class="bg-white rounded-xl shadow-sm p-3 text-center">
      <p class="text-2xl font-bold text-gray-900">${resumen[estado] ?? 0}</p>
      <p class="text-xs text-gray-500 mt-1">${ETIQUETAS_ESTADO[estado]}</p>
    </div>`).join('');
}

function renderEstadoSistema(cierreActivo, modulos, cierreDesde, cierreHasta) {
  const el = document.getElementById('estado-sistema');
  if (!el) return;
  const chips = [];
  // v10.14 (pedido explícito del usuario, item 15): muestra las fechas
  // programadas, no solo si está activo o no.
  const rangoTexto = cierreDesde && cierreHasta ? ` (${cierreDesde} al ${cierreHasta})` : '';
  chips.push(cierreActivo
    ? `<span class="px-2.5 py-1 rounded-full text-xs font-medium bg-amber-100 text-amber-800">Cierre de remuneraciones ACTIVO${rangoTexto}: no se pueden finalizar contrataciones</span>`
    : '<span class="px-2.5 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">Remuneraciones abiertas</span>');
  if (!modulos.prevencion) {
    chips.push('<span class="px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-500">Prevención: pausada en esta demo</span>');
  }
  if (!modulos.bodega) {
    chips.push('<span class="px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-500">Bodega: pausada en esta demo</span>');
  }
  el.innerHTML = chips.join(' ');
}

function renderCupos(cargos) {
  const cuposDiv = document.getElementById('cupos');
  cuposDiv.innerHTML = cargos.map(c => {
    const ocupados = c.cupos_totales - c.cupos_activos;
    const pct = c.cupos_totales > 0 ? Math.round((ocupados / c.cupos_totales) * 100) : 0;
    return `
      <div class="border border-gray-200 rounded-lg p-3">
        <p class="text-sm font-medium text-gray-800">${c.nombre_cargo}</p>
        <p class="text-xs text-gray-500 mb-1">${ocupados} / ${c.cupos_totales} cupos ocupados</p>
        <div class="w-full bg-gray-100 rounded-full h-2">
          <div class="bg-blue-600 h-2 rounded-full" style="width:${pct}%"></div>
        </div>
      </div>`;
  }).join('');
}

function renderTabla() {
  const filtro = document.getElementById('filtro-estado').value;
  const tbody = document.getElementById('tbody-postulaciones');
  const vacio = document.getElementById('vacio');
  const contador = document.getElementById('contador');

  const filas = filtro
    ? TODAS_LAS_POSTULACIONES.filter(p => p.estado === filtro)
    : TODAS_LAS_POSTULACIONES;

  contador.textContent = `${filas.length} postulación(es)`;

  if (!filas.length) {
    tbody.innerHTML = '';
    vacio.classList.remove('hidden');
    return;
  }
  vacio.classList.add('hidden');

  tbody.innerHTML = filas.map(p => `
    <tr class="border-t">
      <td class="px-4 py-3 font-mono">${p.rut}</td>
      <td class="px-4 py-3">${p.nombre_completo}</td>
      <td class="px-4 py-3">${p.nombre_cargo}</td>
      <td class="px-4 py-3">${p.comuna}</td>
      <td class="px-4 py-3"><span class="px-2 py-1 rounded-full text-xs font-medium ${COLOR_ESTADO[p.estado] || 'bg-gray-100 text-gray-700'}">${ETIQUETAS_ESTADO[p.estado] || p.estado}</span></td>
      <td class="px-4 py-3 text-gray-500">${new Date(p.actualizado_at).toLocaleString('es-CL')}</td>
      <td class="px-4 py-3 text-right">
        <button class="text-xs font-semibold text-indigo-600 underline" onclick="abrirDetalleTiempos(${p.id})">⏱ Ver tiempos</button>
      </td>
    </tr>`).join('');
}

// --- v10.10: descarga en Excel, filtrando por rango de fecha de postulación
async function exportarGerenciaExcel() {
  const desde = document.getElementById('desde-gerencia').value; // "AAAA-MM-DDTHH:MM"
  const hasta = document.getElementById('hasta-gerencia').value;
  const params = new URLSearchParams();
  if (desde) params.set('desde', desde.replace('T', ' ') + ':00');
  if (hasta) params.set('hasta', hasta.replace('T', ' ') + ':00');

  try {
    const res = await apiFetch(`/gerencia/exportar_excel.php?${params.toString()}`);
    const blob = await res.blob();
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'proceso_completo.xlsx';
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);
  } catch (err) {
    mostrarAlerta('alerta', err.message || 'No hay datos para exportar en ese rango.');
  }
}
