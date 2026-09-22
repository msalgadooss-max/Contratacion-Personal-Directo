/**
 * v6.9 - Panel de Capataz: la selección rápida en portería que propuso
 * Ricardo (reunión 28-ago). Reutiliza los mismos endpoints de Terreno
 * (/terreno/listar.php, aprobar.php, rechazar.php, ver_cv.php), pero
 * con una pantalla pensada para hacerse de pie, rápido: RUT grande
 * para comparar con la cédula, y dos botones grandes.
 *
 * v10.5 (Mejorar APP, punto 2): el postulante ya no elige cargo al
 * postular -- el Capataz lo asigna acá mismo, justo al seleccionarlo.
 *
 * v10.6 (Mejorar APP, puntos 3 y 4): idea de Ricardo -- en vez de un
 * select, el Capataz ARRASTRA la tarjeta del postulante hasta la caja
 * (con casquito) del cargo que le corresponde. Un solo gesto hace la
 * selección y la asignación de cargo. Es arrastre real (Pointer Events,
 * funciona con mouse y con el dedo), no un "tocar para elegir"
 * simplificado -- confirmado explícitamente con el usuario. Mientras
 * hay un arrastre en curso se pausa el refresco automático (ver
 * ARRASTRANDO más abajo) para no destruirle la tarjeta bajo el dedo.
 *
 * v10.14 (pedido explícito del usuario, lista post-prueba):
 *   - item 5: ya no dice "Aprobado por Jefe de Terreno" -- ese primer
 *     filtro se eliminó, el Capataz ve directamente todo lo que llega
 *     por el QR.
 *   - item 9: se retira la pestaña "Recepción" -- ahora es un botón
 *     dentro de la nueva pestaña "Personal Contratado".
 *   - item 11: antes de poder arrastrar, hay que marcar "Trae sus
 *     documentos" -- el asa de arrastre queda deshabilitada hasta
 *     entonces.
 */
let CARGOS_CON_CUPO = [];
let ARRASTRANDO = false;
// v10.10: habilita el botón "Deshacer selección" dentro del modal de
// detalle de "Estado en vivo" (ver estado-vivo.js) -- solo en este
// dashboard, para el error típico de "arrastré a la caja equivocada".
ESTADO_VIVO_MOSTRAR_DESHACER = true;

(async () => {
  const usuario = await protegerDashboard('Capataz');
  if (!usuario) return;
  await cargarCargosConCupo();
  await cargarLista();
  configurarTabs();
  iniciarEstadoVivo();
  setInterval(() => {
    if (ARRASTRANDO) return;
    if (TAB_ACTIVA === 'seleccion') { cargarCargosConCupo(); cargarLista(); }
    else cargarContratados();
  }, 15000);
})();

// v10.10: cuando se deshace una selección (ver estado-vivo.js), refresca
// también los cupos y la lista propia de este dashboard -- la
// postulación vuelve a aparecer ahí para elegir el cargo correcto.
function onDeshacerSeleccion() {
  cargarCargosConCupo();
  if (TAB_ACTIVA === 'seleccion') cargarLista();
}

// v10.13 (pedido explícito del usuario): botón "🔄 Actualizar" en el
// header -- por si el proceso "parece pegado", refresca todo sin
// recargar la página ni salir del panel.
function actualizarTodo() {
  cargarCargosConCupo();
  cargarLista();
  cargarContratados();
  cargarEstadoVivo();
  mostrarAlerta('alerta', 'Actualizado.', 'exito');
}

async function cargarCargosConCupo() {
  try {
    const data = await apiFetch('/public/cargos_disponibles.php');
    CARGOS_CON_CUPO = data.cargos.filter(c => c.tiene_cupo);
    renderZonasCargo();
  } catch (e) {
    CARGOS_CON_CUPO = [];
    renderZonasCargo();
  }
}

function renderZonasCargo() {
  const cont = document.getElementById('zonas-cargo');
  if (!cont) return;
  if (!CARGOS_CON_CUPO.length) {
    cont.innerHTML = '<p class="col-span-full text-sm text-gray-400 bg-white rounded-xl shadow-sm px-4 py-6 text-center">No hay cargos con cupo abierto ahora mismo. Pide a Jefe de Terreno que abra cupos.</p>';
    return;
  }
  cont.innerHTML = CARGOS_CON_CUPO.map(c => `
    <div class="cargo-zone rounded-xl border-2 border-dashed border-orange-200 bg-orange-50/60 p-4 flex flex-col items-center justify-center gap-1 text-center" data-cargo-id="${c.id}">
      <span class="text-3xl leading-none">⛑️</span>
      <p class="text-sm font-bold text-gray-800 leading-tight">${c.nombre_cargo}</p>
      <p class="text-xs text-orange-700 font-semibold">${c.cupos_disponibles} cupo(s)</p>
    </div>`).join('');
}

// --- v10.14: pestañas (Selección en terreno / Personal Contratado) --------
let TAB_ACTIVA = 'seleccion';
let CONTRATADOS_CARGADO = false;

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
  if (tab === 'seleccion') cargarLista();
  if (tab === 'contratados') cargarContratados();
}

async function cargarLista() {
  const cont = document.getElementById('lista-postulantes');
  const vacio = document.getElementById('vacio');
  try {
    const data = await apiFetch('/terreno/listar.php');
    if (!data.postulaciones.length) {
      cont.innerHTML = '';
      vacio.classList.remove('hidden');
      return;
    }
    vacio.classList.add('hidden');
    cont.innerHTML = data.postulaciones.map(p => `
      <div class="postulante-card bg-white rounded-xl shadow-sm overflow-hidden" data-postulacion-id="${p.id}">
        <label class="flex items-center gap-2 px-4 py-2.5 bg-amber-50 border-b border-amber-100 text-sm font-semibold text-amber-800 cursor-pointer">
          <input type="checkbox" class="check-documentos w-4 h-4 accent-amber-600" onchange="toggleArrastre(${p.id}, this.checked)">
          Trae sus documentos
        </label>
        <div class="drag-handle flex items-center justify-center gap-2 bg-gray-100 text-gray-300 text-xs font-bold tracking-wide py-2 border-b border-gray-100 select-none" data-habilitado="false" style="pointer-events:none">
          <span class="text-base leading-none">⠿⠿⠿</span> MARCA DOCUMENTOS PARA ARRASTRAR
        </div>
        <div class="p-5">
          <div class="flex items-start justify-between gap-4 flex-wrap">
            <div>
              <p class="text-2xl font-mono font-bold text-gray-900 tracking-wide">${celdaDocumento(p)}</p>
              <p class="nombre-postulante text-base font-semibold text-gray-800">${p.nombre_completo}</p>
              <p class="text-sm text-gray-500">${p.comuna}</p>
            </div>
            ${p.tiene_cv
              ? `<a href="${API_BASE_URL}/terreno/ver_cv.php?postulacion_id=${p.id}" target="_blank" class="text-blue-600 font-medium underline text-sm">Ver CV</a>`
              : (p.experiencia_sin_cv
                  ? `<span class="text-xs text-amber-700 bg-amber-50 px-2 py-1 rounded cursor-help" title="${p.experiencia_sin_cv.replace(/"/g, '&quot;')}">Sin CV (ver experiencia) ⓘ</span>`
                  : '<span class="text-gray-400 text-xs">Sin CV</span>')}
          </div>
          <button class="no-arrastrar w-full mt-4 bg-red-100 hover:bg-red-200 text-red-700 font-bold text-base rounded-lg py-3" onclick="noSeleccionar(${p.id})">
            ✕ No selecciona
          </button>
        </div>
      </div>`).join('');
    cont.querySelectorAll('.postulante-card').forEach((card) => {
      const id = parseInt(card.dataset.postulacionId, 10);
      const handle = card.querySelector('.drag-handle');
      if (handle) iniciarArrastre(handle, card, id);
    });
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

// v10.14 (pedido explícito del usuario, item 11): "antes debe indicar
// que trae sus documentos... y luego puede arrastrarlo" -- el asa
// queda deshabilitada (gris, sin puntero) hasta marcar el check.
function toggleArrastre(postulacionId, habilitado) {
  const card = document.querySelector(`.postulante-card[data-postulacion-id="${postulacionId}"]`);
  if (!card) return;
  const handle = card.querySelector('.drag-handle');
  if (!handle) return;
  handle.dataset.habilitado = habilitado ? 'true' : 'false';
  handle.style.pointerEvents = habilitado ? 'auto' : 'none';
  handle.classList.toggle('bg-gray-100', !habilitado);
  handle.classList.toggle('text-gray-300', !habilitado);
  handle.classList.toggle('bg-gray-50', habilitado);
  handle.classList.toggle('text-gray-400', habilitado);
  handle.classList.toggle('hover:bg-gray-100', habilitado);
  handle.classList.toggle('cursor-grab', habilitado);
  handle.classList.toggle('active:cursor-grabbing', habilitado);
  handle.innerHTML = habilitado
    ? '<span class="text-base leading-none">⠿⠿⠿</span> ARRASTRA HACIA UN CARGO'
    : '<span class="text-base leading-none">⠿⠿⠿</span> MARCA DOCUMENTOS PARA ARRASTRAR';
}

// v10.14 (pedido explícito del usuario: "no me aparece [deshacer]"): el
// botón de deshacer existía, pero estaba escondido dentro del detalle
// de "Estado en vivo" -- nadie lo iba a encontrar justo después de
// arrastrar por error. Ahora aparece de inmediato, junto al aviso de
// "Seleccionado", con el botón de deshacer ahí mismo, por 15 segundos.
function mostrarAlertaConDeshacer(postulacionId) {
  const el = document.getElementById('alerta');
  if (!el) return;
  el.innerHTML = `
    <div class="border rounded-lg px-4 py-3 text-sm bg-green-50 text-green-700 border-green-200 flex items-center justify-between gap-3 flex-wrap">
      <span>✓ Seleccionado. Ya puede completar su Etapa 2.</span>
      <button onclick="deshacerSeleccion(${postulacionId})" class="shrink-0 bg-white border border-red-200 hover:bg-red-50 text-red-600 text-xs font-semibold rounded-lg px-3 py-1.5">↩ Me equivoqué, deshacer</button>
    </div>`;
  setTimeout(() => {
    if (el.innerHTML.includes(`deshacerSeleccion(${postulacionId})`)) el.innerHTML = '';
  }, 15000);
}

// --- v10.6: arrastre real de la tarjeta hasta la caja del cargo -----------
// v10.18 (pedido explicito del usuario, hallado en una prueba real): el
// "fantasma" arrastrado era un clon completo de la tarjeta -- una masa
// grande y rigida que tapaba las cajas de cupo justo debajo del dedo,
// impidiendo ver (y por lo tanto acertar) el destino. Ahora es una
// pildora chica con solo el nombre, flotando ARRIBA del punto de
// contacto (no encima), para que la caja de cupo quede siempre visible
// mientras se arrastra. La deteccion de destino sigue usando la
// posicion real del puntero (elementFromPoint), no la del fantasma, asi
// que este cambio es solo visual -- no cambia que se puede soltar.
const ARRASTRE_OFFSET_Y = 54; // px que el fantasma flota sobre el dedo/cursor

function iniciarArrastre(handle, card, postulacionId) {
  handle.addEventListener('pointerdown', (e) => {
    if (handle.dataset.habilitado !== 'true') return; // v10.14: falta marcar "trae sus documentos"
    if (e.button !== undefined && e.button !== 0) return;
    e.preventDefault();
    ARRASTRANDO = true;
    handle.setPointerCapture(e.pointerId);

    const nombre = card.querySelector('.nombre-postulante')?.textContent.trim() || 'Postulante';
    const ghost = document.createElement('div');
    ghost.className = 'drag-ghost';
    ghost.setAttribute('aria-hidden', 'true');
    ghost.innerHTML = `<span class="drag-ghost-dot"></span><span class="drag-ghost-nombre"></span>`;
    ghost.querySelector('.drag-ghost-nombre').textContent = nombre;
    ghost.style.left = (e.clientX - 90) + 'px';
    ghost.style.top = (e.clientY - ARRASTRE_OFFSET_Y) + 'px';
    document.body.appendChild(ghost);
    card.classList.add('arrastrando-origen');

    let zonaActual = null;

    function mover(e2) {
      ghost.style.left = (e2.clientX - 90) + 'px';
      ghost.style.top = (e2.clientY - ARRASTRE_OFFSET_Y) + 'px';

      const bajoElPuntero = document.elementFromPoint(e2.clientX, e2.clientY);
      const zona = bajoElPuntero ? bajoElPuntero.closest('.cargo-zone') : null;
      if (zona !== zonaActual) {
        if (zonaActual) zonaActual.classList.remove('cargo-zone--hover');
        if (zona) zona.classList.add('cargo-zone--hover');
        zonaActual = zona;
      }
    }

    async function soltar(e2) {
      handle.removeEventListener('pointermove', mover);
      handle.removeEventListener('pointerup', soltar);
      handle.removeEventListener('pointercancel', soltar);
      try { handle.releasePointerCapture(e2.pointerId); } catch (err) {}
      ghost.remove();
      card.classList.remove('arrastrando-origen');
      if (zonaActual) zonaActual.classList.remove('cargo-zone--hover');
      ARRASTRANDO = false;

      if (zonaActual) {
        await asignarCargoArrastrado(postulacionId, zonaActual.dataset.cargoId);
      }
    }

    handle.addEventListener('pointermove', mover);
    handle.addEventListener('pointerup', soltar);
    handle.addEventListener('pointercancel', soltar);
  });
}

async function asignarCargoArrastrado(id, cargoId) {
  try {
    await apiFetch('/terreno/aprobar.php', { method: 'POST', body: { postulacion_id: id, cargo_id: cargoId } });
    mostrarAlertaConDeshacer(id);
    await cargarCargosConCupo();
    await cargarLista();
    // v10.10: destello en la caja del cargo recién usado -- para que la
    // rebaja del cupo sea visible de un vistazo, no un numero que
    // cambio en silencio en el re-render.
    const zona = document.querySelector(`.cargo-zone[data-cargo-id="${cargoId}"]`);
    if (zona) {
      zona.classList.add('cargo-zone--rebajado');
      setTimeout(() => zona.classList.remove('cargo-zone--rebajado'), 900);
    }
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

async function noSeleccionar(id) {
  const motivo = await pedirMotivoRechazo();
  if (motivo === null) return;
  try {
    await apiFetch('/terreno/rechazar.php', { method: 'POST', body: { postulacion_id: id, motivo } });
    mostrarAlerta('alerta', 'Postulación no continúa.', 'exito');
    await cargarLista();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

// --- v10.14: Personal Contratado (reemplaza la vieja pestaña "Recepción") -
// Reutiliza terreno/historico.php?vista=contratados (misma data que ve
// Jefe de Terreno), sin filtros de fecha -- panel pensado para
// hacerse de pie, rápido.
async function cargarContratados() {
  const tbody = document.getElementById('tbody-contratados');
  const vacio = document.getElementById('contratados-vacio');
  try {
    const data = await apiFetch('/terreno/historico.php?vista=contratados');
    if (!data.postulaciones.length) {
      tbody.innerHTML = '';
      vacio.classList.remove('hidden');
      return;
    }
    vacio.classList.add('hidden');
    tbody.innerHTML = data.postulaciones.map(p => {
      const accion = p.estado === 'Contratado'
        ? `<button class="bg-green-600 hover:bg-green-700 text-white text-xs font-semibold px-3 py-1.5 rounded-lg" onclick="confirmarRecepcion(${p.id})">Ya lo retiré</button>`
        : '<span class="text-xs text-gray-400">Ya retirado</span>';
      return `<tr class="border-t">
        <td class="px-4 py-3 font-mono">${celdaDocumento(p)}</td>
        <td class="px-4 py-3">${p.nombre_completo}</td>
        <td class="px-4 py-3">${p.nombre_cargo}</td>
        <td class="px-4 py-3">${p.aprobado_por_nombre || '-'}</td>
        <td class="px-4 py-3 text-right">${accion}</td>
      </tr>`;
    }).join('');
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

async function confirmarRecepcion(id) {
  if (!confirm('¿Confirmas que fuiste a buscar a esta persona? Esto da por terminado el proceso completo.')) return;
  try {
    const data = await apiFetch('/terreno/recepcion_confirmar.php', { method: 'POST', body: { postulacion_id: id } });
    mostrarAlerta('alerta', data.mensaje, 'exito');
    await cargarContratados();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}
