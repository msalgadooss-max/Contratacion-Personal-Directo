/**
 * Punto único de configuración del frontend.
 * Si el sistema se sirve desde la raíz del hosting, dejar tal cual.
 * Si vive en un subdirectorio (ej. /rrhh), ajustar API_BASE_URL.
 */
// v10.32: varias obras en la misma plataforma. La obra por defecto (H57) vive
// en la raiz; las demas bajo un prefijo (/h58/...). Todas las paginas leen el
// prefijo de la propia direccion, asi cada obra llama a SU backend y SU base.
const OBRA_PREFIJO = (function () {
  const m = window.location.pathname.match(/^\/(h\d+)(?=\/)/);
  return m ? '/' + m[1] : '';
})();
const API_BASE_URL = OBRA_PREFIJO + '/backend/api';
