(async () => {
  const usuario = await protegerDashboard('Jefe_Bodega');
  if (!usuario) return;
  await cargarLista();
  iniciarLiberados();
  iniciarEstadoVivo();
})();

// v10.13 (pedido explícito del usuario): botón "🔄 Actualizar" en el
// header -- por si el proceso "parece pegado", refresca sin recargar
// la página ni salir del panel.
function actualizarTodo() {
  cargarLista();
  cargarLiberados();
  mostrarAlerta('alerta', 'Actualizado.', 'exito');
}

function esc(valor) {
  // Escapa <, >, &, " y ' : seguro para el texto de la tarjeta Y para atributos HTML.
  return (valor == null ? '' : String(valor))
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

let LIBERADOS_PENDIENTES = 0;

async function cargarLista() {
  const tbody = document.getElementById('tbody-postulaciones');
  const vacio = document.getElementById('vacio');
  try {
    const data = await apiFetch('/bodega/listar.php');
    renderTallas(data.tallas_pendientes || []);
    renderNomina(data.liberados_pendientes || []);
    if (!data.postulaciones.length) {
      tbody.innerHTML = '';
      vacio.classList.remove('hidden');
      return;
    }
    vacio.classList.add('hidden');
    tbody.innerHTML = data.postulaciones.map(p => `
      <tr class="border-t">
        <td class="px-4 py-3 font-mono">${esc(p.rut)}</td>
        <td class="px-4 py-3">${esc(p.nombre_completo)}</td>
        <td class="px-4 py-3">${esc(p.nombre_cargo)}</td>
        <td class="px-4 py-3">${esc(p.talla_calzado)}</td>
        <td class="px-4 py-3">${esc(p.talla_overol)}</td>
        <td class="px-4 py-3 text-right">
          ${p.puede_entregar
            ? `<button class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold px-3 py-1.5 rounded-lg" onclick="marcarEpp(${Number(p.id)})">Entregar EPP</button>`
            : (p.espera === 'irl'
                ? `<span class="text-xs text-gray-400" title="Prevención todavía no registra la IRL de esta persona">⏳ Esperando IRL (Prevención)</span>`
                : `<span class="text-xs text-gray-400" title="El JAO todavía no firma el contrato de esta persona">⏳ Esperando firma de contrato (JAO)</span>`)}
        </td>
      </tr>`).join('');
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

async function marcarEpp(id) {
  if (!confirm('¿Entregar el kit de EPP? Esto cierra la contratación y deja a la persona liberada.')) return;
  try {
    const data = await apiFetch('/bodega/marcar_epp.php', { method: 'POST', body: { postulacion_id: id } });
    mostrarAlerta('alerta', data.mensaje, 'exito');
    await cargarLista();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

// --- v10.22: tallas de postulantes (un solo correo con tabla) -------------
let TALLAS_PENDIENTES = 0;
let TALLAS_LISTA = [];

// Si el panel quedó abierto desde antes de las 14:00, la tarjeta se pone
// roja sola al pasar la hora (sin esperar a que se recargue la lista).
setInterval(() => { if (TALLAS_LISTA.length) renderTallas(TALLAS_LISTA); }, 60000);

function renderTallas(personas) {
  const cont = document.getElementById('tallas-pendientes');
  TALLAS_LISTA = personas;
  TALLAS_PENDIENTES = personas.length;
  if (!personas.length) {
    // v10.29: aunque no haya tallas sin enviar, se puede bajar el Excel con
    // todo el personal que sigue en proceso.
    cont.className = 'bg-white rounded-xl shadow-sm p-4 mb-6 flex flex-wrap items-center justify-between gap-3';
    cont.innerHTML = `
      <p class="text-sm text-gray-600">No hay tallas pendientes de envío.</p>
      <button onclick="descargarTallasExcel(true)" class="border border-gray-300 hover:bg-gray-50 text-gray-800 text-sm font-semibold px-4 py-2 rounded-lg">
        📥 Descargar Excel de todo el personal en proceso
      </button>`;
    return;
  }
  // Pasadas las 14:00 ya no deberían entrar más postulantes: si todavía hay
  // tallas sin enviar, la tarjeta se pone roja para que no se olvide.
  const pasadas14 = new Date().getHours() >= 14;
  cont.className = pasadas14
    ? 'bg-red-50 border-2 border-red-300 rounded-xl shadow-sm p-5 mb-6'
    : 'bg-white rounded-xl shadow-sm p-5 mb-6';
  const aviso14 = pasadas14
    ? `<p class="text-sm font-bold text-red-700 mb-3">⏰ Ya pasaron las 14:00 y hay tallas sin enviar. Envía el correo ahora para que se prepare el kit de cada persona.</p>`
    : '';
  cont.innerHTML = `
    ${aviso14}
    <div class="flex items-start justify-between gap-4 flex-wrap">
      <div>
        <p class="text-sm font-bold text-gray-900">Tallas de postulantes
          <span class="ml-2 text-xs font-semibold bg-amber-100 text-amber-800 px-2 py-0.5 rounded-full">${personas.length} sin enviar</span>
        </p>
        <p class="text-xs text-gray-500 mt-1 max-w-xl">Un solo correo a Bodega con esta tabla, en vez de uno por postulante. Lo ideal es enviarlo a las 14:00, cuando ya no entran más postulantes; si alguien completa después, queda aquí y sale en un envío aparte.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button onclick="descargarTallasExcel()" class="border border-gray-300 hover:bg-gray-50 text-gray-800 text-sm font-semibold px-4 py-2 rounded-lg">
          📥 Descargar Excel
        </button>
        <button id="btn-enviar-tallas" onclick="enviarTallas()" class="bg-gray-900 hover:bg-gray-800 text-white text-sm font-semibold px-4 py-2 rounded-lg disabled:opacity-50">
          ✉ Enviar tallas por correo
        </button>
      </div>
    </div>
    <div class="overflow-x-auto mt-4">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500 text-left">
          <tr>
            <th class="px-3 py-2">Nombre</th>
            <th class="px-3 py-2">RUT</th>
            <th class="px-3 py-2">Cargo</th>
            <th class="px-3 py-2">Calzado</th>
            <th class="px-3 py-2">Overol</th>
            <th class="px-3 py-2">Completó</th>
          </tr>
        </thead>
        <tbody>
          ${personas.map(p => `
            <tr class="border-t">
              <td class="px-3 py-2 font-medium">${esc(p.nombre_completo)}</td>
              <td class="px-3 py-2 font-mono">${esc(p.rut)}</td>
              <td class="px-3 py-2">${esc(p.nombre_cargo)}</td>
              <td class="px-3 py-2">${esc(p.talla_calzado)}</td>
              <td class="px-3 py-2">${esc(p.talla_overol)}</td>
              <td class="px-3 py-2 text-gray-600">${horaCorta(p.completado_at)}</td>
            </tr>`).join('')}
        </tbody>
      </table>
    </div>`;
}

// v10.29 (pedido explícito del usuario): las tallas también se pueden bajar en
// Excel (con un resumen de cuántos kits hay de cada medida), sin depender del correo.
async function descargarTallasExcel(todos = false) {
  try {
    const res = await apiFetch('/bodega/exportar_tallas_excel.php' + (todos ? '?todos=1' : ''));
    const blob = await res.blob();
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'tallas_bodega.xlsx';
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);
  } catch (err) {
    mostrarAlerta('alerta', err.message || 'No fue posible descargar el Excel.');
  }
}

async function enviarTallas() {
  const n = TALLAS_PENDIENTES;
  if (!confirm(`¿Enviar por correo las tallas de ${n} ${n === 1 ? 'postulante' : 'postulantes'}?`)) return;
  const boton = document.getElementById('btn-enviar-tallas');
  boton.disabled = true;
  boton.textContent = 'Enviando...';
  try {
    const data = await apiFetch('/bodega/enviar_tallas.php', { method: 'POST', body: {} });
    mostrarAlerta('alerta', data.mensaje, 'exito');
    await cargarLista();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
    boton.disabled = false;
    boton.textContent = '✉ Enviar tallas por correo';
  }
}

// --- v10.21: nómina de liberados (un solo correo con tabla) ---------------
function horaCorta(fechaHoraSql) {
  if (!fechaHoraSql) return '-';
  const d = new Date(String(fechaHoraSql).replace(' ', 'T'));
  return isNaN(d) ? '-' : d.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' });
}

function renderNomina(liberados) {
  const cont = document.getElementById('nomina-liberados');
  LIBERADOS_PENDIENTES = liberados.length;
  if (!liberados.length) {
    cont.classList.add('hidden');
    cont.innerHTML = '';
    return;
  }
  cont.classList.remove('hidden');
  cont.innerHTML = `
    <div class="flex items-start justify-between gap-4 flex-wrap">
      <div>
        <p class="text-sm font-bold text-gray-900">Nómina de liberados
          <span class="ml-2 text-xs font-semibold bg-amber-100 text-amber-800 px-2 py-0.5 rounded-full">${liberados.length} sin avisar</span>
        </p>
        <p class="text-xs text-gray-500 mt-1 max-w-xl">Un solo correo con esta tabla para Jefe de Terreno, Capataces, Administrador de Contrato, JAO y Prevención. Envíala cuando termines de entregar el grupo.</p>
      </div>
      <button id="btn-enviar-nomina" onclick="enviarNomina()" class="bg-gray-900 hover:bg-gray-800 text-white text-sm font-semibold px-4 py-2 rounded-lg disabled:opacity-50">
        ✉ Enviar nómina por correo
      </button>
    </div>
    <div class="overflow-x-auto mt-4">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500 text-left">
          <tr>
            <th class="px-3 py-2">Nombre</th>
            <th class="px-3 py-2">RUT</th>
            <th class="px-3 py-2">Cargo</th>
            <th class="px-3 py-2">Liberado</th>
            <th class="px-3 py-2">Seleccionado por</th>
          </tr>
        </thead>
        <tbody>
          ${liberados.map(l => `
            <tr class="border-t">
              <td class="px-3 py-2 font-medium">${esc(l.nombre_completo)}</td>
              <td class="px-3 py-2 font-mono">${esc(l.rut)}</td>
              <td class="px-3 py-2">${esc(l.nombre_cargo)}</td>
              <td class="px-3 py-2">${horaCorta(l.liberado_at)}</td>
              <td class="px-3 py-2 text-gray-600">${esc(l.seleccionado_por || '-')}</td>
            </tr>`).join('')}
        </tbody>
      </table>
    </div>`;
}

async function enviarNomina() {
  const n = LIBERADOS_PENDIENTES;
  if (!confirm(`¿Enviar por correo la nómina con ${n} ${n === 1 ? 'trabajador liberado' : 'trabajadores liberados'}?`)) return;
  const boton = document.getElementById('btn-enviar-nomina');
  boton.disabled = true;
  boton.textContent = 'Enviando...';
  try {
    const data = await apiFetch('/bodega/enviar_nomina.php', { method: 'POST', body: {} });
    mostrarAlerta('alerta', data.mensaje, 'exito');
    await cargarLista();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
    boton.disabled = false;
    boton.textContent = '✉ Enviar nómina por correo';
  }
}

// --- v10.39 (pedido del usuario): liberados por día, listos para ingresar mañana ---
// "Liberado" = Prevención ya hizo el check del Día 0. Se ve por día (o rango), se
// filtra en pantalla y se baja en Excel con los mismos filtros (el Excel además trae
// filtros de columna propios).
let LIB = { filas: [], desde: '', hasta: '' };

function hoyIso() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
function sumarDias(iso, n) {
  const [y, m, d] = iso.split('-').map(Number);
  const f = new Date(y, m - 1, d + n);
  return `${f.getFullYear()}-${String(f.getMonth() + 1).padStart(2, '0')}-${String(f.getDate()).padStart(2, '0')}`;
}
function fechaCorta(iso) {
  if (!iso) return '-';
  const [y, m, d] = iso.slice(0, 10).split('-');
  return `${d}-${m}-${y}`;
}

function iniciarLiberados() {
  const cont = document.getElementById('liberados-dia');
  const hoy = hoyIso();
  cont.innerHTML = `
    <div class="flex items-start justify-between gap-4 flex-wrap">
      <div>
        <p class="text-sm font-bold text-gray-900">Liberados para ingresar <span id="lib-contador" class="ml-2 text-xs font-semibold bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded-full"></span></p>
        <p class="text-xs text-gray-500 mt-1 max-w-xl">Personas con el check de Prevención hecho (Día 0): quedan listas para entrar mañana. Elige el día, filtra y descarga el Excel.</p>
      </div>
      <button onclick="descargarLiberadosExcel()" class="bg-gray-900 hover:bg-gray-800 text-white text-sm font-semibold px-4 py-2 rounded-lg">📥 Descargar Excel</button>
    </div>
    <div class="flex flex-wrap items-end gap-3 mt-4 text-sm">
      <label class="flex flex-col text-xs text-gray-500">Desde
        <input id="lib-desde" type="date" value="${hoy}" class="border rounded-lg px-2 py-1.5 text-sm text-gray-900" onchange="cargarLiberados()"></label>
      <label class="flex flex-col text-xs text-gray-500">Hasta
        <input id="lib-hasta" type="date" value="${hoy}" class="border rounded-lg px-2 py-1.5 text-sm text-gray-900" onchange="cargarLiberados()"></label>
      <div class="flex gap-1.5">
        <button class="border border-gray-300 hover:bg-gray-50 text-xs font-semibold px-3 py-2 rounded-lg" onclick="liberadosRapido(0)">Hoy</button>
        <button class="border border-gray-300 hover:bg-gray-50 text-xs font-semibold px-3 py-2 rounded-lg" onclick="liberadosRapido(1)">Ayer</button>
        <button class="border border-gray-300 hover:bg-gray-50 text-xs font-semibold px-3 py-2 rounded-lg" onclick="liberadosRapido(7)">Últimos 7 días</button>
      </div>
      <label class="flex flex-col text-xs text-gray-500">Buscar
        <input id="lib-q" type="search" placeholder="Nombre o RUT" class="border rounded-lg px-2 py-1.5 text-sm text-gray-900 w-40" oninput="renderLiberados()"></label>
      <label class="flex flex-col text-xs text-gray-500">Cargo
        <select id="lib-cargo" class="border rounded-lg px-2 py-1.5 text-sm text-gray-900" onchange="renderLiberados()"><option value="">Todos</option></select></label>
      <label class="flex flex-col text-xs text-gray-500">Capataz
        <select id="lib-capataz" class="border rounded-lg px-2 py-1.5 text-sm text-gray-900" onchange="renderLiberados()"><option value="">Todos</option></select></label>
      <label class="flex flex-col text-xs text-gray-500">Estado
        <select id="lib-estado" class="border rounded-lg px-2 py-1.5 text-sm text-gray-900" onchange="renderLiberados()">
          <option value="">Todos</option>
          <option value="Induccion_ok">Ingresa mañana</option>
          <option value="EPP_listo">EPP entregado</option>
          <option value="Contratado">Contratado</option>
          <option value="Proceso_completo">En terreno</option>
        </select></label>
    </div>
    <div class="overflow-x-auto mt-4">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500 text-left">
          <tr>
            <th class="px-3 py-2">Nombre</th><th class="px-3 py-2">RUT</th><th class="px-3 py-2">Cargo</th>
            <th class="px-3 py-2">Capataz</th><th class="px-3 py-2">Frente</th><th class="px-3 py-2">Prof. de Terreno</th>
            <th class="px-3 py-2">Liberado</th><th class="px-3 py-2">Ingresa</th>
            <th class="px-3 py-2">Calzado</th><th class="px-3 py-2">Overol</th><th class="px-3 py-2">Estado</th>
          </tr>
        </thead>
        <tbody id="lib-tbody"></tbody>
      </table>
      <p id="lib-vacio" class="text-center text-gray-400 text-sm py-6 hidden">No hay personas liberadas en ese período.</p>
    </div>`;
  cargarLiberados();
}

function liberadosRapido(diasAtras) {
  const hoy = hoyIso();
  document.getElementById('lib-hasta').value = diasAtras === 1 ? sumarDias(hoy, -1) : hoy;
  document.getElementById('lib-desde').value = diasAtras === 7 ? sumarDias(hoy, -6) : sumarDias(hoy, -diasAtras);
  cargarLiberados();
}

async function cargarLiberados() {
  const desde = document.getElementById('lib-desde').value;
  const hasta = document.getElementById('lib-hasta').value;
  try {
    const data = await apiFetch(`/bodega/liberados_dia.php?desde=${encodeURIComponent(desde)}&hasta=${encodeURIComponent(hasta)}`);
    LIB = { filas: data.liberados || [], desde: data.desde, hasta: data.hasta };
    // Las listas de filtro se arman con lo que realmente hay en el período.
    const opciones = (id, valores) => {
      const sel = document.getElementById(id);
      const actual = sel.value;
      sel.innerHTML = '<option value="">Todos</option>' + [...new Set(valores.filter(Boolean))].sort((a, b) => a.localeCompare(b, 'es'))
        .map(v => `<option value="${esc(v)}">${esc(v)}</option>`).join('');
      sel.value = [...sel.options].some(o => o.value === actual) ? actual : '';
    };
    opciones('lib-cargo', LIB.filas.map(f => f.nombre_cargo));
    opciones('lib-capataz', LIB.filas.map(f => f.capataz));
    renderLiberados();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

function liberadosFiltrados() {
  const q = document.getElementById('lib-q').value.trim().toLowerCase();
  const cargo = document.getElementById('lib-cargo').value;
  const capataz = document.getElementById('lib-capataz').value;
  const estado = document.getElementById('lib-estado').value;
  return LIB.filas.filter(f =>
    (!cargo || f.nombre_cargo === cargo) && (!capataz || f.capataz === capataz) && (!estado || f.estado === estado) &&
    (!q || `${f.nombre_completo} ${f.rut}`.toLowerCase().includes(q)));
}

function renderLiberados() {
  const filas = liberadosFiltrados();
  document.getElementById('lib-contador').textContent =
    filas.length === LIB.filas.length ? `${filas.length}` : `${filas.length} de ${LIB.filas.length}`;
  document.getElementById('lib-vacio').classList.toggle('hidden', filas.length > 0);
  const color = { Induccion_ok: 'bg-amber-100 text-amber-800', EPP_listo: 'bg-sky-100 text-sky-800', Contratado: 'bg-emerald-100 text-emerald-800', Proceso_completo: 'bg-gray-100 text-gray-700' };
  document.getElementById('lib-tbody').innerHTML = filas.map(f => `
    <tr class="border-t">
      <td class="px-3 py-2 font-medium">${esc(f.nombre_completo)}</td>
      <td class="px-3 py-2 font-mono">${esc(f.rut)}</td>
      <td class="px-3 py-2">${esc(f.nombre_cargo)}</td>
      <td class="px-3 py-2">${esc(f.capataz || '-')}</td>
      <td class="px-3 py-2">${esc(f.frente || '-')}</td>
      <td class="px-3 py-2 text-gray-600">${esc(f.profesional_terreno || '-')}</td>
      <td class="px-3 py-2 text-gray-600">${fechaCorta(f.dia_liberacion)}</td>
      <td class="px-3 py-2 text-gray-600">${fechaCorta(f.fecha_ingreso)}</td>
      <td class="px-3 py-2">${esc(f.talla_calzado || '-')}</td>
      <td class="px-3 py-2">${esc(f.talla_overol || '-')}</td>
      <td class="px-3 py-2"><span class="text-xs font-semibold px-2 py-0.5 rounded-full ${color[f.estado] || 'bg-gray-100 text-gray-700'}">${esc(f.estado_texto)}</span></td>
    </tr>`).join('');
}

async function descargarLiberadosExcel() {
  const p = new URLSearchParams({
    desde: document.getElementById('lib-desde').value, hasta: document.getElementById('lib-hasta').value,
    cargo: document.getElementById('lib-cargo').value, capataz: document.getElementById('lib-capataz').value,
    estado: document.getElementById('lib-estado').value, q: document.getElementById('lib-q').value.trim(),
  });
  try {
    const res = await apiFetch('/bodega/exportar_liberados_excel.php?' + p.toString());
    const blob = await res.blob();
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `liberados_${p.get('desde')}${p.get('hasta') !== p.get('desde') ? '_a_' + p.get('hasta') : ''}.xlsx`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);
  } catch (err) {
    mostrarAlerta('alerta', err.message || 'No fue posible descargar el Excel.');
  }
}
