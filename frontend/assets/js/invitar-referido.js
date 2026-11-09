/**
 * v10.28 (pedido explícito del usuario) - "Invitar a un referido": tarjeta
 * compartida por el panel de Capataz y el de Jefe de Terreno. Entrega el
 * enlace PERSONAL de postulación de quien la usa (index.html?ref=<id>), en
 * cuatro formas: QR, copiar, WhatsApp y correo. Quien postule por ese
 * enlace queda asociado a esta persona (y, si es Capataz, solo él lo ve
 * para seleccionarlo) -- puede hacerlo a distancia, sin ir a la obra.
 *
 * Uso: iniciarInvitacion('id-del-contenedor', usuario)
 * Requiere dashboard-common.js (esc, mostrarAlerta) y api.js (apiFetch).
 */
function iniciarInvitacion(contenedorId, usuario) {
  const cont = document.getElementById(contenedorId);
  if (!cont || !usuario) return;

  const url = `${window.location.origin}${OBRA_PREFIJO}/frontend/public/index.html?ref=${encodeURIComponent(usuario.id)}`;
  const mensajeWsp = `Hola, te invito a postular a ICAFAL (${usuario.obra_nombre || 'nuestra obra'}). Completa tu postulación desde tu celular aquí: ${url}`;

  cont.innerHTML = `
    <div class="bg-white rounded-xl shadow-sm p-5 max-w-xl">
      <h2 class="text-sm font-semibold text-gray-800 mb-1">Invitar a un referido</h2>
      <p class="text-xs text-gray-500 mb-4">
        Comparte tu enlace personal con alguien que quieras recomendar. Puede postular desde su celular,
        <b>sin estar en la obra</b>. Lo que postule por tu enlace queda asociado a ti.
      </p>

      <div class="flex flex-col sm:flex-row items-center gap-4 mb-4">
        <img id="${contenedorId}-qr" alt="QR de tu enlace de invitación"
             class="w-36 h-36 border border-gray-200 rounded-lg shrink-0"
             src="${API_BASE_URL}/public/qr_imagen.php?u=${encodeURIComponent(url)}">
        <div class="min-w-0 w-full">
          <p class="text-xs text-gray-400 mb-1">Tu enlace</p>
          <p class="text-xs text-gray-700 break-all bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 mb-3">${esc(url)}</p>
          <div class="flex flex-wrap gap-2">
            <button type="button" data-accion="copiar" class="text-sm font-semibold border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-2 rounded-lg">📋 Copiar enlace</button>
            <a data-accion="whatsapp" target="_blank" rel="noopener" href="https://wa.me/?text=${encodeURIComponent(mensajeWsp)}"
               class="text-sm font-semibold bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded-lg">💬 WhatsApp</a>
          </div>
        </div>
      </div>

      <form data-accion="correo" class="border-t border-gray-100 pt-4 space-y-3">
        <p class="text-xs font-semibold text-gray-700">O envíalo por correo</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <input name="nombre" type="text" maxlength="100" placeholder="Nombre (opcional)"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
          <input name="correo" type="email" required placeholder="correo@ejemplo.com"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-4 py-2 rounded-lg">Enviar invitación</button>
      </form>
    </div>`;

  cont.querySelector('[data-accion="copiar"]').addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(url);
      mostrarAlerta('alerta', 'Enlace copiado.', 'exito');
    } catch (e) {
      // Sin permiso de portapapeles (o página sin HTTPS): se deja el enlace seleccionado.
      window.prompt('Copia este enlace:', url);
    }
  });

  cont.querySelector('form[data-accion="correo"]').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.currentTarget;
    const boton = form.querySelector('button[type="submit"]');
    boton.disabled = true;
    try {
      const data = await apiFetch('/terreno/invitar_referido.php', {
        method: 'POST',
        body: { correo: form.correo.value, nombre: form.nombre.value },
      });
      mostrarAlerta('alerta', data.mensaje, 'exito');
      form.reset();
    } catch (err) {
      mostrarAlerta('alerta', err.message);
    } finally {
      boton.disabled = false;
    }
  });
}
