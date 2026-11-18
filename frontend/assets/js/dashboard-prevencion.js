/**
 * Panel de Prevención.
 *
 * v10.24 (pedido explícito del usuario, 07-10): cada día va en su propia
 * pestaña con contador -- "Día 0 · Postulación" (verificación) y "Día 1 ·
 * Contratación (IRL)".
 *
 * v10.21 (pedido explícito del usuario, tras el piloto del 16-09):
 * Prevención tiene DOS check, uno por día, ambos con un casillero de
 * confirmación (ya no hay catálogo de cursos en esta pantalla; las tablas
 * y endpoints de cursos siguen existiendo en la base, ocultos):
 *
 *   1) Verificación (inducción) -- día de postulación, después de que el JAO verificó a
 *      la persona. Termina su día y recibe el correo "preséntate mañana
 *      a las 8 am" (ver prevencion/marcar_induccion.php).
 *   2) IRL -- día de contratación, después de que el JAO firmó su
 *      contrato. Habilita a Bodega para entregar el kit (ver
 *      prevencion/marcar_irl.php).
 */
(async () => {
  const usuario = await protegerDashboard('Prevencionista');
  if (!usuario) return;
  configurarTabs();
  await cargarLista();
  iniciarEstadoVivo();
})();

// --- v10.24: pestañas Día 0 / Día 1 ----------------------------------------
let TAB_ACTIVA = 'dia0';
let TAB_ELEGIDA_POR_USUARIO = false;

function configurarTabs() {
  document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      TAB_ELEGIDA_POR_USUARIO = true;
      cambiarTab(btn.dataset.tab);
      if (btn.dataset.tab === 'kits') cargarKitsEpp();
    });
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
}

function pintarContador(id, n) {
  const el = document.getElementById(id);
  if (!el) return;
  el.textContent = n;
  el.className = 'ml-1 text-xs font-bold px-2 py-0.5 rounded-full ' + (n > 0 ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-500');
}

// v10.13 (pedido explícito del usuario): botón "🔄 Actualizar" en el
// header -- por si el proceso "parece pegado", refresca sin recargar
// la página ni salir del panel.
function actualizarTodo() {
  cargarLista();
  mostrarAlerta('alerta', 'Actualizado.', 'exito');
}

function esc(valor) {
  // Escapa <, >, &, " y ' : seguro para el texto de la tarjeta Y para atributos HTML.
  return (valor == null ? '' : String(valor))
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function filaConCheck(p, etiqueta, handler) {
  return `
    <tr class="border-t">
      <td class="px-4 py-3 font-mono">${esc(p.rut)}</td>
      <td class="px-4 py-3">${esc(p.nombre_completo)}</td>
      <td class="px-4 py-3">${esc(p.nombre_cargo)}</td>
      <td class="px-4 py-3 text-right">
        <label class="inline-flex items-center gap-2 cursor-pointer select-none">
          <input type="checkbox" class="w-6 h-6 accent-indigo-600 cursor-pointer" onchange="${handler}(this, ${Number(p.id)})">
          <span class="text-sm font-semibold text-gray-700">${etiqueta}</span>
        </label>
      </td>
    </tr>`;
}

function pintarTabla(tbodyId, vacioId, filas, etiqueta, handler) {
  const tbody = document.getElementById(tbodyId);
  const vacio = document.getElementById(vacioId);
  if (!filas.length) {
    tbody.innerHTML = '';
    vacio.classList.remove('hidden');
    return;
  }
  vacio.classList.add('hidden');
  tbody.innerHTML = filas.map(p => filaConCheck(p, etiqueta, handler)).join('');
}

async function cargarLista() {
  try {
    const data = await apiFetch('/prevencion/listar.php');
    const dia0 = data.postulaciones || [];
    const dia1 = data.irl_pendientes || [];
    pintarTabla('tbody-postulaciones', 'vacio', dia0, 'Verificación realizada', 'marcarInduccion');
    pintarTablaIrl(dia1);
    pintarContador('cuenta-dia0', dia0.length);
    pintarContador('cuenta-dia1', dia1.length);
    // Si el usuario todavía no eligió pestaña, se abre la que tiene
    // pendientes (la de contratación solo si es la única con gente).
    if (!TAB_ELEGIDA_POR_USUARIO) {
      cambiarTab(dia0.length === 0 && dia1.length > 0 ? 'dia1' : 'dia0');
    }
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

// Ambos check son irreversibles una vez confirmados (no existe endpoint
// para revertirlos), así que piden confirmación mostrando el nombre -- para
// que un toque accidental en el celular no avance a la persona equivocada.
async function confirmarCheck(checkbox, id, endpoint, pregunta) {
  if (!checkbox.checked) return;
  const nombre = checkbox.closest('tr').children[1].textContent.trim();
  if (!confirm(`${pregunta} ${nombre}?\n\nEsta acción no se puede deshacer.`)) {
    checkbox.checked = false;
    return;
  }
  checkbox.disabled = true;
  try {
    const data = await apiFetch(endpoint, { method: 'POST', body: { postulacion_id: id } });
    mostrarAlerta('alerta', data.mensaje, 'exito');
    await cargarLista();
  } catch (err) {
    checkbox.checked = false;
    checkbox.disabled = false;
    mostrarAlerta('alerta', err.message);
  }
}

function marcarInduccion(checkbox, id) {
  return confirmarCheck(checkbox, id, '/prevencion/marcar_induccion.php', '¿Confirmas que la verificación quedó realizada para');
}

// v10.46 (pedido del usuario, 17-11): la IRL del Día 1 tiene DOS confirmaciones.
//   1) "Presentes en la sala"  2) "IRL realizada" (solo se habilita cuando ya están presentes).
// Recién la segunda deja a la persona lista para Bodega.
function marcarIrl(checkbox, id) {
  return confirmarCheck(checkbox, id, '/prevencion/marcar_irl.php', '¿Confirmas que está presente en la sala');
}

function marcarIrlRealizada(checkbox, id) {
  return confirmarCheck(checkbox, id, '/prevencion/marcar_irl_realizada.php', '¿Confirmas que la IRL quedó realizada para');
}

function filaIrl(p) {
  const presente = !!p.presente_sala;
  return `
    <tr class="border-t">
      <td class="px-4 py-3 font-mono">${esc(p.rut)}</td>
      <td class="px-4 py-3">${esc(p.nombre_completo)}</td>
      <td class="px-4 py-3">${esc(p.nombre_cargo)}</td>
      <td class="px-4 py-3">
        <label class="inline-flex items-center gap-2 select-none ${presente ? 'opacity-80' : 'cursor-pointer'}">
          <input type="checkbox" class="w-6 h-6 accent-indigo-600" ${presente ? 'checked disabled' : `onchange="marcarIrl(this, ${Number(p.id)})"`}>
          <span class="text-sm font-semibold ${presente ? 'text-green-700' : 'text-gray-700'}">${presente ? '✓ Presente en la sala' : 'Presente en la sala'}</span>
        </label>
      </td>
      <td class="px-4 py-3 text-right">
        <label class="inline-flex items-center gap-2 select-none ${presente ? 'cursor-pointer' : 'opacity-40'}">
          <input type="checkbox" class="w-6 h-6 accent-indigo-600" ${presente ? `onchange="marcarIrlRealizada(this, ${Number(p.id)})"` : 'disabled'}
                 title="${presente ? '' : 'Primero confirma que está presente en la sala'}">
          <span class="text-sm font-semibold text-gray-700">IRL realizada</span>
        </label>
      </td>
    </tr>`;
}

function pintarTablaIrl(filas) {
  const tbody = document.getElementById('tbody-irl');
  const vacio = document.getElementById('vacio-irl');
  if (!filas.length) {
    tbody.innerHTML = '';
    vacio.classList.remove('hidden');
    return;
  }
  vacio.classList.add('hidden');
  tbody.innerHTML = filas.map(filaIrl).join('');
}


// --- v10.49: Kits de EPP por cargo ---------------------------------------------
// Kit base (cargo_id 0) + diferencias por cargo. El servidor guarda solo las diferencias.
let EPP = null;
let EPP_SEL = 0;   // 0 = kit base; otro = id de cargo

async function cargarKitsEpp() {
  try {
    EPP = await apiFetch('/prevencion/epp_config.php');
    const aviso = document.getElementById('kits-aviso');
    if (!EPP.disponible) {
      aviso.textContent = 'Los kits de EPP todavía no están habilitados en esta base de datos (falta la actualización v10.49).';
      aviso.classList.remove('hidden');
      return;
    }
    aviso.classList.add('hidden');
    pintarKitsLista();
    pintarKitsEditor();
    pintarKitsCatalogo();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

function eppCantidades(sel) {
  // Cantidades efectivas del kit seleccionado: base, con las diferencias del cargo encima.
  const c = Object.assign({}, EPP.base);
  if (sel !== 0) Object.assign(c, EPP.por_cargo[sel] || {});
  return c;
}

function pintarKitsLista() {
  const fila = (id, nombre, marca) => `
    <li><button onclick="elegirKit(${id})" class="w-full text-left px-3 py-2 rounded-lg flex items-center justify-between gap-2 ${EPP_SEL === id ? 'bg-blue-50 text-blue-700 font-semibold' : 'hover:bg-gray-50 text-gray-700'}">
      <span>${esc(nombre)}</span>${marca}</button></li>`;
  document.getElementById('kits-lista').innerHTML =
    fila(0, 'Kit base (todos los cargos)', '<span class="text-[10px] font-bold bg-gray-900 text-white rounded-full px-2 py-0.5">BASE</span>') +
    '<li class="text-[11px] uppercase tracking-wide text-gray-400 px-3 pt-3 pb-1">Cargos</li>' +
    EPP.cargos.map(c => fila(c.id, c.nombre_cargo,
      c.personalizado ? '<span class="text-[10px] font-semibold bg-amber-100 text-amber-800 rounded-full px-2 py-0.5">con cambios</span>' : '')).join('');
}

function elegirKit(id) {
  EPP_SEL = id;
  pintarKitsLista();
  pintarKitsEditor();
}

function pintarKitsEditor() {
  const cant = eppCantidades(EPP_SEL);
  const esBase = EPP_SEL === 0;
  const cargo = EPP.cargos.find(c => c.id === EPP_SEL);
  const items = EPP.items.filter(i => i.activo);
  const categorias = [...new Set(items.map(i => i.categoria))];
  const talla = { calzado: 'usa el N° de calzado', overol: 'usa la talla de overol', ninguna: '' };
  const totalItems = items.filter(i => (cant[i.id] || 0) > 0).length;
  document.getElementById('kits-editor').innerHTML = `
    <div class="flex items-start justify-between gap-3 flex-wrap mb-4">
      <div>
        <h3 class="text-base font-bold text-gray-900">${esBase ? 'Kit base · todos los cargos' : esc(cargo ? cargo.nombre_cargo : '')}</h3>
        <p class="text-xs text-gray-500 mt-0.5">${esBase
          ? 'Lo que recibe todo trabajador. Cambiar esto afecta a todos los cargos que no tengan cambios propios.'
          : 'Parte del kit base. Ajusta cantidades: 0 = este cargo no lo lleva. Lo marcado <em>base</em> viene del kit base.'}</p>
      </div>
      <span id="kits-contador" class="text-xs font-semibold bg-gray-100 text-gray-600 rounded-full px-3 py-1">${totalItems} elementos</span>
    </div>
    ${categorias.map(cat => `
      <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400 mt-4 mb-1">${esc(cat)}</p>
      <div class="grid sm:grid-cols-2 gap-x-6">
        ${items.filter(i => i.categoria === cat).map(i => {
          const q = cant[i.id] || 0;
          const base = (EPP.base[i.id] || 0) > 0;
          return `<label class="flex items-center justify-between gap-3 py-1.5 border-b border-gray-100">
            <span class="text-sm text-gray-800 min-w-0">${esc(i.nombre)}
              ${i.tipo_talla !== 'ninguna' ? `<span class="block text-[11px] text-gray-400">${talla[i.tipo_talla]}</span>` : ''}
              ${!esBase && base ? '<span class="text-[10px] font-semibold text-gray-400">base</span>' : ''}</span>
            <input type="number" min="0" max="99" value="${q}" data-item="${i.id}" oninput="actualizarContadorKit()"
                   class="w-16 border border-gray-300 rounded-lg px-2 py-1 text-sm text-center ${q > 0 ? 'bg-emerald-50 border-emerald-300' : ''}">
          </label>`;
        }).join('')}
      </div>`).join('')}
    <div class="flex flex-wrap gap-2 mt-5">
      <button onclick="guardarKit()" class="bg-gray-900 hover:bg-gray-800 text-white text-sm font-semibold px-4 py-2 rounded-lg">Guardar kit</button>
      ${!esBase && cargo && cargo.personalizado
        ? '<button onclick="restablecerKit()" class="border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-semibold px-4 py-2 rounded-lg">Volver al kit base</button>' : ''}
    </div>`;
}

function actualizarContadorKit() {
  const inputs = [...document.querySelectorAll('#kits-editor input[data-item]')];
  inputs.forEach(i => i.classList.toggle('bg-emerald-50', Number(i.value) > 0));
  const n = inputs.filter(i => Number(i.value) > 0).length;
  document.getElementById('kits-contador').textContent = `${n} elementos`;
}

async function guardarKit() {
  const items = {};
  document.querySelectorAll('#kits-editor input[data-item]').forEach(i => { items[i.dataset.item] = Number(i.value) || 0; });
  try {
    const data = await apiFetch('/prevencion/epp_kit_guardar.php', { method: 'POST', body: { cargo_id: EPP_SEL, items } });
    mostrarAlerta('alerta', data.mensaje, 'exito');
    await cargarKitsEpp();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

async function restablecerKit() {
  if (!confirm('¿Quitar los cambios de este cargo y usar solo el kit base?')) return;
  try {
    const data = await apiFetch('/prevencion/epp_kit_guardar.php', { method: 'POST', body: { cargo_id: EPP_SEL, restablecer: true } });
    mostrarAlerta('alerta', data.mensaje, 'exito');
    await cargarKitsEpp();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

function pintarKitsCatalogo() {
  const etiquetaTalla = { ninguna: 'Sin talla', calzado: 'N° de calzado', overol: 'Talla de overol' };
  const opcionesTalla = (v) => ['ninguna', 'calzado', 'overol'].map(t =>
    `<option value="${t}" ${t === v ? 'selected' : ''}>${etiquetaTalla[t]}</option>`).join('');
  const fila = (i) => `
    <tr class="border-t" data-fila="${i ? i.id : 0}">
      <td class="py-1.5 pr-2"><input type="text" value="${esc(i ? i.nombre : '')}" placeholder="Nombre del elemento" class="f-nombre w-full border border-gray-300 rounded-lg px-2 py-1 text-sm"></td>
      <td class="py-1.5 pr-2"><input type="text" value="${esc(i ? i.categoria : '')}" placeholder="Categoría" class="f-cat w-32 border border-gray-300 rounded-lg px-2 py-1 text-sm"></td>
      <td class="py-1.5 pr-2"><select class="f-talla border border-gray-300 rounded-lg px-2 py-1 text-sm">${opcionesTalla(i ? i.tipo_talla : 'ninguna')}</select></td>
      <td class="py-1.5 pr-2 text-center"><input type="checkbox" class="f-activo" ${!i || i.activo ? 'checked' : ''}></td>
      <td class="py-1.5 text-right"><button onclick="guardarElementoEpp(this)" class="text-xs font-semibold text-blue-700 hover:underline">${i ? 'Guardar' : '+ Agregar'}</button></td>
    </tr>`;
  document.getElementById('kits-catalogo').innerHTML = `
    <p class="text-xs text-gray-500 mb-2">Los elementos no se borran: se desactivan (dejan de aparecer en los kits nuevos).</p>
    <table class="w-full text-sm">
      <thead class="text-left text-xs text-gray-500"><tr><th>Elemento</th><th>Categoría</th><th>Talla</th><th class="text-center">Activo</th><th></th></tr></thead>
      <tbody>${fila(null)}${EPP.items.map(fila).join('')}</tbody>
    </table>`;
}

async function guardarElementoEpp(boton) {
  const tr = boton.closest('tr');
  const body = {
    id: Number(tr.dataset.fila) || 0,
    nombre: tr.querySelector('.f-nombre').value,
    categoria: tr.querySelector('.f-cat').value,
    tipo_talla: tr.querySelector('.f-talla').value,
    activo: tr.querySelector('.f-activo').checked,
  };
  try {
    const data = await apiFetch('/prevencion/epp_item_guardar.php', { method: 'POST', body });
    mostrarAlerta('alerta', data.mensaje, 'exito');
    await cargarKitsEpp();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}
