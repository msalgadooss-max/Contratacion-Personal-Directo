/**
 * v6.5 - Guía de uso compartida por los 3 dashboards internos que
 * participan del flujo de contratación (Jefe_Terreno, Admin_Contrato,
 * Jefe_Administrativo/JAO). Un solo archivo con el contenido de las 3
 * guías para no duplicar el resumen general del proceso en cada
 * dashboard -- cada uno solo pide su sección con abrirGuia('rol').
 *
 * Uso: <button onclick="abrirGuia('admin_contrato')">Guía de uso</button>
 * y agregar <script src="../assets/js/guia-proceso.js"></script>.
 */

const GUIA_RESUMEN_GENERAL = `
  <ol class="space-y-3">
    <li class="flex gap-3"><span class="shrink-0 w-6 h-6 rounded-full bg-gray-200 text-gray-700 text-xs font-bold flex items-center justify-center">0</span>
      <div><p class="font-semibold text-gray-900">Jefe de Terreno solicita cupos, Administrador abre la vacante</p>
      <p class="text-gray-600">Todo cargo parte sin cupos. Jefe de Terreno pide, por ejemplo, "5 jornales", y esa solicitud queda <b>Pendiente</b> hasta que el Administrador de Contrato la aprueba. Recién ahí se abre la vacante: el cargo muestra cupos disponibles y la gente puede postular a él.</p></div></li>
    <li class="flex gap-3"><span class="shrink-0 w-6 h-6 rounded-full bg-gray-200 text-gray-700 text-xs font-bold flex items-center justify-center">1</span>
      <div><p class="font-semibold text-gray-900">El postulante postula solo</p>
      <p class="text-gray-600">Llena sus datos básicos, sube su CV y su cédula de identidad (ambos lados) desde el formulario público (QR en portería). Queda en estado <b>Pendiente</b>.</p></div></li>
    <li class="flex gap-3"><span class="shrink-0 w-6 h-6 rounded-full bg-gray-200 text-gray-700 text-xs font-bold flex items-center justify-center">2</span>
      <div><p class="font-semibold text-gray-900">Jefe de Terreno sigue el avance</p>
      <p class="text-gray-600">Ya no aprueba postulaciones una por una: desde su panel ve quién fue seleccionado y en qué paso va cada persona, y pide los cupos que necesita (paso 0). Todo lo que llega por el QR lo ve directamente el Capataz.</p></div></li>
    <li class="flex gap-3"><span class="shrink-0 w-6 h-6 rounded-full bg-gray-200 text-gray-700 text-xs font-bold flex items-center justify-center">3</span>
      <div><p class="font-semibold text-gray-900">El Capataz selecciona en persona, en portería</p>
      <p class="text-gray-600">Compara el RUT declarado contra la cédula física, marca que trae sus documentos y arrastra su tarjeta hasta el cargo. Con eso la postulación pasa a <b>Seleccionado en terreno</b> y se reserva un cupo del cargo. La persona recibe un correo con su QR de ingreso y el enlace de la Etapa 2, y el JAO recibe un aviso.</p></div></li>
    <li class="flex gap-3"><span class="shrink-0 w-6 h-6 rounded-full bg-blue-100 text-blue-700 text-xs font-bold flex items-center justify-center">4</span>
      <div><p class="font-semibold text-gray-900">Pasa a la sala de espera</p>
      <p class="text-gray-600">Portería escanea su QR de ingreso y lo deja pasar a la sala de espera, donde completa sus datos. El Administrador de Contrato ya no autoriza persona por persona: su parte termina al aprobar los cupos (paso 0).</p></div></li>
    <li class="flex gap-3"><span class="shrink-0 w-6 h-6 rounded-full bg-gray-200 text-gray-700 text-xs font-bold flex items-center justify-center">5</span>
      <div><p class="font-semibold text-gray-900">El postulante completa la Etapa 2</p>
      <p class="text-gray-600">Con el enlace recibido, carga sus datos de contratación y el resto de sus documentos (contrato, Fonasa/Isapre, AFP, etc.). Al terminar, queda lista para que el JAO verifique su identidad, y Bodega recibe en un solo correo las tallas del día.</p></div></li>
    <li class="flex gap-3"><span class="shrink-0 w-6 h-6 rounded-full bg-gray-200 text-gray-700 text-xs font-bold flex items-center justify-center">6</span>
      <div><p class="font-semibold text-gray-900">Día 0 (postulación): el JAO verifica y Prevención verifica</p>
      <p class="text-gray-600">Portería confirma su ingreso con el QR (control de la puerta). El JAO verifica que el RUT coincida con la cédula y le da su check -- sin depender de que Portería haya escaneado. Después Prevención hace su verificación y también le da su check -- ahí termina su día de postulación y el postulante recibe un correo: "preséntate mañana a las 8 am".</p></div></li>
    <li class="flex gap-3"><span class="shrink-0 w-6 h-6 rounded-full bg-gray-200 text-gray-700 text-xs font-bold flex items-center justify-center">7</span>
      <div><p class="font-semibold text-gray-900">Día 1 (contratación, 8 am): Portería autoriza, JAO firma y enrola, Prevención hace la IRL y Bodega entrega el EPP</p>
      <p class="text-gray-600">El trabajador vuelve al otro día: Portería ve su fase por cédula y autoriza su paso a contratación (el JAO recibe el aviso), firma el contrato y su enrolamiento de asistencia con el JAO, pasa con Prevención a la IRL (segundo check) y luego a Bodega por su kit. Al entregarlo, el postulante queda <b>✔ Contratado</b> y listo para incorporarse a la cuadrilla del Capataz que lo reclutó, y Bodega envía una nómina con todos los liberados a Capataz, Jefe de Terreno y el resto del equipo.</p></div></li>
    <li class="flex gap-3"><span class="shrink-0 w-6 h-6 rounded-full bg-green-100 text-green-700 text-xs font-bold flex items-center justify-center">8</span>
      <div><p class="font-semibold text-gray-900">Cierre: Capataz/Jefe de Terreno lo van a buscar</p>
      <p class="text-gray-600">Con el EPP entregado, se avisa a Capataz y Jefe de Terreno para que lo busquen en sala de reuniones o Bodega. Al confirmar que lo recibieron, el proceso queda <b>✔ completo</b> de punta a punta.</p></div></li>
  </ol>
  <p class="text-xs text-gray-400 mt-4">En cualquier etapa antes de esto, Capataz, Jefe de Terreno o Administrador de Contrato pueden rechazar la postulación con un motivo estandarizado; el postulante siempre recibe el mismo mensaje genérico, nunca el motivo real (ese queda solo en el registro interno).</p>
`;

const GUIA_POR_ROL = {
  terreno: {
    titulo: 'Guía de uso · Jefe de Terreno',
    contenido: `
      <p class="text-gray-700 mb-4">Tu rol es <b>solicitar cupos y hacer seguimiento</b> -- ya no apruebas ni rechazas postulantes uno por uno; eso lo hace el Capataz, en persona, en portería.</p>
      <ul class="space-y-2.5 text-gray-700">
        <li>• <b>Pestaña "Solicitar Cupos":</b> pide, por ejemplo, "5 jornales". La solicitud queda <b>Pendiente</b> hasta que el Administrador de Contrato la aprueba -- recién ahí se abre la vacante y el cargo muestra cupos disponibles. En la tabla de abajo ves el estado de tus solicitudes (Pendiente/Aprobada/Rechazada).</li>
        <li>• <b>¿El cargo que necesitas no está en la lista?</b> Elige "➕ Otro (agregar cargo nuevo)" y escribe su nombre -- el cargo se crea en el catálogo recién cuando el Administrador de Contrato apruebe esa solicitud, no antes.</li>
        <li>• <b>Pestaña "Banco de Postulantes":</b> de solo lectura -- todos los que acaban de llegar por el formulario público (QR), esperando que el Capataz los seleccione en persona. No hay ninguna acción que tomar aquí, solo seguimiento.</li>
        <li>• <b>Pestaña "Postulantes":</b> quienes el Capataz ya seleccionó y siguen avanzando (completando Etapa 2, en revisión del JAO, etc.).</li>
        <li>• <b>Pestaña "Personal Contratado":</b> cuando alguien queda contratado, aparece acá con el botón <b>"Ya lo retiré"</b> -- apriétalo cuando vayas a buscarlo y se lo lleves a su cuadrilla. Con eso el proceso de esa persona queda 100% cerrado. La ve también el Capataz: cualquiera de los dos puede confirmarlo.</li>
        <li>• <b>Estado en vivo:</b> el widget de arriba te muestra en qué fase está cada postulante activo.</li>
      </ul>`,
  },
  capataz: {
    titulo: 'Guía de uso · Capataz',
    contenido: `
      <p class="text-gray-700 mb-4">Tu trabajo es la <b>selección en portería</b>, en persona -- ves directamente a todos los que acaban de postular por el QR, sin ningún filtro previo. Cada persona tiene <b>su propia tarjeta numerada</b>, con 3 pasos:</p>
      <ul class="space-y-2.5 text-gray-700">
        <li>• <b>Arriba de la tarjeta</b> ves su número, su <b>RUT bien grande</b> y su nombre, para compararlos al toque con su cédula física.</li>
        <li>• <b>① "Trae sus documentos":</b> márcalo primero. Al hacerlo, <b>esa tarjeta se ilumina y las demás se apagan</b> (solo una persona a la vez), y arriba aparece el nombre de a quién estás asignando.</li>
        <li>• <b>② / ③ Arrastra hasta el cargo:</b> mantén apretada la franja naranja de la tarjeta y suéltala sobre la caja del cargo que le corresponde (⛑️). <b>Las cajas de cargos quedan fijas arriba</b> aunque bajes por la lista. Un solo gesto lo selecciona y le asigna el cargo real, y baja en 1 el cupo de esa caja.</li>
        <li>• <b>¿Te equivocaste de caja?</b> Apenas sueltas, aparece un botón "↩ Me equivoqué, deshacer" junto al aviso de "Seleccionado" -- solo funciona por un rato corto, mientras la postulación no haya avanzado más.</li>
        <li>• <b>"✕ No selecciona a esta persona":</b> es el enlace chico al final de su tarjeta. Eliges un motivo estandarizado (no hay cupos, documentación incompleta, etc.). El postulante recibe un correo genérico, nunca el motivo real completo.</li>
        <li>• <b>CV:</b> si lo subió, aparece "📄 Ver CV". Si no, ves "Sin CV · ver experiencia" -- tócalo para leer lo que declaró (en el computador viene abierto).</li>
        <li>• Al seleccionar, la persona recibe <b>un solo correo</b> con su QR para pasar por Portería y el botón para completar su Etapa 2; el JAO recibe un aviso de que viene en camino.</li>
        <li>• Solo aparecen cajas de cargos con <b>vacante abierta</b> (una solicitud de cupos ya aprobada por el Administrador de Contrato) -- si no ves el cargo que necesitas, pide a Jefe de Terreno que solicite más cupos.</li>
        <li>• La pantalla se actualiza sola cada 15 segundos y <b>no te desmarca</b> la tarjeta que estás preparando.</li>
        <li>• <b>Pestaña "Personal Contratado":</b> cuando alguien queda contratado (listo para tu cuadrilla), aparece acá con el botón <b>"Ya lo retiré"</b> -- apriétalo cuando lo vayas a buscar y se lo lleves a su cuadrilla. La ve también Jefe de Terreno: cualquiera de los dos puede confirmarlo.</li>
      </ul>`,
  },
  admin_contrato: {
    titulo: 'Guía de uso · Administrador de Contrato',
    contenido: `
      <p class="text-gray-700 mb-4">Tu tarea es <b>aprobar los cupos</b> que pide Jefe de Terreno -- ahí termina tu parte del proceso. Ya no autorizas contratación por contratación: eso lo maneja el Capataz al seleccionar, y el JAO se entera directo.</p>
      <ul class="space-y-2.5 text-gray-700">
        <li>• <b>Pestaña "Solicitudes de Cupo":</b> Jefe de Terreno pide cupos por cargo. Al aprobar, se abre la vacante (el cargo pasa a mostrar esos cupos como disponibles) y se avisa por correo a Jefe de Terreno, a los Capataz y al JAO.</li>
        <li>• Puedes abrir una cantidad distinta a la pedida (ej. pidieron 5, solo hay presupuesto para 3) y dejar una observación.</li>
        <li>• <b>Pestaña "Estado del proceso":</b> cada trabajador activo, individualizado, con la etapa exacta en la que está ahora mismo -- útil para responder "¿cómo va tal persona?" sin tener que preguntarle a otro rol. Arriba, un resumen de tiempos promedio (postulante llenando Etapa 2, JAO hasta finalizar) con filtro por fecha y exportación a Excel.</li>
      </ul>`,
  },
  jao: {
    titulo: 'Guía de uso · Jefe Administrativo (JAO)',
    contenido: `
      <p class="text-gray-700 mb-4">Tu parte son <b>dos acciones separadas, en días distintos</b>: verificar identidad y completar los datos de nómina el <b>Día 0</b> (postulación), y firmar el contrato el <b>Día 1</b> (contratación, 8 am). Ves a todos los que están en cualquiera de las dos etapas -- lo que cambia es cuándo se te habilita cada botón.</p>
      <ul class="space-y-2.5 text-gray-700">
        <li>• <b>Día 0 -- Verificar identidad ("Coincide" / "No coincide"):</b> el botón aparece apenas la persona completa su Etapa 2. Compara el RUT declarado contra su cédula (la subida y la que tiene frente a ti) y confirma. <b>No depende de que Portería haya escaneado su QR</b>: si no lo escaneó, solo ves una nota gris "sin QR en Portería", y al verificar queda registrado su ingreso. Es lo que le abre la puerta a Prevención: hasta que no das "Coincide", Prevención no la ve.</li>
        <li>• <b>Datos de nómina:</b> los completas el Día 0 o el Día 1, pero los necesitas completos antes de poder firmar el contrato.</li>
        <li>• <b>Observar un documento:</b> si algo está mal o ilegible, puedes rechazar ese documento puntual -- el postulante recibe un correo pidiéndole que lo vuelva a subir, sin afectar el resto de sus documentos ya aprobados.</li>
        <li>• <b>Día 1, 8am -- Firmar Contrato (y enrolamiento de asistencia):</b> cuando Portería autoriza el paso de la persona a contratación te llega un aviso por correo y su tarjeta muestra "✓ Paso autorizado por Portería" (si Portería no alcanzó, usa "Confirmar manualmente" en la tarjeta). Se habilita recién cuando ya verificaste la identidad, Prevención hizo su verificación del Día 0, completaste los datos de nómina Y no queda ningún documento observado. Al firmar, la persona pasa con Prevención a la <b>IRL</b> y después a Bodega -- queda Contratado recién cuando Bodega entrega el kit de EPP.</li>
        <li>• <b>⏱ "Ver tiempos":</b> en cada tarjeta, el detalle de cuándo se hizo cada paso y cuánto demoró, desde que el Capataz seleccionó a la persona.</li>
        <li>• <b>Ventana de contratación:</b> tú fijas las fechas en que se puede contratar (Desde / Hasta); fuera de ellas no se puede firmar contrato ni entregar EPP. Debe incluir el día en que se contrata.</li>
        <li>• <b>Estado en vivo:</b> arriba ves en qué paso va cada persona y cuáles están "pendientes en tu bandeja".</li>
        <li>• <b>Pestaña "Contratados":</b> histórico de todos los que Bodega ya cerró (EPP entregado).</li>
        <li>• <b>Pestaña "Rechazados":</b> los que fueron rechazados en cualquier etapa anterior, solo para trazabilidad -- tú no rechazas desde aquí.</li>
      </ul>`,
  },
  prevencion: {
    titulo: 'Guía de uso · Prevención de Riesgos',
    contenido: `
      <p class="text-gray-700 mb-4">Tienes <b>dos check, uno por día, cada uno en su pestaña</b> (con un número de pendientes). En ambos, marcas el casillero de la persona y confirmas (no se puede deshacer).</p>
      <ul class="space-y-2.5 text-gray-700">
        <li>• <b>Pestaña "Día 0 · Postulación" (verificación):</b> ves a cada persona apenas el JAO verifica su identidad. Al confirmar tu check termina su día: el postulante recibe el correo "preséntate mañana a las 8 am".</li>
        <li>• <b>Pestaña "Día 1 · Contratación (IRL)", 8 am:</b> aparece ahí apenas el JAO firma su contrato. Cuando hiciste la IRL, la marcas -- ahí la persona pasa a Bodega para la entrega del kit.</li>
        <li>• Si el catálogo de cursos está activo, al confirmar tu check del Día 0 esos cursos quedan <b>aprobados</b> automáticamente para esa persona.</li>
        <li>• Si alguien no te aparece en el Día 0, es porque el JAO todavía no le da su "Coincide".</li>
        <li>• No ves datos sensibles de la persona (AFP, banco, etc.) -- solo lo necesario para identificarla.</li>
      </ul>`,
  },
  bodega: {
    titulo: 'Guía de uso · Jefe de Bodega',
    contenido: `
      <p class="text-gray-700 mb-4">Tu parte es <b>entregar el kit de EPP</b> -- el paso que cierra el ciclo completo y deja a la persona ✔ Contratada.</p>
      <ul class="space-y-2.5 text-gray-700">
        <li>• Las tallas (calzado y overol) de quienes completan su postulación te llegan en <b>un solo correo</b>, que tú mismo envías con el botón "Enviar tallas por correo" de tu panel -- lo ideal es a las 14:00, cuando ya no entran más postulantes (pasada esa hora, la tarjeta se pone <b>roja</b> mientras queden tallas sin enviar). Si alguien completa después, queda pendiente y sale en un envío aparte. Así preparas los kits con anticipación, antes de que las personas vuelvan al día siguiente.</li>
        <li>• <b>El botón de entrega se habilita recién cuando el JAO ya firmó el contrato Y Prevención registró la IRL</b> (día 1, 8 am) -- antes de eso ves qué falta: "esperando firma de contrato" o "esperando IRL". Cuando se habilita, te llega el aviso "Entrega EPP ahora".</li>
        <li>• Al confirmar la entrega, el cupo ya estaba reservado desde que el Capataz seleccionó a la persona -- tu acción deja el estado en <b>Contratado</b> y le avisa al postulante con su QR final de acceso.</li>
        <li>• <b>Nómina de liberados:</b> arriba de la lista aparece una tabla con todos los que ya liberaste y todavía no avisaste. Cuando termines de entregar el grupo, aprieta <b>"Enviar nómina por correo"</b>: sale UN solo correo con la tabla a Jefe de Terreno, Capataces, Administrador de Contrato, JAO y Prevención (no un aviso por cada trabajador). Cada persona aparece una sola vez.</li>
      </ul>`,
  },
  porteria: {
    titulo: 'Guía de uso · Portería',
    contenido: `
      <p class="text-gray-700 mb-4">Tienes <b>dos tareas en dos días distintos</b>: controlar la puerta el Día 0 (postulación) y autorizar el paso a contratación el Día 1 (8 am).</p>
      <ul class="space-y-2.5 text-gray-700">
        <li>• <b>Día 0 -- Control de la puerta:</b> la persona llega con el QR que le llegó por correo cuando el Capataz la seleccionó. Escanéalo (o escribe su RUT y el código de seguimiento en la tarjeta de abajo) y verifica que esté habilitada para pasar a la sala de espera.</li>
        <li>• <b>Día 1, 8 am -- "Día 1 · Contratación":</b> la persona vuelve a la obra. Escribe su RUT / cédula en la primera tarjeta y aprieta <b>"Ver fase"</b>: ves en qué paso está.</li>
        <li>• Si está lista para contratación (hizo su Día 0 completo), aparece el botón para <b>autorizar su paso</b>. Al apretarlo, el JAO recibe un aviso y a la persona le aparece disponible para firmar su contrato.</li>
        <li>• Si la persona no está lista (por ejemplo, falta la verificación de Prevención), el sistema te dice qué le falta -- no la autorices.</li>
        <li>• Si por algún motivo no alcanzas a autorizar a alguien, el JAO puede confirmarlo manualmente desde su panel: la persona no se queda detenida.</li>
      </ul>`,
  },
};

function abrirGuia(rol) {
  const datos = GUIA_POR_ROL[rol];
  if (!datos) return;

  let modal = document.getElementById('guia-proceso-modal');
  if (!modal) {
    modal = document.createElement('div');
    modal.id = 'guia-proceso-modal';
    modal.className = 'hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4';
    modal.onclick = (e) => { if (e.target === modal) cerrarGuia(); };
    document.body.appendChild(modal);
  }

  modal.innerHTML = `
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full p-6 relative max-h-[85vh] overflow-y-auto">
      <button onclick="cerrarGuia()" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600 text-xl leading-none">✕</button>
      <h2 class="text-lg font-bold text-gray-900 mb-1">${datos.titulo}</h2>
      <p class="text-xs text-gray-400 mb-5">Cómo funciona el proceso completo y qué te toca hacer a ti en cada etapa.</p>

      <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">Proceso completo, de punta a punta</p>
      <div class="bg-gray-50 rounded-xl p-4 mb-6 text-sm">${GUIA_RESUMEN_GENERAL}</div>

      <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">Tu parte en el proceso</p>
      <div class="text-sm">${datos.contenido}</div>
    </div>`;

  modal.classList.remove('hidden');
}

function cerrarGuia() {
  const modal = document.getElementById('guia-proceso-modal');
  if (modal) modal.classList.add('hidden');
}

// --- v6.7: "Flujo del proceso" -- diagrama visual del pipeline completo ----
// v10.24: actualizado al flujo de DOS DÍAS (Día 0 = postulación y
// verificación; Día 1 = contratación a las 8 am). Mismo contenido para todos
// los dashboards -- no depende del rol que lo abre.
function pasoFlujo(numero, titulo, detalle, opciones = {}) {
  const { rama = '', ultimo = false } = opciones;
  return `
    <div class="flex gap-3">
      <div class="flex flex-col items-center">
        <span class="shrink-0 w-8 h-8 rounded-full ${ultimo ? 'bg-green-600' : 'bg-blue-600'} text-white text-sm font-bold flex items-center justify-center">${ultimo ? '✔' : numero}</span>
        ${!ultimo ? '<span class="w-px flex-1 bg-gray-300 my-1" style="min-height:14px;"></span>' : ''}
      </div>
      <div class="pb-6 flex-1">
        <p class="font-semibold text-gray-900 text-sm">${titulo}</p>
        <p class="text-xs text-gray-500 mt-0.5">${detalle}</p>
        ${rama}
      </div>
    </div>`;
}

function ramaFlujo(texto, tipo = 'rechazo') {
  const estilos = {
    rechazo: 'bg-red-50 border-red-200 text-red-700',
    observacion: 'bg-amber-50 border-amber-200 text-amber-800',
  };
  const icono = tipo === 'rechazo' ? '✕' : '↺';
  return `<div class="mt-2 border rounded-lg px-3 py-2 text-xs ${estilos[tipo]}"><b>${icono}</b> ${texto}</div>`;
}

const FLUJO_DIAGRAMA_HTML = `
  <p class="text-sm text-gray-600 mb-5">Así funciona hoy el proceso completo, de punta a punta. Las cajas rojas y ámbar son las ramas donde el proceso se desvía de la ruta principal.</p>
  <div>
    ${pasoFlujo(1, 'Jefe de Terreno solicita cupos', 'Ej: "necesito 5 jornales". La solicitud queda Pendiente.', {
      rama: ramaFlujo('Administrador rechaza la solicitud → no se abre ningún cupo, el cargo sigue igual.'),
    })}
    ${pasoFlujo(2, 'Administrador de Contrato aprueba → se abre la vacante', 'Recién aquí el cargo suma cupos disponibles. Avisa por correo a Jefe de Terreno, a los Capataz y al JAO. Su rol termina acá: ya no revisa postulaciones una por una.')}
    ${pasoFlujo(3, 'Postulante postula (Etapa 1)', 'Llena datos básicos, sube su CV y su cédula (frente y reverso), desde el formulario público (QR en portería). No elige cargo -- eso lo asigna el Capataz al seleccionarlo.')}
    ${pasoFlujo(4, 'Capataz selecciona en persona, en portería', 'Único filtro en terreno: marca que trae sus documentos y arrastra su tarjeta hasta la caja (con cupo) del cargo que le corresponde -- un solo gesto selecciona y asigna el cargo real. En ese momento le llegan al postulante, juntos: el link para completar Etapa 2 y el QR para que Portería lo deje pasar a la sala de espera. El JAO recibe un aviso de que viene en camino.', {
      rama: ramaFlujo('No selecciona → el postulante recibe un correo genérico con un motivo estandarizado, sin el motivo real completo. No sigue el proceso.'),
    })}
    ${pasoFlujo(5, 'Portería confirma el ingreso con el QR', 'Control de la puerta: lo deja pasar a la sala de espera -- ahí mismo, con su celular, completa sus datos y documentos. Si el escaneo no se alcanza a hacer, no detiene el proceso: el JAO puede verificar igual.')}
    ${pasoFlujo(6, 'Postulante completa Etapa 2', 'Datos personales, previsionales, bancarios + documentos: cédula, certificado de AFP, de salud, de residencia y (si aplica) último finiquito. Al terminar, el JAO recibe el aviso de que ya está listo para revisión.')}
    ${pasoFlujo(7, 'Día 0: JAO verifica identidad', 'Compara el RUT declarado contra la cédula subida, con la persona frente a él, apenas completa su Etapa 2 (no depende de que Portería haya escaneado su QR). Le da su check ("Coincide") y la persona pasa a Prevención.', {
      rama: ramaFlujo('El JAO observa un documento → el postulante recibe un correo pidiéndole que lo vuelva a subir, y vuelve a este mismo paso apenas lo corrige. El resto de lo ya aprobado no se pierde.', 'observacion'),
    })}
    ${pasoFlujo(8, 'Día 0: Prevención verifica (primer check)', 'Cuando el JAO ya verificó identidad, Prevención marca con un check que su verificación quedó realizada -- ahí termina el día de postulación y el postulante recibe el correo: "preséntate mañana a las 8 am para avanzar en tu proceso".')}
    ${pasoFlujo(9, 'Día 1, 8 am: Portería autoriza su paso a contratación', 'La persona llega a Portería, que la busca por su cédula y ve en qué fase está. Si figura "lista para contratación", Portería autoriza su paso -- ahí el JAO recibe un aviso y a la persona le aparece disponible para firma de contrato en su panel. (Si Portería no alcanza, el JAO puede confirmarlo manualmente desde la tarjeta.)')}
    ${pasoFlujo(10, 'Día 1: JAO firma el contrato y enrola su asistencia', 'Se habilita recién cuando Portería autorizó su paso, ya verificó identidad, Prevención hizo su verificación del Día 0, completó los datos de nómina Y no queda ningún documento observado. Al firmar, la persona pasa con Prevención a la IRL.')}
    ${pasoFlujo(11, 'Día 1: Prevención hace la IRL (segundo check)', 'Apenas el JAO firma, la persona aparece en la segunda lista de Prevención. Cuando la IRL quedó realizada, Prevención la marca -- ahí pasa a Bodega y Bodega recibe el aviso "Entrega EPP ahora".')}
    ${pasoFlujo(12, 'Día 1: Bodega entrega el kit de EPP y libera', 'Se habilita recién cuando el JAO firmó Y Prevención registró la IRL. Al entregarlo, el postulante queda ✔ Contratado (el cupo ya se había reservado antes, al momento de la selección del Capataz) y recibe el QR final de acceso a la obra.')}
    ${pasoFlujo(13, 'Bodega envía la nómina de liberados', 'Cuando termina de entregar el grupo, Bodega envía UN solo correo con la tabla de todos los trabajadores liberados (nombre, RUT, cargo, hora y quién los seleccionó) a Jefe de Terreno, Capataces, Administrador de Contrato, JAO y Prevención -- en vez de un aviso suelto por cada trabajador.')}
    ${pasoFlujo(14, 'Capataz o Jefe de Terreno confirman que lo retiraron', 'Lo van a buscar a la sala de espera y confirman en su panel ("Ya lo retiré"), en la pestaña Personal Contratado. La persona queda en condiciones de incorporarse a la cuadrilla del Capataz que la reclutó, y el ciclo completo queda cerrado, desde la postulación hasta su primer día de trabajo.', { ultimo: true })}
  </div>`;

function abrirFlujo() {
  let modal = document.getElementById('flujo-proceso-modal');
  if (!modal) {
    modal = document.createElement('div');
    modal.id = 'flujo-proceso-modal';
    modal.className = 'hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4';
    modal.onclick = (e) => { if (e.target === modal) cerrarFlujo(); };
    document.body.appendChild(modal);
  }

  modal.innerHTML = `
    <div class="bg-white rounded-2xl shadow-2xl max-w-xl w-full p-6 relative max-h-[85vh] overflow-y-auto">
      <button onclick="cerrarFlujo()" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600 text-xl leading-none">✕</button>
      <h2 class="text-lg font-bold text-gray-900 mb-1">Flujo del proceso</h2>
      ${FLUJO_DIAGRAMA_HTML}
    </div>`;

  modal.classList.remove('hidden');
}

function cerrarFlujo() {
  const modal = document.getElementById('flujo-proceso-modal');
  if (modal) modal.classList.add('hidden');
}
