// Service worker: el atajo de teclado, la cola automática (cola.js) y las
// llamadas a la IA (ia.js), que siguen aunque el popup se cierre.
importScripts('util.js', 'fill.js', 'tanda.js', 'panel.js', 'cola.js', 'ia.js');

const TITULO = 'SnaveCloud';

// ---- Captura de la URL del video (está en la RED, no en el HTML) ----
// Flow sirve el video desde flow-content.google/video/<id>?... (mismo <id> que la
// miniatura image/<id>). Al ver/cargar el video, el navegador pide esa URL; aquí
// se observa y se guarda por id, para entregársela al panel. Es recuperar TU
// propio video (la URL que el navegador ya recibe), no forzar nada.
const videosCapturados = new Map(); // id -> { url, t }
function recordarVideo(url) {
  const m = String(url || '').match(/flow-content\.google\/video\/([a-z0-9-]+)/i);
  if (!m) return;
  videosCapturados.set(m[1].toLowerCase(), { url, t: Date.now() });
  // No dejar crecer sin fin: deja los 100 más nuevos
  if (videosCapturados.size > 100) {
    const viejos = [...videosCapturados.entries()].sort((a, b) => a[1].t - b[1].t).slice(0, videosCapturados.size - 100);
    for (const [k] of viejos) videosCapturados.delete(k);
  }
}
function urlVideoPorId(id) {
  const v = videosCapturados.get(String(id || '').toLowerCase());
  return v ? v.url : '';
}
try {
  chrome.webRequest.onCompleted.addListener(
    (d) => recordarVideo(d.url),
    { urls: ['https://flow-content.google/video/*', 'https://*.flow-content.google/video/*'] }
  );
  chrome.webRequest.onBeforeRequest.addListener(
    (d) => recordarVideo(d.url),
    { urls: ['https://flow-content.google/video/*', 'https://*.flow-content.google/video/*'] }
  );
} catch (e) { /* sin webRequest: se usa solo lo que haya en el DOM */ }

// La extension YA trae su conexion (URL + token) en conexion.json. Al instalar o
// arrancar, si el perfil aun no tiene token guardado, se toma de ahi y queda
// CONECTADA sola, sin que el cliente pegue nada. (Si el cliente ya se conecto a
// mano, no se pisa lo suyo.)
// Genera (o recupera) el token PROPIO de esta extension, en el formato que pide
// el panel: 48 caracteres hex (24 bytes al azar). Ese es el token que el cliente
// copia y pega en su panel SnaveCloud para activar su cuenta.
async function miToken() {
  const g = await chrome.storage.local.get(['mi_token']);
  if (g.mi_token && /^[a-f0-9]{48}$/.test(g.mi_token)) return g.mi_token;
  const b = crypto.getRandomValues(new Uint8Array(24));
  const tok = [...b].map((x) => x.toString(16).padStart(2, '0')).join('');
  await chrome.storage.local.set({ mi_token: tok });
  return tok;
}

async function asegurarConexion() {
  try {
    const c = await (await fetch(chrome.runtime.getURL('conexion.json'), { cache: 'no-store' })).json();
    const g = await chrome.storage.local.get(['panel_url']);
    const panelUrl = String((c && (c.panel_url || c.url)) || '').trim();
    const g2 = await chrome.storage.local.get(['panel_token']);
    const tok = await miToken();                 // token propio de la extension
    const patch = {};
    if (!g2.panel_token) patch.panel_token = tok;  // solo si no hay (no pisa uno compartido)
    if (panelUrl && !g.panel_url) patch.panel_url = panelUrl;
    if (Object.keys(patch).length) await chrome.storage.local.set(patch);
  } catch (e) { /* sin conexion.json: igual deja su token */ }
}
chrome.runtime.onInstalled.addListener(asegurarConexion);
chrome.runtime.onStartup.addListener(asegurarConexion);
asegurarConexion();

// Activa la extension con el CORREO de Google: pide su token a Cloudflare. Solo
// funciona si la cuenta esta en la lista permitida. Guarda el token y conecta.
async function activarPorCorreo(correo) {
  correo = String(correo || '').trim().toLowerCase();
  if (!correo.includes('@')) return { ok: false, error: 'Escribe tu correo de Google.' };
  const g = await chrome.storage.local.get(['sync_url', 'panel_url']);
  let sync = String(g.sync_url || '').trim();
  let panel = String(g.panel_url || '').trim();
  try {
    const c = await (await fetch(chrome.runtime.getURL('conexion.json'), { cache: 'no-store' })).json();
    if (!sync) sync = String((c && c.sync_url) || '').trim();
    if (!panel) panel = String((c && (c.panel_url || c.url)) || '').trim();
  } catch (e) {}
  if (!sync) return { ok: false, error: 'Falta la URL de activación (sync_url) en conexion.json.' };

  let r;
  try {
    const u = `${sync.replace(/\/$/, '')}/?flow=registrar&cuenta=${encodeURIComponent(correo)}&t=${Date.now()}`;
    r = await (await fetch(u, { cache: 'no-store' })).json();
  } catch (e) {
    return { ok: false, error: 'No llegué al servidor de activación. Revisa tu internet.' };
  }
  if (!r || !r.ok || !r.token) return { ok: false, error: (r && r.error) || 'No se pudo activar.' };

  await chrome.storage.local.set({ panel_url: panel, panel_token: r.token, cuenta: correo });
  // Comprobar que el token conecta con el panel de verdad
  const ping = await llamarPanel('ping').catch((e) => ({ ok: false, error: e.message }));
  if (!ping.ok) {
    await chrome.storage.local.remove('panel_token');
    return { ok: false, error: 'Token recibido pero el panel no conecta: ' + (ping.error || '') };
  }
  return { ok: true, cuenta: correo };
}

// Resultado del atajo en la insignia del icono: OK o ! (el motivo sale al pasar
// el raton por encima).
function avisar(tabId, ok, texto) {
  chrome.action.setBadgeBackgroundColor({ tabId, color: ok ? '#1e8e3e' : '#d93025' });
  chrome.action.setBadgeText({ tabId, text: ok ? 'OK' : '!' });
  chrome.action.setTitle({ tabId, title: `${TITULO}: ${texto}` });
  // La insignia se quita sola; el titulo con el motivo se queda hasta la proxima vez
  setTimeout(() => chrome.action.setBadgeText({ tabId, text: '' }), ok ? 3000 : 8000);
}

// Atajo: rellena Flow con el siguiente prompt de la tanda y los ultimos
// ajustes del popup, sin abrirlo, y pulsa la flecha de crear.
chrome.commands.onCommand.addListener(async (command, tab) => {
  if (command !== 'rellenar' || !tab) return;
  if (!ES_FLOW.test(tab.url || '')) {
    return avisar(tab.id, false, 'abre un proyecto de Google Flow en esta pestaña');
  }
  if ((await leerCola()).activa) return avisar(tab.id, false, 'la cola automática está en marcha');

  const estado = await siguientePrompt();
  if (!estado.lista.length) return avisar(tab.id, false, 'primero escribe un prompt en el popup');
  if (!estado.actual) {
    return avisar(tab.id, false, `tanda terminada (${estado.lista.length} de ${estado.lista.length}). Abre el popup para empezar otra`);
  }

  const ajustes = await leerAjustes();
  const prompt = componerPrompt(estado.actual, ajustes);

  chrome.action.setBadgeText({ tabId: tab.id, text: '…' });
  try {
    const antes = (await enPagina(tab.id, mediosDeLaPagina, ajustes.mode)).map((m) => m.clave);
    const result = await enPagina(tab.id, fillFlow, { ...ajustes, prompt });
    if (!result.ok) return avisar(tab.id, false, result.error);
    await marcarHecho(estado);
    // Para que "Descargar" sepa cuales son los de este prompt
    await chrome.storage.local.set({ ultimo: { prompt, antes, tab: tab.id } });
    const donde = `${estado.indice + 1} de ${estado.lista.length}`;
    if (!result.envio) return avisar(tab.id, true, `${donde} listo, pulsa la flecha de crear ${result.note}`.trim());
    await chrome.storage.local.set({ ultimoRelleno: { flecha: result.flecha, botones: result.botonesCompositor } });
    const envioRes = await enviarUnaVez(tab.id, result.envio, result.antes, prompt);
    avisar(tab.id, envioRes.ok, envioRes.ok ? `${donde} enviado a Flow` : `${donde}: ${envioRes.error}`);
  } catch (e) {
    avisar(tab.id, false, e.message);
  }
});

// Una pestaña con un proyecto de Flow abierto: la activa si lo es, si no la
// primera que haya. null si no hay ninguna.
async function buscarPestanaFlow() {
  const tabs = await chrome.tabs.query({ url: ['https://flow.google.com/*', 'https://labs.google/*'] });
  const proyectos = tabs.filter((t) => /\/project\//.test(t.url || ''));
  return proyectos.find((t) => t.active) || proyectos[0] || null;
}

// Mensajes del popup
chrome.runtime.onMessage.addListener((msg, _sender, responder) => {
  if (msg.tipo === 'iniciarCola') {
    (async () => {
      // Sin pestaña de Flow abierta, se abre sola (en su ventana) el último proyecto usado
      const tabId = msg.tabId || (await buscarPestanaFlow())?.id || (await abrirVentanaFlow());
      if (!tabId) return responder({ ok: false, error: 'Abre una vez un proyecto de Google Flow; después la extensión lo abrirá sola.' });
      await iniciarCola(tabId, msg.origen);
      responder({ ok: true });
    })();
    return true;
  }
  if (msg.tipo === 'enviarAhora') {
    enviarUnaVez(msg.tabId, msg.envio, msg.antes, msg.prompt)
      .then((r) => responder(r))
      .catch((e) => responder({ ok: false, error: e.message }));
    return true;
  }
  if (msg.tipo === 'panelPing') {
    llamarPanel('ping')
      .then((r) => responder(r))
      .catch((e) => responder({ ok: false, error: e.message }));
    return true;
  }
  if (msg.tipo === 'activarPorCorreo') {
    activarPorCorreo(msg.correo).then(responder).catch((e) => responder({ ok: false, error: e.message }));
    return true;
  }
  if (msg.tipo === 'detenerCola') {
    detenerCola('Cola detenida.').then(() => responder({ ok: true }));
    return true;
  }
  if (msg.tipo === 'guion') {
    // El resultado se guarda aquí, no en el popup: así no se pierde si se cierra
    guionAEscenas(msg.datos)
      .then(async (escenas) => {
        await chrome.storage.local.set({ prompt: escenas.join('\n'), tanda: { hechos: [] } });
        responder({ ok: true, n: escenas.length });
      })
      .catch((e) => responder({ ok: false, error: e.message }));
    return true;
  }
});
