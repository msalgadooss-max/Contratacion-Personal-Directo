/**
 * v3.4 - "Estado en vivo": widget compartido por los dashboards internos
 * (Terreno, Admin_Contrato, JAO, Gerencia, y desde v10.10 Capataz). Se
 * actualiza solo cada 10 segundos, estilo rastreo de pedido -- nombre +
 * cargo + en qué fase está. No requiere lógica distinta por rol: el
 * backend ya entrega texto listo para mostrar y nunca datos sensibles.
 *
 * v4: cada tarjeta es clickeable -- abre un panel con una línea de
 * progreso "inicio -> meta" (mismos pasos que ve el propio postulante
 * en su seguimiento), calculada por el backend en estado_vivo.php.
 *
 * v10.10: rediseño del panel de detalle (pedido explícito del usuario:
 * "cada paso se ve muy junto y las letras se cruzan, extiéndelo... y
 * agrega inicio y término al final, tipo vista atlética") -- panel más
 * ancho, más espacio entre pasos, y banderas de Inicio/Meta a los
 * costados. También agrega el botón "Deshacer selección" del Capataz
 * (ver ESTADO_VIVO_MOSTRAR_DESHACER más abajo).
 *
 * Uso: <div id="estado-vivo"></div> en el HTML, y llamar
 * iniciarEstadoVivo() una vez cargada la página. Si el dashboard debe
 * ofrecer "Deshacer selección" (hoy solo Capataz), definir
 * `ESTADO_VIVO_MOSTRAR_DESHACER = true` ANTES de llamar a
 * iniciarEstadoVivo().
 */
let ESTADO_VIVO_ULTIMO = [];
if (typeof ESTADO_VIVO_MOSTRAR_DESHACER === 'undefined') {
  var ESTADO_VIVO_MOSTRAR_DESHACER = false;
}

function iniciarEstadoVivo() {
  const cont = document.getElementById('estado-vivo');
  if (!cont) return;

  cont.innerHTML = `
    <div class="bg-white rounded-xl shadow-sm p-4 mb-6">
      <div class="flex items-center gap-2 mb-3">
        <span class="relative flex h-2.5 w-2.5">
          <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
          <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-green-500"></span>
        </span>
        <p class="text-sm font-semibold text-gray-800">Estado en vivo</p>
        <span class="text-xs text-gray-400">se actualiza solo · toca a alguien para ver su avance</span>
      </div>
      <div id="estado-vivo-lista" class="flex gap-3 overflow-x-auto pb-1"></div>
    </div>
    <div id="estado-vivo-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" onclick="if(event.target===this) cerrarDetalleTrabajador()">
      <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full p-6 relative">
        <button onclick="cerrarDetalleTrabajador()" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600 text-xl leading-none">✕</button>
        <div id="estado-vivo-modal-contenido"></div>
      </div>
    </div>`;

  cargarEstadoVivo();
  setInterval(cargarEstadoVivo, 10000);
}

async function cargarEstadoVivo() {
  const lista = document.getElementById('estado-vivo-lista');
  if (!lista) return;
  try {
    const data = await apiFetch('/estado_vivo.php');
    ESTADO_VIVO_ULTIMO = data.trabajadores;
    if (!data.trabajadores.length) {
      lista.innerHTML = '<p class="text-sm text-gray-400 py-2">No hay trabajadores en proceso ahora mismo.</p>';
      return;
    }
    lista.innerHTML = data.trabajadores.map(t => `
      <div class="shrink-0 w-56 border rounded-lg px-3 py-2.5 cursor-pointer transition hover:shadow-md hover:-translate-y-0.5 ${t.contratado ? 'bg-green-50 border-green-200' : (t.pendiente_de_ti ? 'bg-amber-50 border-amber-300' : 'bg-gray-50 border-gray-200')}"
           onclick="abrirDetalleTrabajador(${t.id})">
        <p class="text-sm font-semibold text-gray-900 truncate">${esc(t.nombre_completo)}</p>
        <p class="text-xs text-gray-500 mb-1.5">${esc(t.nombre_cargo)}</p>
        <p class="text-xs font-medium ${t.contratado ? 'text-green-700' : 'text-blue-700'}">${esc(t.fase)}</p>
        ${t.pendiente_de_ti ? '<p class="text-[11px] font-semibold text-amber-700 mt-1">👉 Pendiente en tu bandeja</p>' : ''}
      </div>`).join('');

    // Si el modal de detalle está abierto para alguien que sigue en la
    // lista, refresca su contenido con el dato nuevo (sin cerrarlo).
    // v10.10: si el usuario había hecho scroll en el track (muchos
    // pasos), se guarda y se restaura -- si no, el refresco automático
    // de cada 10s lo devolvía siempre al principio.
    const modal = document.getElementById('estado-vivo-modal');
    if (modal && !modal.classList.contains('hidden')) {
      const idAbierto = Number(modal.dataset.idAbierto);
      const actualizado = data.trabajadores.find(t => t.id === idAbierto);
      if (actualizado) {
        const trackViejo = document.querySelector('#estado-vivo-modal-contenido .overflow-x-auto');
        const scrollPrevio = trackViejo ? trackViejo.scrollLeft : 0;
        renderDetalleTrabajador(actualizado);
        const trackNuevo = document.querySelector('#estado-vivo-modal-contenido .overflow-x-auto');
        if (trackNuevo) trackNuevo.scrollLeft = scrollPrevio;
      }
    }

    // v6.5: hook opcional para dashboards que además muestran esta misma
    // data en una tabla completa (ej. pestaña "Estado del proceso" de
    // Admin_Contrato) -- se define solo ahí, así este archivo compartido
    // no depende de ningún dashboard en particular.
    if (typeof onEstadoVivoActualizado === 'function') onEstadoVivoActualizado();
  } catch (err) {
    // silencioso: no queremos que un widget secundario tape el resto del dashboard
    lista.innerHTML = '<p class="text-sm text-gray-400 py-2">No se pudo cargar el estado en vivo.</p>';
  }
}

function abrirDetalleTrabajador(id) {
  const trabajador = ESTADO_VIVO_ULTIMO.find(t => t.id === id);
  if (!trabajador) return;
  const modal = document.getElementById('estado-vivo-modal');
  modal.dataset.idAbierto = id;
  modal.classList.remove('hidden');
  renderDetalleTrabajador(trabajador);
}

function cerrarDetalleTrabajador() {
  const modal = document.getElementById('estado-vivo-modal');
  if (modal) { modal.classList.add('hidden'); delete modal.dataset.idAbierto; }
}

function renderDetalleTrabajador(t) {
  const cont = document.getElementById('estado-vivo-modal-contenido');
  if (!cont) return;

  const pasos = t.pasos || [];
  const completados = pasos.filter(p => p.completado).length;
  const idxActual = Math.min(completados, pasos.length - 1);

  // v10.10: cada paso tiene un ANCHO FIJO (no flex:1) y el track completo
  // es un solo scroll horizontal -- así, con muchos pasos, nunca se
  // aprietan ni se cruzan las letras (antes se repartían el ancho fijo
  // del modal entre todos los pasos, y con 8-10 pasos el texto quedaba
  // ilegible). El conector entre cada par de pasos se dibuja aparte
  // (segmento por segmento), en vez de una sola barra de ancho variable
  // calculada en %, que no funciona bien dentro de un contenedor con
  // scroll propio.
  const ANCHO_PASO = 108;
  const puntos = pasos.map((p, idx) => {
    const esActual = idx === idxActual && !t.contratado;
    let circulo;
    if (p.completado && !esActual) {
      circulo = `<div class="w-8 h-8 rounded-full bg-green-500 text-white flex items-center justify-center text-sm font-bold shadow-sm shrink-0">✓</div>`;
    } else if (esActual) {
      circulo = `<div class="w-8 h-8 rounded-full bg-blue-600 text-white flex items-center justify-center text-xs font-bold shadow-md ring-4 ring-blue-100 animate-pulse shrink-0">●</div>`;
    } else {
      circulo = `<div class="w-8 h-8 rounded-full bg-gray-200 border border-gray-300 shrink-0"></div>`;
    }
    // El segmento conector a la IZQUIERDA de este paso está "hecho" si
    // el paso anterior ya se completó (o si este es el primero: no hay
    // conector, ese lugar lo ocupa la bandera de Inicio).
    const conector = idx === 0 ? '' : `
      <div class="flex items-center shrink-0" style="width:28px">
        <div class="h-1 w-full rounded-full ${pasos[idx - 1].completado ? 'bg-green-500' : 'bg-gray-200'}"></div>
      </div>`;
    return conector + `
      <div class="flex flex-col items-center text-center gap-2 shrink-0" style="width:${ANCHO_PASO}px">
        ${circulo}
        <p class="text-[11px] leading-tight ${p.completado || esActual ? 'text-gray-800 font-medium' : 'text-gray-400'}">${esc(p.etiqueta)}</p>
      </div>`;
  }).join('');

  const conectorFinal = `
    <div class="flex items-center shrink-0" style="width:28px">
      <div class="h-1 w-full rounded-full ${pasos.length && pasos[pasos.length - 1].completado ? 'bg-green-500' : 'bg-gray-200'}"></div>
    </div>`;

  cont.innerHTML = `
    <div class="mb-5">
      <p class="text-xs uppercase tracking-wide text-gray-400 font-semibold mb-1">Avance del proceso</p>
      <h3 class="text-lg font-bold text-gray-900">${esc(t.nombre_completo)}</h3>
      <p class="text-sm text-gray-500">${esc(t.nombre_cargo)}</p>
    </div>

    <div class="overflow-x-auto pb-2 -mx-2 px-2">
      <div class="flex items-start" style="width:max-content">
        <div class="flex flex-col items-center text-center gap-2 shrink-0" style="width:56px">
          <div class="w-8 h-8 rounded-full bg-gray-800 text-white flex items-center justify-center text-sm shrink-0">🚩</div>
          <p class="text-[11px] leading-tight text-gray-500 font-semibold">Inicio</p>
        </div>
        ${puntos}
        ${conectorFinal}
        <div class="flex flex-col items-center text-center gap-2 shrink-0" style="width:56px">
          <div class="w-8 h-8 rounded-full ${t.contratado ? 'bg-green-500' : 'bg-gray-200 border border-gray-300'} flex items-center justify-center text-sm shrink-0">🏁</div>
          <p class="text-[11px] leading-tight ${t.contratado ? 'text-green-700' : 'text-gray-400'} font-semibold">Meta</p>
        </div>
      </div>
    </div>

    <div class="mt-4 rounded-xl px-4 py-3 text-sm font-semibold text-center ${t.contratado ? 'bg-green-50 text-green-700' : 'bg-blue-50 text-blue-700'}">
      ${esc(t.fase)}
    </div>
    ${t.pendiente_de_ti ? '<div class="mt-3 rounded-xl px-4 py-3 text-sm font-semibold text-center bg-amber-50 text-amber-800 border border-amber-200">👉 El postulante está pendiente en tu bandeja</div>' : ''}
    ${ESTADO_VIVO_MOSTRAR_DESHACER && t.estado === 'Pre_aprobado_terreno' ? `
    <div class="mt-4 pt-4 border-t border-gray-100">
      <button id="btn-deshacer-seleccion" onclick="deshacerSeleccion(${t.id})" class="w-full bg-white border border-red-200 hover:bg-red-50 text-red-600 text-sm font-semibold rounded-lg py-2.5">
        ↩ Deshacer selección (me equivoqué de cargo)
      </button>
    </div>` : ''}`;
}

// --- v10.10: Capataz puede deshacer una selección reciente si se
// equivocó de cargo -- ver terreno/deshacer_seleccion.php. Vive acá
// (no en dashboard-capataz.js) porque se dispara desde este mismo
// modal de detalle.
async function deshacerSeleccion(id) {
  const btn = document.getElementById('btn-deshacer-seleccion');
  if (btn) { btn.disabled = true; btn.textContent = 'Deshaciendo...'; }
  try {
    const data = await apiFetch('/terreno/deshacer_seleccion.php', { method: 'POST', body: { postulacion_id: id } });
    mostrarAlerta('alerta', data.mensaje, 'exito');
    cerrarDetalleTrabajador();
    await cargarEstadoVivo();
    // v10.10: hook opcional -- dashboard-capataz.js lo define para
    // refrescar también su propia lista de selección en terreno.
    if (typeof onDeshacerSeleccion === 'function') onDeshacerSeleccion();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
    if (btn) { btn.disabled = false; btn.textContent = '↩ Deshacer selección (me equivoqué de cargo)'; }
  }
}
