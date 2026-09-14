/**
 * Wrapper de fetch para toda la app.
 * - Siempre envía cookies de sesión (credentials: 'include').
 * - Adjunta el header X-CSRF-Token automáticamente si hay uno guardado
 *   (lo setea login.js / dashboard-common.js tras autenticarse).
 * - Normaliza el manejo de errores: lanza un Error con el mensaje que
 *   entregó el backend en `error`.
 */
let CSRF_TOKEN = sessionStorage.getItem('csrf_token') || null;

function setCsrfToken(token) {
  CSRF_TOKEN = token;
  sessionStorage.setItem('csrf_token', token);
}

// v10.14 (pedido explícito del usuario, nota de mano "Mejorar Post.
// Piloto": "también arrojó primero a un postulante un error con rojo en
// su celular que decía algo como 'Failed Fetch'"): cuando el navegador
// no logra ni siquiera conectarse al servidor (una red de celular
// inestable, o el instante justo de un redeploy), fetch() lanza un
// error crudo en inglés ("Failed to fetch") que no significa nada para
// un postulante. Se reintenta una vez, en silencio, después de un
// segundo -- suficiente para cortes breves -- y si vuelve a fallar, se
// muestra un mensaje en español que explica qué hacer.
async function fetchConReintento(url, opciones) {
  try {
    return await fetch(url, opciones);
  } catch (err) {
    await new Promise((resolve) => setTimeout(resolve, 1000));
    try {
      return await fetch(url, opciones);
    } catch (err2) {
      throw new Error('No se pudo conectar. Revisa tu conexión a internet e inténtalo de nuevo en unos segundos.');
    }
  }
}

async function apiFetch(path, { method = 'GET', body = null } = {}) {
  const headers = { 'Content-Type': 'application/json' };
  if (CSRF_TOKEN) headers['X-CSRF-Token'] = CSRF_TOKEN;

  const res = await fetchConReintento(API_BASE_URL + path, {
    method,
    headers,
    credentials: 'include',
    body: body ? JSON.stringify(body) : null,
  });

  // Las descargas de archivo (CSV) no son JSON: dejar pasar tal cual.
  const contentType = res.headers.get('content-type') || '';
  if (!contentType.includes('application/json')) {
    if (!res.ok) throw new Error('No fue posible completar la operación.');
    return res;
  }

  const data = await res.json();
  if (!res.ok || data.ok === false) {
    throw new Error(data.error || 'Ocurrió un error inesperado.');
  }
  return data;
}

/**
 * v2: variante para enviar archivos (multipart/form-data), ej. el CV
 * en la postulación pública. No se fija Content-Type a mano: el
 * navegador arma el boundary correcto solo si se lo dejamos.
 */
async function apiFetchFormData(path, formData) {
  const headers = {};
  if (CSRF_TOKEN) headers['X-CSRF-Token'] = CSRF_TOKEN;

  const res = await fetchConReintento(API_BASE_URL + path, {
    method: 'POST',
    headers,
    credentials: 'include',
    body: formData,
  });

  const data = await res.json();
  if (!res.ok || data.ok === false) {
    throw new Error(data.error || 'Ocurrió un error inesperado.');
  }
  return data;
}
