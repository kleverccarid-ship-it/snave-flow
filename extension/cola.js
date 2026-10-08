// Cola automática: rellena, envía, espera el resultado, lo descarga y pasa al
// siguiente prompt de la tanda sin que la persona pulse nada.
//
// Flow ignora los clics simulados desde la página, así que el clic en la flecha
// se hace con chrome.debugger (Input.dispatchMouseEvent), que Chrome trata como
// un clic real. Se conecta solo al escribir y pulsar, y se suelta enseguida
// (soltarDepurador): la barra "SnaveCloud comenzó a depurar" sale solo un momento.
//
// Los prompts salen de la tanda del popup (origen 'tanda') o del panel
// SnaveCloud (origen 'panel', ver panel.js). Con el panel, la cola no termina:
// espera trabajos nuevos hasta que se detiene, y los resultados se suben al
// panel en vez de guardarse en Descargas.
//
// Corre en el service worker. El estado va en storage.local 'cola' para que el
// popup lo pinte y para retomarlo si Chrome duerme el service worker:
//   activa, tabId, origen, mensaje, errores [ {prompt, motivo} ], fallosSeguidos,
//   pendientes [ { prompt, nombre, numero, donde, antes, desde, n, tipo, trabajoId } ]
//              lo que se está generando: con el panel, varios a la vez (Reglas →
//              "Pedidos a la vez", 3 por defecto); con la tanda, de uno en uno
//   retenido   pedido que va a otro proyecto: espera a que acaben los de este
//   reclamados videos ya entregados, para que dos pedidos iguales no se lleven el mismo

// Con el panel, además, la extensión le manda un latido (qué está haciendo) y
// obedece su configuración: pausas, esperas, fallos permitidos y el botón
// "Pausar" (como en La Casa Dark, el panel pone las reglas).

const SONDEO = 4000;           // cada cuánto mirar si ya salió el resultado
const SONDEO_PANEL = 10000;    // cada cuánto preguntar al panel si hay trabajos

// Esperas, pausa y fallos seguidos permitidos: los del panel si la cola viene
// de allí; si no, los de por defecto (sin créditos o Flow roto: no seguir
// quemando la lista)
async function reglas(cola) {
  const c = cola.origen === 'panel' ? await configDelPanel() : CONFIG_PANEL_DEFECTO;
  return {
    esperaMax: { video: c.espera_video_min * 60e3, imagen: c.espera_imagen_min * 60e3 },
    pausa: c.pausa_seg * 1000,
    maxFallos: c.max_fallos_seguidos,
    // Cuántos pedidos generándose a la vez en Flow
    lote: cola.origen === 'panel' ? Math.max(1, Number(c.simultaneos) || 3) : 1,
  };
}

// Lo que se está generando (las colas guardadas por versiones anteriores tenían un solo "pendiente")
const listaPendientes = (cola) => cola.pendientes || (cola.pendiente ? [cola.pendiente] : []);
const claveDe = (p) => String(p.trabajoId || `${p.numero}-${p.desde}`);

// Fallos de Flow que suelen pasarse solos o que otra cuenta sí puede hacer: el
// trabajo vuelve a la cola del panel (y otra cuenta/perfil lo intenta). Incluye
// "no pude elegir el modelo" (quizá esa cuenta no tiene ese modelo; otra sí).
const FALLO_PASAJERO = /no empez[oó] a generar|no acept[oó]|alta demanda|no est[aá] visible|no pudo generarlo|no devolvi[oó] nada|no pude elegir el modelo|no encontr[eé]|sin cr[eé]ditos|no cambi[oó] a modo/i;

let colaCorriendo = false;     // hay un bucle vivo en este service worker
const depurando = new Set();   // pestañas con chrome.debugger conectado

const normTexto = (s) => String(s || '').replace(/\s+/g, ' ').trim().toLowerCase();

async function leerCola() {
  return (await chrome.storage.local.get('cola')).cola || {};
}

async function guardarCola(cambios) {
  const cola = { ...(await leerCola()), ...cambios };
  await chrome.storage.local.set({ cola });
  return cola;
}

async function iniciarCola(tabId, origen) {
  tabId = await ventanaPropia(tabId);
  await guardarCola({ activa: true, tabId, origen: origen === 'panel' ? 'panel' : 'tanda',
    mensaje: 'Empezando…', errores: [], fallosSeguidos: 0, pendiente: null, pendientes: [], retenido: null });
  // Por si Chrome duerme el service worker a mitad: la alarma lo despierta y sigue
  chrome.alarms.create('cola', { periodInMinutes: 1 });
  bucleCola();
}

async function detenerCola(mensaje) {
  const cola = await leerCola();
  const { tabId, origen } = cola;
  for (const p of listaPendientes(cola)) {
    if (p.trabajoId) await terminarEnPanel(p.trabajoId, false, 'Cola detenida antes de terminar. Mira si el video salió en Flow.');
  }
  // El retenido ya estaba tomado del panel: que vuelva a la cola
  if (cola.retenido && cola.retenido.trabajoId) await terminarEnPanel(cola.retenido.trabajoId, false, 'Cola detenida.', true);
  await guardarCola({ activa: false, pendiente: null, pendientes: [], retenido: null, mensaje });
  if (origen === 'panel') await latidoPanel({ activa: false, mensaje }, true).catch(() => {});
  chrome.alarms.clear('cola');
  if (tabId != null && depurando.has(tabId)) {
    depurando.delete(tabId);
    await chrome.debugger.detach({ tabId }).catch(() => {});
  }
}

// Siempre encendida (cola del panel): ante fallos seguidos no se apaga, espera
// unos minutos y vuelve a intentarlo, avisando al panel. La tanda local sí se para.
const ESPERA_TRAS_FALLOS = 5 * 60e3;
async function demasiadosFallos(cola, motivo) {
  if (cola.origen !== 'panel') return detenerCola(`Cola parada: ${motivo} Mira la pestaña.`);
  const mensaje = `${motivo} Espero 5 min y sigo.`;
  await guardarCola({ fallosSeguidos: 0, mensaje });
  // Durante la espera sigue dando señal: si no, el panel la ve "Apagada"
  for (const fin = Date.now() + ESPERA_TRAS_FALLOS; Date.now() < fin && (await leerCola()).activa;) {
    await latidoPanel({ activa: true, mensaje: `${motivo} Sigo en ${Math.ceil((fin - Date.now()) / 60e3)} min.` }, true).catch(() => {});
    await sleep(15000);
  }
}

// Pestaña de Flow para seguir trabajando: la de la cola si sigue abierta, otra
// de un proyecto de Flow, o una ventana nueva con el último proyecto usado
async function recuperarPestana(tabId) {
  if (tabId != null) {
    // Vale también la página de inicio: el pedido abrirá o creará su proyecto
    const t = await chrome.tabs.get(tabId).catch(() => null);
    if (t && /^https:\/\/(flow\.google\.com|labs\.google)\//.test(t.url || '')) return tabId;
  }
  const tabs = await chrome.tabs.query({ url: ['https://flow.google.com/*', 'https://labs.google/*'] });
  const otra = tabs.find((t) => /\/project\//.test(t.url || ''));
  return otra ? otra.id : abrirVentanaFlow();
}

async function bucleCola() {
  if (colaCorriendo) return;
  colaCorriendo = true;
  try {
    while ((await leerCola()).activa) {
      const cola = await leerCola();
      const pend = listaPendientes(cola);
      const { lote } = await reglas(cola);
      // Hay trabajo: la pestaña de Flow no está ociosa
      if ((pend.length || cola.retenido) && cola.ociosaDesde) await guardarCola({ ociosaDesde: 0 });

      // Un pedido retenido (va a otro proyecto) sale cuando ya no queda nada en este
      if (cola.retenido) {
        if (!pend.length) {
          const sig = cola.retenido;
          await guardarCola({ retenido: null });
          await enviarPrompt(cola, sig);
        } else {
          await revisarPendientes(cola);
          await sleep(SONDEO);
        }
        continue;
      }
      // Ya hay tantos generándose como se permite: solo mirar cómo van
      if (pend.length >= lote) {
        await revisarPendientes(cola);
        await sleep(SONDEO);
        continue;
      }

      let siguiente;
      if (cola.origen === 'panel') {
        try {
          const cfg = await latidoPanel({ activa: true, mensaje: cola.mensaje, trabajo: pend[0] && pend[0].trabajoId });
          if (!cfg.procesar) {
            // "Pausar el procesamiento" desde la página de SnaveCloud: no se
            // piden más, pero lo que ya se está generando se termina y se entrega
            await guardarCola({ mensaje: 'En pausa desde SnaveCloud. Pulsa "Reanudar" en el panel.' });
            if (pend.length) await revisarPendientes(cola);
            await sleep(pend.length ? SONDEO : SONDEO_PANEL);
            continue;
          }
          siguiente = await siguienteDelPanel();
        } catch (e) {
          // Un corte de red no debe tumbar una cola que corre durante horas
          await guardarCola({ mensaje: `No pude hablar con el panel (${e.message}). Reintento en 10 s…` });
          await sleep(SONDEO_PANEL);
          continue;
        }
      } else {
        siguiente = await siguienteDeLaTanda();
      }
      if (siguiente && cola.origen === 'panel') {
        await guardarCola({ ociosaDesde: 0 });
        // Siempre encendida: si cerraron la pestaña de Flow, se busca o se abre otra
        const tabId = await recuperarPestana(cola.tabId).catch(() => null);
        if (!tabId) {
          if (siguiente.trabajoId) await terminarEnPanel(siguiente.trabajoId, false, 'No hay ningún proyecto de Flow abierto.', true);
          await guardarCola({ mensaje: 'No hay ningún proyecto de Flow abierto: ábrelo una vez en Chrome.' });
          await sleep(SONDEO_PANEL);
          continue;
        }
        if (tabId !== cola.tabId) await guardarCola({ tabId });
        // Si va a otro proyecto y aún hay pedidos generándose en este, espera
        // (cambiar de proyecto a mitad haría perder de vista los que están en curso)
        if (pend.length && (await vaAOtroProyecto(tabId, siguiente))) {
          await guardarCola({ retenido: siguiente, mensaje: `${siguiente.donde}: va a otro proyecto, espera a que terminen los ${pend.length} en curso.` });
          continue;
        }
        await enviarPrompt({ ...cola, tabId }, siguiente);
      } else if (siguiente) {
        await enviarPrompt(cola, siguiente);
      } else if (pend.length) {
        // No hay más pedidos: mirar cómo van los que se están generando
        await revisarPendientes(cola);
        await sleep(SONDEO);
      } else if (cola.origen === 'panel') {
        await guardarCola({ mensaje: cola.tabId == null && /cerré la pestaña/.test(cola.mensaje || '') ? cola.mensaje : 'Esperando trabajos del panel…' });
        await cerrarPestanaOciosa();
        await sleep(SONDEO_PANEL);
      } else {
        const { lista } = await siguientePrompt();
        const fallos = (cola.errores || []).length;
        await detenerCola(`Cola terminada: ${lista.length} de ${lista.length}` + (fallos ? `, ${fallos} sin resultado.` : '.'));
      }
    }
  } catch (e) {
    const cola = await leerCola();
    if (cola.activa && cola.origen === 'panel') {
      // Siempre encendida: se recupera la pestaña (o se abre otra) y se sigue en 15 s
      const tabId = await recuperarPestana(cola.tabId).catch(() => null);
      await guardarCola({ tabId: tabId || cola.tabId, mensaje: `Problema: ${e.message}. Me recupero y sigo en 15 s…` });
      await latidoPanel({ activa: true, mensaje: `Recuperándome: ${e.message}` }, true).catch(() => {});
      setTimeout(bucleCola, 15000);
    } else {
      await detenerCola('Cola parada: ' + e.message);
    }
  } finally {
    colaCorriendo = false;
  }
}

// Suelta el depurador en cuanto termina de escribir y pulsar. Mientras está
// conectado, Chrome enseña en TODAS las ventanas la barra "SnaveCloud comenzó
// a depurar este navegador" (aunque solo depura la pestaña de Flow): así sale
// solo unos segundos por pedido y no todo el rato.
async function soltarDepurador(tabId) {
  if (tabId == null || !depurando.delete(tabId)) return;
  await chrome.debugger.detach({ tabId }).catch(() => {});
}

async function conectarDebugger(tabId) {
  if (depurando.has(tabId)) return;
  try {
    await chrome.debugger.attach({ tabId }, '1.3');
  } catch (e) {
    // Ya conectado desde antes de que el service worker se durmiera
    if (!/already attached/i.test(e.message)) throw e;
  }
  depurando.add(tabId);
}

/* ---------- Imágenes de referencia del pedido: guardarlas en la PC ---------- */
// La extensión NO sube imágenes a Flow (eso evadía su anti-bot y arriesgaba la
// cuenta). En su lugar, las imágenes de referencia de un pedido del panel se
// GUARDAN en la PC (carpeta Descargas/snave-referencias), para que la persona las
// tenga a mano y, si quiere, las suba a Flow ELLA MISMA.
async function descargarEntradasAPc(sig) {
  const { base } = await configPanel();
  const imgs = await descargarEntradas(sig.entradas).catch(() => []);
  // URLs para la vista previa en la ventana de la extensión (popup)
  const previews = (sig.entradas || []).map((e) => {
    const ruta = String((e && e.ruta) || '');
    const url = /^https?:\/\//i.test(ruta) ? ruta : base + ruta.replace(/^\//, '');
    return { rol: (e && e.rol) || 'referencia', url, nombre: (ruta.split('/').pop() || 'imagen').split('?')[0] };
  });
  let n = 0;
  const detalle = [];
  for (const im of imgs) {
    const limpio = String(im.nombre || 'imagen.png').replace(/[^a-zA-Z0-9._-]/g, '_');
    try {
      await chrome.downloads.download({
        url: im.dataUrl,
        filename: `snave-referencias/${sig.numero || 'pedido'}-${im.rol || 'ref'}-${limpio}`,
        conflictAction: 'uniquify', saveAs: false,
      });
      n++; detalle.push('ok ' + im.rol + ': ' + limpio);
    } catch (e) { detalle.push('falló: ' + e.message); }
  }
  await chrome.storage.local.set({
    ultimoAdjuntar: { descargadasALaPc: n, pedidas: sig.entradas.length, carpeta: 'Descargas/snave-referencias', detalle, cuando: new Date().toISOString() },
    // Para que el popup muestre las imágenes de referencia del último pedido
    ultimasReferencias: { pedido: sig.numero || sig.trabajoId || '', imagenes: previews, cuando: new Date().toISOString() },
  });
  return n;
}

// Flow trabaja en una pestaña normal, sin abrir ventanas: la que ya esté
// abierta o una nueva en segundo plano en la ventana de la persona.
async function ventanaPropia(tabId) {
  const tab = await chrome.tabs.get(tabId);
  if (/\/project\//.test(tab.url || '')) {
    await chrome.storage.local.set({ flow_proyecto: tab.url });
    // Detecta el proyecto abierto y lo apunta en el panel, para poder elegirlo en los pedidos
    const nombre = String(tab.title || '').replace(/^.*?flow\s*[:\-–]\s*/i, '').trim();
    await registrarProyectoEnPanel(idProyecto(tab.url), nombre, 0).catch(() => {});
  }
  return tabId;
}

// Abre Flow en una pestaña de fondo (sin ventana nueva): el último proyecto
// usado, o la página de inicio si nunca se usó ninguno (el pedido creará uno)
async function abrirVentanaFlow() {
  const { flow_proyecto } = await chrome.storage.local.get('flow_proyecto');
  const win = await chrome.windows.getLastFocused({ windowTypes: ['normal'] }).catch(() => null);
  const tab = await chrome.tabs.create({ url: flow_proyecto || FLOW_INICIO, active: false, ...(win ? { windowId: win.id } : {}) });
  // Es de la extensión: la puede cerrar cuando no quede nada por hacer
  await guardarCola({ pestanaPropia: tab.id });
  await esperarCarga(tab.id);
  return tab.id;
}

// Sin nada generándose ni en espera durante un minuto, se cierra la pestaña
// de Flow que abrió la extensión (una que abrió la persona no se toca). Con
// el próximo pedido se abre otra sola.
const CERRAR_OCIOSA = 60e3;
async function cerrarPestanaOciosa() {
  const cola = await leerCola();
  if (!cola.ociosaDesde) return guardarCola({ ociosaDesde: Date.now() });
  if (Date.now() - cola.ociosaDesde < CERRAR_OCIOSA) return;
  if (cola.tabId == null || cola.tabId !== cola.pestanaPropia) return;
  if (listaPendientes(cola).length || cola.retenido) return; // algo generándose: no se cierra
  await soltarDepurador(cola.tabId);
  await chrome.tabs.remove(cola.tabId).catch(() => {});
  await guardarCola({ tabId: null, pestanaPropia: null, mensaje: 'Todo entregado: cerré la pestaña de Flow. Con el próximo pedido la abro sola.' });
}

/* ---------- Proyectos de Flow: la extensión los abre o los crea sola ---------- */

const FLOW_INICIO = 'https://flow.google.com/';
const idProyecto = (url) => ((String(url || '').match(/\/project\/([A-Za-z0-9-]{8,64})/) || [])[1] || '');

// Espera a que la pestaña termine de cargar y, si es un proyecto, a que Flow
// monte la caja del prompt
async function esperarCarga(tabId, ms = 40000) {
  const fin = Date.now() + ms;
  while (Date.now() < fin && (await chrome.tabs.get(tabId)).status !== 'complete') await sleep(500);
  if (!idProyecto((await chrome.tabs.get(tabId)).url)) { await sleep(3000); return; }
  while (Date.now() < fin && !(await enPagina(tabId, proyectoListo).catch(() => false))) await sleep(700);
  await sleep(1000);
}

async function irA(tabId, url) {
  await chrome.tabs.update(tabId, { url });
  await sleep(800);
  await esperarCarga(tabId);
}

// Crea un proyecto en Flow desde su página de inicio. Devuelve su id.
async function crearProyecto(tabId) {
  await irA(tabId, FLOW_INICIO);
  let b = null;
  for (let i = 0; i < 10 && !(b && b.x); i++) { // Flow tarda en pintar el inicio
    b = await enPagina(tabId, botonNuevoProyecto).catch(() => null);
    if (!(b && b.x)) await sleep(1000);
  }
  if (!b || !b.x) {
    await chrome.storage.local.set({ ultimoFallo: { error: (b && b.error) || 'Sin botón de proyecto nuevo', dom: JSON.stringify((b && b.botones) || []), cuando: new Date().toISOString() } });
    throw new Error((b && b.error) || 'No pude crear el proyecto en Flow.');
  }
  await clicReal(tabId, b);
  await soltarDepurador(tabId);
  for (let i = 0; i < 40; i++) { // hasta 20 s a que Flow abra el proyecto nuevo
    await sleep(500);
    const id = idProyecto((await chrome.tabs.get(tabId)).url);
    if (id) { await esperarCarga(tabId); return id; }
  }
  throw new Error('Pulsé "Nuevo proyecto" pero Flow no abrió ningún proyecto.');
}

// Proyecto creado con el "+" del panel (id provisional "nuevo-…"): se crea en
// Flow con su primer pedido. Los demás pedidos de ese proyecto que ya estaban
// tomados van al mismo, gracias a este apunte provisional -> id de Flow.
const esAliasPanel = (p) => /^nuevo-[a-f0-9]{16}$/.test(String(p || ''));
async function proyectoReal(p) {
  if (!esAliasPanel(p)) return p || '';
  const { proyectos_alias } = await chrome.storage.local.get('proyectos_alias');
  return (proyectos_alias || {})[p] || p;
}
async function apuntarAlias(alias, id) {
  const { proyectos_alias } = await chrome.storage.local.get('proyectos_alias');
  const m = { ...(proyectos_alias || {}), [alias]: id };
  // Solo los últimos 50
  await chrome.storage.local.set({ proyectos_alias: Object.fromEntries(Object.entries(m).slice(-50)) });
}

// Deja la pestaña en el proyecto que pide el pedido y se lo cuenta al panel:
//   '' (automático): el que ya está abierto, el último usado o uno nuevo
//   'nuevo' o 'nuevo-…' (del "+"): crea uno       id: abre ese proyecto
async function irAProyecto(tabId, sig) {
  const alias = esAliasPanel(sig.proyecto) ? sig.proyecto : '';
  const pedido = await proyectoReal(sig.proyecto);
  const crear = pedido === 'nuevo' || esAliasPanel(pedido);
  let actual = idProyecto((await chrome.tabs.get(tabId)).url);
  let destino = crear ? '' : pedido;
  if (!destino && !crear) {
    destino = actual || idProyecto((await chrome.storage.local.get('flow_proyecto')).flow_proyecto);
  }
  if (!destino) {
    await guardarCola({ mensaje: `${sig.donde}: creando un proyecto nuevo en Flow…` });
    destino = await crearProyecto(tabId);
    if (alias) await apuntarAlias(alias, destino);
  } else if (destino !== actual) {
    await guardarCola({ mensaje: `${sig.donde}: abriendo el proyecto de Flow…` });
    await irA(tabId, `https://flow.google.com/project/${destino}`);
    if (idProyecto((await chrome.tabs.get(tabId)).url) !== destino) throw new Error('No pude abrir ese proyecto de Flow. ¿Es de esta cuenta de Google?');
  }
  const tab = await chrome.tabs.get(tabId);
  await chrome.storage.local.set({ flow_proyecto: tab.url });
  // El nombre que da Flow va en el título de la pestaña ("Google Flow: sept 25 - 15:02")
  const nombre = String(tab.title || '').replace(/^.*?flow\s*[:\-–]\s*/i, '').trim();
  // Con el alias, el panel pasa el proyecto del "+" y todos sus pedidos a este id
  if (sig.trabajoId || pedido === '' || alias) await registrarProyectoEnPanel(destino, nombre, sig.trabajoId, alias).catch(() => {});
  return destino;
}

// Flow solo procesa la flecha con la página visible. Se muestra su pestaña
// unos segundos y luego se vuelve a la que la persona tenía delante (si no se
// ha movido a otra mientras). Solo si la ventana está minimizada se muestra un
// instante. Devuelve la función que lo devuelve todo.
async function activarPestana(tabId) {
  const previa = (await chrome.tabs.query({ active: true, windowId: (await chrome.tabs.get(tabId)).windowId }))[0];
  const tab = await chrome.tabs.update(tabId, { active: true });
  await sleep(300);
  const visible = await enPagina(tabId, () => document.visibilityState === 'visible').catch(() => false);
  let antes = null;
  if (!visible) {
    antes = await chrome.windows.getLastFocused().catch(() => null);
    await chrome.windows.update(tab.windowId, { state: 'normal', focused: true }).catch(() => {});
    await sleep(500);
  }
  return async () => {
    const ahora = await chrome.tabs.get(tabId).catch(() => null);
    if (previa && previa.id !== tabId && ahora && ahora.active) await chrome.tabs.update(previa.id, { active: true }).catch(() => {});
    if (antes && antes.id !== tab.windowId) await chrome.windows.update(antes.id, { focused: true }).catch(() => {});
  };
}

// Centro de la flecha marcada por fillFlow, medido por el propio Chrome (como
// Puppeteer): así no importan el zoom de la página ni el escalado de Windows.
async function puntoDeLaFlecha(tabId, selector = '[data-flow-helper="enviar"]') {
  const target = { tabId };
  try {
    const { root } = await chrome.debugger.sendCommand(target, 'DOM.getDocument', { depth: 0 });
    const { nodeId } = await chrome.debugger.sendCommand(target, 'DOM.querySelector', { nodeId: root.nodeId, selector });
    if (!nodeId) return null;
    await chrome.debugger.sendCommand(target, 'DOM.scrollIntoViewIfNeeded', { nodeId }).catch(() => {});
    const { quads } = await chrome.debugger.sendCommand(target, 'DOM.getContentQuads', { nodeId });
    const q = quads && quads[0];
    if (!q) return null;
    return { x: (q[0] + q[2] + q[4] + q[6]) / 4, y: (q[1] + q[3] + q[5] + q[7]) / 4 };
  } catch (e) {
    return null;
  }
}

// Clic de verdad en la flecha (o en el punto de reserva), con el botón
// izquierdo marcado como pulsado, igual que un ratón físico
async function clicReal(tabId, reserva) {
  await conectarDebugger(tabId);
  const target = { tabId };
  const { x, y } = (await puntoDeLaFlecha(tabId)) || reserva;
  const base = { x, y, pointerType: 'mouse' };
  await chrome.debugger.sendCommand(target, 'Input.dispatchMouseEvent', { type: 'mouseMoved', ...base });
  await sleep(60);
  await chrome.debugger.sendCommand(target, 'Input.dispatchMouseEvent', { type: 'mousePressed', ...base, button: 'left', buttons: 1, clickCount: 1 });
  await sleep(60);
  await chrome.debugger.sendCommand(target, 'Input.dispatchMouseEvent', { type: 'mouseReleased', ...base, button: 'left', buttons: 0, clickCount: 1 });
}

/* ---------- Videos en 1080p ---------- */
// Flow da el video en 720p ("Original size") y, sin gastar créditos, en 1080p
// ("Upscaled"); el 4K cuesta créditos y no se toca. Para el 1080p se usa su
// menú: tres puntos → Download → 1080p. Flow lo prepara y lo descarga; la
// extensión cancela esa descarga (no se guarda en el PC) y sube el archivo al
// panel. Si algo falla, se entrega el original como antes.
const CALIDAD_VIDEO = /1080\s*p/i;
const ESPERA_HD = 3 * 60e3; // lo que Flow puede tardar en preparar el 1080p

// Pasa el ratón (y si se pide, pulsa) sobre lo marcado con data-fh-clic
async function ratonEn(tabId, pulsar) {
  const p = await puntoDeLaFlecha(tabId, '[data-fh-clic]');
  if (!p) return false;
  const base = { x: p.x, y: p.y, pointerType: 'mouse' };
  await chrome.debugger.sendCommand({ tabId }, 'Input.dispatchMouseEvent', { type: 'mouseMoved', ...base });
  if (pulsar) {
    await sleep(80);
    await chrome.debugger.sendCommand({ tabId }, 'Input.dispatchMouseEvent', { type: 'mousePressed', ...base, button: 'left', buttons: 1, clickCount: 1 });
    await sleep(60);
    await chrome.debugger.sendCommand({ tabId }, 'Input.dispatchMouseEvent', { type: 'mouseReleased', ...base, button: 'left', buttons: 0, clickCount: 1 });
  }
  return true;
}

// Espera la próxima descarga que haga Flow, la cancela y da su dirección
function esperarDescargaDeFlow(ms) {
  let fin;
  const promesa = new Promise((ok) => {
    const escuchar = async (item) => {
      const url = item.finalUrl || item.url || '';
      if (item.byExtensionId === chrome.runtime.id) return; // las de la tanda
      // Solo lo que viene de Flow/Google: una descarga tuya de otra web no se toca
      const deFlow = /^blob:https:\/\/(flow\.google\.com|labs\.google)\//.test(url)
        || /^https:\/\/([a-z0-9-]+\.)*(google\.com|googleusercontent\.com|googleapis\.com|flow-content\.google|labs\.google)\//.test(url);
      if (!deFlow) return;
      fin(url);
      await chrome.downloads.cancel(item.id).catch(() => {});
      await chrome.downloads.erase({ id: item.id }).catch(() => {});
    };
    const reloj = setTimeout(() => fin(null), ms);
    fin = (url) => { clearTimeout(reloj); chrome.downloads.onCreated.removeListener(escuchar); ok(url); };
    chrome.downloads.onCreated.addListener(escuchar);
  });
  return { promesa, cancelar: () => fin(null) };
}

// El video m en 1080p como Blob, o null (y entonces se entrega el original)
async function videoEn1080(tabId, m) {
  if (m.tipo !== 'video' || tabId == null) return null;
  const descarga = esperarDescargaDeFlow(ESPERA_HD);
  let devolverFoco = async () => {};
  try {
    devolverFoco = await activarPestana(tabId);
    await enPaginaMain(tabId, espiaDescargas);
    await conectarDebugger(tabId);
    // Ratón encima de la tarjeta (así sale su botón de tres puntos) y menú
    if (!(await enPagina(tabId, marcarParaDescarga, m.clave, 'tarjeta'))) throw new Error('No encontré la tarjeta del video.');
    await ratonEn(tabId, false);
    await sleep(700);
    if (!(await enPagina(tabId, marcarParaDescarga, m.clave, 'menu'))) throw new Error('No encontré el botón de tres puntos.');
    await ratonEn(tabId, true);
    await sleep(800);
    // Download → 1080p (el submenú se abre al pasar por encima o al pulsar)
    if (!(await enPagina(tabId, marcarParaDescarga, m.clave, 'item', 'download|descargar|baixar'))) throw new Error('No encontré "Download" en el menú.');
    await ratonEn(tabId, true);
    await sleep(800);
    if (!(await enPagina(tabId, marcarParaDescarga, m.clave, 'item', CALIDAD_VIDEO.source))) throw new Error('No encontré la opción 1080p.');
    await ratonEn(tabId, true);
    await soltarDepurador(tabId);
    await devolverFoco();
    devolverFoco = async () => {};

    const url = await descarga.promesa;
    if (!url) throw new Error('Flow no entregó el 1080p a tiempo.');
    const origen = url.startsWith('blob:') ? await enPaginaMain(tabId, blobComoDataUrl, url) : url;
    if (!origen) throw new Error('No pude leer el archivo 1080p.');
    const res = await fetch(origen, { credentials: 'include' });
    if (!res.ok) throw new Error(`Flow respondió HTTP ${res.status}`);
    const blob = await res.blob();
    if (blob.size < 50000) throw new Error('El 1080p llegó vacío.');
    return blob;
  } catch (e) {
    // Para "Copiar diagnóstico"
    await chrome.storage.local.set({ ultimoFallo1080: { error: e.message, cuando: new Date().toISOString() } });
    await enPagina(tabId, cerrarMenus).catch(() => {});
    return null;
  } finally {
    descarga.cancelar();
    await soltarDepurador(tabId);
    await devolverFoco();
  }
}

// Pulsar la flecha fuera de la cola (botón Rellenar y atajo). Si hizo falta
// el depurador, se suelta al terminar para que se quite la barra de Chrome.
async function enviarUnaVez(tabId, envio, antes, prompt) {
  try {
    return await enviarConReintentos(tabId, envio, antes, prompt);
  } finally {
    await soltarDepurador(tabId);
  }
}

// Pulsa la flecha de crear. Flow ignora los clics simulados desde la página
// (el diagnóstico lo confirmó: flecha correcta, activa y con el prompt, y no
// generó), y sí acepta el clic real del depurador, que es lo que hacía
// La Casa Dark con LCS_TRUSTED_CLICK. Así que primero el clic real; el envío
// se confirma con el espía de red, porque Flow no siempre vacía la caja.
// Solo si no se confirma, el método desde la página como reserva.
// Devuelve { ok, via, error }.
// antes = { tarjetas, galeria, primerLote } de fillFlow: cómo estaba la galería
async function enviarConReintentos(tabId, envio, antes, prompt, estricto = false, clave = '') {
  // Lo que ya hay en Flow queda marcado como viejo: el bloque nuevo será el de este pedido
  if (clave) await enPagina(tabId, marcarBloquesViejos).catch(() => {});
  await enPaginaMain(tabId, instalarEspia);
  await conectarDebugger(tabId);
  await chrome.debugger.sendCommand({ tabId }, 'Emulation.setFocusEmulationEnabled', { enabled: true }).catch(() => {});

  // Escribir el prompt con el teclado real (como Puppeteer): así el editor de
  // Flow lo registra como tecleado por una persona. Lo escrito desde la página
  // se ve en la caja, pero Flow puede no darlo por bueno y la flecha no hace nada.
  if (await enPagina(tabId, prepararTeclado)) {
    await chrome.debugger.sendCommand({ tabId }, 'Input.insertText', { text: prompt });
    await sleep(500);
  }

  const t0 = Date.now();
  await clicReal(tabId, (await enPagina(tabId, prepararReintento)) || envio);
  // Flow tarda un poco en pintar la nueva generación: margen de 8 s antes de
  // dar el clic por perdido (reintentar antes de tiempo duplicaba videos)
  const c = await enPaginaMain(tabId, confirmarEnvio, prompt, antes, t0, 8000, estricto, clave);
  if (c && c.ok) return { ok: true, via: `clic real (${c.por})` };

  // Reserva: ratón simulado, Enter y click desde la página
  const r = await enPaginaMain(tabId, enviarEnPagina, prompt, antes, estricto, clave, t0);
  if (r && r.ok) return { ok: true, via: r.via };

  const diag = { ...((c && c.diag) || {}), flechaActiva: r && r.diag ? r.diag.flechaActiva : undefined };
  await chrome.storage.local.set({
    ultimoFallo: { error: (r && r.error) || 'Flow no empezó a generar.', dom: JSON.stringify(diag), cuando: new Date().toISOString() },
  });
  return { ok: false, error: 'Flow no empezó a generar (probé clic real, ratón, Enter y click). Mira si apareció una tarjeta nueva.' };
}

// Lo próximo que hay que generar, venga de donde venga:
//   { prompt (el texto final), nombre (para el archivo), ajustes, numero, donde,
//     trabajoId (solo del panel), alEnviar (qué apuntar cuando Flow lo acepta) }
async function siguienteDeLaTanda() {
  const estado = await siguientePrompt();
  if (!estado.actual) return null;
  const ajustes = await leerAjustes();
  return {
    prompt: componerPrompt(estado.actual, ajustes),
    nombre: estado.actual,
    ajustes,
    numero: estado.indice + 1,
    donde: `${estado.indice + 1} de ${estado.lista.length}`,
    alEnviar: () => marcarHecho(estado),
  };
}

// Los trabajos del panel traen sus propios ajustes y el prompt ya completo
async function siguienteDelPanel() {
  const t = await tomarDelPanel();
  if (!t) return null;
  const ajustes = { ...(await leerAjustes()), mode: t.mode, model: t.model, aspect: t.aspect, res: '', dur: t.dur, count: t.count };
  // Imágenes de entrada (inicio/fin/referencia): van como metadatos { rol, ruta };
  // se descargan a data URLs justo antes de rellenar (enviarPrompt), no se guardan.
  return { prompt: t.prompt, nombre: t.prompt, ajustes, numero: t.id, donde: `Panel #${t.id}`, trabajoId: t.id, proyecto: t.proyecto || '', reintento: !!t.reintento, calidad: t.calidad || '', entradas: Array.isArray(t.entradas) ? t.entradas : [] };
}

async function enviarPrompt(cola, sig) {
  const { tabId } = cola;
  const { prompt, ajustes, donde } = sig;
  let devolverFoco = async () => {};
  try {
    await guardarCola({ mensaje: `${donde}: rellenando…` });
    devolverFoco = await activarPestana(tabId);
    // El proyecto del pedido: lo abre, o lo crea si hace falta
    await irAProyecto(tabId, sig);

    // Salvaguarda anti-bot: si Flow marcó "actividad inusual" en ESTE proyecto,
    // abrir un proyecto NUEVO e intentar ahí (a veces el bloqueo es solo del
    // proyecto). Si el nuevo también sale bloqueado, es la cuenta: parar la cola.
    if (await enPagina(tabId, hayBloqueoFlow).catch(() => false)) {
      const reintentos = ((await leerCola()).antibotReintentos || 0);
      if (reintentos >= 2) {
        await devolverFoco();
        if (sig.trabajoId) await terminarEnPanel(sig.trabajoId, false, 'Actividad inusual también en proyectos nuevos; cola en pausa.', true);
        await detenerCola('Flow sigue marcando "actividad inusual" aun abriendo proyectos nuevos: parece ser la cuenta, no el proyecto. Paré la cola. Deja descansar la cuenta un rato.');
        return;
      }
      await guardarCola({ antibotReintentos: reintentos + 1, mensaje: `${donde}: Flow marcó actividad inusual; abro un proyecto nuevo e intento ahí…` });
      const nuevo = await crearProyecto(tabId).catch(() => null);
      if (!nuevo || (await enPagina(tabId, hayBloqueoFlow).catch(() => false))) {
        await devolverFoco();
        if (sig.trabajoId) await terminarEnPanel(sig.trabajoId, false, 'Actividad inusual' + (nuevo ? ' también en el proyecto nuevo' : ' y no pude abrir proyecto nuevo') + '; cola en pausa.', true);
        await detenerCola('Flow marcó "actividad inusual"' + (nuevo ? ' también en un proyecto nuevo: es la cuenta.' : ' y no pude abrir un proyecto nuevo.') + ' Paré la cola; deja descansar la cuenta.');
        return;
      }
      // Proyecto nuevo limpio: se registra y se sigue con el pedido aquí mismo
      await registrarProyectoEnPanel(nuevo, '', sig.trabajoId).catch(() => {});
      await guardarCola({ mensaje: `${donde}: proyecto nuevo abierto, genero aquí…` });
    }

    // Si es un reintento, ¿Flow ya lo había creado la otra vez? Entonces se
    // entrega eso en vez de generarlo de nuevo
    if (sig.reintento && sig.trabajoId && (await entregarSiYaExiste(cola, sig))) {
      await devolverFoco();
      return;
    }
    // Imágenes de referencia del pedido: se GUARDAN en la PC (Descargas/
    // snave-referencias) para que las tengas a mano. La extensión no las mete en
    // Flow; eso lo haces tú si quieres. Luego se genera el video normal.
    if (sig.entradas && sig.entradas.length) {
      await guardarCola({ mensaje: `${donde}: guardando ${sig.entradas.length} imagen(es) de referencia en tu PC…` });
      await descargarEntradasAPc(sig).catch(() => {});
    }
    const antes = (await enPagina(tabId, mediosDeLaPagina, ajustes.mode)).map((m) => m.clave);
    const fallosAntes = await enPagina(tabId, avisosDeFallo);
    const r = await enPagina(tabId, fillFlow, { ...ajustes, prompt });
    if (!r.ok || !r.envio) {
      // Lo que se veía en Flow, para "Copiar diagnóstico"
      await chrome.storage.local.set({ ultimoFallo: { error: r.error || 'Sin flecha de crear', dom: r.dom || '', cuando: new Date().toISOString() } });
      throw new Error(r.ok ? 'No encontré la flecha de crear.' : r.error);
    }

    await chrome.storage.local.set({ ultimoRelleno: { flecha: r.flecha, botones: r.botonesCompositor } });
    await guardarCola({ mensaje: `${donde}: pulsando la flecha de crear…` });
    // Con otros pedidos en curso, que "salga algo nuevo arriba" no demuestra que
    // este se envió (puede ser el anterior): solo vale ver SU prompt
    const estricto = listaPendientes(await leerCola()).length > 0;
    // Marca del bloque de Flow de este pedido: su número del panel (así, si se
    // reintenta, se reconoce lo que ya había creado la vez anterior)
    const bloque = String(sig.trabajoId || `t${sig.numero}-${Date.now()}`);
    const envioRes = await enviarConReintentos(tabId, r.envio, r.antes, prompt, estricto, bloque);
    await soltarDepurador(tabId); // ya escribió y pulsó: fuera la barra de Chrome
    await devolverFoco(); // Flow ya aceptó el pedido: sigue generando aunque no se vea
    if (!envioRes.ok) throw new Error(`${donde}: ${envioRes.error}`);

    if (sig.alEnviar) await sig.alEnviar();
    // Para que "Descargar" del popup sepa cuáles son los de este prompt
    await chrome.storage.local.set({ ultimo: { prompt, antes, tab: tabId } });
    const nuevo = {
      prompt, nombre: sig.nombre, numero: sig.numero, donde, antes, desde: Date.now(),
      n: Number(ajustes.count) || 1, tipo: ajustes.mode, trabajoId: sig.trabajoId || null, fallosAntes, bloque, calidad: sig.calidad || '',
    };
    // Envío OK: se reinicia el contador de reintentos por "actividad inusual"
    await guardarCola({ mensaje: `${donde}: enviado, generando…`, pendiente: null, pendientes: [...listaPendientes(await leerCola()), nuevo], antibotReintentos: 0 });
    await sleep((await reglas(cola)).pausa); // respiro antes de mandar el siguiente
  } catch (e) {
    await devolverFoco();
    await soltarDepurador(tabId);
    if (!sig.trabajoId) throw e;
    // El trabajo ya estaba tomado: que el panel no lo deje colgado en "procesando".
    // Si es un fallo pasajero, vuelve a la cola y se sigue con el siguiente,
    // salvo que ya sean demasiados seguidos
    const pasajero = FALLO_PASAJERO.test(e.message);
    await terminarEnPanel(sig.trabajoId, false, e.message, pasajero);
    if (!pasajero) throw e;
    const { maxFallos, pausa } = await reglas(cola);
    const fallosSeguidos = ((await leerCola()).fallosSeguidos || 0) + 1;
    if (fallosSeguidos >= maxFallos) return demasiadosFallos(cola, `${fallosSeguidos} fallos seguidos. Último: ${e.message}`);
    await guardarCola({ fallosSeguidos, mensaje: `${e.message} Vuelve a la cola del panel.` });
    await sleep(pausa);
  }
}

// Reintento: si en el proyecto ya está lo que se pidió (de la vez anterior) y
// nadie se lo llevó, se entrega eso. Devuelve true si lo entregó.
async function entregarSiYaExiste(cola, sig) {
  const reclamados = (await leerCola()).reclamados || [];
  const n = Number(sig.ajustes.count) || 1;
  const hay = (await enPagina(cola.tabId, mediosDeLaPagina, sig.ajustes.mode, false, sig.prompt, String(sig.trabajoId)).catch(() => []))
    .filter((m) => !reclamados.includes(m.clave)).slice(0, n);
  if (hay.length < n) return false;
  await guardarCola({ mensaje: `${sig.donde}: ya estaba creado en Flow, lo entrego sin repetirlo…` });
  const subidos = await entregarAlPanel(sig.trabajoId, hay, sig.calidad === '1080p' ? cola.tabId : null);
  await terminarEnPanel(sig.trabajoId, true, `Ya estaba creado en Flow: ${subidos} de ${hay.length} subidos al panel`);
  const actual = await leerCola();
  await guardarCola({ fallosSeguidos: 0, reclamados: [...(actual.reclamados || []), ...hay.map((m) => m.clave)].slice(-300) });
  return true;
}

// ¿El pedido necesita otro proyecto distinto del que está abierto?
async function vaAOtroProyecto(tabId, sig) {
  const actual = idProyecto((await chrome.tabs.get(tabId).catch(() => ({}))).url);
  const p = await proyectoReal(sig.proyecto);
  return !actual || p === 'nuevo' || esAliasPanel(p) || (p !== '' && p !== actual);
}

const comprobados = new Map(); // pedido -> última vez que se preguntó al panel

// Una pasada por todo lo que se está generando: entrega lo que ya salió, suelta
// lo que el panel ya no da por suyo y cierra lo que Flow rechazó o tardó demasiado
async function revisarPendientes(cola) {
  const pend = listaPendientes(cola);
  const partes = [];
  for (const p of pend) {
    if (!(await leerCola()).activa) return;
    const resultado = await revisarUno(await leerCola(), p);
    if (resultado) partes.push(resultado);
  }
  const quedan = listaPendientes(await leerCola());
  if (quedan.length && partes.length) {
    const mensaje = `Generando ${quedan.length} a la vez: ${partes.join(' · ')}`;
    await guardarCola({ mensaje });
    if (cola.origen === 'panel') await latidoPanel({ activa: true, mensaje, trabajo: quedan[0].trabajoId }).catch(() => {});
  }
}

const quitarPendiente = async (p) =>
  guardarCola({ pendientes: listaPendientes(await leerCola()).filter((x) => claveDe(x) !== claveDe(p)) });

// Revisa un pedido. Devuelve un texto corto de cómo va ("#51 0/1"), o null si
// ya terminó (entregado, rechazado, soltado o vencido)
async function revisarUno(cola, p) {
  const { esperaMax, maxFallos } = await reglas(cola);
  const limite = p.desde + esperaMax[p.tipo];

  // Cada 20 s: ¿el panel sigue dándolo por "generándose"? Si lo reintentaron,
  // lo borraron o venció, se suelta (antes se quedaba esperando un video que
  // nunca iba a llegar y no tomaba los demás)
  if (p.trabajoId && Date.now() - (comprobados.get(claveDe(p)) || 0) > 20000) {
    comprobados.set(claveDe(p), Date.now());
    const estado = await estadoEnPanel(p.trabajoId);
    if (estado && estado !== 'procesando') {
      await quitarPendiente(p);
      await guardarCola({ mensaje: `${p.donde}: el panel lo tiene como "${estado}", lo suelto.` });
      return null;
    }
  }

  // Sus videos: los del bloque de Flow con SU prompt, que no estuvieran antes
  // y que no se haya llevado ya otro pedido igual
  const reclamados = (await leerCola()).reclamados || [];
  // Flow no usa <video> (renderiza en canvas); la URL del video solo está en la
  // RED (flow-content.google/video/<id>, mismo id que la miniatura). El background
  // la capturó; aquí se cruza por id y se rellena el src para entregarla al panel.
  const nuevos = async () => {
    // Videos: pasar el ratón por los tiles (SIN clic) para que Flow dibuje el
    // <video> con su src. Y el background además captura la URL de la red.
    if (p.tipo !== 'imagen') await enPagina(cola.tabId, despertarVideos, p.prompt, p.bloque || '').catch(() => {});
    let lista = (await enPagina(cola.tabId, mediosDeLaPagina, p.tipo, false, p.prompt, p.bloque || ''))
    .map((m) => {
      if (m.tipo === 'video' && m.idVideo) {
        // La URL flow-content/video que el background capturó de la red: la mejor
        // para R2 (tiene id y Expires). Mejora incluso un enlace asb ya puesto.
        const u = urlVideoPorId(m.idVideo);
        if (u) return { ...m, src: u, esAsb: false };
      }
      return m;
    })
    // Videos: solo valen cuando YA tienen una URL (si no, se espera)
    .filter((m) => m.tipo !== 'video' || m.src)
    .filter((m) => !p.antes.includes(m.clave) && !reclamados.includes(m.clave));
    // Margen para que cargue la URL buena (flow-content/video) antes de conformarse
    // con el enlace asb, que R2 descarga peor. Pasados 90 s desde que se pidió, se
    // entrega el asb igual (mejor eso que nada).
    if (Date.now() < p.desde + 90e3) lista = lista.filter((m) => !m.esAsb);
    return lista;
  };
  let listos = await nuevos();
  const rechazo = listos.length ? null : await enPagina(cola.tabId, rechazoArriba, p.prompt, p.bloque || '').catch(() => null);
  const fallidos = 0; // con varios a la vez, el recuento global de fallos ya no dice de quién es: vale el rechazo de su bloque
  if (!rechazo && listos.length < p.n && Date.now() < limite) return `${p.donde.replace('Panel ', '')} ${listos.length}/${p.n}`;

  // Terminado: se saca de la lista antes de entregar, para no entregarlo dos veces
  await quitarPendiente(p);
  const errores = cola.errores || [];
  const nombre = p.nombre || p.prompt;
  let resumen = rechazo
    ? `Flow: "${rechazo.texto}"`
    : fallidos
      ? 'Flow no pudo generarlo (alta demanda u otro fallo suyo; no se cobró). Reinténtalo más tarde'
      : `Superó el tiempo máximo de entrega (${Math.round(esperaMax[p.tipo] / 60e3)} min) sin resultado de Flow`;
  if (listos.length) {
    await sleep(1500); // que termine de cargar el último
    // Solo los pedidos: al moverse la galería Flow carga otros videos que no
    // estaban antes y también parecerían "nuevos". Los de este prompt son los
    // más recientes, que Flow pone arriba del todo (los primeros de la página).
    listos = (await nuevos()).slice(0, p.n);
  }
  if (p.trabajoId) {
    // Al panel en vez de a Descargas: el panel es donde se guardan
    let subidos = 0;
    if (listos.length) {
      await guardarCola({ mensaje: `${p.donde}: subiendo ${listos.length} al panel…` });
      // En 1080p solo si el pedido lo pidió (el 720p es el que ya se ve en Flow)
      subidos = await entregarAlPanel(p.trabajoId, listos, p.calidad === '1080p' ? cola.tabId : null);
      const enHD = listos.filter((m) => m.hd).length;
      resumen = `${subidos} de ${listos.length} subidos al panel` + (enHD ? ` (${enHD} en 1080p)` : '') + (subidos < listos.length ? ' (el resto como enlace)' : '');
    }
    // Sin nada generado: el panel lo reintenta si quedan intentos (salvo si Flow
    // lo rechazó por sus normas: repetir el mismo prompt no sirve). Si Flow lo
    // rechazó o falló, en SnaveCloud queda como "Rechazo de Flow" con su mensaje.
    const nada = listos.length === 0;
    const esRechazo = nada && !!(rechazo || fallidos);
    await terminarEnPanel(p.trabajoId, !nada,
      listos.length < p.n && !nada ? `${listos.length} de ${p.n} generados. ${resumen}` : resumen,
      nada && !(rechazo && rechazo.politica), esRechazo);
  } else if (listos.length) {
    descargarMedios(listos, (m, i) => `${String(p.numero).padStart(2, '0')}-${i + 1} ${nombre}`);
    resumen = `descargando ${listos.length}`;
  }
  if (listos.length < p.n) {
    errores.push({ prompt: nombre, motivo: listos.length ? `solo ${listos.length} de ${p.n}` : fallidos ? 'Flow falló (alta demanda)' : 'sin resultado' });
  }
  // Un rechazo por normas es culpa del prompt, no de la cuenta: no cuenta como fallo seguido
  const actual = await leerCola();
  const fallosSeguidos = listos.length || (rechazo && rechazo.politica) ? 0 : (actual.fallosSeguidos || 0) + 1;
  await guardarCola({
    errores, fallosSeguidos, mensaje: `${p.donde}: ${resumen}.`,
    reclamados: [...(actual.reclamados || []), ...listos.map((m) => m.clave)].slice(-300),
  });

  if (fallosSeguidos >= maxFallos) {
    await demasiadosFallos(await leerCola(), `${fallosSeguidos} prompts seguidos sin resultado. ¿Sin créditos, o Flow dio un error?`);
  }
  return null;
}

// La persona pulsó "Cancelar" en la barra de depuración, o cerró la pestaña
chrome.debugger.onDetach.addListener(async ({ tabId }, motivo) => {
  if (!depurando.delete(tabId)) return;
  const cola = await leerCola();
  if (!(cola.activa && cola.tabId === tabId)) return;
  if (cola.origen === 'panel') {
    // Siempre encendida: cerrar la ventana de Flow le llega a Chrome igual que
    // pulsar "Cancelar" en su barra, así que ninguna de las dos la apaga. Solo
    // el interruptor "Procesar automáticamente". En el siguiente pedido se
    // vuelve a conectar (y a abrir Flow si hace falta).
    await guardarCola({ mensaje: 'Se cerró Flow o su barra: lo vuelvo a abrir en el siguiente pedido.' });
  } else if (motivo === 'canceled_by_user') {
    await detenerCola('Cola parada: cancelaste la barra de depuración de Chrome.');
  } else {
    await detenerCola('Cola parada: se cerró o cambió la pestaña de Flow.');
  }
});

chrome.alarms.onAlarm.addListener(async ({ name }) => {
  if (name !== 'cola') return;
  if ((await leerCola()).activa) bucleCola();
  else chrome.alarms.clear('cola');
});

// Chrome se cerró con la cola en marcha: no retomarla a ciegas al abrir
// Si Chrome durmió o reinició el service worker (o se recargó la extensión) con
// la cola del panel encendida, sigue sola
leerCola().then((c) => {
  if (c.activa && c.origen === 'panel') {
    chrome.alarms.create('cola', { periodInMinutes: 1 });
    bucleCola();
  }
});

// Al abrir Chrome: la cola del panel sigue sola (siempre encendida), abriendo el
// último proyecto de Flow en su ventana. La tanda local no se retoma a ciegas.
chrome.runtime.onStartup.addListener(async () => {
  const cola = await leerCola();
  if (!cola.activa) return;
  if (cola.origen !== 'panel') return detenerCola('Cola parada: Chrome se cerró mientras corría.');
  await sleep(5000); // que Chrome termine de restaurar las pestañas
  const tabId = await recuperarPestana(null).catch(() => null);
  // Lo que estaba generándose al cerrar Chrome sigue en la lista: al abrir
  // Flow otra vez se busca su video, y si no sale, vence y el panel lo reintenta
  await guardarCola({ tabId, mensaje: 'Chrome se abrió: sigo con los pedidos del panel.' });
  chrome.alarms.create('cola', { periodInMinutes: 1 });
  bucleCola();
});
