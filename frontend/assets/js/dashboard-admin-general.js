/**
 * v3: el JAO ahora revisa la ficha completa (datos + 6 documentos),
 * llena los campos de nómina (amarillos) y recién ahí puede finalizar.
 * También ve un anillo de contratados vs rechazados.
 */
const ETIQUETAS_DOC = {
  cv: 'CV',
  cedula_identidad: 'Cédula (frente)',
  cedula_identidad_reverso: 'Cédula (reverso)',
  certificado_afp: 'Certificado AFP',
  certificado_salud: 'Certificado Fonasa/Isapre',
  ultimo_finiquito: 'Último Finiquito',
  certificado_residencia: 'Certificado de Residencia',
};

let CIERRE_ACTIVO = false;
let LISTAS = null;
// v9.2: Etapa 1 del piloto -- Prevención inactiva como candado digital.
let MODULO_PREVENCION_ACTIVO = true;
let CHART_DONUT = null;

(async () => {
  const usuario = await protegerDashboard('Jefe_Administrativo');
  if (!usuario) return;
  LISTAS = (await apiFetch('/public/listas.php')).listas;
  await cargarLista();
  await cargarEstadisticas();
  await cargarCierre();
  configurarTabs();
  iniciarEstadoVivo();
})();

document.getElementById('rango-estadisticas').addEventListener('change', cargarEstadisticas);

// v10.13 (pedido explícito del usuario): botón "🔄 Actualizar" en el
// header -- por si el proceso "parece pegado", refresca todo sin
// recargar la página ni salir del panel.
function actualizarTodo() {
  cargarLista();
  cargarEstadisticas();
  cargarCierre();
  cargarEstadoVivo();
  mostrarAlerta('alerta', 'Actualizado.', 'exito');
}

// --- v6: pestañas Pendientes / Contratados / Rechazados --------------------
let CONTRATADOS_TAB_CARGADO = false;
let RECHAZADOS_TAB_CARGADO = false;

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
  if (tab === 'contratados' && !CONTRATADOS_TAB_CARGADO) {
    CONTRATADOS_TAB_CARGADO = true;
    cargarContratadosTab();
  }
  if (tab === 'rechazados' && !RECHAZADOS_TAB_CARGADO) {
    RECHAZADOS_TAB_CARGADO = true;
    cargarRechazadosTab();
  }
}

async function cargarContratadosTab() {
  const tbody = document.getElementById('tbody-contratados');
  const vacio = document.getElementById('contratados-vacio');
  try {
    const data = await apiFetch('/admin_general/contratados_listar.php');
    if (!data.postulaciones.length) {
      tbody.innerHTML = '';
      vacio.classList.remove('hidden');
      return;
    }
    vacio.classList.add('hidden');
    tbody.innerHTML = data.postulaciones.map(p => `
      <tr class="border-t">
        <td class="px-4 py-3"><input type="checkbox" class="chk-carpeta" value="${p.id}"></td>
        <td class="px-4 py-3 font-mono">${celdaDocumento(p)}</td>
        <td class="px-4 py-3">${p.nombre_completo}</td>
        <td class="px-4 py-3">${p.nombre_cargo}</td>
        <td class="px-4 py-3">${p.exportado_at ? `<span class="text-xs text-gray-500">${new Date(p.exportado_at).toLocaleDateString('es-CL')}</span>` : '<span class="text-xs text-blue-700 bg-blue-50 px-1.5 py-0.5 rounded">pendiente</span>'}</td>
        <td class="px-4 py-3 text-right"><button class="text-xs font-semibold text-gray-700 underline" onclick="descargarCarpeta(${p.id})">📁 Descargar</button></td>
        <td class="px-4 py-3 text-right"><button class="text-xs font-semibold text-blue-600 underline" onclick="abrirDetalleTiempos(${p.id})">⏱ Ver tiempos</button></td>
      </tr>`).join('');
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

async function cargarRechazadosTab() {
  const tbody = document.getElementById('tbody-rechazados');
  const vacio = document.getElementById('rechazados-vacio');
  try {
    const data = await apiFetch('/admin_general/rechazados_listar.php');
    if (!data.postulaciones.length) {
      tbody.innerHTML = '';
      vacio.classList.remove('hidden');
      return;
    }
    vacio.classList.add('hidden');
    tbody.innerHTML = data.postulaciones.map(p => `
      <tr class="border-t">
        <td class="px-4 py-3 font-mono">${celdaDocumento(p)}</td>
        <td class="px-4 py-3">${p.nombre_completo}</td>
        <td class="px-4 py-3">${p.nombre_cargo}</td>
        <td class="px-4 py-3 text-gray-600">${p.motivo || '-'}</td>
        <td class="px-4 py-3 text-gray-500">${new Date(p.actualizado_at).toLocaleDateString('es-CL')}</td>
        <td class="px-4 py-3 text-right"><button class="text-xs font-semibold text-blue-600 underline" onclick="abrirDetalleTiempos(${p.id})">⏱ Ver tiempos</button></td>
      </tr>`).join('');
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

async function cargarLista() {
  const cont = document.getElementById('lista-postulaciones');
  const vacio = document.getElementById('vacio');
  try {
    const data = await apiFetch('/admin_general/listar.php');
    CIERRE_ACTIVO = data.cierre_remuneraciones_activo;
    MODULO_PREVENCION_ACTIVO = data.modulo_prevencion_activo;
    renderBadgeCierre();
    if (!data.postulaciones.length) {
      cont.innerHTML = '';
      vacio.classList.remove('hidden');
      return;
    }
    vacio.classList.add('hidden');
    cont.innerHTML = data.postulaciones.map(p => tarjeta(p)).join('');
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

// v5: mapa tipo -> detalle (observado/motivo/resubido), para poder
// mostrar el estado de cada documento junto a su link.
function detalleDoc(p, tipo) {
  return (p.documentos_detalle || []).find(d => d.tipo === tipo);
}

function enlacesDocumentos(p) {
  const tipos = ['cv', ...p.documentos];
  return tipos.map(tipo => {
    const url = `${API_BASE_URL}/documentos/ver.php?postulacion_id=${p.id}&tipo=${tipo}`;
    const detalle = detalleDoc(p, tipo);
    const puedeRechazar = tipo !== 'cv'; // el CV es de Etapa 1, no forma parte del rechazo de Etapa 2
    let badge = '';
    if (detalle && detalle.observado) {
      badge = `<span class="text-[10px] text-amber-700 bg-amber-100 px-1.5 py-0.5 rounded font-semibold" title="${detalle.motivo_rechazo}">⚠ observado</span>`;
    } else if (detalle && detalle.resubido) {
      badge = `<span class="text-[10px] text-blue-700 bg-blue-100 px-1.5 py-0.5 rounded font-semibold">↺ corregido</span>`;
    }
    return `
      <span class="inline-flex items-center gap-1">
        <a href="${url}" target="_blank" class="inline-block bg-blue-50 hover:bg-blue-100 text-blue-700 text-xs font-medium px-2.5 py-1 rounded-md">${ETIQUETAS_DOC[tipo] || tipo}</a>
        ${badge}
        ${puedeRechazar && !(detalle && detalle.observado) ? `<button title="Rechazar este documento" class="text-[11px] text-red-500 hover:text-red-700 px-1" onclick="rechazarDocumento(${p.id}, '${tipo}')">✕</button>` : ''}
      </span>`;
  }).join(' ');
}

async function rechazarDocumento(id, tipo) {
  const motivo = prompt(`¿Por qué rechazas "${ETIQUETAS_DOC[tipo] || tipo}"? El postulante recibirá este mensaje:`);
  if (motivo === null) return;
  if (!motivo.trim()) {
    mostrarAlerta('alerta', 'Debes indicar un motivo.');
    return;
  }
  try {
    await apiFetch('/admin_general/rechazar_documento.php', { method: 'POST', body: { postulacion_id: id, tipo_documento: tipo, motivo: motivo.trim() } });
    mostrarAlerta('alerta', 'Documento rechazado. Se le avisó al postulante.', 'exito');
    await cargarLista();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

async function noCoincideIdentidad(id) {
  const motivo = prompt('¿Por qué no coincide el RUT de la foto con el declarado?', 'El RUT de la foto no coincide con el declarado.');
  if (motivo === null) return;
  if (!motivo.trim()) {
    mostrarAlerta('alerta', 'Debes indicar un motivo.');
    return;
  }
  try {
    await apiFetch('/admin_general/rechazar_documento.php', { method: 'POST', body: { postulacion_id: id, tipo_documento: 'cedula_identidad', motivo: motivo.trim() } });
    mostrarAlerta('alerta', 'Identidad marcada como no coincidente. Se le avisó al postulante.', 'exito');
    await cargarLista();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

function opciones(lista, seleccionado = '') {
  return lista.map(v => `<option value="${v}" ${v === seleccionado ? 'selected' : ''}>${v}</option>`).join('');
}

function tarjeta(p) {
  return `
    <div class="bg-white rounded-xl shadow-sm p-5">
      <div class="flex items-start justify-between gap-4 flex-wrap">
        <div>
          <p class="font-bold text-gray-900">${p.nombre_completo}</p>
          <p class="text-sm text-gray-500 font-mono">${celdaDocumento(p)} · ${p.nombre_cargo}</p>
        </div>
        <div class="flex items-center gap-2 flex-wrap justify-end">
          <button class="text-xs font-semibold text-blue-600 underline" onclick="abrirDetalleTiempos(${p.id})">⏱ Ver tiempos</button>
          ${p.tiene_datos_jao ? '<span class="text-xs text-green-700 bg-green-50 px-2 py-1 rounded-md font-medium">✓ Nómina completa</span>' : ''}
          ${p.identidad_verificada
            ? `<span class="text-xs text-green-700 bg-green-50 px-2 py-1 rounded-md font-medium" title="Verificado por ${p.identidad_verificada_por_nombre || ''}">✓ Identidad verificada</span>`
            : (p.ingreso_faena_confirmado
                ? `<span class="inline-flex gap-1">
                     <button class="bg-amber-500 hover:bg-amber-600 text-white text-xs font-semibold px-3 py-2 rounded-lg" onclick="verificarIdentidad(${p.id})">Coincide</button>
                     <button class="bg-red-100 hover:bg-red-200 text-red-700 text-xs font-semibold px-3 py-2 rounded-lg" onclick="noCoincideIdentidad(${p.id})">No coincide</button>
                   </span>`
                : `<span class="text-xs text-gray-400 bg-gray-50 px-2 py-1 rounded-md font-medium" title="Portería aún no confirma que llegó a faena">⏳ Esperando ingreso a faena</span>`)}
          <button class="bg-gray-800 hover:bg-gray-900 text-white text-xs font-semibold px-3 py-2 rounded-lg" onclick="toggleFormJao(${p.id})">
            ${p.tiene_datos_jao ? 'Editar datos de nómina' : 'Completar datos de nómina'}
          </button>
          <button class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold px-3 py-2 rounded-lg disabled:opacity-40"
                  ${p.puede_firmar ? '' : 'disabled'} onclick="firmarContrato(${p.id})"
                  title="${MODULO_PREVENCION_ACTIVO ? 'Día 2, 8am: firma de contrato. Habilita a Bodega para entregar el EPP.' : 'Día 2, 8am: firma de contrato. Etapa 1 del piloto: esto cierra la contratación.'}">
            Firmar Contrato (día 2)
          </button>
        </div>
      </div>

      ${p.afp_alerta_jao ? `<p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2 mt-3">⚠ Esta persona declaró un régimen previsional antiguo ("${p.afp}"), no una AFP vigente. Verifica manualmente antes de finalizar.</p>` : ''}
      ${p.tiene_documento_observado ? `<p class="text-xs text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2 mt-3">⚠ Hay un documento observado esperando que el postulante lo corrija: no se puede firmar el contrato hasta entonces. El resto del proceso ya avanzado no se pierde.</p>` : ''}
      ${p.estado === 'Induccion_ok' && !p.puede_firmar && !p.tiene_documento_observado ? `<p class="text-xs text-blue-700 bg-blue-50 border border-blue-200 rounded-md px-3 py-2 mt-3">Ya hizo la inducción de seguridad con Prevención. Falta completar la nómina para poder firmar el contrato.</p>` : ''}
      ${p.estado === 'Aprobado_admin' && !p.puede_firmar && !p.tiene_documento_observado
        ? (MODULO_PREVENCION_ACTIVO
            ? `<p class="text-xs text-gray-500 bg-gray-50 border border-gray-200 rounded-md px-3 py-2 mt-3">Todavía en día 1 -- falta la inducción de seguridad con Prevención antes de poder firmar el contrato.</p>`
            : `<p class="text-xs text-gray-500 bg-gray-50 border border-gray-200 rounded-md px-3 py-2 mt-3">Todavía en día 1 -- falta verificar la identidad y/o completar la nómina antes de poder firmar el contrato.</p>`)
        : ''}
      ${!MODULO_PREVENCION_ACTIVO && p.puede_firmar ? `<p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2 mt-3">Etapa 1 del piloto: al firmar, esta acción cierra la contratación directamente.</p>` : ''}

      <div class="grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-1 text-sm mt-3 text-gray-700">
        <p><span class="text-gray-400">AFP:</span> ${p.afp}</p>
        <p><span class="text-gray-400">Salud:</span> ${p.isapre_fonasa}</p>
        <p><span class="text-gray-400">Banco:</span> ${p.banco} (${p.tipo_cuenta})</p>
        <p><span class="text-gray-400">N° cuenta:</span> ${p.numero_cuenta}</p>
        <p><span class="text-gray-400">Nacionalidad:</span> ${p.nacionalidad}</p>
        <p><span class="text-gray-400">Comuna:</span> ${p.comuna_etapa2}</p>
        <p><span class="text-gray-400">Calzado:</span> ${p.talla_calzado}</p>
        <p><span class="text-gray-400">Overol:</span> ${p.talla_overol}</p>
      </div>

      <div class="mt-3 flex flex-wrap gap-1.5">${enlacesDocumentos(p)}</div>

      <div id="form-jao-${p.id}" class="hidden mt-4 pt-4 border-t"></div>
    </div>`;
}

function toggleFormJao(id) {
  const cont = document.getElementById(`form-jao-${id}`);
  if (!cont.classList.contains('hidden')) {
    cont.classList.add('hidden');
    cont.innerHTML = '';
    return;
  }
  cont.classList.remove('hidden');
  cont.innerHTML = formularioJao(id);
}

function formularioJao(id) {
  const l = LISTAS;
  return `
    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Datos de nómina (Buk)</p>
    <div class="grid grid-cols-2 gap-3 text-sm">
      <div><label class="block text-xs text-gray-600 mb-1">Código de ficha</label>
        <input id="jao-codigo_ficha-${id}" class="w-full border border-gray-300 rounded-lg px-2 py-1.5"></div>
      <div><label class="block text-xs text-gray-600 mb-1">Fecha ingreso compañía</label>
        <input id="jao-ingreso_compania-${id}" type="date" class="w-full border border-gray-300 rounded-lg px-2 py-1.5"></div>

      <div><label class="block text-xs text-gray-600 mb-1">Forma de pago</label>
        <select id="jao-forma_pago-${id}" class="w-full border border-gray-300 rounded-lg px-2 py-1.5"><option value="">Selecciona</option>${opciones(l.forma_pago)}</select></div>
      <div><label class="block text-xs text-gray-600 mb-1">Régimen previsional</label>
        <select id="jao-regimen_previsional-${id}" class="w-full border border-gray-300 rounded-lg px-2 py-1.5"><option value="">Selecciona</option>${opciones(l.regimen_previsional)}</select></div>

      <div><label class="block text-xs text-gray-600 mb-1">AFC</label>
        <select id="jao-afc-${id}" class="w-full border border-gray-300 rounded-lg px-2 py-1.5"><option value="">Selecciona</option>${opciones(l.afc)}</select></div>
      <div><label class="block text-xs text-gray-600 mb-1">Jubilado</label>
        <select id="jao-jubilado-${id}" class="w-full border border-gray-300 rounded-lg px-2 py-1.5">${opciones(l.jubilado, 'No')}</select></div>

      <div class="col-span-2"><label class="block text-xs text-gray-600 mb-1">Escala de sueldo</label>
        <select id="jao-escala_sueldo-${id}" class="w-full border border-gray-300 rounded-lg px-2 py-1.5"><option value="">Selecciona</option>${opciones(l.escala_sueldo)}</select></div>

      <div><label class="block text-xs text-gray-600 mb-1">Proceso</label>
        <select id="jao-proceso-${id}" class="w-full border border-gray-300 rounded-lg px-2 py-1.5"><option value="">Selecciona</option>${opciones(l.proceso)}</select></div>
      <div><label class="block text-xs text-gray-600 mb-1">Tipo transfer</label>
        <select id="jao-tipo_transfer-${id}" class="w-full border border-gray-300 rounded-lg px-2 py-1.5"><option value="">Selecciona</option>${opciones(l.tipo_transfer)}</select></div>

      <div><label class="block text-xs text-gray-600 mb-1">Fecha reconocimiento <span class="text-gray-400">(opcional)</span></label>
        <input id="jao-fecha_reconocimiento-${id}" type="date" class="w-full border border-gray-300 rounded-lg px-2 py-1.5"></div>
      <div><label class="block text-xs text-gray-600 mb-1">Recomendado</label>
        <select id="jao-recomendado-${id}" class="w-full border border-gray-300 rounded-lg px-2 py-1.5">${opciones(l.recomendado, 'No')}</select></div>

      <div><label class="block text-xs text-gray-600 mb-1">Bono de obra <span class="text-gray-400">(opcional)</span></label>
        <input id="jao-bono_obra-${id}" class="w-full border border-gray-300 rounded-lg px-2 py-1.5"></div>
      <div><label class="block text-xs text-gray-600 mb-1">Retención judicial</label>
        <select id="jao-retencion_judicial-${id}" class="w-full border border-gray-300 rounded-lg px-2 py-1.5">${opciones(l.retencion_judicial)}</select></div>

      <div><label class="block text-xs text-gray-600 mb-1">Seguro Covid fecha inicio <span class="text-gray-400">(opcional)</span></label>
        <input id="jao-seguro_covid_fecha_inicio-${id}" type="date" class="w-full border border-gray-300 rounded-lg px-2 py-1.5"></div>
      <div></div>

      <div><label class="block text-xs text-gray-600 mb-1">Discapacidad</label>
        <select id="jao-discapacidad-${id}" class="w-full border border-gray-300 rounded-lg px-2 py-1.5">${opciones(l.discapacidad, 'No')}</select></div>
      <div><label class="block text-xs text-gray-600 mb-1">Fecha notif. discapacidad <span class="text-gray-400">(opcional)</span></label>
        <input id="jao-fecha_notif_discapacidad-${id}" type="date" class="w-full border border-gray-300 rounded-lg px-2 py-1.5"></div>

      <div><label class="block text-xs text-gray-600 mb-1">Invalidez</label>
        <select id="jao-invalidez-${id}" class="w-full border border-gray-300 rounded-lg px-2 py-1.5">${opciones(l.invalidez, 'No')}</select></div>
      <div><label class="block text-xs text-gray-600 mb-1">Fecha notif. invalidez <span class="text-gray-400">(opcional)</span></label>
        <input id="jao-fecha_notif_invalidez-${id}" type="date" class="w-full border border-gray-300 rounded-lg px-2 py-1.5"></div>
    </div>
    <button class="mt-4 bg-gray-900 hover:bg-gray-800 text-white text-sm font-semibold px-4 py-2 rounded-lg" onclick="guardarDatosJao(${id})">
      Guardar datos de nómina
    </button>`;
}

async function guardarDatosJao(id) {
  const campo = (nombre) => document.getElementById(`jao-${nombre}-${id}`).value;
  const body = {
    postulacion_id: id,
    codigo_ficha: campo('codigo_ficha'),
    ingreso_compania: campo('ingreso_compania'),
    forma_pago: campo('forma_pago'),
    regimen_previsional: campo('regimen_previsional'),
    afc: campo('afc'),
    jubilado: campo('jubilado'),
    escala_sueldo: campo('escala_sueldo'),
    proceso: campo('proceso'),
    tipo_transfer: campo('tipo_transfer'),
    fecha_reconocimiento: campo('fecha_reconocimiento'),
    recomendado: campo('recomendado'),
    bono_obra: campo('bono_obra'),
    retencion_judicial: campo('retencion_judicial'),
    seguro_covid_fecha_inicio: campo('seguro_covid_fecha_inicio'),
    discapacidad: campo('discapacidad'),
    fecha_notif_discapacidad: campo('fecha_notif_discapacidad'),
    invalidez: campo('invalidez'),
    fecha_notif_invalidez: campo('fecha_notif_invalidez'),
  };
  try {
    await apiFetch('/admin_general/guardar_datos_jao.php', { method: 'POST', body });
    mostrarAlerta('alerta', 'Datos de nómina guardados.', 'exito');
    await cargarLista();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

async function verificarIdentidad(id) {
  if (!confirm('Confirma que el RUT declarado por el postulante coincide con el de la foto/PDF de su cédula de identidad.')) return;
  try {
    await apiFetch('/admin_general/verificar_identidad.php', { method: 'POST', body: { postulacion_id: id } });
    mostrarAlerta('alerta', 'Identidad verificada.', 'exito');
    await cargarLista();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

async function firmarContrato(id) {
  const confirmacion = MODULO_PREVENCION_ACTIVO
    ? '¿Registrar la firma del contrato? Esto habilita a Bodega para entregar el EPP -- el cupo se descuenta recién cuando Bodega entrega.'
    : '¿Registrar la firma del contrato? Etapa 1 del piloto: esto cierra la contratación y descuenta el cupo de inmediato.';
  if (!confirm(confirmacion)) return;
  try {
    const data = await apiFetch('/admin_general/firmar_contrato.php', { method: 'POST', body: { postulacion_id: id } });
    mostrarAlerta('alerta', data.mensaje, 'exito');
    await cargarLista();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

function renderBadgeCierre() {
  const badge = document.getElementById('badge-cierre');
  if (CIERRE_ACTIVO) {
    badge.textContent = 'Activo: contrataciones bloqueadas';
    badge.className = 'px-2.5 py-1 rounded-full text-xs font-medium bg-amber-100 text-amber-800 shrink-0';
  } else {
    badge.textContent = 'Abierto';
    badge.className = 'px-2.5 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 shrink-0';
  }
}

// v10.14 (pedido explícito del usuario, item 15): carga el estado real
// (con fechas) apenas arranca el panel -- listar.php solo entrega el
// booleano, así que se pide aparte a cierre_remuneraciones.php.
async function cargarCierre() {
  try {
    const data = await apiFetch('/admin_general/cierre_remuneraciones.php');
    CIERRE_ACTIVO = data.activo;
    document.getElementById('cierre-desde').value = data.desde || '';
    document.getElementById('cierre-hasta').value = data.hasta || '';
    renderBadgeCierre();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

async function guardarCierre() {
  const desde = document.getElementById('cierre-desde').value;
  const hasta = document.getElementById('cierre-hasta').value;
  if (!desde || !hasta) {
    mostrarAlerta('alerta', 'Completa ambas fechas.');
    return;
  }
  if (!confirm(`¿Programar cierre de remuneraciones del ${desde} al ${hasta}? "Finalizar Contratación" quedará bloqueado en ese rango.`)) return;
  try {
    const data = await apiFetch('/admin_general/cierre_remuneraciones.php', { method: 'POST', body: { activo: false, desde, hasta } });
    CIERRE_ACTIVO = data.activo;
    renderBadgeCierre();
    mostrarAlerta('alerta', data.mensaje, 'exito');
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

async function limpiarCierre() {
  if (!confirm('¿Quitar el cierre de remuneraciones? Se volverá a poder finalizar contrataciones y solicitar cupos con normalidad.')) return;
  try {
    const data = await apiFetch('/admin_general/cierre_remuneraciones.php', { method: 'POST', body: { activo: false, desde: '', hasta: '' } });
    CIERRE_ACTIVO = data.activo;
    document.getElementById('cierre-desde').value = '';
    document.getElementById('cierre-hasta').value = '';
    renderBadgeCierre();
    mostrarAlerta('alerta', data.mensaje, 'exito');
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

async function cargarEstadisticas() {
  const dias = document.getElementById('rango-estadisticas').value;
  try {
    const data = await apiFetch(`/admin_general/estadisticas.php?dias=${dias}`);
    const { Contratado, Rechazado } = data.conteo;
    const ctx = document.getElementById('chart-donut');
    if (CHART_DONUT) CHART_DONUT.destroy();
    CHART_DONUT = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: ['Contratado', 'Rechazado'],
        datasets: [{ data: [Contratado, Rechazado], backgroundColor: ['#16a34a', '#dc2626'], borderWidth: 0 }],
      },
      options: { plugins: { legend: { display: false } }, cutout: '65%' },
    });
    document.getElementById('leyenda-chart').innerHTML = `
      <p><span class="inline-block w-2.5 h-2.5 rounded-full bg-green-600 mr-1.5"></span>Contratado: <strong>${Contratado}</strong></p>
      <p><span class="inline-block w-2.5 h-2.5 rounded-full bg-red-600 mr-1.5"></span>Rechazado: <strong>${Rechazado}</strong></p>`;
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

// --- v4: exportación selectiva -- el JAO elige con checkboxes ------------
let CONTRATADOS_EXPORT = [];

async function abrirModalExport() {
  const modal = document.getElementById('modal-export');
  const cont = document.getElementById('lista-export');
  cont.innerHTML = '<p class="text-sm text-gray-400 py-4 text-center">Cargando...</p>';
  modal.classList.remove('hidden');
  try {
    const data = await apiFetch('/admin_general/contratados_listar.php');
    CONTRATADOS_EXPORT = data.postulaciones;
    if (!CONTRATADOS_EXPORT.length) {
      cont.innerHTML = '<p class="text-sm text-gray-400 py-4 text-center">Todavía no hay nadie en estado Contratado.</p>';
      return;
    }
    cont.innerHTML = CONTRATADOS_EXPORT.map(p => `
      <label class="flex items-center gap-3 border border-gray-200 rounded-lg px-3 py-2 text-sm cursor-pointer hover:bg-gray-50">
        <input type="checkbox" class="chk-export" value="${p.id}" ${p.exportado_at ? '' : 'checked'}>
        <span class="flex-1">
          <span class="font-medium text-gray-900">${p.nombre_completo}</span>
          <span class="text-gray-400 font-mono text-xs"> · ${p.rut}</span>
          <span class="text-gray-500 text-xs"> · ${p.nombre_cargo}</span>
        </span>
        ${p.exportado_at ? '<span class="text-[10px] text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded">ya exportado</span>' : '<span class="text-[10px] text-blue-700 bg-blue-50 px-1.5 py-0.5 rounded">pendiente</span>'}
      </label>`).join('');
  } catch (err) {
    cont.innerHTML = `<p class="text-sm text-red-500 py-4 text-center">${err.message}</p>`;
  }
}

function cerrarModalExport() {
  document.getElementById('modal-export').classList.add('hidden');
}

function seleccionarTodosExport(valor) {
  document.querySelectorAll('.chk-export').forEach(chk => { chk.checked = valor; });
}

// v10.14 (pedido explícito del usuario, item 14): abrirDetalleTiempos()
// y sus funciones auxiliares se mudaron a dashboard-common.js -- ahora
// también las usan Admin de Contrato y Subgerente, no solo el JAO.

// --- v10: descargar carpeta(s) de documentos para subir a Buk ------------
function seleccionarTodosContratados(valor) {
  document.querySelectorAll('.chk-carpeta').forEach(chk => { chk.checked = valor; });
}

async function descargarZipCarpetas(ids, nombrePorDefecto) {
  try {
    const res = await apiFetch(`/admin_general/descargar_carpetas.php?ids=${ids.join(',')}`);
    const blob = await res.blob();
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = nombrePorDefecto;
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);
  } catch (err) {
    mostrarAlerta('alerta', err.message || 'No fue posible descargar la carpeta.');
  }
}

function descargarCarpeta(id) {
  descargarZipCarpetas([id], `carpeta_trabajador_${id}.zip`);
}

function descargarCarpetasSeleccionadas() {
  const ids = Array.from(document.querySelectorAll('.chk-carpeta:checked')).map(chk => chk.value);
  if (!ids.length) {
    mostrarAlerta('alerta', 'Selecciona al menos una persona para descargar su carpeta.');
    return;
  }
  descargarZipCarpetas(ids, `carpetas_trabajadores_${new Date().toISOString().slice(0, 10)}.zip`);
}

async function confirmarExport() {
  const ids = Array.from(document.querySelectorAll('.chk-export:checked')).map(chk => chk.value);
  if (!ids.length) {
    mostrarAlerta('alerta', 'Selecciona al menos una persona para exportar.');
    return;
  }
  try {
    const res = await apiFetch(`/admin_general/exportar_excel.php?ids=${ids.join(',')}`);
    const blob = await res.blob();
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'carga_masiva_buk.xlsx';
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);
    cerrarModalExport();
    mostrarAlerta('alerta', `Se exportaron ${ids.length} persona(s).`, 'exito');
  } catch (err) {
    mostrarAlerta('alerta', err.message || 'No fue posible exportar.');
  }
}
