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
// v10.19 (pedido explícito del usuario): quién soy yo, para poder
// resaltar en la lista "este viene por mí" contra los demás.
let USUARIO_ACTUAL = null;

(async () => {
  const usuario = await protegerDashboard('Capataz');
  if (!usuario) return;
  USUARIO_ACTUAL = usuario;
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
    <div class="cargo-zone rounded-xl border-2 border-dashed border-orange-300 bg-orange-50 px-3 py-2.5 flex items-center gap-2.5" data-cargo-id="${c.id}">
      <span class="text-2xl leading-none">⛑️</span>
      <div class="min-w-0">
        <p class="text-sm font-bold text-gray-800 leading-tight truncate">${esc(c.nombre_cargo)}</p>
        <p class="text-xs text-orange-700 font-semibold">${c.cupos_disponibles} cupo(s)</p>
      </div>
    </div>`).join('');
}

// v10.23: escapa texto de la BD antes de meterlo en el HTML de las tarjetas.
function esc(valor) {
  const d = document.createElement('div');
  d.textContent = valor == null ? '' : String(valor);
  return d.innerHTML;
}

// v10.23: la ÚNICA tarjeta "activa" (con documentos marcados, lista para
// arrastrar). Sobrevive al refresco automático de 15 s -- antes la lista se
// volvía a dibujar y desmarcaba el check justo cuando el Capataz lo había
// puesto.
let ACTIVA_ID = null;

function actualizarTituloZonas(nombre) {
  const t = document.getElementById('zonas-titulo');
  if (!t) return;
  if (nombre) {
    t.className = 'text-sm font-bold text-orange-700 mb-2';
    t.innerHTML = `③ Arrastra a <span class="bg-orange-600 text-white px-2 py-0.5 rounded-full">${esc(nombre)}</span> hasta su cargo ↓`;
  } else {
    t.className = 'text-xs font-bold text-gray-500 mb-2 uppercase tracking-wide';
    t.textContent = 'Cargos con cupo';
  }
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

// v10.19 (pedido explícito del usuario, tras la reunión con la obra del
// 16-09): si el postulante declaró en Etapa 1 quién lo está esperando,
// se lo mostramos al Capataz -- resaltado en naranjo si es él mismo
// ("viene por mí"), en gris neutro si espera a otro Capataz (para que
// no se confunda ni se lo lleve por error).
function match(p) {
  if (!p.capataz_esperado_id) return '';
  const esParaMi = USUARIO_ACTUAL && Number(p.capataz_esperado_id) === Number(USUARIO_ACTUAL.id);
  const clases = esParaMi
    ? 'bg-blue-50 text-blue-700 border border-blue-200'
    : 'bg-gray-100 text-gray-600 border border-gray-200';
  const texto = esParaMi ? '✓ Viene por ti' : `Espera a: ${p.capataz_esperado_nombre}`;
  return `<p class="inline-block mt-1.5 text-xs font-semibold px-2 py-1 rounded-full ${clases}">${texto}</p>`;
}

// v10.23: el CV (o la experiencia declarada si no tiene) va DEBAJO del nombre,
// no en una columna a la derecha -- en un celular angosto esa columna le
// quitaba espacio al RUT. La experiencia se muestra a la vista (antes solo
// aparecía al pasar el mouse, que en un teléfono no existe).
// En el celular la experiencia queda PLEGADA (solo una etiqueta chica que se
// toca para abrir, para no ocupar pantalla); en escritorio, que sobra
// espacio, aparece abierta. Lo que el Capataz abre o cierra se recuerda
// aunque la lista se redibuje cada 15 s.
const EXPERIENCIA_ABIERTA = {};
function esEscritorio() { return window.matchMedia('(min-width: 640px)').matches; }
function recordarExperiencia(id, abierta) { EXPERIENCIA_ABIERTA[id] = abierta; }

function bloqueCv(p) {
  if (p.tiene_cv) {
    return `<a href="${API_BASE_URL}/terreno/ver_cv.php?postulacion_id=${p.id}" target="_blank" class="inline-block mt-2 text-xs font-semibold text-blue-700 bg-blue-50 border border-blue-200 rounded-full px-3 py-1">📄 Ver CV</a>`;
  }
  if (p.experiencia_sin_cv) {
    const abierta = p.id in EXPERIENCIA_ABIERTA ? EXPERIENCIA_ABIERTA[p.id] : esEscritorio();
    return `<details class="mt-2" ${abierta ? 'open' : ''} ontoggle="recordarExperiencia(${p.id}, this.open)">
      <summary class="inline-block cursor-pointer select-none text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-200 rounded-full px-3 py-1" style="list-style:none">Sin CV · ver experiencia ▾</summary>
      <p class="mt-1.5 text-xs bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 text-amber-900 break-words">${esc(p.experiencia_sin_cv)}</p>
    </details>`;
  }
  return '<p class="mt-2 text-xs text-gray-400">Sin CV</p>';
}

async function cargarLista() {
  const cont = document.getElementById('lista-postulantes');
  const vacio = document.getElementById('vacio');
  try {
    const data = await apiFetch('/terreno/listar.php');
    if (!data.postulaciones.length) {
      cont.innerHTML = '';
      cont.classList.remove('hay-activa');
      ACTIVA_ID = null;
      actualizarTituloZonas(null);
      vacio.classList.remove('hidden');
      return;
    }
    vacio.classList.add('hidden');
    // v10.23: cada tarjeta lleva primero QUIÉN es (número + RUT + nombre) y
    // después, dentro de la misma tarjeta, sus dos pasos: ① check, ② asa de
    // arrastre. "No selecciona" queda chico y aparte, lejos del check de la
    // tarjeta siguiente.
    cont.innerHTML = data.postulaciones.map((p, i) => `
      <div class="postulante-card bg-white rounded-2xl shadow-sm border-2 border-gray-200 overflow-hidden" data-postulacion-id="${p.id}">
        <div class="flex items-start gap-4 p-5">
          <div class="shrink-0 w-11 h-11 rounded-full bg-gray-900 text-white text-lg font-extrabold flex items-center justify-center" aria-label="Persona ${i + 1}">${i + 1}</div>
          <div class="min-w-0 flex-1">
            <p class="text-2xl font-mono font-bold text-gray-900 tracking-wide">${celdaDocumento(p)}</p>
            <p class="nombre-postulante text-lg font-bold text-gray-800">${esc(p.nombre_completo)}</p>
            <p class="text-sm text-gray-500">${esc(p.comuna)}</p>
            ${match(p)}
            ${bloqueCv(p)}
          </div>
        </div>
        <div class="px-5 pb-5 space-y-3">
          <label class="flex items-center gap-3 rounded-xl border-2 border-amber-300 bg-amber-50 px-4 py-3 cursor-pointer">
            <input type="checkbox" class="check-documentos w-6 h-6 shrink-0 accent-amber-600" onchange="toggleArrastre(${p.id}, this.checked)">
            <span>
              <span class="block text-sm font-bold text-amber-900">① Trae sus documentos</span>
              <span class="block text-xs font-medium text-amber-700">Compara el RUT de arriba con su cédula</span>
            </span>
          </label>
          <div class="drag-handle flex items-center justify-center gap-2 rounded-xl border-2 border-dashed border-gray-300 bg-gray-100 text-gray-400 text-sm font-bold tracking-wide py-3 select-none" data-habilitado="false" style="pointer-events:none">
            <span class="text-base leading-none">⠿⠿⠿</span> ② Primero marca ①
          </div>
          <div class="text-right pt-1">
            <button class="no-arrastrar text-xs font-semibold text-red-600 hover:text-red-700 hover:underline px-2 py-1" onclick="noSeleccionar(${p.id})">
              ✕ No selecciona a esta persona
            </button>
          </div>
        </div>
      </div>`).join('');
    cont.querySelectorAll('.postulante-card').forEach((card) => {
      const id = parseInt(card.dataset.postulacionId, 10);
      const handle = card.querySelector('.drag-handle');
      if (handle) iniciarArrastre(handle, card, id);
    });
    // Si había una tarjeta activa antes del refresco, se vuelve a activar.
    const activaCard = ACTIVA_ID !== null
      ? cont.querySelector(`.postulante-card[data-postulacion-id="${ACTIVA_ID}"]`)
      : null;
    if (activaCard) {
      activaCard.querySelector('.check-documentos').checked = true;
      toggleArrastre(ACTIVA_ID, true);
    } else {
      ACTIVA_ID = null;
      cont.classList.remove('hay-activa');
      actualizarTituloZonas(null);
    }
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
  const lista = document.getElementById('lista-postulantes');

  // v10.23: una sola tarjeta activa a la vez -- al marcar otra, la anterior
  // se desmarca, para que nunca haya dos candidatas al arrastre.
  if (habilitado) {
    lista.querySelectorAll('.postulante-card.activa').forEach((otra) => {
      if (otra !== card) {
        otra.querySelector('.check-documentos').checked = false;
        aplicarEstadoTarjeta(otra, false);
      }
    });
    ACTIVA_ID = postulacionId;
  } else if (ACTIVA_ID === postulacionId) {
    ACTIVA_ID = null;
  }
  aplicarEstadoTarjeta(card, habilitado);
  lista.classList.toggle('hay-activa', ACTIVA_ID !== null);
  actualizarTituloZonas(habilitado ? card.querySelector('.nombre-postulante')?.textContent.trim() : null);
}

// Pinta una tarjeta como activa (check marcado, asa lista y pulsando) o
// como inactiva (asa gris deshabilitada).
function aplicarEstadoTarjeta(card, habilitado) {
  const handle = card.querySelector('.drag-handle');
  if (!handle) return;
  card.classList.toggle('activa', habilitado);
  handle.dataset.habilitado = habilitado ? 'true' : 'false';
  handle.style.pointerEvents = habilitado ? 'auto' : 'none';
  handle.className = habilitado
    ? 'drag-handle listo flex items-center justify-center gap-2 rounded-xl border-2 border-orange-700 bg-blue-600 text-white text-sm font-extrabold tracking-wide py-4 select-none cursor-grab active:cursor-grabbing'
    : 'drag-handle flex items-center justify-center gap-2 rounded-xl border-2 border-dashed border-gray-300 bg-gray-100 text-gray-400 text-sm font-bold tracking-wide py-3 select-none';
  handle.innerHTML = habilitado
    ? '<span class="text-lg leading-none">⠿⠿⠿</span> ② MANTÉN APRETADO Y ARRASTRA HACIA UN CARGO ↑'
    : '<span class="text-base leading-none">⠿⠿⠿</span> ② Primero marca ①';
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
