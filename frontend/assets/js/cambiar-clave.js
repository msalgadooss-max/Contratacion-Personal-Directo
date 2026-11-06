/**
 * v10.31 - Pantalla "Cambiar mi clave". Se muestra obligatoria en el primer
 * ingreso con una clave temporal (la que puso el Desarrollador), y también
 * se puede abrir cuando se quiera desde el enlace "Mi clave" de cada panel.
 */
const RUTA_POR_ROL_CLAVE = {
  Jefe_Terreno: '../dashboards/terreno.html',
  Capataz: '../dashboards/capataz.html',
  Admin_Contrato: '../dashboards/admin_contrato.html',
  Prevencionista: '../dashboards/prevencion.html',
  Jefe_Bodega: '../dashboards/bodega.html',
  Jefe_Administrativo: '../dashboards/admin_general.html',
  Gerencia: '../dashboards/gerencia.html',
  Porteria: '../dashboards/porteria.html',
  Desarrollador: '../dashboards/dev.html',
};

let ROL_ACTUAL = null;
let ES_OBLIGATORIO = false;

function mostrarMensajeClave(texto, ok = false) {
  const clases = ok
    ? 'bg-green-50 border-green-200 text-green-700'
    : 'bg-red-50 border-red-200 text-red-700';
  const div = document.getElementById('alerta');
  div.innerHTML = '<div class="border text-sm rounded-lg px-4 py-3 mb-4 ' + clases + '"></div>';
  div.firstChild.textContent = texto; // textContent: nunca HTML
}

(async () => {
  try {
    const data = await apiFetch('/auth/me.php');
    setCsrfToken(data.csrf_token);
    ROL_ACTUAL = data.usuario.rol;
    ES_OBLIGATORIO = !!data.debe_cambiar_password;
    document.getElementById('aviso-temporal').classList.toggle('hidden', !ES_OBLIGATORIO);
    const volver = document.getElementById('enlace-volver');
    if (ES_OBLIGATORIO) {
      volver.textContent = 'Cerrar sesión';
      volver.addEventListener('click', async (e) => {
        e.preventDefault();
        try { await apiFetch('/auth/logout.php', { method: 'POST', body: {} }); } catch (x) { /* no-op */ }
        window.location.href = 'login.html';
      });
    } else {
      volver.href = RUTA_POR_ROL_CLAVE[ROL_ACTUAL] || 'login.html';
    }
  } catch (e) {
    window.location.href = 'login.html'; // sin sesión
  }
})();

document.getElementById('form-clave').addEventListener('submit', async (e) => {
  e.preventDefault();
  const actual = document.getElementById('clave-actual').value;
  const nueva = document.getElementById('clave-nueva').value;
  const repetir = document.getElementById('clave-repetir').value;
  if (nueva !== repetir) {
    mostrarMensajeClave('Las dos claves nuevas no coinciden.');
    return;
  }
  const boton = document.getElementById('btn-clave');
  boton.disabled = true;
  boton.textContent = 'Guardando...';
  try {
    const data = await apiFetch('/auth/cambiar_password.php', {
      method: 'POST',
      body: { password_actual: actual, password_nueva: nueva },
    });
    mostrarMensajeClave(data.mensaje + ' Te llevamos a tu panel...', true);
    setTimeout(() => {
      window.location.href = RUTA_POR_ROL_CLAVE[data.rol || ROL_ACTUAL] || 'login.html';
    }, 900);
  } catch (err) {
    mostrarMensajeClave(err.message);
    boton.disabled = false;
    boton.textContent = 'Guardar mi clave';
  }
});
