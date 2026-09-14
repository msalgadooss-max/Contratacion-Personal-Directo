/**
 * Etapa 2 - Formulario privado del postulante (acceso vía token en la URL).
 * v3: los campos con alternativas usan las listas EXACTAS de Buk (para
 * que lo que después navegue al Excel de carga masiva sea correcto,
 * ej. "Banco de Chile" y no "Chile"), y región→comuna funciona en
 * cascada igual que en la Etapa 1.
 *
 * v5.1: se reorganiza en un wizard de 3 pasos (con barra de progreso),
 * se guarda un borrador de los campos de texto en localStorage por si
 * el postulante cierra la pestaña a mitad de camino, y las fotos de
 * documentos se pueden tomar con una cámara guiada (con su respaldo
 * normal de "subir archivo" para navegadores/contextos donde la cámara
 * en vivo no está disponible).
 */
const params = new URLSearchParams(window.location.search);
const token = params.get('token') || '';
const CLAVE_BORRADOR = `icafal_borrador_etapa2_${token}`;

const subtitulo = document.getElementById('subtitulo');
const alertaDiv = document.getElementById('alerta');
const form = document.getElementById('form-datos');
const resultadoDiv = document.getElementById('resultado');
const regionSelect = document.getElementById('region');
const comunaSelect = document.getElementById('comuna');

let REGIONES_COMUNAS = {};
let PASO_ACTUAL = 1;
const TOTAL_PASOS = 4;

// v5.2: etiquetas legibles para la pantalla de "Revisar y confirmar".
const ETIQUETAS_CAMPO = {
  fecha_nacimiento: 'Fecha de nacimiento', sexo: 'Sexo', nacionalidad: 'Nacionalidad', estado_civil: 'Estado civil',
  direccion_exacta: 'Dirección', region: 'Región', comuna: 'Comuna', ciudad: 'Ciudad', pais: 'País',
  afp: 'AFP', isapre_fonasa: 'Fonasa/Isapre', banco: 'Banco', tipo_cuenta: 'Tipo de cuenta', numero_cuenta: 'N° de cuenta',
  estudios: 'Estudios', talla_calzado: 'N° de calzado', talla_overol: 'Talla de overol',
  contacto_emergencia_nombre: 'Contacto de emergencia', contacto_emergencia_telefono: 'Teléfono de emergencia',
};

// --- Documentos: definidos como datos, no HTML repetido, para poder ------
// reutilizar la misma lógica de captura con cámara en los 5 campos. -------
// v10: "formato" define el marco de encuadre que se muestra al tomar la
// foto -- 'tarjeta' (apaisado, proporción de una cédula) para la Cédula
// de Identidad, 'documento' (vertical, proporción carta) para el resto,
// que son certificados de página completa, no tarjetas.
const DOCUMENTOS = [
  { id: 'cedula', etiqueta: 'Cédula de Identidad (frente)', obligatorio: true, ayuda: 'Que se lean claramente el RUT y tu nombre.', grupo: 'cedula', formato: 'tarjeta' },
  { id: 'cedula_reverso', etiqueta: 'Cédula de Identidad (reverso)', obligatorio: true, ayuda: 'El reverso también es obligatorio: trae datos que igual necesitamos.', grupo: 'cedula', formato: 'tarjeta' },
  { id: 'certificado_afp', etiqueta: 'Certificado de AFP', obligatorio: true, ayuda: 'Que se lea el nombre de la AFP completo.', formato: 'documento' },
  { id: 'certificado_salud', etiqueta: 'Certificado de Fonasa/Isapre', obligatorio: true, ayuda: 'Que se lea el nombre completo de tu Isapre o "Fonasa".', formato: 'documento' },
  { id: 'certificado_residencia', etiqueta: 'Certificado de Residencia', obligatorio: true, ayuda: 'Que se lea completa tu dirección.', formato: 'documento' },
  { id: 'ultimo_finiquito', etiqueta: 'Último Finiquito', obligatorio: false, ayuda: 'Si nunca has trabajado antes, puedes omitirlo.', formato: 'documento' },
];

const CAMPOS_TEXTO_PASO = {
  1: ['fecha_nacimiento', 'sexo', 'nacionalidad', 'estado_civil', 'direccion_exacta', 'region', 'comuna', 'ciudad', 'pais'],
  2: ['afp', 'isapre_fonasa', 'banco', 'tipo_cuenta', 'numero_cuenta', 'estudios', 'talla_calzado', 'talla_overol', 'contacto_emergencia_nombre', 'contacto_emergencia_telefono'],
};

function llenarSelect(id, valores) {
  document.getElementById(id).innerHTML = '<option value="">Selecciona</option>' +
    valores.map(v => `<option value="${v}">${v}</option>`).join('');
}

async function cargarListas() {
  const data = await apiFetch('/public/listas.php');
  REGIONES_COMUNAS = data.regiones_comunas;
  llenarSelect('sexo', data.listas.sexo);
  llenarSelect('nacionalidad', data.listas.nacionalidad);
  llenarSelect('estado_civil', data.listas.estado_civil);
  llenarSelect('afp', data.listas.fondo_cotizacion);
  llenarSelect('isapre_fonasa', data.listas.fonasa_isapre);
  llenarSelect('banco', data.listas.banco);
  llenarSelect('tipo_cuenta', data.listas.tipo_cuenta);
  llenarSelect('estudios', data.listas.estudios);
  regionSelect.innerHTML = '<option value="">Selecciona tu región</option>' +
    Object.keys(REGIONES_COMUNAS).map(r => `<option value="${r}">${r}</option>`).join('');
}

regionSelect.addEventListener('change', () => {
  const comunas = REGIONES_COMUNAS[regionSelect.value] || [];
  if (!comunas.length) {
    comunaSelect.innerHTML = '<option value="">Primero elige tu región</option>';
    comunaSelect.disabled = true;
    return;
  }
  comunaSelect.innerHTML = '<option value="">Selecciona tu comuna</option>' +
    comunas.map(c => `<option value="${c}">${c}</option>`).join('');
  comunaSelect.disabled = false;
});

// --- v5.1/v6: campos de documento, generados una vez, con captura guiada --
function campoDocumentoHtml(d) {
  return `
    <div class="${d.grupo ? 'flex-1' : ''}">
      <label class="block text-sm font-medium text-gray-700 mb-1">
        ${d.etiqueta} ${!d.obligatorio ? '<span class="text-gray-400 font-normal">(si aplica)</span>' : ''}
      </label>
      <div class="flex gap-2">
        <button type="button" data-camara="${d.id}" class="btn-tomar-foto shrink-0 bg-blue-50 hover:bg-blue-100 text-blue-700 text-sm font-medium px-3 py-2.5 rounded-lg">
          📷 Tomar foto
        </button>
        <input id="${d.id}" type="file" accept="application/pdf,image/*" capture="environment" ${d.obligatorio ? 'required' : ''}
               class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:bg-blue-50 file:text-blue-700 file:text-sm">
      </div>
      <p id="preview-${d.id}" class="text-xs text-green-600 font-medium mt-1 hidden"></p>
      <p class="text-xs text-gray-400 mt-1">${d.ayuda}</p>
    </div>`;
}

function renderCamposDocumentos() {
  const cont = document.getElementById('contenedor-documentos');
  const html = [];
  let i = 0;
  while (i < DOCUMENTOS.length) {
    const d = DOCUMENTOS[i];
    if (d.grupo === 'cedula') {
      // v6: frente y reverso de la cédula se piden juntos, uno al lado
      // del otro, para dejar claro que son "un mismo ítem" con 2 archivos.
      const reverso = DOCUMENTOS[i + 1];
      html.push(`
        <div>
          <p class="text-sm font-semibold text-gray-800 mb-2">Cédula de Identidad <span class="text-gray-400 font-normal text-xs">(ambos lados)</span></p>
          <div class="flex flex-col sm:flex-row gap-3">${campoDocumentoHtml(d)}${campoDocumentoHtml(reverso)}</div>
        </div>`);
      i += 2;
    } else {
      html.push(campoDocumentoHtml(d));
      i += 1;
    }
  }
  cont.innerHTML = html.join('');

  // v6: el botón "Tomar foto" siempre abre una guía de encuadre -- con
  // cámara en vivo si el navegador lo permite (HTTPS o localhost), o
  // como imagen de referencia + acceso a la cámara nativa si no.
  document.querySelectorAll('.btn-tomar-foto').forEach(btn => {
    btn.addEventListener('click', () => {
      const doc = DOCUMENTOS.find(d => d.id === btn.dataset.camara);
      abrirGuiaCaptura(document.getElementById(btn.dataset.camara), doc ? doc.formato : 'documento');
    });
  });

  DOCUMENTOS.forEach(d => {
    document.getElementById(d.id).addEventListener('change', async (e) => {
      const preview = document.getElementById(`preview-${d.id}`);
      let archivo = e.target.files[0];
      if (!archivo) {
        preview.classList.add('hidden');
        return;
      }
      if (archivo.type === 'image/jpeg' || archivo.type === 'image/png') {
        preview.textContent = '⏳ Convirtiendo a PDF...';
        preview.classList.remove('hidden');
        archivo = await convertirImagenAPdf(archivo);
        const dt = new DataTransfer();
        dt.items.add(archivo);
        e.target.files = dt.files;
      }
      preview.textContent = `✓ ${archivo.name}`;
      preview.classList.remove('hidden');
    });
  });
}

// --- v6: guía de encuadre, con o sin cámara en vivo ------------------------
// Antes, el botón "Tomar foto" solo hacía algo si el navegador soportaba
// cámara en vivo (getUserMedia exige HTTPS o localhost) -- en un demo
// servido por HTTP en la red local eso lo dejaba sin ningún encuadre.
// Ahora SIEMPRE se muestra una guía: con video en vivo si se puede, o
// como imagen de referencia + botón que abre la cámara nativa si no.
let INPUT_OBJETIVO_CAPTURA = null;

function soportaCamaraEnVivo() {
  return !!(window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
}

// v10: proporción ancho/alto de cada tipo de marco -- 'tarjeta' es la
// proporción real de una cédula (ISO/IEC 7810 ID-1, 85.6x53.98mm);
// 'documento' es una hoja carta vertical (215.9x279.4mm), para los
// certificados de página completa.
const RATIOS_FORMATO = { tarjeta: 85.6 / 53.98, documento: 215.9 / 279.4 };

// v10: antes el marco vivo (la caja blanca que ves en pantalla) medía su
// tamaño solo por CSS (w-full + aspect-ratio), pero la foto capturada
// tomaba el cuadro COMPLETO de la cámara, no lo que se veía dentro del
// marco -- de ahí que la foto saliera "más ancha" que el recuadro. Ahora
// el marco se dimensiona en JS (en píxeles concretos) y esos mismos
// píxeles se reutilizan para recortar la captura exactamente ahí.
function actualizarMarcoEncuadre(formato) {
  const ratio = RATIOS_FORMATO[formato] || RATIOS_FORMATO.documento;
  const marcoVivo = document.getElementById('marco-vivo');
  const maxW = marcoVivo.clientWidth - 64; // resta el px-8 (32px por lado)
  const maxH = marcoVivo.clientHeight - 120; // deja espacio para el título arriba
  let w = maxW;
  let h = w / ratio;
  if (h > maxH) {
    h = maxH;
    w = h * ratio;
  }
  const rectVivo = document.getElementById('rect-marco-vivo');
  rectVivo.style.width = `${Math.round(w)}px`;
  rectVivo.style.height = `${Math.round(h)}px`;

  const rectEstatica = document.getElementById('rect-guia-estatica');
  rectEstatica.style.width = formato === 'tarjeta' ? '230px' : '170px';
  rectEstatica.style.aspectRatio = String(ratio);
  document.getElementById('icono-guia-estatica').textContent = formato === 'tarjeta' ? '🪪' : '📄';
}

// Calcula, en píxeles NATIVOS del video (no de pantalla), qué región
// corresponde exactamente al recuadro blanco que se ve en pantalla --
// necesario porque el video se muestra con "object-fit: cover" (recorta
// para llenar la pantalla), así que un píxel de pantalla no es 1:1 con
// un píxel del video real.
function calcularRecorteVideo() {
  const video = document.getElementById('video-camara');
  const marco = document.getElementById('rect-marco-vivo');
  const videoRect = video.getBoundingClientRect();
  const marcoRect = marco.getBoundingClientRect();

  const escala = Math.max(videoRect.width / video.videoWidth, videoRect.height / video.videoHeight);
  const anchoVisibleNativo = videoRect.width / escala;
  const altoVisibleNativo = videoRect.height / escala;
  const offsetXNativo = (video.videoWidth - anchoVisibleNativo) / 2;
  const offsetYNativo = (video.videoHeight - altoVisibleNativo) / 2;

  const relX = (marcoRect.left - videoRect.left) / videoRect.width;
  const relY = (marcoRect.top - videoRect.top) / videoRect.height;
  const relW = marcoRect.width / videoRect.width;
  const relH = marcoRect.height / videoRect.height;

  return {
    x: offsetXNativo + relX * anchoVisibleNativo,
    y: offsetYNativo + relY * altoVisibleNativo,
    w: relW * anchoVisibleNativo,
    h: relH * altoVisibleNativo,
  };
}

async function abrirGuiaCaptura(inputObjetivo, formato = 'documento') {
  INPUT_OBJETIVO_CAPTURA = inputObjetivo;
  const modal = document.getElementById('modal-camara');
  const video = document.getElementById('video-camara');
  const guiaEstatica = document.getElementById('guia-estatica');
  const marcoVivo = document.getElementById('marco-vivo');
  const btnCapturar = document.getElementById('btn-capturar');
  const btnAbrirNativa = document.getElementById('btn-abrir-nativa');
  const titulo = document.getElementById('titulo-guia');

  modal.classList.remove('hidden');

  if (soportaCamaraEnVivo()) {
    try {
      // v10.14 (pedido explícito del usuario): "las imágenes sacadas del
      // postulante tienen mala resolución". Sin pedir un tamaño, muchos
      // navegadores de celular entregan la cámara trasera en una
      // resolución baja por defecto (pensada para videollamadas, no para
      // leer un documento). Se pide una resolución alta como "ideal" --
      // el navegador entrega lo más cercano que su cámara soporte, nunca
      // falla si no llega exacto a esto.
      const stream = await navigator.mediaDevices.getUserMedia({
        video: {
          facingMode: 'environment',
          width: { ideal: 2560 },
          height: { ideal: 1440 },
        },
      });
      video.srcObject = stream;
      video.classList.remove('hidden');
      guiaEstatica.classList.add('hidden');
      marcoVivo.classList.remove('hidden');
      btnCapturar.classList.remove('hidden');
      btnAbrirNativa.classList.add('hidden');
      titulo.textContent = 'Encuadra el documento dentro del marco, con buena luz';
      // v10: recién ahora marco-vivo es visible (clientWidth/Height ya
      // no son 0), así que recién ahora se puede calcular su tamaño.
      requestAnimationFrame(() => actualizarMarcoEncuadre(formato));
      return;
    } catch (err) {
      // sigue abajo con la guía estática como respaldo
    }
  }

  actualizarMarcoEncuadre(formato);

  // Sin cámara en vivo (HTTP sin ser localhost, permiso denegado, o
  // navegador sin soporte): se muestra la referencia y se delega la
  // captura real a la cámara nativa del celular via el input con
  // capture="environment".
  video.classList.add('hidden');
  guiaEstatica.classList.remove('hidden');
  marcoVivo.classList.add('hidden');
  btnCapturar.classList.add('hidden');
  btnAbrirNativa.classList.remove('hidden');
  titulo.textContent = 'Encuadra tu documento así: las 4 esquinas visibles y con buena luz';
}

// v10: la foto capturada NO se usa de inmediato -- primero se muestra
// una pantalla de revisión ("¿se ve completa, nítida y sin recortes?")
// con opción de repetir, para no descubrir una foto mala recién al
// revisar el trámite completo en el Paso 4.
let BLOB_CAPTURADO = null;

function mostrarVistaConfirmar(mostrar) {
  document.getElementById('vista-confirmar').classList.toggle('hidden', !mostrar);
  document.getElementById('vista-confirmar').classList.toggle('flex', mostrar);
  document.getElementById('controles-confirmar').classList.toggle('hidden', !mostrar);
  document.getElementById('controles-captura').classList.toggle('hidden', mostrar);
  document.getElementById('video-camara').classList.toggle('hidden', mostrar);
  document.getElementById('marco-vivo').classList.toggle('hidden', mostrar);
  document.getElementById('titulo-guia').classList.toggle('hidden', mostrar);
}

function cerrarCamara() {
  const modal = document.getElementById('modal-camara');
  const video = document.getElementById('video-camara');
  if (video.srcObject) {
    video.srcObject.getTracks().forEach(t => t.stop());
    video.srcObject = null;
  }
  mostrarVistaConfirmar(false);
  BLOB_CAPTURADO = null;
  modal.classList.add('hidden');
}

document.getElementById('btn-cancelar-captura').addEventListener('click', cerrarCamara);

document.getElementById('btn-abrir-nativa').addEventListener('click', () => {
  const input = INPUT_OBJETIVO_CAPTURA;
  cerrarCamara();
  input.click();
});

document.getElementById('btn-capturar').addEventListener('click', () => {
  const video = document.getElementById('video-camara');
  const canvas = document.getElementById('canvas-captura');
  const recorte = calcularRecorteVideo();
  canvas.width = Math.round(recorte.w);
  canvas.height = Math.round(recorte.h);
  canvas.getContext('2d').drawImage(
    video,
    recorte.x, recorte.y, recorte.w, recorte.h,
    0, 0, canvas.width, canvas.height
  );
  canvas.toBlob((blob) => {
    BLOB_CAPTURADO = blob;
    document.getElementById('img-confirmar').src = URL.createObjectURL(blob);
    mostrarVistaConfirmar(true);
  }, 'image/jpeg', 0.92);
});

document.getElementById('btn-repetir-foto').addEventListener('click', () => {
  mostrarVistaConfirmar(false);
  BLOB_CAPTURADO = null;
});

document.getElementById('btn-usar-foto').addEventListener('click', () => {
  if (!BLOB_CAPTURADO) return;
  const archivo = new File([BLOB_CAPTURADO], `captura_${Date.now()}.jpg`, { type: 'image/jpeg' });
  const dt = new DataTransfer();
  dt.items.add(archivo);
  INPUT_OBJETIVO_CAPTURA.files = dt.files;
  INPUT_OBJETIVO_CAPTURA.dispatchEvent(new Event('change'));
  cerrarCamara();
});

// v10: toda foto (venga de la cámara guiada, de la cámara nativa del
// celular, o de "elegir archivo") se convierte a PDF de una sola página
// ANTES de guardarse -- así lo que llega a Bodega/JAO y lo que después
// se sube a Buk es siempre un PDF, nunca una foto suelta. Si algo falla
// en la conversión, se sigue con la imagen original (el backend igual
// acepta JPG/PNG) para no dejar al postulante bloqueado.
function convertirImagenAPdf(archivo) {
  return new Promise((resolve) => {
    if (archivo.type !== 'image/jpeg' && archivo.type !== 'image/png') {
      resolve(archivo); // ya es PDF u otro tipo permitido: no se toca
      return;
    }
    const lector = new FileReader();
    lector.onerror = () => resolve(archivo);
    lector.onload = () => {
      const img = new Image();
      img.onerror = () => resolve(archivo);
      img.onload = () => {
        try {
          const { jsPDF } = window.jspdf;
          const vertical = img.height >= img.width;
          const pdf = new jsPDF({
            orientation: vertical ? 'portrait' : 'landscape',
            unit: 'px',
            format: [img.width, img.height],
          });
          const tipo = archivo.type === 'image/png' ? 'PNG' : 'JPEG';
          pdf.addImage(lector.result, tipo, 0, 0, img.width, img.height);
          const blob = pdf.output('blob');
          const nombrePdf = archivo.name.replace(/\.[^.]+$/, '') + '.pdf';
          resolve(new File([blob], nombrePdf, { type: 'application/pdf' }));
        } catch (e) {
          resolve(archivo); // conversión falló: seguimos con la imagen
        }
      };
      img.src = lector.result;
    };
    lector.readAsDataURL(archivo);
  });
}

function mostrarAlertaSuave(mensaje) {
  alertaDiv.innerHTML = `<div class="bg-amber-50 border border-amber-200 text-amber-700 text-sm rounded-lg px-4 py-3 mb-4">${mensaje}</div>`;
  setTimeout(() => { alertaDiv.innerHTML = ''; }, 5000);
}

// v6.6: red de seguridad -- se detectó que en algunos celulares (autofill
// de fecha de nacimiento o teléfono de Chrome/Android) el navegador puede
// lanzar un error nativo en inglés ("The string did not match the
// expected pattern") que dejaba al postulante literalmente atascado, sin
// ningún mensaje que le dijera qué hacer. autocomplete="off" en esos
// campos debería evitarlo, pero esto asegura que si de todos modos ocurre
// algún error inesperado, el postulante vea un aviso claro en vez de
// quedar bloqueado en silencio.
window.addEventListener('error', (e) => {
  if (form && !form.classList.contains('hidden')) {
    mostrarAlertaSuave('Hubo un problema inesperado con uno de los campos. Si no puedes avanzar, intenta recargar la página (tus datos de texto ya escritos se recuperan solos) o vuelve a intentarlo desde otro navegador.');
  }
});

// --- v5.1/v5.2: wizard de 4 pasos ------------------------------------------
const NOMBRES_PASO = { 1: 'Datos personales', 2: 'Previsión y banco', 3: 'Documentos', 4: 'Revisar y confirmar' };

function irAPaso(n) {
  document.querySelectorAll('.paso').forEach(el => {
    el.classList.toggle('hidden', Number(el.dataset.paso) !== n);
  });
  document.getElementById('progreso-etiqueta').textContent = `Paso ${n} de ${TOTAL_PASOS}`;
  document.getElementById('progreso-nombre-paso').textContent = NOMBRES_PASO[n];
  document.getElementById('progreso-barra').style.width = `${(n / TOTAL_PASOS) * 100}%`;
  PASO_ACTUAL = n;
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function validarPaso(n) {
  const campos = CAMPOS_TEXTO_PASO[n] || [];
  for (const id of campos) {
    const el = document.getElementById(id);
    if (el.disabled) continue; // ej. comuna antes de elegir región
    if (!el.value) {
      el.focus();
      mostrarAlertaSuave('Completa los campos marcados antes de continuar.');
      return false;
    }
  }
  if (n === 3) {
    for (const d of DOCUMENTOS) {
      if (d.obligatorio && !document.getElementById(d.id).files[0]) {
        mostrarAlertaSuave(`Falta adjuntar "${d.etiqueta}".`);
        return false;
      }
    }
  }
  return true;
}

document.querySelectorAll('.btn-siguiente').forEach(btn => {
  btn.addEventListener('click', () => {
    if (!validarPaso(PASO_ACTUAL)) return;
    guardarBorrador();
    irAPaso(Number(btn.dataset.siguiente));
  });
});

// --- v5.2: "Revisar antes de enviar" ---------------------------------------
document.getElementById('btn-ir-a-revisar').addEventListener('click', () => {
  if (!validarPaso(3)) return;
  guardarBorrador();
  renderResumen();
  irAPaso(4);
});

function textoSeleccionado(id) {
  const el = document.getElementById(id);
  return el.value || '(sin dato)';
}

function renderResumen() {
  const cont = document.getElementById('resumen-revision');
  const filasDatos = [...CAMPOS_TEXTO_PASO[1], ...CAMPOS_TEXTO_PASO[2]].map(id => `
    <div class="flex justify-between gap-3 py-1 text-sm border-b border-gray-100 last:border-0">
      <span class="text-gray-500">${ETIQUETAS_CAMPO[id] || id}</span>
      <span class="text-gray-900 font-medium text-right">${textoSeleccionado(id)}</span>
    </div>`).join('');

  const filasDocs = DOCUMENTOS.map(d => {
    const archivo = document.getElementById(d.id).files[0];
    const texto = archivo ? `✓ ${archivo.name}` : (d.obligatorio ? '⚠ falta' : 'no adjuntado');
    const color = archivo ? 'text-green-700' : (d.obligatorio ? 'text-red-600' : 'text-gray-400');
    return `
      <div class="flex justify-between gap-3 py-1 text-sm border-b border-gray-100 last:border-0">
        <span class="text-gray-500">${d.etiqueta}</span>
        <span class="${color} font-medium text-right truncate max-w-[60%]">${texto}</span>
      </div>`;
  }).join('');

  cont.innerHTML = `
    <div class="bg-gray-50 rounded-lg p-4">
      <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Tus datos</p>
      ${filasDatos}
    </div>
    <div class="bg-gray-50 rounded-lg p-4">
      <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Tus documentos</p>
      ${filasDocs}
    </div>`;
}
document.querySelectorAll('.btn-atras').forEach(btn => {
  btn.addEventListener('click', () => irAPaso(Number(btn.dataset.atras)));
});

// --- v5.1: borrador en localStorage (solo texto, nunca archivos) ----------
function guardarBorrador() {
  const campos = {};
  [...CAMPOS_TEXTO_PASO[1], ...CAMPOS_TEXTO_PASO[2]].forEach(id => {
    campos[id] = document.getElementById(id).value;
  });
  try {
    localStorage.setItem(CLAVE_BORRADOR, JSON.stringify({ paso: PASO_ACTUAL, campos }));
  } catch (e) { /* localStorage no disponible: seguimos sin guardar borrador */ }
}

function restaurarBorrador() {
  let borrador;
  try {
    borrador = JSON.parse(localStorage.getItem(CLAVE_BORRADOR) || 'null');
  } catch (e) { return; }
  if (!borrador) return;

  // La región se restaura primero para que dispare la carga de comunas
  // antes de intentar poner el valor guardado de comuna.
  if (borrador.campos.region) {
    regionSelect.value = borrador.campos.region;
    regionSelect.dispatchEvent(new Event('change'));
  }
  Object.entries(borrador.campos).forEach(([id, valor]) => {
    const el = document.getElementById(id);
    if (el && valor) el.value = valor;
  });
  document.getElementById('aviso-borrador').classList.remove('hidden');
  irAPaso(borrador.paso || 1);
}

// Autoguardado mientras el postulante escribe, no solo al presionar "Siguiente".
form.addEventListener('input', () => { if (!form.classList.contains('hidden')) guardarBorrador(); });

async function inicializar() {
  if (!token) {
    mostrarError('Enlace inválido: falta el token de acceso.');
    return;
  }
  try {
    const [infoToken] = await Promise.all([
      apiFetch(`/privado/token_info.php?token=${encodeURIComponent(token)}`),
      cargarListas(),
    ]);
    subtitulo.textContent = `Hola ${infoToken.postulacion.nombre_completo}, completa tus datos para continuar.`;
    renderCamposDocumentos();
    form.classList.remove('hidden');
    document.getElementById('progreso-wizard').classList.remove('hidden');
    document.getElementById('ayuda-asistida').classList.remove('hidden');
    restaurarBorrador();
  } catch (err) {
    mostrarError(err.message);
  }
}

function mostrarError(mensaje) {
  subtitulo.textContent = '';
  alertaDiv.innerHTML = `<div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3">${mensaje}</div>`;
}

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!validarPaso(3)) return;

  const btn = document.getElementById('btn-guardar');
  btn.disabled = true;
  btn.textContent = 'Guardando...';

  const camposTexto = [
    'fecha_nacimiento', 'sexo', 'nacionalidad', 'estado_civil', 'direccion_exacta',
    'region', 'comuna', 'ciudad', 'pais', 'afp', 'isapre_fonasa', 'banco',
    'tipo_cuenta', 'numero_cuenta', 'estudios',
    'contacto_emergencia_nombre', 'contacto_emergencia_telefono',
    'talla_calzado', 'talla_overol',
  ];

  const formData = new FormData();
  formData.append('token', token);
  camposTexto.forEach(id => formData.append(id, document.getElementById(id).value));
  DOCUMENTOS.forEach(d => {
    const input = document.getElementById(d.id);
    if (input.files[0]) formData.append(d.id, input.files[0]);
  });

  try {
    await apiFetchFormData('/privado/guardar_datos.php', formData);
    try { localStorage.removeItem(CLAVE_BORRADOR); } catch (e) { /* no-op */ }
    form.classList.add('hidden');
    document.getElementById('progreso-wizard').classList.add('hidden');
    document.getElementById('ayuda-asistida').classList.add('hidden');
    resultadoDiv.classList.remove('hidden');
    resultadoDiv.innerHTML = `
      <div class="text-green-600 text-4xl">✔</div>
      <h2 class="text-lg font-bold text-gray-900 mt-2">¡Datos guardados!</h2>
      <p class="text-sm text-gray-600 mt-1">Tu proceso continúa. Puedes revisar tu avance en el módulo de seguimiento.</p>`;
  } catch (err) {
    mostrarError(err.message);
    window.scrollTo({ top: 0, behavior: 'smooth' });
    btn.disabled = false;
    btn.textContent = 'Guardar datos';
  }
});

inicializar();
