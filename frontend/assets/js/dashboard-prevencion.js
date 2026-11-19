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
  const cont = document.getElementById('contenedor');
  if (cont) cont.style.maxWidth = tab === 'kits' ? '1360px' : '';
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


// --- v10.49 / v10.51: Kits de EPP por cargo ------------------------------------
// Kit base (cargo_id 0) + diferencias por cargo; el servidor guarda solo las diferencias.
// Diseño nuevo (v10.51): tarjetas tocables con dibujo, cantidad, color del casco y vista de lo que verá Bodega.
const KX_COLORES = { rojo: '#D7263D', amarillo: '#F2B705', azul: '#1F5FBF' };
const KX_ICONOS = {
  casco:          { i1: '#F2B705', i2: '#3B4552' },
  lentes:         { i1: 'rgba(150,205,235,.55)', i2: '#26303C' },
  lentes_oscuros: { i1: '#1A212B', i2: '#0B0F14', sym: 'lentes' },
  barbiquejo:     { i1: '#3E4856', i2: '#8794A3' },
  overol:         { i1: '#2E5B9A', i2: '#F2B705' },
  zapato:         { i1: '#6B4A33', i2: '#24201C' },
  guantes:        { i1: '#D29A58', i2: '#8A5A2B' },
  fono:           { i1: '#E1460E', i2: '#2B3440' },
  chaleco:        { i1: '#F58A1F', i2: '#2B3440' },
  botas:          { i1: '#2F6B3F', i2: '#1B1F24' },
  legionario:     { i1: '#D8D1BE', i2: '#B7AF98' },
  extra:          { i1: '#E1460E', i2: '#2B3440' },
};
const KX_PERSONAS = [
  { nombre: 'Juan Pérez', ini: 'JP', calzado: '42', overol: 'L' },
  { nombre: 'María Soto', ini: 'MS', calzado: '38', overol: 'M' },
];

let EPP = null;          // última respuesta de epp_config.php
let EPP_SEL = 0;         // 0 = kit base; otro = id de cargo
let KX_DRAFTS = {};      // cambios sin guardar, por cargo: { cargoId: { itemId: {q, c} } }
let KX_QUIEN = 0;
let KX_TALLA = 'none';

const kxEl = (id) => document.getElementById('kx-' + id);
const kxCopia = (o) => JSON.parse(JSON.stringify(o));
const kxItem = (id) => EPP.items.find((i) => i.id === Number(id));

// Kit efectivo guardado de un cargo: el base con las diferencias del cargo encima (q = 0 lo quita).
function kxEfectivo(sel) {
  const k = kxCopia(EPP.base || {});
  if (sel !== 0) {
    Object.entries(EPP.por_cargo[sel] || {}).forEach(([id, f]) => {
      if (f.q > 0) k[id] = { q: f.q, c: f.c }; else delete k[id];
    });
  }
  return k;
}
const kxNorm = (kit) => JSON.stringify(Object.keys(kit).filter((id) => kit[id].q > 0).sort((a, b) => a - b)
  .map((id) => [id, kit[id].q, kit[id].c || null]));
const kxDraft = () => (KX_DRAFTS[EPP_SEL] = KX_DRAFTS[EPP_SEL] || kxCopia(kxEfectivo(EPP_SEL)));
const kxSucio = () => kxNorm(kxDraft()) !== kxNorm(kxEfectivo(EPP_SEL));
const kxConCambios = (id) => id !== 0 && (EPP.cargos.find((c) => c.id === id) || {}).personalizado;

function kxIcono(it, color) {
  const def = KX_ICONOS[it.icono] || KX_ICONOS.extra;
  const c1 = (it.colores && color) ? KX_COLORES[color] : def.i1;
  const sym = def.sym || (KX_ICONOS[it.icono] ? it.icono : 'extra');
  return `<svg style="--i1:${c1};--i2:${def.i2}" aria-hidden="true"><use href="#i-${sym}"/></svg>`;
}

async function cargarKitsEpp() {
  try {
    EPP = await apiFetch('/prevencion/epp_config.php');
    const aviso = kxEl('aviso');
    if (!EPP.disponible) {
      aviso.textContent = 'Los kits de EPP todavía no están habilitados en esta base de datos (falta la actualización v10.49).';
      aviso.classList.remove('hidden');
      return;
    }
    aviso.classList.toggle('hidden', EPP.colores_disponibles !== false);
    if (EPP.colores_disponibles === false) {
      aviso.textContent = 'Falta la actualización v10.51 de la base de datos: los kits funcionan, pero todavía no se puede elegir el color del casco.';
    }
    kxTodo();
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  }
}

function kxAviso(texto, error) {
  const e = kxEl('toast');
  e.textContent = texto;
  e.style.background = error ? '#B42318' : '';
  e.classList.add('show');
  clearTimeout(kxAviso.t);
  kxAviso.t = setTimeout(() => e.classList.remove('show'), 2400);
}

function kxTodo() {
  kxPintarCargos();
  kxPintarEditor();
  kxPintarVista();
  kxPintarCatalogo();
  kxEl('save').classList.toggle('show', kxSucio());
}

function kxPintarCargos() {
  const q = kxEl('buscar').value.trim().toLowerCase();
  const activos = new Set(EPP.items.filter((i) => i.activo).map((i) => String(i.id)));
  const nItems = (id) => Object.entries(KX_DRAFTS[id] || kxEfectivo(id)).filter(([k, f]) => f.q > 0 && activos.has(k)).length;
  const cargos = [{ id: 0, nombre_cargo: 'Kit base' }].concat(EPP.cargos);
  kxEl('clist').innerHTML = cargos.filter((c) => c.id === 0 || c.nombre_cargo.toLowerCase().includes(q)).map((c) => {
    if (c.id === 0) {
      return `<button type="button" class="cbtn base-btn" data-id="0" ${EPP_SEL === 0 ? 'aria-current="true"' : ''}><span>Kit base<br><small>Todos los cargos</small></span><small>${nItems(0)} EPP</small></button>`;
    }
    return `<button type="button" class="cbtn" data-id="${c.id}" ${EPP_SEL === c.id ? 'aria-current="true"' : ''}><span>${esc(c.nombre_cargo)}</span>
      <span style="display:flex;align-items:center;gap:7px">${c.personalizado ? '<i class="dot" title="Tiene cambios sobre el kit base"></i>' : ''}<small>${nItems(c.id)} EPP</small></span></button>`;
  }).join('') || '<p class="help" style="padding:8px 10px">Sin resultados.</p>';
}

function kxPintarEditor() {
  const esBase = EPP_SEL === 0;
  const cargo = EPP.cargos.find((c) => c.id === EPP_SEL);
  const kit = kxDraft();
  kxEl('etitulo').textContent = esBase ? 'Kit base · todos los cargos' : (cargo ? cargo.nombre_cargo : '');
  kxEl('esub').textContent = esBase
    ? 'Es lo que recibe todo trabajador al ingresar. Si lo cambias, cambia para todos los cargos que no tengan ajustes propios.'
    : (cargo && cargo.personalizado
        ? 'Parte del kit base y tiene ajustes propios (marcados con un punto en la lista).'
        : 'Hoy usa el kit base completo. Toca un elemento para agregarlo o quitarlo solo a este cargo.');
  kxEl('reset').hidden = esBase || !(cargo && cargo.personalizado) && !kxSucio();
  kxEl('copiar').innerHTML = '<option value="">Copiar el kit de…</option>' +
    [{ id: 0, nombre_cargo: 'Kit base' }].concat(EPP.cargos).filter((c) => c.id !== EPP_SEL)
      .map((c) => `<option value="${c.id}">${esc(c.nombre_cargo)}</option>`).join('');

  const activos = EPP.items.filter((i) => i.activo);
  kxEl('grid').innerHTML = activos.map((it) => {
    const k = kit[it.id] && kit[it.id].q > 0 ? kit[it.id] : null;
    const tag = it.tipo_talla === 'calzado' ? 'Usa el N° de calzado' : it.tipo_talla === 'overol' ? 'Usa la talla de overol'
      : (it.colores ? 'Rojo, amarillo o azul' : '&nbsp;');
    const ctrl = !k ? 'Toca para agregar' : `
      <div class="stepper"><label>Cantidad</label>
        <div class="qty"><button type="button" data-a="menos" data-id="${it.id}" aria-label="Menos ${esc(it.nombre)}">−</button><output>${k.q}</output><button type="button" data-a="mas" data-id="${it.id}" aria-label="Más ${esc(it.nombre)}">+</button></div></div>
      ${it.colores ? `<div class="colors"><span>Color</span><div class="chips">${Object.keys(KX_COLORES).map((co) =>
        `<button type="button" class="chip" data-a="color" data-id="${it.id}" data-c="${co}" aria-pressed="${k.c === co}" aria-label="Color ${co}" title="${co}" style="background:${KX_COLORES[co]}"></button>`).join('')}</div></div>` : ''}`;
    return `<article class="card ${k ? 'on' : ''}">
      <button type="button" class="main" data-a="toggle" data-id="${it.id}" aria-pressed="${!!k}">
        <span class="chk">✓</span>
        <span class="pic">${kxIcono(it, k && k.c)}</span>
        <span class="name">${esc(it.nombre)}</span>
        <span class="tag">${tag}</span>
      </button>
      <div class="ctrl">${ctrl}</div>
    </article>`;
  }).join('') + `<article class="card add"><button type="button" class="main" data-a="nuevo"><span class="pic">+</span><span class="name">Agregar otro EPP</span><span class="tag">Si falta uno, créalo aquí</span></button></article>`;
}

function kxPintarVista() {
  const esBase = EPP_SEL === 0;
  const cargo = EPP.cargos.find((c) => c.id === EPP_SEL);
  const kit = kxDraft();
  const p = KX_PERSONAS[KX_QUIEN];
  kxEl('ptitulo').textContent = esBase ? 'Ejemplo con el kit base' : (cargo ? cargo.nombre_cargo : '');
  kxEl('segWho').innerHTML = KX_PERSONAS.map((x, i) => `<button type="button" data-i="${i}" aria-pressed="${i === KX_QUIEN}">${esc(x.nombre.split(' ')[0])}</button>`).join('');
  kxEl('who').innerHTML = `<div class="av">${p.ini}</div><div><b>${esc(p.nombre)}</b><span>${esBase ? 'Cualquier cargo' : esc(cargo ? cargo.nombre_cargo : '')} · persona de ejemplo</span></div>
    <div class="sizes"><span class="sz"><em>Calzado</em> ${p.calzado}</span><span class="sz"><em>Overol</em> ${p.overol}</span></div>`;
  const filas = EPP.items.filter((i) => i.activo && kit[i.id] && kit[i.id].q > 0);
  kxEl('lines').innerHTML = filas.length ? filas.map((it) => {
    const k = kit[it.id];
    const det = [it.colores && k.c ? `<span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:${KX_COLORES[k.c]};margin-right:5px"></span>${k.c}` : '',
      it.tipo_talla === 'calzado' ? 'N° ' + p.calzado : it.tipo_talla === 'overol' ? 'Talla ' + p.overol : ''].filter(Boolean).join(' · ');
    return `<li class="line"><span class="mini">${kxIcono(it, k.c)}</span><b>${esc(it.nombre)}${det ? `<small>${det}</small>` : ''}</b><span class="times">×${k.q}</span></li>`;
  }).join('') : '<li class="empty">Este kit está vacío. Toca los EPP del centro para armarlo.</li>';
  kxEl('ptotal').textContent = `${filas.length} elementos distintos`;
  kxEl('pcount').textContent = `${filas.reduce((a, i) => a + kit[i.id].q, 0)} piezas`;
}

function kxPintarCatalogo() {
  const etiqueta = { ninguna: 'Sin talla', calzado: 'N° de calzado', overol: 'Talla de overol' };
  const fila = (i) => `
    <tr data-fila="${i ? i.id : 0}">
      <td><input type="text" value="${esc(i ? i.nombre : '')}" placeholder="Nombre del elemento" maxlength="60" class="f-nombre"></td>
      <td><select class="f-talla">${['ninguna', 'calzado', 'overol'].map((t) => `<option value="${t}" ${i && i.tipo_talla === t ? 'selected' : ''}>${etiqueta[t]}</option>`).join('')}</select></td>
      <td class="c"><input type="checkbox" class="f-colores" ${i && i.colores ? 'checked' : ''} ${EPP.colores_disponibles === false ? 'disabled' : ''}></td>
      <td class="c"><input type="checkbox" class="f-activo" ${!i || i.activo ? 'checked' : ''}></td>
      <td class="r"><button type="button" class="btn" data-a="guardar-item">${i ? 'Guardar' : '+ Agregar'}</button></td>
    </tr>`;
  kxEl('catalogo').innerHTML = `
    <p class="help" style="margin:0 0 8px">Cambia el nombre, la talla o los colores de un EPP. Los elementos no se borran: se desactivan y dejan de aparecer en los kits.</p>
    <table class="cat"><thead><tr><th>Elemento</th><th>Talla</th><th class="c">Colores</th><th class="c">Activo</th><th></th></tr></thead>
    <tbody>${EPP.items.map(fila).join('')}</tbody></table>`;
}

// --- eventos -----------------------------------------------------------------
document.addEventListener('click', async (e) => {
  const raiz = e.target.closest('#panel-kits');
  if (!raiz || !EPP || !EPP.disponible) return;

  const cb = e.target.closest('#kx-clist [data-id]');
  if (cb) { EPP_SEL = Number(cb.dataset.id); kxEl('adder').hidden = true; kxTodo(); return; }

  const who = e.target.closest('#kx-segWho [data-i]');
  if (who) { KX_QUIEN = Number(who.dataset.i); kxPintarVista(); return; }

  const talla = e.target.closest('#kx-segTalla button');
  if (talla) {
    KX_TALLA = talla.dataset.v;
    kxEl('segTalla').querySelectorAll('button').forEach((x) => x.setAttribute('aria-pressed', String(x === talla)));
    return;
  }

  const b = e.target.closest('[data-a]');
  if (b) {
    const kit = kxDraft();
    const id = b.dataset.id;
    const it = id ? kxItem(id) : null;
    if (b.dataset.a === 'toggle') {
      if (kit[id] && kit[id].q > 0) delete kit[id]; else kit[id] = { q: 1, c: it.colores ? 'amarillo' : null };
    } else if (b.dataset.a === 'mas') { kit[id].q = Math.min(99, kit[id].q + 1); }
    else if (b.dataset.a === 'menos') { kit[id].q = Math.max(1, kit[id].q - 1); }
    else if (b.dataset.a === 'color') { kit[id].c = b.dataset.c; }
    else if (b.dataset.a === 'nuevo') {
      kxEl('adder').hidden = false;
      kxEl('adder').scrollIntoView({ behavior: 'smooth', block: 'center' });
      kxEl('nuevoNombre').focus();
      return;
    } else if (b.dataset.a === 'guardar-item') { await kxGuardarItem(b); return; }
    kxTodo();
    const foco = document.querySelector(`#kx-grid [data-a="${b.dataset.a}"][data-id="${id}"]${b.dataset.c ? `[data-c="${b.dataset.c}"]` : ''}`);
    if (foco) foco.focus();
    return;
  }

  switch (e.target.id) {
    case 'kx-reset': KX_DRAFTS[EPP_SEL] = kxCopia(kxEfectivo(0)); kxTodo(); break;
    case 'kx-descartar': delete KX_DRAFTS[EPP_SEL]; kxTodo(); break;
    case 'kx-guardar': await kxGuardarKit(); break;
    case 'kx-addCancel': kxEl('adder').hidden = true; break;
    case 'kx-addOk': await kxAgregarItem(); break;
    default: break;
  }
});

document.addEventListener('input', (e) => { if (e.target.id === 'kx-buscar') kxPintarCargos(); });
document.addEventListener('change', (e) => {
  if (e.target.id !== 'kx-copiar' || !e.target.value || !EPP) return;
  KX_DRAFTS[EPP_SEL] = kxCopia(kxEfectivo(Number(e.target.value)));
  kxAviso('Kit copiado. Revísalo y guarda.');
  kxTodo();
});

async function kxGuardarKit() {
  const kit = kxDraft();
  const items = {};
  Object.entries(kit).forEach(([id, f]) => { items[id] = { q: f.q, c: f.c || null }; });
  try {
    const data = await apiFetch('/prevencion/epp_kit_guardar.php', { method: 'POST', body: { cargo_id: EPP_SEL, items } });
    delete KX_DRAFTS[EPP_SEL];
    await cargarKitsEpp();
    kxAviso(data.mensaje || 'Kit guardado');
  } catch (err) {
    kxAviso(err.message, true);
  }
}

async function kxAgregarItem() {
  const nombre = kxEl('nuevoNombre').value.trim();
  if (nombre.length < 3) { kxEl('nuevoNombre').focus(); kxAviso('Escribe el nombre del elemento', true); return; }
  try {
    const data = await apiFetch('/prevencion/epp_item_guardar.php', {
      method: 'POST', body: { nombre, tipo_talla: KX_TALLA, colores: kxEl('nuevoColores').checked, activo: true } });
    const colores = kxEl('nuevoColores').checked && EPP.colores_disponibles !== false;
    kxDraft();                           // lo ya armado sin guardar se conserva
    await cargarKitsEpp();
    kxDraft()[data.id] = { q: 1, c: colores ? 'amarillo' : null };
    kxEl('nuevoNombre').value = '';
    kxEl('nuevoColores').checked = false;
    kxEl('adder').hidden = true;
    kxTodo();
    kxAviso('Agregado al catálogo y a este kit. Guarda el kit para dejarlo listo.');
  } catch (err) {
    kxAviso(err.message, true);
  }
}

async function kxGuardarItem(boton) {
  const tr = boton.closest('tr');
  const body = {
    id: Number(tr.dataset.fila) || 0,
    nombre: tr.querySelector('.f-nombre').value,
    tipo_talla: tr.querySelector('.f-talla').value,
    colores: tr.querySelector('.f-colores').checked,
    activo: tr.querySelector('.f-activo').checked,
  };
  try {
    const data = await apiFetch('/prevencion/epp_item_guardar.php', { method: 'POST', body });
    await cargarKitsEpp();
    kxAviso(data.mensaje || 'Elemento guardado');
  } catch (err) {
    kxAviso(err.message, true);
  }
}
