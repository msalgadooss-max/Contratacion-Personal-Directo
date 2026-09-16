/**
 * Validación de RUT chileno en el cliente (UX inmediata). El backend
 * (backend/includes/rut.php) vuelve a validar todo: esto solo evita
 * viajes de red innecesarios y da feedback rápido al postulante.
 */
function normalizarRut(rut) {
  rut = (rut || '').toUpperCase().trim().replace(/[.\s]/g, '');
  if (!rut.includes('-')) {
    rut = rut.slice(0, -1) + '-' + rut.slice(-1);
  }
  return rut;
}

function validarRut(rutCompleto) {
  const rut = normalizarRut(rutCompleto);
  const match = /^(\d{7,8})-([\dkK])$/.exec(rut);
  if (!match) return false;

  const cuerpo = match[1];
  const dvIngresado = match[2].toUpperCase();

  let suma = 0;
  let multiplicador = 2;
  for (let i = cuerpo.length - 1; i >= 0; i--) {
    suma += parseInt(cuerpo[i], 10) * multiplicador;
    multiplicador = multiplicador === 7 ? 2 : multiplicador + 1;
  }

  const resto = 11 - (suma % 11);
  let dvCalculado;
  if (resto === 11) dvCalculado = '0';
  else if (resto === 10) dvCalculado = 'K';
  else dvCalculado = String(resto);

  return dvCalculado === dvIngresado;
}

// v10.15 -- hallazgo real durante una prueba de punta a punta: este
// campo se usa en pantallas públicas (seguimiento.html, induccion.js)
// que NO preguntan tipo de documento -- es el mismo campo para quien
// postuló con RUT y para quien postuló con "Otro" documento (pasaporte,
// DNI extranjero, etc., ver Etapa 1). Antes se le quitaba a la fuerza
// cualquier caracter que no fuera dígito/K y se le insertaba un guion,
// mutilando cualquier documento que no fuera un RUT chileno -- alguien
// sin RUT nunca podía consultar su estado. Ahora el auto-guion solo se
// aplica si lo que se ha tipeado hasta ahora sigue pareciendo un RUT
// (solo dígitos/K); en cualquier otro caso se deja el texto tal cual.
function formatearRutInput(input) {
  input.addEventListener('input', () => {
    const pareceRut = /^[0-9kK-]*$/.test(input.value);
    if (!pareceRut) return;
    let valor = input.value.toUpperCase().replace(/[^0-9K]/g, '');
    if (valor.length > 1) {
      valor = valor.slice(0, -1) + '-' + valor.slice(-1);
    }
    input.value = valor;
  });
}
