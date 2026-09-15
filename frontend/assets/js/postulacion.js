/**
 * Lógica de la Etapa 1 (formulario público de postulación).
 * v3: campos alineados a la plantilla Buk + listas desplegables reales
 * (tipo de documento) cargadas desde /public/listas.php, para que el
 * postulante nunca escriba libremente algo que después no calce con lo
 * que Buk espera.
 *
 * v10.14 (pedido explícito del usuario): región/comuna se sacan de acá
 * -- se piden recién en la Etapa 2 (completar.js), donde ya se
 * preguntan junto con el resto de los datos de contratación (ciudad,
 * país, dirección exacta). Antes se pedían dos veces, una vez aquí y
 * otra en Etapa 2.
 */
const tipoDocumentoSelect = document.getElementById('tipo_documento');
const numeroDocumentoInput = document.getElementById('numero_documento');
const labelDocumento = document.getElementById('label-documento');
const rutError = document.getElementById('rut-error');
const docNota = document.getElementById('doc-nota');
const form = document.getElementById('form-postulacion');
const resultadoDiv = document.getElementById('resultado');
const btnEnviar = document.getElementById('btn-enviar');
const obraBanner = document.getElementById('obra-banner');
const correoUsuarioInput = document.getElementById('correo_usuario');
const correoDominioSelect = document.getElementById('correo_dominio');
const correoDominioOtroInput = document.getElementById('correo_dominio_otro');
const correoHidden = document.getElementById('correo');

// v4: dominio de correo desplegable -- el postulante solo escribe su
// nombre de usuario y elige el dominio de una lista (o "Otro..." para
// escribirlo completo), para que sea más rápido y evite errores de tipeo.
correoDominioSelect.addEventListener('change', () => {
  correoDominioOtroInput.classList.toggle('hidden', correoDominioSelect.value !== '__otro__');
  if (correoDominioSelect.value === '__otro__') correoDominioOtroInput.focus();
});

// v10.14 (pedido explícito del usuario): "cuando el postulante le pone
// tilde a una letra vocal del correo, no deja avanzar". El teclado del
// celular a veces autocorrige/acentúa una palabra en este campo sin que
// la persona se dé cuenta (ej. "jose" -> "josé") -- un correo real
// nunca tiene tilde en el nombre de usuario, así que el servidor lo
// rechaza como inválido. En vez de solo explicarlo, se le quita la
// tilde en vivo mientras escribe, para que nunca llegue a bloquearlo.
function quitarTildes(texto) {
  return texto.normalize('NFD').replace(/[̀-ͯ]/g, '');
}

function actualizarCorreoCompuesto() {
  const sinTildes = quitarTildes(correoUsuarioInput.value);
  if (sinTildes !== correoUsuarioInput.value) {
    const posicion = correoUsuarioInput.selectionStart;
    correoUsuarioInput.value = sinTildes;
    correoUsuarioInput.setSelectionRange(posicion, posicion);
  }
  const dominio = correoDominioSelect.value === '__otro__'
    ? correoDominioOtroInput.value.trim()
    : correoDominioSelect.value;
  correoHidden.value = correoUsuarioInput.value.trim() + dominio;
}
correoUsuarioInput.addEventListener('input', actualizarCorreoCompuesto);
correoDominioSelect.addEventListener('change', actualizarCorreoCompuesto);
correoDominioOtroInput.addEventListener('input', actualizarCorreoCompuesto);

async function cargarListas() {
  try {
    const data = await apiFetch('/public/listas.php');
    obraBanner.textContent = '📍 ' + (data.obra || 'Obra ICAFAL');

    tipoDocumentoSelect.innerHTML = data.listas.tipo_documento
      .map(v => `<option value="${v}">${v}</option>`).join('');
  } catch (e) {
    tipoDocumentoSelect.innerHTML = '<option value="">Error al cargar</option>';
  }
}

tipoDocumentoSelect.addEventListener('change', () => {
  const esRut = tipoDocumentoSelect.value === 'RUT';
  labelDocumento.textContent = esRut ? 'RUT' : 'N° de documento';
  numeroDocumentoInput.placeholder = esRut ? '12345678-9' : 'Pasaporte, DNI, etc.';
  docNota.classList.toggle('hidden', esRut);
  rutError.classList.add('hidden');
  numeroDocumentoInput.value = '';
});

numeroDocumentoInput.addEventListener('input', () => {
  if (tipoDocumentoSelect.value === 'RUT') {
    let valor = numeroDocumentoInput.value.toUpperCase().replace(/[^0-9K]/g, '');
    if (valor.length > 1) valor = valor.slice(0, -1) + '-' + valor.slice(-1);
    numeroDocumentoInput.value = valor;
  }
});
numeroDocumentoInput.addEventListener('blur', () => {
  if (tipoDocumentoSelect.value !== 'RUT') return;
  const valido = numeroDocumentoInput.value === '' || validarRut(numeroDocumentoInput.value);
  rutError.classList.toggle('hidden', valido);
});

cargarListas();

// v6.9: "No tengo CV" -- Ricardo pidió no bloquear al postulante que
// nunca ha trabajado o no tiene su CV a mano; en vez de eso, se le pide
// contar su última experiencia en 3 campos simples.
const sinCvCheckbox = document.getElementById('sin-cv');
const cvInput = document.getElementById('cv');
const experienciaManualDiv = document.getElementById('experiencia-manual');
sinCvCheckbox.addEventListener('change', () => {
  const sinCv = sinCvCheckbox.checked;
  experienciaManualDiv.classList.toggle('hidden', !sinCv);
  cvInput.required = !sinCv;
  cvInput.disabled = sinCv;
  if (sinCv) cvInput.value = '';
});

// v10.15 (pedido explícito del usuario, item 4 de la lista post-prueba):
// "Sacar foto con la cámara" abre la cámara (no la galería) gracias a
// capture="environment" en el input oculto, y el archivo resultante se
// traspasa al input real de CV via DataTransfer -- así se ve reflejado
// en su nombre de archivo normal y el resto del formulario (envío,
// validaciones) no necesita saber que vino de la cámara y no del picker.
const btnFotoCv = document.getElementById('btn-foto-cv');
const cvCamaraInput = document.getElementById('cv-camara');
if (btnFotoCv && cvCamaraInput) {
  btnFotoCv.addEventListener('click', () => cvCamaraInput.click());
  cvCamaraInput.addEventListener('change', () => {
    if (!cvCamaraInput.files[0]) return;
    const dt = new DataTransfer();
    dt.items.add(cvCamaraInput.files[0]);
    cvInput.files = dt.files;
    cvInput.dispatchEvent(new Event('change', { bubbles: true }));
  });
}

form.addEventListener('submit', async (e) => {
  e.preventDefault();

  if (tipoDocumentoSelect.value === 'RUT' && !validarRut(numeroDocumentoInput.value)) {
    rutError.classList.remove('hidden');
    numeroDocumentoInput.focus();
    return;
  }

  actualizarCorreoCompuesto();
  if (!correoUsuarioInput.value.trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(correoHidden.value)) {
    document.getElementById('alerta').innerHTML =
      '<div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3 mb-4">Ingresa un correo electrónico válido.</div>';
    window.scrollTo({ top: 0, behavior: 'smooth' });
    return;
  }

  if (sinCvCheckbox.checked && !document.getElementById('experiencia_descripcion').value.trim()) {
    document.getElementById('alerta').innerHTML =
      '<div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3 mb-4">Cuéntanos brevemente tu experiencia (o sube tu CV en vez de marcar "No tengo CV").</div>';
    window.scrollTo({ top: 0, behavior: 'smooth' });
    return;
  }

  btnEnviar.disabled = true;
  btnEnviar.textContent = 'Enviando...';

  try {
    const formData = new FormData();
    formData.append('tipo_documento', tipoDocumentoSelect.value);
    formData.append('numero_documento', numeroDocumentoInput.value);
    formData.append('nombre', document.getElementById('nombre').value);
    formData.append('apellido', document.getElementById('apellido').value);
    formData.append('segundo_apellido', document.getElementById('segundo_apellido').value);
    formData.append('telefono', document.getElementById('telefono').value);
    formData.append('correo', document.getElementById('correo').value);
    formData.append('consentimiento_ley19628', document.getElementById('consentimiento').checked ? '1' : '');
    if (sinCvCheckbox.checked) {
      formData.append('experiencia_cargo', document.getElementById('experiencia_cargo').value);
      formData.append('experiencia_fecha', document.getElementById('experiencia_fecha').value);
      formData.append('experiencia_descripcion', document.getElementById('experiencia_descripcion').value);
    } else if (cvInput.files[0]) {
      formData.append('cv', cvInput.files[0]);
    }

    const data = await apiFetchFormData('/public/postular.php', formData);

    form.classList.add('hidden');
    resultadoDiv.classList.remove('hidden');
    resultadoDiv.innerHTML = `
      <div class="text-green-600 text-4xl">✔</div>
      <h2 class="text-lg font-bold text-gray-900">¡Postulación enviada!</h2>
      <p class="text-sm text-gray-600">Gracias por postular a ICAFAL.</p>
      <p class="text-sm text-gray-600 mt-2">Para ver el estado de tu postulación, accede con tu RUT y tu correo electrónico.</p>
      <a href="seguimiento.html" class="inline-block mt-2 bg-blue-600 text-white rounded-lg px-4 py-2 text-sm font-medium">Ir a seguimiento</a>
    `;
  } catch (err) {
    document.getElementById('alerta').innerHTML =
      `<div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3 mb-4">${err.message}</div>`;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  } finally {
    btnEnviar.disabled = false;
    btnEnviar.textContent = 'Enviar postulación';
  }
});
