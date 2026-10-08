const FIELDS = ['prompt', 'mode', 'model', 'aspect', 'res', 'dur', 'count', 'personaje', 'estilo',
  'guion', 'proveedor', 'escenas', 'ia_modelo', 'ia_clave'];
const $ = (id) => document.getElementById(id);

function setStatus(text, kind) {
  $('status').textContent = text;
  $('status').className = kind || '';
}

async function pestanaFlow() {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  return tab && ES_FLOW.test(tab.url || '') ? tab : null;
}

// Los segundos no existen en modo imagen
const pintarModo = () => ($('durLabel').hidden = $('mode').value === 'imagen');
const pintarModeloIA = () => ($('ia_modelo').placeholder = IA_MODELOS[$('proveedor').value]);

// Recordar lo último que se usó
chrome.storage.local.get(FIELDS, (saved) => {
  for (const f of FIELDS) if (saved[f] != null) $(f).value = saved[f];
  pintarModo();
  pintarModeloIA();
});

// Guardar al escribir: el atajo y la cola usan lo último que haya aquí
for (const f of FIELDS) {
  $(f).addEventListener('input', async () => {
    await chrome.storage.local.set({ [f]: $(f).value });
    if (f === 'prompt') pintarTanda();
    if (f === 'mode') pintarModo();
    if (f === 'proveedor') pintarModeloIA();
  });
}

// Por dónde va la tanda: "3 de 10 · siguiente: un gato durmiendo"
async function pintarTanda() {
  const estado = await siguientePrompt();
  const el = $('tanda');
  $('reset').hidden = !estado.hechos.length;
  if (estado.lista.length < 2 && !estado.hechos.length) return (el.textContent = '');
  if (!estado.actual) {
    el.innerHTML = `<span class="hecho">Tanda terminada: ${estado.lista.length} de ${estado.lista.length}.</span>`;
    return;
  }
  el.innerHTML = `<span class="hecho">${estado.indice} de ${estado.lista.length} hechos.</span> ` +
    `Siguiente (${estado.indice + 1}): `;
  el.appendChild(document.createTextNode(estado.actual));
}
pintarTanda();

// Resumen del último fallo a la vista, para poder mandarlo en una captura
async function pintarDiagVisible(id) {
  const { ultimoFallo, ultimoRelleno } = await chrome.storage.local.get(['ultimoFallo', 'ultimoRelleno']);
  const lineas = [`SnaveCloud ${chrome.runtime.getManifest().version} · DIAGNÓSTICO`];
  if (ultimoRelleno && ultimoRelleno.flecha) {
    const f = ultimoRelleno.flecha;
    lineas.push(`Flecha elegida: "${f.nombre || f.texto || f.tag}" (${f.puntos} pts${f.desactivado ? ', DESACTIVADA' : ''})`);
    lineas.push('Botones: ' + (ultimoRelleno.botones || []).map((b) => `${b.nombre || b.texto || b.tag}=${b.puntos}`).join(' · '));
  }
  if (ultimoFallo) {
    lineas.push('Error: ' + ultimoFallo.error);
    let d = ultimoFallo.dom || '';
    try {
      const o = JSON.parse(d);
      d = `flecha activa: ${o.flechaActiva} · prompt en la caja: ${o.tienePrompt}\n` +
        `generación de arriba cambió: ${o.arribaCambio} (${o.arriba || '-'}) · con el prompt: ${o.galeria}\n` +
        `avisos de Flow: ${(o.avisos || []).join(' | ') || 'ninguno'}\n` +
        `peticiones tras el clic:\n  ${(o.peticiones || []).join('\n  ') || 'ninguna'}`;
    } catch (e) { /* era la lista de controles, se enseña tal cual */ }
    if (d) lineas.push(d.slice(0, 1500));
  }
  $(id).textContent = lineas.join('\n');
  $(id).hidden = lineas.length < 2;
}

/* ---------- Vista del panel SnaveCloud ---------- */

function statusPanel(text, kind) {
  $('statusPanel').textContent = text;
  $('statusPanel').className = kind || '';
}

async function mostrarVista(vista) {
  $('vistaPanel').hidden = vista !== 'panel';
  $('vistaManual').hidden = vista === 'panel';
  await chrome.storage.local.set({ vista });
  if (vista === 'panel') pintarPanel();
}
$('aManual').addEventListener('click', () => mostrarVista('manual'));
$('aPanel').addEventListener('click', () => mostrarVista('panel'));
$('version').textContent = chrome.runtime.getManifest().version;

// Estado de la cola (lo que hace ahora) sin preguntar al panel
async function pintarActividad() {
  const { cola } = await chrome.storage.local.get('cola');
  const activa = !!(cola && cola.activa && cola.origen === 'panel');
  $('auto').checked = activa;
  $('actividad').textContent = activa
    ? cola.mensaje || ''
    : (cola && cola.origen === 'panel' && cola.mensaje) || 'En pausa. Activa "Procesar automáticamente" para empezar.';
  // Si la cola se paró por un fallo, el diagnóstico queda a la vista
  if (!activa && cola && cola.origen === 'panel' && /^Cola parada/.test(cola.mensaje || '')) pintarDiagVisible('diagVistaPanel');
  else $('diagVistaPanel').hidden = true;
  return activa;
}

// Contadores del panel y color del estado.
// "Conectado" de verdad = el panel responde OK al token. Mientras NO conecte, se
// muestra la pantalla para COPIAR el token (y pegarlo en el panel).
async function pintarPanel() {
  const { panel_url } = await chrome.storage.local.get(['panel_url']);
  mostrarMiToken();                       // siempre deja el token a la vista para copiar
  if ($('panel_url')) $('panel_url').value = panel_url || '';

  const r = await chrome.runtime.sendMessage({ tipo: 'panelPing' });
  const conectado = !!(r && r.ok);
  $('conectar').hidden = conectado;        // pantalla "copia tu token" cuando NO conecta
  $('conectado').hidden = !conectado;
  if (!conectado) {
    $('puntoMarca').className = 'punto rojo';
    const msg = $('activarMsg');
    if (msg && r && r.error) { msg.textContent = r.error + ' — copia el token y pégalo en el panel ("Crear con Flow").'; msg.style.color = '#f28b82'; }
    return;
  }
  const activa = await pintarActividad();
  const color = activa ? 'verde' : 'amarillo';
  $('punto').className = $('puntoMarca').className = 'punto ' + color;
  $('estadoTexto').textContent = (activa ? 'Procesando · ' : 'En pausa · ') + new URL((await configPanel()).base).host;
  $('nPendientes').textContent = r.pendientes;
  $('nHechos').textContent = r.hechos_hoy;
  $('nCurso').textContent = r.procesando;
  $('nErrores').textContent = r.errores_hoy;
  $('eta').hidden = !r.pendientes;
  $('eta').textContent = `~${r.pendientes * 2} min`; // un video de Veo tarda un par de minutos
  const total = r.hechos_hoy + r.procesando + r.pendientes;
  $('barra').style.width = total ? `${Math.round((100 * r.hechos_hoy) / total)}%` : '0';
  await pintarReferencias();
}

// Muestra las imágenes de referencia del último pedido (si las hubo). Solo vista
// previa; los archivos están en la PC (Descargas/snave-referencias).
async function pintarReferencias() {
  const card = $('refsCard');
  if (!card) return;
  const { ultimasReferencias } = await chrome.storage.local.get('ultimasReferencias');
  const imgs = (ultimasReferencias && ultimasReferencias.imagenes) || [];
  if (!imgs.length) { card.hidden = true; return; }
  card.hidden = false;
  if ($('refsPedido')) $('refsPedido').textContent = ultimasReferencias.pedido ? ('#' + ultimasReferencias.pedido) : '';
  const lista = $('refsLista');
  lista.innerHTML = '';
  const ROL = { inicio: 'Inicio', fin: 'Fin', referencia: 'Referencia' };
  imgs.forEach((im) => {
    const d = document.createElement('div');
    d.className = 'ref-item';
    const img = document.createElement('img');
    img.src = im.url; img.alt = ''; img.title = im.nombre || '';
    const et = document.createElement('span');
    et.className = 'ref-rol';
    et.textContent = ROL[im.rol] || im.rol || '';
    d.appendChild(img); d.appendChild(et);
    lista.appendChild(d);
  });
}

// Muestra el token propio de la extension en el campo (para copiar) y lo deja
// tambien en el campo manual, por si pruebas con otra URL (p. ej. snavecloud.test)
async function mostrarMiToken() {
  const { mi_token, panel_token } = await chrome.storage.local.get(['mi_token', 'panel_token']);
  if ($('miToken')) $('miToken').value = mi_token || '(generando…)';   // el token PROPIO (para copiar)
  // El campo manual muestra el token que se USA ahora (puede ser el propio o uno
  // compartido que pegaste en el Perfil 2). Asi ves y editas el que conecta.
  if ($('panel_token')) $('panel_token').value = panel_token || mi_token || '';
}

$('copiarToken').addEventListener('click', async () => {
  const t = $('miToken').value;
  const msg = $('activarMsg');
  try { await navigator.clipboard.writeText(t); } catch (e) { $('miToken').select(); document.execCommand('copy'); }
  msg.textContent = '✓ Token copiado. Pégalo en tu panel SnaveCloud y pulsa "Comprobar".';
  msg.style.color = '#81c995';
});

$('probar').addEventListener('click', async () => {
  const msg = $('activarMsg');
  $('probar').disabled = true;
  msg.style.color = '#bbb';
  msg.textContent = 'Comprobando…';
  const r = await chrome.runtime.sendMessage({ tipo: 'panelPing' });
  $('probar').disabled = false;
  if (r && r.ok) {
    msg.textContent = '✓ ¡Conectado! Tu token ya está activo en el panel.';
    msg.style.color = '#81c995';
    pintarPanel();
  } else {
    msg.textContent = 'Aún no conecta: ' + ((r && r.error) || '') + ' — ¿ya pegaste el token en el panel?';
    msg.style.color = '#f28b82';
  }
});

$('panelConectar').addEventListener('click', async () => {
  const panel_url = $('panel_url').value.trim();
  const panel_token = $('panel_token').value.trim();
  if (!panel_url || !panel_token) return statusPanel('Pega la URL del panel y el token.', 'err');
  await chrome.storage.local.set({ panel_url, panel_token });
  const r = await chrome.runtime.sendMessage({ tipo: 'panelPing' });
  if (!r.ok) {
    await chrome.storage.local.remove('panel_token');
    return statusPanel('No conecta: ' + r.error, 'err');
  }
  $('panel_token').value = '';
  statusPanel('Conectado.', 'ok');
  pintarPanel();
});

$('auto').addEventListener('change', async () => {
  statusPanel('');
  if (!$('auto').checked) {
    await chrome.runtime.sendMessage({ tipo: 'detenerCola' });
    return pintarPanel();
  }
  const r = await chrome.runtime.sendMessage({ tipo: 'iniciarCola', origen: 'panel' });
  if (!r.ok) {
    $('auto').checked = false;
    return statusPanel(r.error, 'err');
  }
  statusPanel('Deja abierta la pestaña de Flow y no canceles la barra de depuración de Chrome.', 'ok');
  pintarPanel();
});

$('abrirPanel').addEventListener('click', async () => {
  chrome.tabs.create({ url: (await configPanel()).base + 'flow-cola' });
});

if ($('abrirDescargas')) $('abrirDescargas').addEventListener('click', () => {
  try { chrome.downloads.showDefaultFolder(); } catch (e) { setStatus('No pude abrir la carpeta: ' + e.message, 'err'); }
});

$('desconectar').addEventListener('click', async () => {
  if (!confirm('¿Desconectar del panel? Tendrás que pegar el token otra vez.')) return;
  const { cola } = await chrome.storage.local.get('cola');
  if (cola && cola.activa && cola.origen === 'panel') await chrome.runtime.sendMessage({ tipo: 'detenerCola' });
  await chrome.storage.local.remove('panel_token');
  statusPanel('');
  pintarPanel();
});

// La extension trae su conexion (URL + token) en conexion.json: si este perfil
// aun no tiene token, se toma de ahi y queda conectada sola.
async function seedConexion() {
  const g = await chrome.storage.local.get(['panel_url', 'panel_token', 'mi_token']);
  const patch = {};
  // Token propio de la extension (48 hex), si aun no existe
  let tok = g.mi_token;
  if (!tok || !/^[a-f0-9]{48}$/.test(tok)) {
    const b = crypto.getRandomValues(new Uint8Array(24));
    tok = [...b].map((x) => x.toString(16).padStart(2, '0')).join('');
    patch.mi_token = tok;
  }
  // Solo pone el token propio si AUN no hay ninguno. Asi, si pegaste un token
  // compartido (Opcion A: mismo token en dos perfiles), NO se pisa.
  if (!g.panel_token) patch.panel_token = tok;
  if (!g.panel_url) {
    try {
      const c = await (await fetch(chrome.runtime.getURL('conexion.json'), { cache: 'no-store' })).json();
      const url = String((c && (c.panel_url || c.url)) || '').trim();
      if (url) patch.panel_url = url;
    } catch (e) {}
  }
  await chrome.storage.local.set(patch);
}

seedConexion().then(() => {
  mostrarMiToken();
  chrome.storage.local.get('vista', ({ vista }) => mostrarVista(vista || 'panel'));
});
setInterval(() => { if (!$('vistaPanel').hidden) pintarPanel(); }, 5000);

// Estado de la cola automática, que corre en el service worker
async function pintarCola() {
  const { cola } = await chrome.storage.local.get('cola');
  const activa = !!(cola && cola.activa);
  $('cola').textContent = activa ? 'Detener cola' : 'Cola automática';
  $('cola').classList.toggle('parar', activa);
  $('fill').disabled = activa;
  const el = $('colaEstado');
  el.textContent = (cola && cola.mensaje) || '';
  const errores = (cola && cola.errores) || [];
  el.title = errores.map((e) => `${e.motivo}: ${e.prompt}`).join('\n');
  if (errores.length) el.textContent += ` (${errores.length} con problemas; pasa el ratón para verlos)`;
}
pintarCola();

// La cola y la IA cambian el storage desde el service worker
chrome.storage.onChanged.addListener((changes) => {
  if (changes.cola) {
    pintarCola();
    if (!$('vistaPanel').hidden) pintarActividad();
  }
  if (changes.prompt && document.activeElement !== $('prompt')) $('prompt').value = changes.prompt.newValue || '';
  if (changes.prompt || changes.tanda) pintarTanda();
  if (changes.ultimasReferencias && !$('vistaPanel').hidden) pintarReferencias();
});

$('reset').addEventListener('click', async () => {
  await reiniciarTanda();
  pintarTanda();
  setStatus('Tanda reiniciada: el siguiente es el primero de la lista.', 'ok');
});

$('fill').addEventListener('click', async () => {
  const opts = Object.fromEntries(AJUSTES.map((f) => [f, $(f).value]));
  await chrome.storage.local.set({ ...opts, prompt: $('prompt').value });

  const estado = await siguientePrompt();
  if (!estado.lista.length) return setStatus('Escribe un prompt.', 'err');
  if (!estado.actual) {
    return setStatus(`Ya se usaron los ${estado.lista.length} prompt(s) de la lista. Escribe prompts nuevos o pulsa "Empezar la tanda de nuevo" para repetirlos.`);
  }
  opts.prompt = componerPrompt(estado.actual, opts);

  const tab = await pestanaFlow();
  if (!tab) return setStatus('Abre un proyecto de Google Flow en esta pestaña.', 'err');

  $('fill').disabled = true;
  setStatus('Rellenando…');
  try {
    const antes = (await enPagina(tab.id, mediosDeLaPagina, opts.mode)).map((m) => m.clave);
    const result = await enPagina(tab.id, fillFlow, opts);
    if (!result.ok) {
      await chrome.storage.local.set({ ultimoFallo: { error: result.error, dom: result.dom || '', cuando: new Date().toISOString() } });
      pintarDiagVisible('diagVistaManual');
      return setStatus(result.error, 'err');
    }
    await marcarHecho(estado);
    await chrome.storage.local.set({ ultimo: { prompt: opts.prompt, antes, tab: tab.id } });
    if (!result.envio) return setStatus(`Relleno, pero no encontré la flecha: púlsala tú. ${result.note}`, 'err');
    // La flecha la pulsa el service worker con un clic real (chrome.debugger):
    // sigue aunque este popup se cierre cuando Chrome enseña su barra
    await chrome.storage.local.set({ ultimoRelleno: { flecha: result.flecha, botones: result.botonesCompositor } });
    setStatus('Pulsando la flecha de crear…');
    const r = await chrome.runtime.sendMessage({
      tipo: 'enviarAhora', tabId: tab.id, envio: result.envio, antes: result.antes, prompt: opts.prompt,
    });
    setStatus(r.ok ? `Enviado (${r.via}): Flow está generando. ${result.note}` : r.error, r.ok ? 'ok' : 'err');
    if (r.ok) $('diagVistaManual').hidden = true;
    else pintarDiagVisible('diagVistaManual');
  } catch (e) {
    setStatus('Error: ' + e.message, 'err');
  } finally {
    $('fill').disabled = false;
  }
});

$('cola').addEventListener('click', async () => {
  const { cola } = await chrome.storage.local.get('cola');
  if (cola && cola.activa) {
    await chrome.runtime.sendMessage({ tipo: 'detenerCola' });
    return setStatus('Cola detenida. Lo que ya se envió sigue generándose en Flow.', 'ok');
  }
  const tab = await pestanaFlow();
  if (!tab) return setStatus('Abre un proyecto de Google Flow en esta pestaña.', 'err');
  const estado = await siguientePrompt();
  if (!estado.lista.length) return setStatus('Escribe al menos un prompt.', 'err');
  if (!estado.actual) return setStatus('No quedan prompts. Pulsa "Empezar la tanda de nuevo" o cambia la lista.', 'err');

  await chrome.runtime.sendMessage({ tipo: 'iniciarCola', tabId: tab.id, origen: 'tanda' });
  setStatus('Cola en marcha. Puedes cerrar este popup; no cierres la pestaña de Flow ni la barra de depuración.', 'ok');
});

$('generar').addEventListener('click', async () => {
  const datos = {
    proveedor: $('proveedor').value,
    clave: $('ia_clave').value.trim(),
    modelo: $('ia_modelo').value.trim(),
    guion: $('guion').value.trim(),
    escenas: Number($('escenas').value),
    personaje: $('personaje').value.trim(),
  };
  if (!datos.guion) return setStatus('Pega un guion primero.', 'err');
  if (!datos.clave) return setStatus('Falta la API key de la IA.', 'err');
  if (promptsDe($('prompt').value).length && !confirm('Esto reemplaza la lista de prompts actual. ¿Seguir?')) return;

  $('generar').disabled = true;
  setStatus('Pidiendo las escenas a la IA…');
  try {
    // El service worker guarda el resultado en la lista aunque cierres el popup
    const r = await chrome.runtime.sendMessage({ tipo: 'guion', datos });
    if (!r.ok) return setStatus('IA: ' + r.error, 'err');
    setStatus(`${r.n} escenas en la lista. Revísalas y pulsa Rellenar o Cola automática.`, 'ok');
  } finally {
    $('generar').disabled = false;
  }
});

// Lo generado que hay en la página, del tipo elegido arriba
async function mediosDePestana() {
  const tab = await pestanaFlow();
  if (!tab) throw new Error('Abre un proyecto de Google Flow en esta pestaña.');
  const medios = await enPagina(tab.id, mediosDeLaPagina, $('mode').value);
  if (!medios.length) throw new Error('No veo nada generado. Baja con el scroll para que cargue.');
  return medios;
}

// Flow no muestra el prompt en la tarjeta sino un título que resume él
// ("un perro jugando con una niña" -> "Perro jugando con niña"), a veces
// recortado. Vale si uno empieza como el otro, o si todas las palabras con
// contenido del título están en el prompt.
const normPrompt = (s) => (s || '').toLowerCase().replace(/(\.\.\.|…)\s*$/, '').replace(/\s+/g, ' ').trim();
const VACIAS = new Set(['un', 'una', 'unos', 'unas', 'el', 'la', 'los', 'las', 'de', 'del', 'con', 'en', 'y',
  'a', 'al', 'por', 'para', 'the', 'an', 'of', 'with', 'in', 'on', 'and', 'to', 'at']);
const palabras = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '')
  .split(/[^a-z0-9ñ]+/).filter((w) => w.length > 1 && !VACIAS.has(w));

function mismoPrompt(buscado, titulo) {
  if (!titulo) return false;
  if (buscado.startsWith(titulo) || titulo.startsWith(buscado)) return true;
  const delTitulo = palabras(titulo);
  const delPrompt = new Set(palabras(buscado));
  return delTitulo.length >= 2 && delTitulo.every((w) => delPrompt.has(w));
}

// Los enlaces llevan firma y caducan (el "Expires" de la dirección): sirven
// para descargar ya, no para guardarlos y usarlos dentro de días.
$('links').addEventListener('click', async () => {
  try {
    const tab = await pestanaFlow();
    if (!tab) throw new Error('Abre un proyecto de Google Flow en esta pestaña.');
    const mode = $('mode').value;
    const escrito = promptsDe($('prompt').value).length > 0;
    // Con la caja escrita: SOLO el prompt pedido. fill.js filtra por el título del
    // tile (como la cola), no por "lo que apareció después" (poco fiable). Con la
    // caja vacía: todo lo que haya en la página.
    let buscado = '';
    if (escrito) {
      const { ultimo } = await chrome.storage.local.get('ultimo');
      buscado = normPrompt(ultimo ? ultimo.prompt : promptsDe($('prompt').value)[0]);
      // Despertar los videos de ESE prompt para que Flow cargue su URL buena
      // (flow-content/video), no el enlace asb temporal.
      if (mode !== 'imagen') await enPagina(tab.id, despertarVideos, buscado, '').catch(() => {});
    }
    let medios = await enPagina(tab.id, mediosDeLaPagina, mode, false, buscado, '');
    if (!medios.length) {
      return setStatus(escrito
        ? `No veo "${buscado.slice(0, 40)}" generado todavía. Si aún se está generando, espera a que termine; o baja con el scroll.`
        : 'No veo nada generado. Baja con el scroll para que cargue.', 'err');
    }
    // Un video sin URL = todavía generándose o cargando su enlace: no se copia
    const generando = medios.filter((m) => m.tipo === 'video' && !m.src).length;
    medios = medios.filter((m) => m.src);
    if (!medios.length) {
      return setStatus('El video aún se está generando o cargando su enlace. Espera unos segundos y vuelve a pulsar.', 'err');
    }
    await navigator.clipboard.writeText(medios.map((m) => m.src).join('\n'));
    setStatus(`${medios.length} enlace(s) copiados${escrito ? ' (solo tu prompt)' : ''}.`
      + (generando ? ` ${generando} aún generándose, espera.` : '') + ' Caducan en unas horas.', 'ok');
  } catch (e) {
    setStatus(e.message, 'err');
  }
});

$('download').addEventListener('click', async () => {
  if (!chrome.downloads) {
    return setStatus('Falta recargar la extensión en chrome://extensions (↻) para activar las descargas.', 'err');
  }
  $('download').disabled = true;
  try {
    let medios = await mediosDePestana();

    // Con la caja escrita, solo lo del último prompt enviado (en una tanda, el
    // que se acaba de rellenar). Con la caja vacía, todo.
    if (promptsDe($('prompt').value).length) {
      const { ultimo } = await chrome.storage.local.get('ultimo');
      const buscado = normPrompt(ultimo ? ultimo.prompt : promptsDe($('prompt').value)[0]);
      const nuevos = ultimo ? medios.filter((m) => !ultimo.antes.includes(m.clave)) : [];
      // Lo fiable: lo que apareció después de rellenar ese prompt. Si no hay
      // apunte (se escribió a mano en Flow), se compara el título.
      medios = nuevos.length ? nuevos : medios.filter((m) => mismoPrompt(buscado, normPrompt(m.prompt)));
      if (!medios.length) {
        return setStatus(`No veo nada de "${buscado.slice(0, 40)}". Baja con el scroll, o vacía la caja para bajarlo todo.`, 'err');
      }
    }

    const carpeta = descargarMedios(medios, (m, i) => `${String(i + 1).padStart(2, '0')} ${m.prompt}`);
    setStatus(`Descargando ${medios.length} archivo(s) en Descargas/${carpeta}.`, 'ok');
  } catch (e) {
    setStatus(e.message, 'err');
  } finally {
    $('download').disabled = false;
  }
});

// Lista de los controles de Flow, y el último fallo con lo que se veía en ese
// momento, para pegarlo al pedir un arreglo
async function copiarDiagnostico(avisar) {
  const tab = await pestanaFlow();
  if (!tab) return avisar('Abre un proyecto de Google Flow en esta pestaña.', 'err');
  try {
    const d = await enPagina(tab.id, diagnosticoFlow);
    d.videos = await enPagina(tab.id, reporteVideos).catch(() => null);   // qué ve de los videos
    const { ultimoFallo, ultimoRelleno, cola, ultimoFallo1080, ultimaEntrega, ultimoAdjuntar } = await chrome.storage.local.get(['ultimoFallo', 'ultimoRelleno', 'cola', 'ultimoFallo1080', 'ultimaEntrega', 'ultimoAdjuntar']);
    d.ultimaEntrega = ultimaEntrega || null;   // qué pasó al subir el video al panel
    d.ultimoAdjuntar = ultimoAdjuntar || null; // qué pasó al subir imágenes de entrada (inicio/fin/referencia) a Flow
    d.ultimoFallo = ultimoFallo || null;
    d.ultimoFallo1080 = ultimoFallo1080 || null; // por qué un video no salió en 1080p
    d.ultimoRelleno = ultimoRelleno || null; // qué botón se eligió como flecha y cuáles había
    d.cola = cola ? { mensaje: cola.mensaje, errores: cola.errores } : null;
    d.version = chrome.runtime.getManifest().version;
    const texto = JSON.stringify(d, null, 1);
    try {
      await navigator.clipboard.writeText(texto);
    } catch (e) {
      // El popup perdió el foco (pasa al mirar Flow): copia a la antigua
      const t = document.createElement('textarea');
      t.value = texto;
      document.body.appendChild(t);
      t.select();
      document.execCommand('copy');
      t.remove();
    }
    avisar('Diagnóstico copiado. Pégalo en el chat.', 'ok');
  } catch (e) {
    avisar('Error: ' + e.message, 'err');
  }
}
$('diagnostico').addEventListener('click', () => copiarDiagnostico(setStatus));
$('diagPanel').addEventListener('click', () => copiarDiagnostico(statusPanel));

// Mostrar el atajo real, por si se cambió en chrome://extensions/shortcuts.
// Va al final y protegido: si la extensión no se recargó tras actualizarla,
// chrome.commands no existe, y eso no debe dejar el botón sin funcionar.
if (chrome.commands) {
  chrome.commands.getAll((cmds) => {
    const c = (cmds || []).find((x) => x.name === 'rellenar');
    $('shortcut').textContent = (c && c.shortcut) || 'sin asignar (ponlo en chrome://extensions/shortcuts)';
  });
}
