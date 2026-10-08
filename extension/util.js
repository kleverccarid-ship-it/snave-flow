// Utilidades del lado de la extensión (popup y service worker), no de la página.

const ES_FLOW = /^https:\/\/(flow\.google\.com|labs\.google)\//;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// Ejecuta una de las funciones de fill.js dentro de la pestaña y devuelve lo que devuelva
async function enPagina(tabId, func, ...args) {
  const [{ result }] = await chrome.scripting.executeScript({ target: { tabId }, func, args });
  return result;
}

// Igual, pero en el mundo de la propia página (world MAIN), para funciones
// que necesitan ver lo mismo que el código de Flow, como sus peticiones de red
async function enPaginaMain(tabId, func, ...args) {
  const [{ result }] = await chrome.scripting.executeScript({ target: { tabId }, world: 'MAIN', func, args });
  return result;
}

// Los ajustes del popup, con valores por defecto por si nunca se abrió.
// model vacío significa "no cambiar el modelo".
const AJUSTES = ['mode', 'model', 'aspect', 'res', 'dur', 'count', 'personaje', 'estilo'];
async function leerAjustes() {
  const a = await chrome.storage.local.get(AJUSTES);
  return {
    mode: a.mode || 'video',
    model: a.model == null ? 'Lower Priority' : a.model,
    aspect: a.aspect || '',
    res: a.res || '',
    dur: a.dur || '8',
    count: a.count || '1',
    personaje: a.personaje || '',
    estilo: a.estilo || '',
  };
}

// Nombre válido en Windows: sin caracteres prohibidos ni punto o espacio al final
const nombreSeguro = (s) =>
  String(s || '').replace(/[\\/:*?"<>|\r\n]+/g, '').replace(/\s+/g, ' ').trim().slice(0, 60).replace(/[.\s]+$/, '') || 'flow';

// Los enlaces de Flow llevan firma y caducan en unas horas: hay que bajarlos ya
function descargarMedios(medios, nombreDe) {
  const carpeta = 'Flow ' + new Date().toISOString().slice(0, 10);
  medios.forEach((m, i) => {
    chrome.downloads.download({
      url: m.src,
      filename: `${carpeta}/${nombreSeguro(nombreDe(m, i))}.${m.tipo === 'imagen' ? 'png' : 'mp4'}`,
      conflictAction: 'uniquify',
    });
  });
  return carpeta;
}
