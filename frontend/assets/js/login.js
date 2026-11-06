/**
 * Login interno. Tras autenticar, redirige al dashboard correspondiente
 * a su rol -- el propio backend valida el rol en cada endpoint, esto
 * solo evita que un usuario aterrice en el dashboard equivocado.
 */
const RUTA_POR_ROL = {
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

document.getElementById('form-login').addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = document.getElementById('btn-login');
  btn.disabled = true;
  btn.textContent = 'Ingresando...';

  try {
    const data = await apiFetch('/auth/login.php', {
      method: 'POST',
      body: {
        correo: document.getElementById('correo').value,
        password: document.getElementById('password').value,
      },
    });
    setCsrfToken(data.csrf_token);
    if (data.debe_cambiar_password) {
      window.location.href = 'cambiar_clave.html';
      return;
    }
    window.location.href = RUTA_POR_ROL[data.usuario.rol] || '../public/login.html';
  } catch (err) {
    document.getElementById('alerta').innerHTML =
      `<div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3 mb-4">${err.message}</div>`;
    btn.disabled = false;
    btn.textContent = 'Ingresar';
  }
});
