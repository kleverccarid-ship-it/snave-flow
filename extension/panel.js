// Conexión con el panel SnaveCloud (dashboard/flow-cola). El panel guarda la
// cola de prompts; la extensión toma uno, lo genera en Flow y le sube el
// resultado. La URL del panel y el token se configuran en el popup.
//
// La API se llama sin ".php": el .htaccess del panel redirige las URLs con
// .php con un 301, y esa redirección convierte los POST en GET.

async function configPanel() {
  const g = await chrome.storage.local.get(['panel_url', 'panel_token', 'mi_token']);
  let base = String(g.panel_url || '').trim().replace(/flow-cola(\.php)?\/?$/, '');
  if (base && !base.endsWith('/')) base += '/';
  // El token de conexion es el propio de la extension (mi_token). Si por lo que
  // sea panel_token esta vacio, se usa mi_token igual.
  const token = String(g.panel_token || g.mi_token || '').trim();
  return { base, token };
}

async function llamarPanel(accion, datos) {
  const { base, token } = await configPanel();
  if (!base || !token) throw new Error('Falta la URL o el token del panel.');
  let res;
  try {
    res = await fetch(`${base}flow-cola-api?accion=${accion}`, {
      method: datos ? 'POST' : 'GET',
      headers: { 'X-Flow-Token': token },
      body: datos,
      cache: 'no-store',
    });
  } catch (e) {
    // "Failed to fetch" no dice nada: explicar qué revisar
    throw new Error(`No llego a ${new URL(base).host}. Revisa que la URL sea la carpeta dashboard/, que XAMPP/el servidor esté encendido y que recargaste la extensión (↻).`);
  }
  const body = await res.json().catch(() => ({}));
  if (!res.ok || !body.ok) throw new Error(body.error || `El panel respondió HTTP ${res.status}. ¿La URL es la carpeta dashboard/?`);
  return body;
}

function formulario(campos) {
  const f = new FormData();
  for (const [k, v] of Object.entries(campos)) f.append(k, String(v));
  return f;
}

// El próximo trabajo del panel ({ id, prompt, mode, model, aspect, dur, count }) o null
async function tomarDelPanel() {
  return (await llamarPanel('siguiente')).trabajo;
}

// Descarga las imágenes de entrada (inicio/fin/referencia) del panel a data URLs,
// para que fillFlow las suba a Flow. Cada entrada: { rol, ruta }. La ruta puede
// ser relativa (uploads/flow/...) -> se resuelve contra la URL del panel; o
// absoluta (http..., de R2) -> se usa tal cual. Devuelve [{ rol, dataUrl, nombre }].
async function descargarEntradas(entradas) {
  if (!Array.isArray(entradas) || !entradas.length) return [];
  const { base } = await configPanel();
  const out = [];
  for (const e of entradas) {
    const ruta = String((e && e.ruta) || '');
    if (!ruta) continue;
    const url = /^https?:\/\//i.test(ruta) ? ruta : base + ruta.replace(/^\//, '');
    try {
      const res = await fetch(url, { cache: 'no-store' });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const blob = await res.blob();
      if (blob.size < 100) throw new Error('imagen vacía');
      const dataUrl = await new Promise((ok, no) => {
        const fr = new FileReader();
        fr.onload = () => ok(fr.result);
        fr.onerror = () => no(new Error('no se pudo leer la imagen'));
        fr.readAsDataURL(blob);
      });
      const nombre = (ruta.split('/').pop() || 'imagen').split('?')[0];
      out.push({ rol: (e && e.rol) || 'referencia', dataUrl, nombre });
    } catch (err) { /* si una falla, se sigue con las demás */ }
  }
  return out;
}

// Sube cada archivo al panel. Si una subida falla (archivo muy grande, por
// ejemplo), manda al menos el enlace de Flow, que caduca en unas horas.
// Con tabId, los videos van en 1080p (videoEn1080, en cola.js) si Flow lo da;
// si no, el original. Cada medio queda con m.hd = true si subió en 1080p.
async function entregarAlPanel(id, medios, tabId = null) {
  let subidos = 0;
  const log = [];     // queda guardado en ultimaEntrega para el diagnóstico
  for (const m of medios) {
    const hd = tabId != null ? await videoEn1080(tabId, m).catch(() => null) : null;
    if (hd) {
      try {
        const f = formulario({ id });
        f.append('archivo', hd, 'flow-1080p.mp4');
        await llamarPanel('archivo', f);
        m.hd = true; subidos++; log.push('ok 1080p');
        continue;
      } catch (e) { log.push('1080p falló: ' + e.message); }
    }
    if (!m.src) { log.push('sin URL'); continue; }
    // 1) Subir el ARCHIVO (los bytes). El enlace flow-content.google/video/… está
    //    atado a la sesión de Google: el panel NO puede reproducirlo (no tiene esas
    //    cookies). El archivo sí se ve siempre, sin necesitar R2. La extensión sí
    //    puede bajarlo porque corre en el navegador con la sesión abierta.
    //    (bytes=0- = el archivo completo)
    try {
      const res = await fetch(m.src, { credentials: 'include', headers: { Range: 'bytes=0-' } });
      if (!res.ok && res.status !== 206) throw new Error('HTTP ' + res.status);
      const blob = await res.blob();
      if (blob.size < 10000) throw new Error('archivo vacío (' + blob.size + ' bytes)');
      const f = formulario({ id });
      f.append('archivo', blob, m.tipo === 'imagen' ? 'flow.png' : 'flow.mp4');
      await llamarPanel('archivo', f);
      subidos++; log.push('ok archivo (' + Math.round(blob.size / 1024) + ' KB)');
      continue;
    } catch (e) { log.push('archivo falló: ' + e.message); }
    // 2) Plan B: si no se pudo bajar el archivo, al menos el ENLACE (para R2 en
    //    producción). En local sin R2 este enlace no se podrá reproducir.
    try {
      await llamarPanel('enlace', formulario({ id, url: m.src, tipo: m.tipo }));
      subidos++; log.push('enlace (plan B): ' + String(m.src).slice(0, 50));
    } catch (e) { log.push('enlace falló: ' + e.message); }
  }
  try { await chrome.storage.local.set({ ultimaEntrega: { id, n: medios.length, srcs: medios.map((m) => String(m.src || '(vacío)').slice(0, 90)), log, cuando: new Date().toISOString() } }); } catch (e) {}
  return subidos;
}

// transitorio: fallo de Flow que suele pasarse solo (alta demanda); el panel
// devuelve el trabajo a la cola si le quedan intentos
// rechazo: Flow no quiso o no pudo generarlo; en el panel sale como "Rechazo de Flow"
async function terminarEnPanel(id, ok, mensaje, transitorio = false, rechazo = false) {
  // Si el panel no responde, el trabajo se queda "procesando" y se puede
  // reintentar desde el panel; no vale la pena parar la cola por eso
  // Va con el proyecto donde se generó, para que el pedido quede apuntando a él
  const p = await proyectoActual();
  await llamarPanel('terminar', formulario({ id, ok: ok ? 1 : 0, mensaje, transitorio: transitorio ? 1 : 0, rechazo: rechazo ? 1 : 0,
    flow_id: p.flow_id || '', nombre: p.nombre || '' })).catch(() => {});
}

// Estado del pedido en el panel: 'procesando', 'pendiente', 'hecho', 'no_existe'...
// null si el panel no responde (entonces se sigue esperando como si nada)
async function estadoEnPanel(id) {
  try {
    return (await llamarPanel(`estado&id=${encodeURIComponent(id)}`)).estado;
  } catch (e) {
    return null;
  }
}

// El proyecto de Flow abierto en la pestaña de la cola: { flow_id, nombre } o {}
async function proyectoActual() {
  try {
    const { cola } = await chrome.storage.local.get('cola');
    if (!cola || cola.tabId == null) return {};
    const tab = await chrome.tabs.get(cola.tabId);
    const id = (String(tab.url || '').match(/\/project\/([A-Za-z0-9-]{8,64})/) || [])[1];
    // El nombre que da Flow va en el título de la pestaña ("Google Flow: sept 25 - 15:02")
    return id ? { flow_id: id, nombre: String(tab.title || '').replace(/^.*?flow\s*[:\-–]\s*/i, '').trim() } : {};
  } catch (e) {
    return {};
  }
}

// Le cuenta al panel en qué proyecto de Flow se trabajó (o cuál se creó)
// alias: el id provisional "nuevo-…" si el proyecto se creó con el "+" del panel
async function registrarProyectoEnPanel(flowId, nombre, trabajoId, alias = '') {
  const { base, token } = await configPanel();
  if (!base || !token) return;
  await llamarPanel('proyecto', formulario({ flow_id: flowId, nombre: nombre || '', id: trabajoId || 0, alias }));
}

// Configuración que manda el panel (como en La Casa Dark: el panel pone las reglas)
const CONFIG_PANEL_DEFECTO = {
  procesar: true, pausa_seg: 3, espera_video_min: 10, espera_imagen_min: 3, max_intentos: 3, max_fallos_seguidos: 2, simultaneos: 3,
};
async function configDelPanel() {
  const { panel_config } = await chrome.storage.local.get('panel_config');
  return { ...CONFIG_PANEL_DEFECTO, ...(panel_config || {}) };
}

// Latido: le cuenta al panel qué hace la extensión y se trae su configuración.
// Como mucho uno cada 15 s, salvo que cambie el estado.
let ultimoLatido = { t: 0, clave: '' };
// Lee el correo de la cuenta de Google Flow conectada en la pestaña (está en el
// aria-label del botón de cuenta, o en el menú si está abierto). Se inyecta.
function leerCuentaFlow() {
  const MAIL = /[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i;
  for (const n of document.querySelectorAll('[aria-label],a[aria-label],img[alt],[data-email]')) {
    const t = `${n.getAttribute('aria-label') || ''} ${n.getAttribute('alt') || ''} ${n.getAttribute('data-email') || ''}`;
    const m = t.match(MAIL);
    if (m) return m[0].toLowerCase();
  }
  const m2 = (document.body.textContent || '').match(MAIL);
  return m2 ? m2[0].toLowerCase() : '';
}

let cuentaFlowCache = { v: '', t: 0 };
async function cuentaDeFlow() {
  if (cuentaFlowCache.v && Date.now() - cuentaFlowCache.t < 60000) return cuentaFlowCache.v;
  try {
    const tab = await buscarPestanaFlow();
    if (tab) {
      const [{ result }] = await chrome.scripting.executeScript({ target: { tabId: tab.id }, func: leerCuentaFlow });
      if (result) cuentaFlowCache = { v: result, t: Date.now() };
    }
  } catch (e) {}
  return cuentaFlowCache.v;
}

async function latidoPanel({ activa, mensaje, trabajo }, forzar = false) {
  const clave = `${activa}|${trabajo || ''}`;
  if (!forzar && clave === ultimoLatido.clave && Date.now() - ultimoLatido.t < 15000) return configDelPanel();
  ultimoLatido = { t: Date.now(), clave };
  // Con el proyecto abierto: así el panel lo detecta aunque no haya pedidos
  const p = await proyectoActual();
  const cuentaFlow = await cuentaDeFlow();
  const r = await llamarPanel('latido', formulario({
    activa: activa ? 1 : 0, mensaje: String(mensaje || '').slice(0, 90),
    version: chrome.runtime.getManifest().version, trabajo: trabajo || 0,
    flow_id: p.flow_id || '', nombre: p.nombre || '',
    cuenta_flow: cuentaFlow || '',
  }));
  if (r.config) await chrome.storage.local.set({ panel_config: r.config });
  return { ...CONFIG_PANEL_DEFECTO, ...(r.config || {}) };
}
