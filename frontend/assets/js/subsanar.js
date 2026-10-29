/**
 * v5 - Página pública para que el postulante corrija SOLO el/los
 * documentos que el JAO marcó como observados, usando el token de
 * subsanación de su correo. No repite toda la Etapa 2.
 */
const params = new URLSearchParams(window.location.search);
const token = params.get('token') || '';

const cargandoDiv = document.getElementById('cargando');
const form = document.getElementById('form-subsanar');
const resultadoDiv = document.getElementById('resultado');
const alertaDiv = document.getElementById('alerta');

async function cargar() {
  if (!token) {
    cargandoDiv.textContent = 'Enlace incompleto.';
    return;
  }
  try {
    const data = await apiFetch(`/public/subsanar_info.php?token=${encodeURIComponent(token)}`);
    cargandoDiv.classList.add('hidden');
    form.classList.remove('hidden');
    form.innerHTML = `
      <p class="text-sm text-gray-600">Hola <strong>${esc(data.nombre_completo)}</strong>, esto es lo que necesitamos que corrijas:</p>
      ${data.documentos.map(d => `
        <div class="border border-amber-200 bg-amber-50 rounded-lg p-4">
          <p class="text-sm font-semibold text-amber-800">${esc(d.etiqueta)}</p>
          <p class="text-xs text-amber-700 mt-1 mb-3">${esc(d.motivo)}</p>
          <input type="file" name="${esc(d.tipo)}" accept="application/pdf,image/*"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm bg-white file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:bg-blue-50 file:text-blue-700 file:text-sm">
        </div>`).join('')}
      <button type="submit" id="btn-enviar"
              class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg py-3 text-base transition">
        Enviar corrección
      </button>`;
  } catch (err) {
    cargandoDiv.textContent = err.message;
  }
}

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = document.getElementById('btn-enviar');
  btn.disabled = true;
  btn.textContent = 'Enviando...';

  try {
    const formData = new FormData(form);
    formData.append('token', token);
    const data = await apiFetchFormData('/public/subsanar_enviar.php', formData);
    form.classList.add('hidden');
    resultadoDiv.classList.remove('hidden');
    resultadoDiv.innerHTML = `
      <div class="text-green-600 text-4xl">✔</div>
      <h2 class="text-lg font-bold text-gray-900">¡Listo!</h2>
      <p class="text-sm text-gray-600">${esc(data.mensaje)}</p>`;
  } catch (err) {
    alertaDiv.innerHTML = `<div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3 mb-4">${esc(err.message)}</div>`;
    window.scrollTo({ top: 0, behavior: 'smooth' });
    btn.disabled = false;
    btn.textContent = 'Enviar corrección';
  }
});

cargar();
