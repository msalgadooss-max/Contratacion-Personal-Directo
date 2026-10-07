(async () => {
  const usuario = await protegerDashboard('Porteria');
  if (!usuario) return;
})();

let ULTIMA_CONSULTA = null;

// --- v10.21: día de contratación (8 am) ----------------------------------
// Portería busca por cédula, ve la fase y autoriza el paso a contratación;
// eso le avisa al JAO y le habilita la firma de contrato.
let RUT_CONTRATACION = '';

function esc(valor) {
  const d = document.createElement('div');
  d.textContent = valor == null ? '' : String(valor);
  return d.innerHTML;
}

document.getElementById('form-contratacion').addEventListener('submit', async (e) => {
  e.preventDefault();
  await buscarContratacion(document.getElementById('rut-contratacion').value.trim());
});

async function buscarContratacion(rut) {
  const btn = document.getElementById('btn-buscar-contratacion');
  const cont = document.getElementById('resultado-contratacion');
  btn.disabled = true;
  btn.textContent = 'Buscando...';
  cont.innerHTML = '';
  try {
    const d = await apiFetch(`/porteria/buscar_contratacion.php?rut=${encodeURIComponent(rut)}`);
    RUT_CONTRATACION = rut;
    cont.innerHTML = `
      <div class="border rounded-xl p-4 ${d.puede_autorizar ? 'bg-green-50 border-green-300' : 'bg-gray-50 border-gray-200'}">
        <p class="font-bold text-gray-900">${esc(d.nombre_completo)}</p>
        <p class="text-sm text-gray-500 font-mono">${esc(d.rut)} · ${esc(d.cargo)}</p>
        <p class="text-sm font-semibold mt-3 ${d.puede_autorizar ? 'text-green-700' : 'text-gray-700'}">${esc(d.fase)}</p>
        ${d.puede_autorizar
          ? `<button id="btn-autorizar-contratacion" onclick="autorizarContratacion()" class="w-full mt-4 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-lg py-3 text-base">Autorizar paso a contratación</button>`
          : ''}
      </div>`;
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  } finally {
    btn.disabled = false;
    btn.textContent = 'Ver fase';
  }
}

async function autorizarContratacion() {
  if (!confirm('¿Autorizar el paso de esta persona a contratación? Se le avisará al JAO.')) return;
  const btn = document.getElementById('btn-autorizar-contratacion');
  btn.disabled = true;
  btn.textContent = 'Autorizando...';
  try {
    const data = await apiFetch('/porteria/autorizar_contratacion.php', { method: 'POST', body: { rut: RUT_CONTRATACION } });
    mostrarAlerta('alerta', data.mensaje, 'exito');
    await buscarContratacion(RUT_CONTRATACION);
  } catch (err) {
    mostrarAlerta('alerta', err.message);
    btn.disabled = false;
    btn.textContent = 'Autorizar paso a contratación';
  }
}

document.getElementById('form-consulta').addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = document.getElementById('btn-consultar');
  const resultadoDiv = document.getElementById('resultado');
  btn.disabled = true;
  btn.textContent = 'Verificando...';
  resultadoDiv.innerHTML = '';

  const rut = document.getElementById('rut').value;
  const codigo = document.getElementById('codigo').value.trim().toUpperCase();
  ULTIMA_CONSULTA = { rut, codigo };

  try {
    const data = await apiFetch('/porteria/consultar.php', { method: 'POST', body: { rut, codigo } });
    const autorizado = data.estado_acceso === 'AUTORIZADO';
    resultadoDiv.innerHTML = `
      <div class="${autorizado ? 'bg-green-500' : 'bg-red-600'} rounded-xl p-6 text-center text-white shadow-lg">
        <p class="text-2xl font-extrabold">${data.estado_acceso}</p>
        ${data.mensaje ? `<p class="text-sm font-medium mt-1 opacity-90">${data.mensaje}</p>` : ''}
        <div class="bg-white/10 rounded-lg p-3 text-left space-y-1 mt-4 text-sm">
          <p><span class="opacity-70">Nombre:</span> <strong>${data.nombre_completo}</strong></p>
          <p><span class="opacity-70">RUT:</span> <strong>${data.rut}</strong></p>
          <p><span class="opacity-70">Cargo:</span> <strong>${data.cargo}</strong></p>
        </div>
      </div>`;
    // v7: además de la consulta de acceso a la obra (post-EPP), se
    // ofrece confirmar el ingreso a faena del día 1 (candado para JAO).
    await mostrarBotonIngresoFaena(rut, codigo);
  } catch (err) {
    mostrarAlerta('alerta', err.message);
  } finally {
    btn.disabled = false;
    btn.textContent = 'Verificar';
  }
});

async function mostrarBotonIngresoFaena(rut, codigo) {
  const cont = document.getElementById('ingreso-faena');
  cont.innerHTML = '';
  try {
    const data = await apiFetch(`/porteria/ingreso_estado.php?rut=${encodeURIComponent(rut)}&codigo=${encodeURIComponent(codigo)}`);
    if (data.ya_confirmado) {
      cont.innerHTML = `<div class="bg-green-50 border border-green-200 text-green-700 text-sm font-medium rounded-lg px-4 py-3 text-center">✓ Ingreso a faena ya confirmado</div>`;
    } else if (data.puede_confirmar) {
      cont.innerHTML = `<button id="btn-ingreso-faena" onclick="confirmarIngresoFaena()" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg py-3 text-base">Confirmar ingreso a faena</button>`;
    }
  } catch (err) {
    // Si no aplica (ej. otra fase del proceso), simplemente no se muestra nada.
  }
}

async function confirmarIngresoFaena() {
  if (!ULTIMA_CONSULTA) return;
  const btn = document.getElementById('btn-ingreso-faena');
  btn.disabled = true;
  btn.textContent = 'Confirmando...';
  try {
    const data = await apiFetch('/porteria/marcar_ingreso.php', { method: 'POST', body: ULTIMA_CONSULTA });
    mostrarAlerta('alerta', data.mensaje, 'exito');
    await mostrarBotonIngresoFaena(ULTIMA_CONSULTA.rut, ULTIMA_CONSULTA.codigo);
  } catch (err) {
    mostrarAlerta('alerta', err.message);
    btn.disabled = false;
    btn.textContent = 'Confirmar ingreso a faena';
  }
}
