// Funciones que corren dentro de la página de Flow: fillFlow (rellenar),
// mediosDeLaPagina (qué se ha generado) y estadoEnvio (si se envió). Las usan
// el popup, el atajo de teclado y la cola (cola.js).
// Se ejecutan con chrome.scripting.executeScript, así que cada una tiene que
// ser autosuficiente: nada de variables de fuera. Busca los controles
// por su texto visible, porque las clases de Flow cambian con cada version.

async function fillFlow(opts) {
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const visible = (el) => !!el && el.getClientRects().length > 0;
  const imagen = opts.mode === 'imagen';

  async function waitFor(find, ms = 3000, cada = 120) {
    const end = Date.now() + ms;
    while (Date.now() < end) {
      const v = find();
      if (v) return v;
      await sleep(cada);
    }
    return null;
  }

  // Texto propio de un control, sin iconos (Flow mete ligaduras como
  // "videocam" o "crop_16_9" dentro del botón), sin acentos y en minúsculas.
  // Con un filtro de iconos incompleto, "Video" se leía "videocam video".
  const ICONOS = '.google-symbols, .material-symbols-outlined, .material-symbols-rounded, .material-icons, mat-icon, svg, [aria-hidden="true"]';
  const ownText = (el) => {
    let t = String((el && el.textContent) || '');
    for (const ic of (el && el.querySelectorAll ? el.querySelectorAll(ICONOS) : [])) {
      const it = ic.textContent || '';
      if (it) t = t.replace(it, ' ');
    }
    return t.replace(/\s+/g, ' ').trim().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  };
  const area = (el) => { const r = el.getBoundingClientRect(); return r.width * r.height; };

  const CONTROLES = "button, [role='button'], [role='radio'], [role='tab'], [role='option'], [role='menuitem'], [role='menuitemradio'], [role='combobox'], label, [tabindex], mat-button-toggle";
  const CLICABLE = "button, [role='button'], [role='radio'], [role='tab'], [role='option'], [role='menuitem'], [role='menuitemradio'], mat-button-toggle, [tabindex], li";
  const controles = () => [...document.querySelectorAll(CONTROLES)].filter(visible);
  const clicableDe = (el) => (el && el.closest(CLICABLE)) || el;
  // El envoltorio mat-button-toggle no responde al clic; su botón interno sí
  const botonInterno = (el) =>
    el.matches("button, [role='radio']") ? el : el.querySelector("button, [role='radio']") || el.closest("button, [role='radio']") || clicableDe(el);

  // El control más interno y pequeño cuyo texto propio encaja EXACTO con re.
  // Nada de dentro de "excluir" (el chip): sus trozos "Video" o "x1" no son opciones.
  const findExact = (re, excluir) => {
    const fuera = (el) => !(excluir && excluir.contains(el));
    const todos = controles().filter((el) => fuera(el) && re.test(ownText(el)));
    const internos = todos.filter((el) => !todos.some((o) => o !== el && el.contains(o)));
    const hit = (internos.length ? internos : todos).sort((a, b) => area(a) - area(b))[0];
    if (hit) return hit;
    return [...document.body.querySelectorAll('div, span, p, label, li, a')]
      .filter((el) => fuera(el) && (el.textContent || '').trim().length <= 14 && re.test(ownText(el)) && visible(el))
      .sort((a, b) => area(a) - area(b))[0] || null;
  };

  // ¿Está marcada esta opción? true / false / null si Flow no lo dice
  const marcado = (el) => {
    const cadena = [el, el.parentElement, el.closest("button, [role='radio'], [role='tab'], [role='option'], mat-button-toggle")].filter(Boolean);
    for (const c of cadena) {
      const attrs = ['aria-selected', 'aria-checked', 'aria-pressed', 'data-state', 'data-selected', 'data-active']
        .map((a) => String(c.getAttribute(a) || '').toLowerCase());
      if (attrs.some((v) => ['true', 'on', 'selected', 'checked', 'active'].includes(v))) return true;
      if (attrs.some((v) => ['false', 'off', 'unchecked'].includes(v))) return false;
      if (/(^|[\s_-])(selected|active|checked|is-active|mat-button-toggle-checked)([\s_-]|$)/i.test(String(c.className || ''))) return true;
    }
    return null;
  };

  // Clic completo (puntero + ratón), como el de una persona
  const press = (el) => {
    for (const type of ['pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click']) {
      const Ev = type.startsWith('pointer') ? PointerEvent : MouseEvent;
      el.dispatchEvent(new Ev(type, { bubbles: true, cancelable: true, view: window }));
    }
  };
  // Como La Casa Dark: puntero, luego .click(), luego teclado, hasta que se confirme
  const pulsar = async (el, confirmar, ms = 1500) => {
    const b = botonInterno(el);
    for (let i = 0; i < 3; i++) {
      if (i === 0) press(b);
      else if (i === 1) b.click();
      else {
        b.focus();
        for (const k of ['keydown', 'keyup']) b.dispatchEvent(new KeyboardEvent(k, { key: 'Enter', code: 'Enter', keyCode: 13, bubbles: true, cancelable: true }));
      }
      if (await waitFor(confirmar, ms)) return true;
    }
    return false;
  };

  // Lista corta de los controles visibles, para saber qué había cuando algo falla
  const dom = () => controles().slice(0, 60).map((el) => {
    const t = ownText(el).slice(0, 24) || String(el.getAttribute('aria-label') || '').slice(0, 24);
    if (!t) return null;
    const dis = el.disabled || el.getAttribute('aria-disabled') === 'true';
    return `${el.getAttribute('role') || el.tagName.toLowerCase()}:"${t}"${marcado(el) === true ? '*' : ''}${dis ? '[dis]' : ''}`;
  }).filter(Boolean).join(' | ').slice(0, 1500);
  const fallo = (error) => {
    const d = dom();
    document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', code: 'Escape', keyCode: 27, bubbles: true }));
    return { ok: false, error, dom: d };
  };

  // 1. Chip de ajustes junto a la caja ("Video · 720p · 8 s · x1"). Primero
  // por su aria-label, luego por el texto, luego porque acaba en la cantidad.
  const findChip = () => {
    const todos = [...document.querySelectorAll("button, [role='button'], [role='combobox']")].filter(visible);
    return todos.find((el) => /(gatilho|activador|disparador|trigger|abrir|bot[oó]n|button).{0,25}(config|ajuste|setting)/i.test(el.getAttribute('aria-label') || ''))
      || todos.find((el) => /^(video|imagen|imagem|image)\s*·/.test(ownText(el)))
      || todos.filter((el) => { const t = ownText(el).replace(/\s+/g, ''); return /x[1-4]$/.test(t) && !/^x[1-4]$/.test(t) && t.length < 60; })
        .sort((a, b) => area(a) - area(b))[0]
      || null;
  };
  let chip = findChip();

  // El menú está abierto si se ven los dos interruptores Imagen y Video (o la cantidad)
  const par = () => {
    const c = findChip() || chip;
    return { img: findExact(/^(imagen|imagem|image)$/, c), vid: findExact(/^video$/, c) };
  };
  const menuAbierto = () => { const p = par(); return !!(p.img && p.vid) || !!findExact(/^x1$/, findChip() || chip); };
  const abrirMenu = async () => {
    if (menuAbierto()) return true;
    for (let i = 0; i < 3; i++) {
      chip = findChip() || chip;
      if (!chip) break;
      if (i === 0) chip.click(); else press(chip);
      if (await waitFor(menuAbierto, 2500)) return true;
      await sleep(250);
    }
    return menuAbierto();
  };
  // Escape no siempre lo cierra; el chip es un interruptor, así que se pulsa otra vez
  const cerrarMenu = async () => {
    if (!menuAbierto()) return;
    document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', code: 'Escape', keyCode: 27, bubbles: true }));
    await sleep(250);
    for (let i = 0; i < 2 && menuAbierto(); i++) {
      chip = findChip() || chip;
      if (!chip) break;
      if (i === 0) chip.click(); else press(chip);
      await waitFor(() => !menuAbierto(), 2000);
    }
  };

  if (!(await abrirMenu())) {
    return fallo(chip ? 'Flow no abrió el menú de ajustes.' : 'No encontré el botón de ajustes de Flow. ¿Estás dentro de un proyecto?');
  }

  // 2. Modelo: el desplegable del menú. En español "imagen" es también el
  // interruptor del modo, así que el modelo Imagen se reconoce por su número.
  // Normaliza: quita acentos, espacios, guiones, corchetes, PUNTOS, y unifica
  // "lower priority" = "low priority" (Flow escribe "Lower", el pedido "low").
  const q = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
    .replace(/[\s\-–—[\]()·.]+/g, '').replace(/lowerpriority/g, 'lowpriority');
  const quiero = q(opts.model);
  const esModelo = (texto) => {
    const t = q(texto);
    // Si el pedido NO es "lower priority", no vale la opción que SÍ lo es (no
    // confundir "Lite" con "Lite [Lower Priority]"), y al revés.
    return !!quiero && t.includes(quiero) && (quiero.includes('lowpriority') || !t.includes('lowpriority'));
  };
  const modeloCtl = () => {
    const c = findChip();
    const todos = controles().filter((el) => {
      if (c && (el === c || c.contains(el) || el.contains(c))) return false;
      const t = ownText(el);
      return /nano\s*banana|\bveo\b|omni|imagen\s*\d/.test(t) && t.length < 70 && !el.closest("[role='listbox'], [role='menu'], [role='option']");
    });
    return todos.find((el) => el.getAttribute('role') === 'combobox' || el.hasAttribute('aria-haspopup'))
      || todos.sort((a, b) => area(a) - area(b))[0] || null;
  };
  const textoModelo = () => { const c = modeloCtl(); return c ? ownText(c) : ''; };

  // 3. Modo Video / Imagen. Varias señales votan, como en La Casa Dark,
  // porque Flow no siempre marca el interruptor con aria-*.
  const estadoModo = () => {
    const s = { imagen: 0, video: 0 };
    const p = par();
    const si = p.img ? marcado(p.img) : null;
    const sv = p.vid ? marcado(p.vid) : null;
    if (si === true) s.imagen++;
    if (sv === true) s.video++;
    if (si === false && sv !== false) s.video++;
    if (sv === false && si !== false) s.imagen++;
    const c = findChip();
    const ct = c ? ownText(c) : '';
    if (/^(imagen|imagem|image)\b/.test(ct)) s.imagen++;
    if (/^video\b/.test(ct)) s.video++;
    const ml = textoModelo();
    if (/nano\s*banana|imagen\s*\d/.test(ml)) s.imagen++;
    if (/\bveo\b|omni/.test(ml)) s.video++;
    if (findExact(/^(4|6|8)\s*s$/, c)) s.video++;
    return s;
  };
  const modo = imagen ? 'imagen' : 'video';
  const otroModo = imagen ? 'video' : 'imagen';
  const modoOk = () => { const s = estadoModo(); return s[modo] > s[otroModo]; };
  if (!modoOk()) {
    const p = par();
    const objetivo = imagen ? p.img : p.vid;
    if (!objetivo) return fallo(`No encontré el interruptor "${imagen ? 'Imagen' : 'Video'}" en el menú de ajustes.`);
    if (!(await pulsar(objetivo, modoOk))) return fallo(`Flow no cambió a modo ${imagen ? 'Imagen' : 'Video'}.`);
    await sleep(300);
  }

  // Las opciones no siempre son menuitem (a veces son div sueltos): vale el
  // elemento más pequeño que lleve ese nombre y no sea el propio desplegable
  const buscarOpcion = (ctl) => {
    const c = findChip(); // el chip también enseña el nombre del modelo
    const ajeno = (el, x) => !(x && (el === x || x.contains(el) || el.contains(x)));
    return [...document.querySelectorAll("[role='option'], [role='menuitem'], [role='menuitemradio'], li, button, [role='button'], div, span")]
      .filter((el) => visible(el) && ajeno(el, ctl) && ajeno(el, c) && ownText(el).length < 70 && esModelo(ownText(el)))
      .sort((a, b) => area(a) - area(b))[0] || null;
  };
  if (quiero && !esModelo(textoModelo())) {
    let elegido = false;
    for (let i = 0; i < 2 && !elegido; i++) {
      await abrirMenu();
      const ctl = modeloCtl();
      if (!ctl) break;
      // Si la lista ya está abierta no se vuelve a pulsar: eso la cerraría
      let opcion = buscarOpcion(ctl);
      if (!opcion) {
        if (i === 0) ctl.click(); else press(ctl);
        opcion = await waitFor(() => buscarOpcion(ctl), 2000);
      }
      if (!opcion) continue;
      press(clicableDe(opcion));
      elegido = !!(await waitFor(() => esModelo(textoModelo()), 1500));
    }
    if (!elegido) return fallo(`No pude elegir el modelo "${opts.model}". ¿Tu cuenta lo tiene?`);
    await sleep(300);
  }

  // 4. Opciones del menú: cantidad (obligatoria), segundos, formato y resolución
  const skipped = [];
  const elegir = async (re, nombre, obligatorio) => {
    await abrirMenu();
    const el = findExact(re, findChip());
    if (!el) {
      if (obligatorio) return `No encontré la opción "${nombre}" en el menú de ajustes.`;
      skipped.push(nombre);
      return null;
    }
    if (marcado(el) !== true) {
      // Si Flow no dice si está marcada (null), un clic basta
      await pulsar(el, () => marcado(findExact(re, findChip()) || el) !== false, 1000);
    }
    return null;
  };
  const esc = (s) => String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const pasos = [[new RegExp(`^x${esc(opts.count)}$`), 'x' + opts.count, true]];
  if (!imagen && opts.dur) pasos.push([new RegExp(`^${esc(opts.dur)}\\s*s$`), opts.dur + 's', false]);
  if (opts.aspect) pasos.push([new RegExp('^' + esc(opts.aspect).replace(':', '\\s*:\\s*') + '$'), opts.aspect, false]);
  if (opts.res) pasos.push([new RegExp(`^${esc(opts.res.toLowerCase())}$`), opts.res, false]);
  for (const [re, nombre, obligatorio] of pasos) {
    const error = await elegir(re, nombre, obligatorio);
    if (error) return fallo(error);
    await sleep(150);
  }
  if (!modoOk()) return fallo(`Flow cambió el modo después de configurarlo (esperaba ${imagen ? 'Imagen' : 'Video'}).`);
  await cerrarMenu();

  // 5. Prompt. Se busca la caja DESPUÉS de configurar: cambiar modo o modelo
  // vuelve a dibujar el editor, y la caja de antes deja de existir.
  const PLACEHOLDER = /qu[eé] quieres crear|what do you want to create|o que voc[eê] quer/i;
  const EDITABLE = 'textarea, input:not([type]), input[type="text"], [contenteditable]:not([contenteditable="false"]), [role="textbox"]';
  const placeholderOf = (el) =>
    el.getAttribute('placeholder') || el.getAttribute('aria-label') || el.getAttribute('aria-placeholder') || el.dataset.placeholder || '';
  const findBox = () => {
    const c = findChip();
    const raiz = c && (c.closest('form') || (c.parentElement && c.parentElement.parentElement && c.parentElement.parentElement.parentElement));
    const dentro = raiz && [...raiz.querySelectorAll(EDITABLE)].find(visible);
    if (dentro) return dentro;
    const cajas = [...document.querySelectorAll(EDITABLE)].filter(visible);
    return [...document.querySelectorAll(`flow-base-prompt-box :is(${EDITABLE})`)].filter(visible)[0]
      || cajas.find((el) => PLACEHOLDER.test(placeholderOf(el) + ' ' + el.textContent))
      || cajas.find((el) => el.matches('[data-slate-editor="true"], .ProseMirror'))
      || null;
  };

  // 5b. Imágenes de entrada (inicio/fin/referencia): subirlas a Flow ANTES del
  // prompt. Cada una llega como data URL (opts.entradas = [{rol,dataUrl,nombre}]).
  // Se inyecta el archivo en el input[type=file] de Flow SIN abrir el diálogo del
  // sistema (DataTransfer), y se elige su rol si Flow lo pide. Devuelve un
  // diagnóstico por imagen para "Copiar diagnóstico" si algo no encaja.
  const compositorDe = (box) => (box && box.closest && box.closest('flow-base-prompt-box')) || document.querySelector('flow-base-prompt-box') || box;
  // Cuenta las imágenes ya adjuntas (ingredientes) en el compositor
  const nAdjuntos = (comp) => comp ? comp.querySelectorAll('img, canvas, flow-media-chip, [class*="ingredient"], [class*="chip"], [class*="thumbnail"], [class*="attachment"]').length : 0;
  // MODO SEGURO: la extensión NO sube ni arrastra imágenes a Flow (eso disparaba
  // el anti-bot "actividad inusual" y arriesgaba la cuenta). Las imágenes de
  // referencia/inicio/fin las adjunta la PERSONA a mano en el proyecto de Flow;
  // aquí solo se COMPRUEBA que ya haya alguna adjunta antes de generar.
  async function adjuntarImagen(ent, box) {
    const rol = (ent && ent.rol) || 'referencia';
    const hayAntibot = /actividad inusual|unusual activity/i.test(document.body.innerText || '');
    const comp = compositorDe(box);
    const yaAdjuntas = nAdjuntos(comp);
    return { rol, modo: 'manual', yaAdjuntas, antibot: hayAntibot,
      nota: 'La extensión no adjunta imágenes (modo seguro). Adjunta tú la imagen en Flow; la extensión solo pone el prompt y genera.' };
  }

  let box = await waitFor(findBox, 3000);
  if (!box && document.activeElement && document.activeElement.matches(EDITABLE)) box = document.activeElement;
  if (!box) return fallo('Ajustes aplicados, pero no encontré la caja del prompt.');

  // Marcarla para que estadoEnvio() la encuentre después sin repetir la búsqueda
  document.querySelectorAll('[data-flow-helper="caja"]').forEach((el) => el.removeAttribute('data-flow-helper'));
  box.setAttribute('data-flow-helper', 'caja');

  const texto = String(opts.prompt || '').trim();
  if (box.isContentEditable || !['TEXTAREA', 'INPUT'].includes(box.tagName)) {
    // El editor (ProseMirror) solo se entera del texto por una edición del navegador
    box.click();
    box.focus();
    document.execCommand('selectAll', false, null);
    const escrito = document.execCommand('insertText', false, texto);
    if (!escrito || !String(box.textContent || '').includes(texto.slice(0, 24))) {
      box.innerHTML = '<p>' + texto.replace(/[&<>"]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch])) + '</p>';
      box.dispatchEvent(new InputEvent('beforeinput', { bubbles: true, cancelable: true, inputType: 'insertText', data: texto }));
    }
  } else {
    // Usar el setter nativo para que Angular detecte el cambio
    const proto = box.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
    box.focus();
    Object.getOwnPropertyDescriptor(proto, 'value').set.call(box, texto);
  }
  box.dispatchEvent(new Event('input', { bubbles: true }));
  box.dispatchEvent(new Event('change', { bubbles: true }));
  await sleep(250);
  const enCaja = String(box.value ?? box.textContent ?? '');
  if (!enCaja.includes(texto.slice(0, 24))) return fallo('No pude escribir el prompt en la caja de Flow.');
  const note = skipped.length ? `(este modelo no tiene: ${skipped.join(', ')})` : '';

  // Imágenes de entrada (inicio/fin/referencia): ya con el prompt escrito, se
  // suben a Flow. Se hace aquí (y no antes) para no estorbar a la caja del prompt.
  const adjuntos = [];
  for (const ent of (opts.entradas || [])) {
    adjuntos.push(await adjuntarImagen(ent, box).catch((e) => ({ rol: ent && ent.rol, ok: false, error: e.message })));
  }
  // Si se adjuntó algo, confirmar que el prompt sigue en la caja (por si el menú
  // del "+" la tocó); si se vació, re-escribirlo
  if (adjuntos.length && box.isConnected) {
    const sigue = String(box.value ?? box.textContent ?? '');
    if (!sigue.includes(texto.slice(0, 24))) {
      box.focus();
      try { document.execCommand('selectAll', false, null); document.execCommand('insertText', false, texto); } catch (e) {}
      box.dispatchEvent(new Event('input', { bubbles: true }));
      await sleep(200);
    }
  }

  // 6. La flecha de crear. En el Flow actual es solo un icono; su nombre
  // ("Iniciar generación") va en texto oculto o en aria-label. La cola la
  // pulsa con chrome.debugger usando "envio"; a mano, queda con el foco para Enter.
  const tarjetas = document.querySelectorAll('flow-batch-info, flow-tile-container, flow-video-tile, flow-image-tile').length;
  // Cuántas generaciones de la galería llevan ya este prompt (flow-expandable-prompt):
  // si después hay una más, el envío salió. Flow no vacía la caja al enviar.
  const huellaG = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '');
  const galeria = [...document.querySelectorAll('flow-expandable-prompt')]
    .filter((el) => huellaG(el.textContent).includes(huellaG(texto).slice(0, 40))).length;
  // Firma de la generación de ARRIBA del todo: las nuevas salen ahí. Contar
  // tarjetas no sirve porque Flow solo dibuja las que caben en pantalla.
  const bloqueArriba = (document.querySelector('flow-batch-info') || {}).parentElement;
  const primerLote = bloqueArriba
    ? (bloqueArriba.textContent || '').replace(/\s+/g, ' ').slice(0, 200) + '|' +
      [...bloqueArriba.querySelectorAll('img, video')].map((m) => String(m.currentSrc || m.src || '').split('?')[0]).join(',')
    : '';
  // Se puntúan los botones del compositor en vez de coger el primero que
  // encaje: el "+" (adjuntar) va antes que la flecha y su nombre también puede
  // decir "crear". La flecha es el botón redondo de la derecha del todo.
  const chipAhora = findChip();
  let raiz = box.closest('form');
  for (let n = box.parentElement, i = 0; !raiz && n && i < 8; n = n.parentElement, i++) {
    if (chipAhora && n.contains(chipAhora)) raiz = n;
  }
  raiz = raiz || box.parentElement;
  const nombreDe = (el) => (ownText(el) + ' ' + String(el.getAttribute('aria-label') || '') + ' ' + String(el.getAttribute('title') || '')).toLowerCase()
    .normalize('NFD').replace(/[̀-ͯ]/g, '');
  const candidatos = [...raiz.querySelectorAll("button, [role='button']")].filter((el) => visible(el) && el !== chipAhora && !(chipAhora && chipAhora.contains(el)));
  const derecha = Math.max(...candidatos.map((el) => el.getBoundingClientRect().right));
  const abajo = Math.max(...candidatos.map((el) => el.getBoundingClientRect().bottom));
  const puntos = (el) => {
    const n = nombreDe(el);
    let p = 0;
    if (/(iniciar generacion|start generation|iniciar geracao)/.test(n)) p += 100;
    if (el.matches('button.generate-icon-button, flow-generate-icon-button button')) p += 100;
    if (/\b(arrow_forward|arrow_upward|send)\b/.test(el.textContent)) p += 60;
    if (/(generar|generate|enviar|submit)/.test(n)) p += 40;
    if (el.type === 'submit') p += 20;
    if (el.getBoundingClientRect().right >= derecha - 2) p += 30; // el de más a la derecha…
    if (el.getBoundingClientRect().bottom >= abajo - 2) p += 15; // …en la fila de abajo (arriba está la ✕)
    if (/(agregar|anadir|adjuntar|add|attach|subir|upload|agente|agent|borrar|clear|cerrar|close|eliminar|delete|config|ajuste|setting)/.test(n)) p -= 200;
    if (/^\s*[+x×]\s*$/.test(el.textContent) || /\b(add|close|clear)\b/.test(el.textContent)) p -= 200;
    return p;
  };
  const sendBtn = candidatos.map((el) => [el, puntos(el)]).filter(([, p]) => p > 0).sort((a, b) => b[1] - a[1])[0]?.[0] || null;
  const describir = (el) => el && {
    nombre: nombreDe(el).trim().slice(0, 60), texto: el.textContent.trim().slice(0, 40), tag: el.tagName.toLowerCase(),
    puntos: puntos(el), desactivado: !!(el.disabled || el.getAttribute('aria-disabled') === 'true'),
  };
  const botonesCompositor = candidatos.map(describir);
  if (!sendBtn) return { ok: true, focused: false, note, tarjetas, envio: null, dom: JSON.stringify(botonesCompositor), adjuntos };
  // Para que la cola pueda volver a pulsarla si el primer clic no entra
  document.querySelectorAll('[data-flow-helper="enviar"]').forEach((el) => el.removeAttribute('data-flow-helper'));
  sendBtn.setAttribute('data-flow-helper', 'enviar');

  await waitFor(() => (!sendBtn.disabled && sendBtn.getAttribute('aria-disabled') !== 'true') ? sendBtn : null, 2500);
  sendBtn.scrollIntoView({ block: 'nearest' });
  sendBtn.focus();
  // Centro de la flecha en píxeles CSS de la ventana, lo que espera Input.dispatchMouseEvent
  const r = sendBtn.getBoundingClientRect();
  const envio = { x: r.left + r.width / 2, y: r.top + r.height / 2 };
  const desactivada = sendBtn.disabled || sendBtn.getAttribute('aria-disabled') === 'true';
  // Qué botón se eligió y cuáles había, para el diagnóstico si el envío falla
  // "antes": cómo estaba la galería antes de enviar, para confirmar el envío después
  return {
    ok: true, focused: document.activeElement === sendBtn, note, tarjetas, envio, desactivada,
    antes: { tarjetas, galeria, primerLote }, flecha: describir(sendBtn), botonesCompositor, adjuntos,
  };
}

// Videos o imágenes generados que hay en la página. Flow solo pinta los que
// caben en pantalla y cerca, así que en proyectos largos hay que bajar con el
// scroll antes para que aparezcan todos. tipo: 'video' o 'imagen'.
// soloArriba: solo lo del bloque de generación de arriba del todo, que es donde
// Flow pone la más reciente. La cola lo usa para no confundir su resultado con
// otros videos que Flow carga al mover la galería.
// delPrompt: solo lo de los bloques de generación cuyo prompt es ese (así, con
// varios pedidos generándose a la vez, cada uno encuentra el suyo).
// clave: el bloque de Flow que la extensión marcó como de ese pedido al enviarlo
// (data-fh-bloque). Es lo más fiable: con imágenes, Flow traduce el prompt al
// inglés y ya no se puede buscar por el texto.
function mediosDeLaPagina(tipo, soloArriba = false, delPrompt = '', clave = '') {
  const TARJETA = 'flow-batch-info, flow-tile-container, flow-video-tile, flow-image-tile';
  // Flow sirve las imágenes desde flow-content.google / googleusercontent, y los
  // VIDEOS (al reproducir) desde googlevideo.com/videoplayback (como YouTube).
  const MEDIO_OK = /^https:\/\/(([a-z0-9-]+\.)*(flow-content\.google|googleusercontent\.com|googlevideo\.com)\/|flow\.google\.com\/asb\/|labs\.google\/fx\/api\/)/;
  const huella = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '');
  let raices = [document];
  let marcado = clave && document.querySelector(`[data-fh-bloque="${CSS.escape(String(clave))}"]`);
  // Si Flow redibujó la galería, la marca pudo quedar en el bloque de otro
  // pedido: solo vale si ese bloque lleva el prompt de este. Si no, se quita
  // y se busca por el prompt (antes el pedido se quedaba "Generándose" con el
  // video ya hecho en Flow)
  if (marcado && delPrompt) {
    const buscada = huella(delPrompt).slice(0, 40);
    const suyo = [...marcado.querySelectorAll('flow-expandable-prompt')];
    if (suyo.length && !suyo.some((el) => huella(el.textContent).includes(buscada))) {
      delete marcado.dataset.fhBloque;
      marcado = null;
    }
  }
  // Flag: si se pidió por prompt, al final se filtra la salida por el título del
  // tile (Flow ya NO usa flow-batch-info; los videos van en flow-video-tile con
  // su prompt en el pie). Por eso ya no se restringe la raíz a flow-batch-info.
  const filtrarPorPrompt = !marcado && !!delPrompt;
  if (marcado) {
    raices = [marcado];
  } else if (delPrompt) {
    const buscada = huella(delPrompt).slice(0, 40);
    const porBatch = [...document.querySelectorAll('flow-batch-info')].map((b) => b.parentElement).filter((b) =>
      b && [...b.querySelectorAll('flow-expandable-prompt')].some((el) => huella(el.textContent).includes(buscada)));
    // Si no hay flow-batch-info que coincida (galería nueva), se busca en TODO y
    // luego se filtra por el título del tile (abajo).
    raices = porBatch.length ? porBatch : [document];
  } else if (soloArriba) {
    raices = [(document.querySelector('flow-batch-info') || {}).parentElement || document];
  }
  const buscadaPrompt = delPrompt ? huella(delPrompt).slice(0, 40) : '';
  const vistos = new Set();
  const out = [];
  // IMÁGENES: se recorren los <img>. VIDEOS: NO se recorren los <video> aquí (un
  // mismo video sale en 2 elementos y se contaba doble); los videos los junta el
  // bloque de flow-video-tile de abajo, 1 por tile.
  for (const el of (tipo === 'imagen' ? raices.flatMap((r) => [...r.querySelectorAll('img')]) : [])) {
    const src = el.currentSrc || el.src || '';
    const valido = MEDIO_OK.test(src) && el.naturalWidth >= 256; // deja fuera avatares e iconos
    if (!valido) continue;
    const clave = src.split('?')[0]; // el mismo medio puede salir dos veces
    if (vistos.has(clave)) continue;
    // Flow pone en el pie de la tarjeta un título que resume el prompt
    const tarjeta = el.closest(TARJETA) || el.parentElement;
    // Saltar las MINIATURAS de VIDEO: un flow-video-tile muestra su portada como
    // <img>, y NO es una imagen generada. Si no, al pedir una imagen se colaba la
    // miniatura del último video. Se reconoce por el tile de video, un <video>
    // dentro, o el icono play_circle del pie.
    const esMiniaturaVideo = el.closest('flow-video-tile')
      || (tarjeta && (tarjeta.matches && tarjeta.matches('flow-video-tile')
        || tarjeta.querySelector('video')
        || /play_circle/.test(tarjeta.textContent || '')));
    if (esMiniaturaVideo) continue;
    vistos.add(clave);
    const prompt = (tarjeta && tarjeta.innerText || '').trim().split('\n').pop() || tipo;
    out.push({ src, clave, prompt, tipo });
  }

  // VIDEOS: en la galería, un video terminado se ve como MINIATURA (<img>), sin
  // <video> hasta que le das play. Por eso, además de los <video>, se revisan los
  // tiles de video y se saca la URL real del video del DOM (de un <video>/<source>
  // o de cualquier atributo/HTML que tenga una URL ".../video/..."). Así la
  // extensión reconoce que YA se generó (deja de reintentar y crear nuevos).
  if (tipo !== 'imagen') {
    const RE_VIDEO = /https:\/\/(?:[a-z0-9-]+\.)*(?:flow-content\.google\/video\/|googlevideo\.com\/videoplayback)[^"'\s)]+/i;
    // El id del video = el id de la miniatura o del propio video (image/<id> o video/<id>)
    const RE_ID = /flow-content\.google\/(?:image|video)\/([a-z0-9-]+)/i;
    for (const r of raices) {
      for (const tile of r.querySelectorAll('flow-video-tile, flow-tile-container')) {
        // Un tile puede tener 2 <video> (asb + flow-content) = el MISMO video. Se
        // elige UNA, prefiriendo flow-content/video (tiene id y Expires, mejor para R2).
        // El enlace asb caduca peor y R2 lo descarga mal, así que solo vale de reserva.
        const fuentes = [...tile.querySelectorAll('video, source')]
          .map((e) => e.currentSrc || e.src || '').filter((s) => MEDIO_OK.test(s));
        // URL buena (flow-content/video o googlevideo): la que R2 descarga bien.
        const buena = fuentes.find((s) => /flow-content\.google\/video\//i.test(s))
          || fuentes.find((s) => /googlevideo\.com\/videoplayback/i.test(s))
          || ((tile.outerHTML.match(RE_VIDEO) || [])[0] || '').replace(/&amp;/g, '&');
        const asb = fuentes.find((s) => /flow\.google\.com\/asb\//i.test(s)) || '';
        const vsrc = buena || asb || fuentes[0] || '';
        // El id del video = el id de la MINIATURA (image/<id>) o de la URL del propio
        // video. El background cruza ese id con la URL que capturó de la red.
        const img = tile.querySelector('img');
        const idm = `${(img && (img.currentSrc || img.src)) || ''} ${tile.outerHTML}`.match(RE_ID);
        const idVideo = idm ? idm[1].toLowerCase() : '';
        const prompt = (tile.innerText || '').trim().split('\n').pop() || 'video';
        // La clave estable es el id del video (la miniatura). Así se detecta como
        // "nuevo" aunque la URL cambie de firma.
        const clave = idVideo || (vsrc ? vsrc.split('?')[0] : '');
        if (!clave || vistos.has(clave)) continue;
        vistos.add(clave);
        // esAsb: solo tenemos el enlace asb (sin la URL buena). cola.js dará un
        // margen a que cargue la buena antes de conformarse con este.
        out.push({ src: vsrc, clave, prompt, tipo: 'video', idVideo, pendienteRed: !vsrc, esAsb: !buena && !!asb });
      }
    }
  }
  // Si se pidió por prompt y se buscó en toda la galería, se deja solo lo cuyo
  // título (pie del tile) coincide con el prompt. Así cada pedido ve SOLO lo suyo.
  if (filtrarPorPrompt && buscadaPrompt) {
    const filtrado = out.filter((m) => huella(m.prompt).includes(buscadaPrompt));
    if (filtrado.length) return filtrado;
  }
  return out;
}

// Flow muestra el video terminado como MINIATURA (<img>) y solo carga el
// <video> con su URL real al pasar el ratón o darle play. Esto le da ese "toque"
// (igual que cuando TÚ lo ves) a los tiles de ESTE pedido, para que aparezca la
// URL del video y la extensión pueda entregarlo al panel. Devuelve cuántos
// quedaron con <video> cargado.
async function despertarVideos(delPrompt = '', clave = '') {
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const MEDIO = /flow-content\.google|googleusercontent\.com|flow\.google\.com\/asb\/|googlevideo\.com/i;
  const huella = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '');
  const tieneVideo = (tile) => { const v = tile.querySelector('video'); return !!(v && MEDIO.test(v.currentSrc || v.src || '')); };

  let raiz = clave && document.querySelector(`[data-fh-bloque="${CSS.escape(String(clave))}"]`);
  if (!raiz && delPrompt) {
    const b = huella(delPrompt).slice(0, 40);
    raiz = [...document.querySelectorAll('flow-batch-info')].map((x) => x.parentElement)
      .find((x) => x && [...x.querySelectorAll('flow-expandable-prompt')].some((el) => huella(el.textContent).includes(b)));
  }
  const tiles = [...(raiz || document).querySelectorAll('flow-video-tile, flow-tile-container')];
  let ok = 0;
  for (const tile of tiles) {
    if (tieneVideo(tile)) { ok++; continue; }
    // SOLO pasar el ratón (hover): Flow dibuja el <video> con su src al pasar por
    // encima. NO se hace clic (eso abría el editor). Es como cuando TÚ lo miras.
    try { tile.scrollIntoView({ block: 'center' }); } catch (e) {}
    const objetivos = [tile, tile.querySelector('img'), tile.querySelector('[class*="thumbnail"]')].filter(Boolean);
    for (const o of objetivos) {
      for (const t of ['pointerover', 'pointerenter', 'mouseover', 'mouseenter', 'mousemove']) {
        try { o.dispatchEvent(new (t.startsWith('pointer') ? PointerEvent : MouseEvent)(t, { bubbles: true, cancelable: true, view: window })); } catch (e) {}
      }
    }
    let got = false;
    for (let i = 0; i < 12 && !got; i++) { if (tieneVideo(tile)) got = true; else await sleep(150); }
    // Quitar el ratón para que no se quede "hovered"
    for (const o of objetivos) {
      for (const t of ['pointerout', 'pointerleave', 'mouseout', 'mouseleave']) {
        try { o.dispatchEvent(new (t.startsWith('pointer') ? PointerEvent : MouseEvent)(t, { bubbles: true, cancelable: true, view: window })); } catch (e) {}
      }
    }
    if (got) ok++;
  }
  return ok;
}

// Reporte de lo que la extensión VE de los videos en la galería: cuántos tiles,
// cuántos <video> con src, y muestras (si el <video> cargó, la miniatura, y si la
// URL del video está en el HTML). Sirve para saber por qué no se entrega.
function reporteVideos() {
  const MEDIO = /flow-content\.google|googleusercontent\.com|flow\.google\.com\/asb\/|googlevideo\.com/i;
  const RE_VIDEO = /https:\/\/(?:[a-z0-9-]+\.)*(?:flow-content\.google\/video\/|googlevideo\.com\/videoplayback)[^"'\s)]+/i;
  const corta = (s) => String(s || '').slice(0, 90);
  const tiles = [...document.querySelectorAll('flow-video-tile, flow-tile-container')];
  const videos = [...document.querySelectorAll('video')];
  const muestras = tiles.slice(0, 5).map((t) => {
    const v = t.querySelector('video');
    const img = t.querySelector('img');
    return {
      tieneVideo: !!(v && (v.currentSrc || v.src)),
      videoSrc: corta(v ? (v.currentSrc || v.src || '') : ''),
      imgSrc: corta(img ? (img.currentSrc || img.src || '') : ''),
      urlVideoEnHtml: corta((t.outerHTML.match(RE_VIDEO) || [''])[0]),
      texto: (t.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 60),
    };
  });
  return {
    tilesVideo: tiles.length,
    videosConSrc: videos.filter((v) => MEDIO.test(v.currentSrc || v.src || '')).length,
    totalVideos: videos.length,
    muestras,
  };
}

// Para ajustar fillFlow cuando Flow cambia su interfaz: abre el panel de
// ajustes y apunta los botones que se ven (texto, rol, aria, etiqueta Angular
// más cercana). No lee prompts, cuentas ni nada de fuera de los controles.
async function diagnosticoFlow() {
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const visible = (el) => el.getClientRects().length > 0;
  const textOf = (el) => {
    let out = '';
    const w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    while (w.nextNode()) if (!w.currentNode.parentElement.closest('mat-icon, .google-symbols, .material-icons')) out += w.currentNode.nodeValue;
    return out.replace(/\s+/g, ' ').trim();
  };
  const SEL = 'button, [role="button"], [role="menuitem"], [role="option"], [role="radio"], [role="tab"], [role="switch"], [role="combobox"], [role="listbox"]';
  const etiquetaCercana = (el) => {
    for (let n = el; n; n = n.parentElement) if (n.tagName.includes('-')) return n.tagName.toLowerCase();
    return '';
  };
  const describir = (el) => {
    const r = el.getBoundingClientRect();
    const d = {
      texto: textOf(el).slice(0, 80),
      todo: el.textContent.replace(/\s+/g, ' ').trim().slice(0, 80),
      tag: el.tagName.toLowerCase(),
      padre: etiquetaCercana(el.parentElement || el),
      pos: `${Math.round(r.left)},${Math.round(r.top)}`,
    };
    for (const a of ['role', 'aria-label', 'aria-selected', 'aria-checked', 'aria-pressed', 'aria-expanded', 'aria-haspopup', 'disabled', 'data-state']) {
      // Sin correos ni nombres de la cuenta (el botón de la cuenta los lleva en su aria-label)
      if (el.hasAttribute(a)) d[a] = el.getAttribute(a).replace(/[^\s()]+@[^\s()]+/g, '[correo]').replace(/^(Cuenta de Google|Google Account):.*/is, '$1: [oculto]');
    }
    return d;
  };
  const botones = () => [...document.querySelectorAll(SEL)].filter(visible).map(describir);

  const antes = botones();
  // El chip de ajustes: por su nombre, o por acabar en la cantidad. Nunca una
  // opción suelta "x1".."x4" del menú: pulsarla cambiaba la cantidad elegida.
  const visibles = [...document.querySelectorAll(SEL)].filter(visible);
  const chip = visibles.find((el) => /(bot[oó]n|button|gatilho|trigger).{0,25}(config|ajuste|setting)/i.test(el.getAttribute('aria-label') || ''))
    || visibles
      .filter((el) => { const t = textOf(el).replace(/\s+/g, '').toLowerCase(); return /x[1-4]$/.test(t) && !/^x[1-4]$/.test(t) && t.length < 60; })
      .sort((a, b) => textOf(a).length - textOf(b).length)[0];
  // Si el menú ya está abierto no se toca nada: el diagnóstico no debe cambiar ajustes
  const menuYaAbierto = visibles.some((el) => /^x[1-4]$/.test(textOf(el).trim()));
  let despues = null;
  if (chip && !menuYaAbierto) {
    chip.click();
    await sleep(1200);
    const vistos = new Set(antes.map((b) => b.todo + b.pos));
    despues = botones().filter((b) => !vistos.has(b.todo + b.pos)); // solo lo que apareció al abrir
    document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', code: 'Escape', keyCode: 27, bubbles: true }));
    await sleep(300);
    // Flow a veces ignora Escape: el chip es un interruptor, se pulsa otra vez
    if ([...document.querySelectorAll(SEL)].filter(visible).some((el) => /^x[1-4]$/.test(textOf(el).trim()))) chip.click();
  }
  // Cómo es una generación terminada de la galería: la cadena de etiquetas
  // hasta el bloque y sus imágenes/videos (solo dominio y ruta, sin la firma)
  const lote = (() => {
    const info = document.querySelector('flow-batch-info');
    if (!info) return null;
    let bloque = info;
    for (let i = 0; i < 8 && bloque.parentElement; i++) {
      bloque = bloque.parentElement;
      if (bloque.querySelector('img, video') && bloque.querySelector('flow-expandable-prompt')) break;
    }
    const cadena = [];
    for (let n = info; n && n !== bloque.parentElement; n = n.parentElement) cadena.push(n.tagName.toLowerCase());
    const medios = [...bloque.querySelectorAll('img, video, source')].slice(0, 8).map((m) => ({
      tag: m.tagName.toLowerCase(),
      src: String(m.currentSrc || m.src || m.getAttribute('src') || '').split('?')[0].slice(0, 90),
      poster: String(m.getAttribute('poster') || '').split('?')[0].slice(0, 90),
      tam: m.tagName === 'IMG' ? `${m.naturalWidth}x${m.naturalHeight}` : '',
      padre: etiquetaCercana(m),
    }));
    const botones = [...bloque.querySelectorAll('button, [role="button"]')].slice(0, 12)
      .map((b) => b.getAttribute('aria-label') || textOf(b).slice(0, 30)).filter(Boolean);
    return { cadena: cadena.join(' < '), medios, botones };
  })();
  const cajas = [...document.querySelectorAll('textarea, [contenteditable]:not([contenteditable="false"]), [role="textbox"]')]
    .filter(visible).map((el) => ({ tag: el.tagName.toLowerCase(), padre: etiquetaCercana(el), placeholder: el.getAttribute('placeholder') || el.getAttribute('aria-label') || '' }));
  return {
    url: location.pathname.replace(/[0-9a-f-]{36}/g, '<id>'),
    chip: chip ? describir(chip) : null,
    botonesAntes: antes,
    alAbrirChip: despues,
    lote,
    cajas,
    videos: document.querySelectorAll('video').length,
    imagenes: document.querySelectorAll('img').length,
  };
}

// En la página de inicio de Flow: dónde está el botón de crear un proyecto
// nuevo. Devuelve { x, y } (centro, para el clic real) o { error, botones }
// con lo que había, para ajustarlo si Flow cambia.
function botonNuevoProyecto() {
  const visible = (el) => el.getClientRects().length > 0;
  const nombre = (el) => (el.innerText + ' ' + (el.getAttribute('aria-label') || '') + ' ' + (el.getAttribute('title') || ''))
    .replace(/\b[a-z]+(_[a-z0-9]+)+\b/g, ' ').replace(/\s+/g, ' ').trim();
  const NUEVO = /(nuevo proyecto|proyecto nuevo|crear proyecto|new project|create project|novo projeto|criar projeto)/i;
  const botones = [...document.querySelectorAll('button, a, [role="button"]')].filter(visible);
  const b = botones.find((el) => NUEVO.test(nombre(el)));
  if (!b) return { error: 'No encontré el botón "Nuevo proyecto" en la página de inicio de Flow.', botones: botones.map(nombre).filter(Boolean).slice(0, 40) };
  b.scrollIntoView({ block: 'center' });
  const r = b.getBoundingClientRect();
  return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
}

// ¿La página del proyecto ya está lista para escribir? (caja del prompt montada)
function proyectoListo() {
  return !!document.querySelector('flow-base-prompt-box, [aria-label="Iniciar generación"], [aria-label="Start generation"]');
}

// ¿Flow rechazó o falló la generación de ESTE prompt? Mira el bloque de arriba
// (donde Flow pone la más reciente) y solo si su prompt es el nuestro, para no
// culpar a este pedido del fallo de otro. Devuelve null o
// { politica: true si es por sus normas de contenido, texto: lo que dijo Flow }.
function rechazoArriba(prompt, clave = '') {
  const huella = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '');
  // El bloque más reciente (el primero de la página) cuyo prompt es el nuestro:
  // con varios pedidos a la vez ya no tiene por qué ser el de arriba del todo
  const bloque = (clave && document.querySelector(`[data-fh-bloque="${CSS.escape(String(clave))}"]`))
    || [...document.querySelectorAll('flow-batch-info')].map((b) => b.parentElement).find((b) =>
      b && [...b.querySelectorAll('flow-expandable-prompt')].some((el) => huella(el.textContent).includes(huella(prompt).slice(0, 40))));
  if (!bloque) return null;
  // Sin los nombres de iconos (play_circle, favorite, more_vert...)
  const texto = (bloque.innerText || '').replace(/\b[a-z]+(_[a-z0-9]+)+\b|\b(favorite|download|undo|redo|delete|close|add)\b/g, ' ').replace(/\s+/g, ' ');
  const POLITICA = /(infring|incumpl|vulnera|viola|pol[ií]tica|violate|polic(y|ies)|prohibid|no (se )?(puede|podemos) generar|can.?t generate|unable to generate|contenido (sensible|inapropiado)|unsafe|pode violar)/i;
  const FALLO = /(no se pudo completar|couldn.?t complete|could not complete|algo sali[oó] mal|something went wrong|alta demanda|high demand|int[eé]ntalo (de nuevo|m[aá]s tarde)|try again)/i;
  if (!POLITICA.test(texto) && !FALLO.test(texto)) return null;
  // La frase del aviso: de la que coincide hasta el final de esa oración
  const m = texto.match(new RegExp('[^.]{0,80}(' + POLITICA.source.slice(1, -1) + '|' + FALLO.source.slice(1, -1) + ')[^.]{0,160}\\.?', 'i'));
  return { politica: POLITICA.test(texto), texto: (m ? m[0] : texto).trim().slice(0, 200) };
}

// Cuántas tarjetas de generación fallida hay en la página ("No se pudo
// completar la acción. Flow está experimentando una alta demanda..."). La cola
// compara con las que había antes de enviar para no esperar a un video que no llegará.
function avisosDeFallo() {
  const FALLO = /no se pudo completar la acci[oó]n|couldn[’']t complete (the|this) action|could not complete (the|this) action|something went wrong|algo sali[oó] mal/gi;
  return (document.body.innerText.match(FALLO) || []).length;
}

// ¿Flow marcó "actividad inusual" (anti-bot)? Si sí, la cola se para sola para no
// arriesgar la cuenta de Google.
function hayBloqueoFlow() {
  return /actividad inusual|unusual activity/i.test(document.body.innerText || '');
}


// Espía de red: apunta cada petición que hace Flow (fetch y XHR) con la
// "huella" de su cuerpo (solo letras y números, sin acentos), para saber si
// salió una con el texto del prompt. Corre en el mundo MAIN de la página y se
// instala una vez por pestaña. Lee también cuerpos que no son texto (Request,
// Blob, FormData, bytes): Flow no siempre manda un string.
function instalarEspia() {
  // Con versión: si la pestaña no se recargó tras actualizar la extensión, la
  // nueva se pone encima de la vieja (antes se quedaba la vieja, que no leía
  // los cuerpos que no son texto)
  const VERSION = 2;
  if (window.__fhEspia >= VERSION) return true;
  window.__fhEspia = VERSION;
  window.__fhReqs = window.__fhReqs || [];
  const huella = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '');
  // Se decodifica trozo a trozo (un % suelto no estropea el resto) y dos veces
  // por si viene doblemente codificado; luego los ñ de JSON
  const decodificar = (b) => String(b || '').split('&').map((parte) => {
    let s = parte.replace(/\+/g, ' ');
    for (let i = 0; i < 2; i++) { try { s = decodeURIComponent(s); } catch (e) { break; } }
    return s;
  }).join('&').replace(/\\+u([0-9a-fA-F]{4})/g, (_, h) => String.fromCharCode(parseInt(h, 16)));
  const leer = async (body) => {
    if (!body) return '';
    if (typeof body === 'string') return body;
    if (body instanceof URLSearchParams) return body.toString();
    if (body instanceof FormData) return [...body.entries()].map(([k, v]) => k + '=' + (typeof v === 'string' ? v : '')).join('&');
    if (body instanceof Blob) return body.text();
    if (body instanceof ArrayBuffer || ArrayBuffer.isView(body)) return new TextDecoder().decode(body);
    return '';
  };
  const guardar = (url, cuerpo) => {
    const q = { t: Date.now(), url: String(url || '').slice(0, 200), huella: '', bytes: 0 };
    window.__fhReqs.push(q);
    if (window.__fhReqs.length > 60) window.__fhReqs.shift();
    Promise.resolve(cuerpo).then(leer).then((texto) => {
      q.bytes = texto.length;
      q.huella = huella(decodificar(texto)).slice(0, 40000);
    }).catch(() => {});
  };
  const fetchOriginal = window.fetch;
  window.fetch = function (input, init) {
    try {
      const esRequest = typeof Request !== 'undefined' && input instanceof Request;
      const url = typeof input === 'string' ? input : input && input.url;
      const cuerpo = init && init.body != null ? init.body : esRequest ? input.clone().text() : '';
      guardar(url, cuerpo);
    } catch (e) { /* nunca romper la petición de Flow */ }
    return fetchOriginal.apply(this, arguments);
  };
  const abrir = XMLHttpRequest.prototype.open;
  const enviar = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function (metodo, url) { this.__fhUrl = url; return abrir.apply(this, arguments); };
  XMLHttpRequest.prototype.send = function (body) {
    try { guardar(this.__fhUrl, body); } catch (e) { /* igual */ }
    return enviar.apply(this, arguments);
  };
  return true;
}

// ¿Empezó Flow a generar desde t0? Sí si salió una petición con el texto del
// prompt, o apareció una tarjeta nueva, o la caja se vació con alguna petición
// de Flow detrás (la caja vacía sola no basta: Flow la vacía al redibujarla, y
// a veces NO la vacía al enviar). Espera hasta ms. Corre en el mundo MAIN.
// estricto: hay otros pedidos generándose, así que solo vale lo que lleva
// ESTE prompt (una generación nueva arriba o la caja vacía pueden ser del anterior)
// Antes de pulsar la flecha: todos los bloques de generación que ya hay quedan
// marcados como "viejo". El primero que aparezca sin marca después es el de
// este pedido (tomarBloqueNuevo).
function marcarBloquesViejos() {
  document.querySelectorAll('flow-batch-info').forEach((b) => {
    const bl = b.parentElement;
    if (bl && !bl.dataset.fhBloque) bl.dataset.fhBloque = 'viejo';
  });
  return true;
}
function tomarBloqueNuevo(clave) {
  if (!clave) return false;
  if (document.querySelector(`[data-fh-bloque="${CSS.escape(String(clave))}"]`)) return true;
  const bl = [...document.querySelectorAll('flow-batch-info')].map((b) => b.parentElement).find((b) => b && !b.dataset.fhBloque);
  if (!bl) return false;
  bl.dataset.fhBloque = String(clave);
  return true;
}

async function confirmarEnvio(prompt, antes, t0, ms, estricto = false, clave = '') {
  const sleep = (x) => new Promise((r) => setTimeout(r, x));
  const { tarjetas: tarjetasAntes = 0, galeria: galeriaAntes = null, primerLote = null } = antes || {};
  // Firma de la generación de arriba (mismo cálculo que en fillFlow)
  const firmaArriba = () => {
    const b = (document.querySelector('flow-batch-info') || {}).parentElement;
    return b ? (b.textContent || '').replace(/\s+/g, ' ').slice(0, 200) + '|' +
      [...b.querySelectorAll('img, video')].map((m) => String(m.currentSrc || m.src || '').split('?')[0]).join(',') : '';
  };
  const huella = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '');
  const buscada = huella(prompt).slice(0, 60);
  // Igual que tomarBloqueNuevo (cada función de página va sola, sin las de fuera)
  const tomarBloqueNuevoAqui = (c) => {
    if (document.querySelector(`[data-fh-bloque="${CSS.escape(String(c))}"]`)) return true;
    const bl = [...document.querySelectorAll('flow-batch-info')].map((b) => b.parentElement).find((b) => b && !b.dataset.fhBloque);
    if (!bl) return false;
    bl.dataset.fhBloque = String(c);
    return true;
  };
  // Generaciones de la galería con este prompt (la señal más segura en el Flow actual)
  const enGaleria = () => [...document.querySelectorAll('flow-expandable-prompt')]
    .filter((el) => huella(el.textContent).includes(huella(prompt).slice(0, 40))).length;
  const RPC = /batchexecute|StreamGenerateContent|aisandbox-pa\.googleapis\.com|batchGenerate|flowMedia|generate/i;
  const tarjetas = () => document.querySelectorAll('flow-batch-info, flow-tile-container, flow-video-tile, flow-image-tile').length;
  const caja = document.querySelector('[data-flow-helper="caja"]');
  const tienePrompt = () => !!caja && caja.isConnected && String(caja.value ?? caja.textContent ?? '').includes(String(prompt).trim().slice(0, 24));
  const desde = () => (window.__fhReqs || []).filter((q) => q.t >= t0 - 50);
  const ok = () => {
    const reqs = desde();
    // Un bloque de generación nuevo (sin marca): es el de este pedido
    if (clave && tomarBloqueNuevoAqui(clave)) return 'bloque nuevo';
    if (galeriaAntes != null && enGaleria() > galeriaAntes) return 'nueva generacion con el prompt';
    if (buscada.length >= 8 && reqs.some((q) => q.huella.includes(buscada))) return 'peticion con el prompt';
    if (estricto) return '';
    if (primerLote != null && firmaArriba() !== primerLote) return 'nueva generacion arriba';
    if (tarjetas() > tarjetasAntes) return 'tarjeta nueva';
    if (!tienePrompt() && reqs.some((q) => RPC.test(q.url))) return 'caja vacia + peticion';
    return '';
  };
  for (const fin = Date.now() + ms; Date.now() < fin; await sleep(150)) {
    const por = ok();
    if (por) return { ok: true, por };
  }
  const por = ok();
  return {
    ok: !!por,
    por,
    diag: {
      tienePrompt: tienePrompt(),
      galeria: `${galeriaAntes} -> ${enGaleria()}`,
      tarjetas: `${tarjetasAntes} -> ${tarjetas()}`,
      arribaCambio: primerLote != null && firmaArriba() !== primerLote,
      // Avisos o errores que Flow enseñe (sin créditos, alta demanda, política...)
      avisos: [...document.querySelectorAll('[role="alert"], [role="alertdialog"], [role="dialog"], [role="status"], [aria-live], mat-snack-bar-container, .mat-mdc-snack-bar-label, .cdk-overlay-pane')]
        .filter((el) => el.getClientRects().length && (el.innerText || '').trim())
        .map((el) => el.innerText.replace(/\s+/g, ' ').trim().slice(0, 160))
        .filter((t, i, a) => a.indexOf(t) === i).slice(0, 5),
      arriba: firmaArriba().split('|')[0].slice(0, 80),
      peticiones: desde().map((q) => `${q.url.replace(/^https?:\/\/[^/]+/, '').slice(0, 70)} [${q.bytes}b${buscada && q.huella.includes(buscada) ? ', CON PROMPT' : ''}]`),
    },
  };
}

// Pulsa la flecha de crear como La Casa Dark: desde la propia página, con la
// secuencia completa de puntero y ratón, luego Enter en la caja y luego
// .click(), en dos pasadas. Se ejecuta en el mundo MAIN de la página (world:
// 'MAIN') para poder vigilar las peticiones de red de Flow: el envío solo
// cuenta si sale una petición con el texto del prompt, porque Flow también
// vacía la caja cuando la vuelve a dibujar tras cambiar el modelo.
// Devuelve { ok, via } o { ok: false, error }.
// desde: cuándo se dio el clic real anterior (si lo hubo). Si Flow lo aceptó
// tarde, no se vuelve a pulsar: pulsar otra vez generaba el video repetido.
async function enviarEnPagina(prompt, antes, estricto = false, clave = '', desde = 0) {
  // Bloque nuevo sin marca = el de este pedido (ver marcarBloquesViejos)
  const tomarBloque = () => {
    if (!clave) return false;
    if (document.querySelector(`[data-fh-bloque="${CSS.escape(String(clave))}"]`)) return true;
    const bl = [...document.querySelectorAll('flow-batch-info')].map((b) => b.parentElement).find((b) => b && !b.dataset.fhBloque);
    if (!bl) return false;
    bl.dataset.fhBloque = String(clave);
    return true;
  };
  const { tarjetas: tarjetasAntes = 0, galeria: galeriaAntes = null, primerLote = null } = antes || {};
  const firmaArriba = () => {
    const b = (document.querySelector('flow-batch-info') || {}).parentElement;
    return b ? (b.textContent || '').replace(/\s+/g, ' ').slice(0, 200) + '|' +
      [...b.querySelectorAll('img, video')].map((m) => String(m.currentSrc || m.src || '').split('?')[0]).join(',') : '';
  };
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const waitFor = async (fn, ms, cada = 200) => {
    for (const fin = Date.now() + ms; Date.now() < fin; await sleep(cada)) if (fn()) return true;
    return !!fn();
  };
  // Solo letras y números, sin acentos: así el prompt se reconoce dentro del
  // cuerpo de la petición aunque vaya codificado (%20, +, ñ...)
  const huella = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '');
  // Las peticiones las apunta instalarEspia(), que se ejecuta antes que esto
  if (!window.__fhReqs) window.__fhReqs = [];

  const EDITABLE = 'textarea, [contenteditable]:not([contenteditable="false"]), [role="textbox"]';
  const boton = () => document.querySelector('[data-flow-helper="enviar"]');
  const caja = () => {
    const c = document.querySelector('[data-flow-helper="caja"]');
    if (c && c.isConnected) return c;
    // Flow volvió a dibujar el editor: el nuevo está junto a la flecha
    const b = boton();
    const raiz = b && (b.closest('form') || b.parentElement && b.parentElement.parentElement);
    const nueva = raiz && [...raiz.querySelectorAll(EDITABLE)].find((el) => el.getClientRects().length);
    if (nueva) nueva.setAttribute('data-flow-helper', 'caja');
    return nueva || null;
  };
  const textoCaja = () => { const c = caja(); return c ? String(c.value ?? c.textContent ?? '').trim() : ''; };
  const texto = String(prompt || '').trim();
  const tienePrompt = () => textoCaja().includes(texto.slice(0, 24));
  const asegurarTexto = async () => {
    if (tienePrompt()) return true;
    const c = caja();
    if (!c) return false;
    c.click(); c.focus();
    if (c.isContentEditable) {
      document.execCommand('selectAll', false, null);
      if (!document.execCommand('insertText', false, texto) || !tienePrompt()) {
        c.innerHTML = '<p>' + texto.replace(/[&<>"]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch])) + '</p>';
        c.dispatchEvent(new InputEvent('beforeinput', { bubbles: true, cancelable: true, inputType: 'insertText', data: texto }));
      }
    } else {
      Object.getOwnPropertyDescriptor(Object.getPrototypeOf(c), 'value').set.call(c, texto);
    }
    c.dispatchEvent(new Event('input', { bubbles: true }));
    c.dispatchEvent(new Event('change', { bubbles: true }));
    await sleep(200);
    return tienePrompt();
  };
  const activa = () => { const b = boton(); return !!b && b.isConnected && !b.disabled && b.getAttribute('aria-disabled') !== 'true'; };
  const RPC = /batchexecute|StreamGenerateContent|aisandbox-pa\.googleapis\.com|batchGenerate|flowMedia/i;
  const huellaPrompt = huella(texto).slice(0, 60);
  const tarjetas = () => document.querySelectorAll('flow-batch-info, flow-tile-container, flow-video-tile, flow-image-tile').length;
  // Enviado = salió una petición con este prompt, o apareció una tarjeta, o la
  // caja se vació Y salió alguna petición de Flow (no basta con la caja vacía)
  const enGaleria = () => [...document.querySelectorAll('flow-expandable-prompt')]
    .filter((el) => huella(el.textContent).includes(huella(texto).slice(0, 40))).length;
  const enviadoDesde = (t0) => {
    const reqs = window.__fhReqs.filter((q) => q.t >= t0 - 50);
    if (tomarBloque()) return true;
    if (galeriaAntes != null && enGaleria() > galeriaAntes) return true;
    if (huellaPrompt.length >= 8 && reqs.some((q) => q.huella.includes(huellaPrompt))) return true;
    if (estricto) return false; // con otros en curso, solo vale lo que lleva este prompt
    if (primerLote != null && firmaArriba() !== primerLote) return true;
    if (tarjetas() > tarjetasAntes) return true;
    return !tienePrompt() && reqs.some((q) => RPC.test(q.url));
  };

  // La secuencia de ratón completa que usa La Casa Dark
  const puntero = (el) => {
    el.scrollIntoView({ block: 'center', inline: 'center' });
    const r = el.getBoundingClientRect();
    const o = {
      bubbles: true, cancelable: true, composed: true, view: window, button: 0, buttons: 1,
      clientX: r.left + r.width / 2, clientY: r.top + r.height / 2, pointerId: 1, pointerType: 'mouse', isPrimary: true, detail: 1,
    };
    try { el.focus(); } catch (e) { /* sin foco no pasa nada */ }
    el.dispatchEvent(new PointerEvent('pointerover', o));
    el.dispatchEvent(new MouseEvent('mouseover', o));
    el.dispatchEvent(new PointerEvent('pointermove', o));
    el.dispatchEvent(new MouseEvent('mousemove', o));
    el.dispatchEvent(new PointerEvent('pointerdown', o));
    el.dispatchEvent(new MouseEvent('mousedown', o));
    el.dispatchEvent(new PointerEvent('pointerup', { ...o, buttons: 0 }));
    el.dispatchEvent(new MouseEvent('mouseup', { ...o, buttons: 0 }));
    el.dispatchEvent(new MouseEvent('click', { ...o, buttons: 0 }));
  };
  const enter = () => {
    const c = caja();
    if (!c) return;
    c.focus();
    for (const tipo of ['keydown', 'keyup']) {
      c.dispatchEvent(new KeyboardEvent(tipo, { key: 'Enter', code: 'Enter', keyCode: 13, which: 13, bubbles: true, cancelable: true }));
    }
  };

  if (!boton()) return { ok: false, error: 'No encontré la flecha de crear.' };
  // Una pasada con esperas cortas: si Flow acepta, responde en menos de un segundo
  const intentos = [['puntero', () => puntero(boton())], ['Enter', enter], ['click', () => boton().click()]];
  const t1 = desde || Date.now();
  for (const [via, accion] of intentos) {
    // ¿Llegó ya el clic anterior (el real o el intento previo)? Entonces no se repite
    if (await waitFor(() => enviadoDesde(t1), 1500, 150)) return { ok: true, via: 'clic anterior (Flow tardó en mostrarlo)' };
    if (!(await asegurarTexto())) return { ok: false, error: 'Flow borró el prompt de la caja y no pude volver a escribirlo.' };
    await waitFor(activa, 2500, 100);
    if (document.visibilityState !== 'visible') return { ok: false, error: 'La pestaña de Flow no está visible: déjala delante.' };
    const t0 = Date.now();
    try { accion(); } catch (e) { /* se prueba la siguiente forma */ }
    if (await waitFor(() => enviadoDesde(t0), 3500, 150)) return { ok: true, via };
  }
  return {
    ok: false,
    error: 'Flow no empezó a generar (probé ratón, Enter y click).',
    diag: { flechaActiva: activa(), tienePrompt: tienePrompt(), peticiones: window.__fhReqs.slice(-5).map((q) => q.url.slice(0, 80)) },
  };
}

// Antes de escribir con el teclado real: foco en la caja del prompt y todo su
// contenido seleccionado, para que Input.insertText lo reemplace. Devuelve
// true si encontró la caja.
function prepararTeclado() {
  const caja = document.querySelector('[data-flow-helper="caja"]');
  if (!caja || !caja.isConnected) return false;
  caja.click();
  caja.focus();
  if (caja.isContentEditable) {
    const sel = window.getSelection();
    sel.removeAllRanges();
    const r = document.createRange();
    r.selectNodeContents(caja);
    sel.addRange(r);
  } else {
    caja.select();
  }
  return true;
}

// Para reintentar el envío: pone el foco al final de la caja del prompt (por
// si se prueba con Enter) y devuelve dónde está ahora la flecha, o null.
function prepararReintento() {
  const caja = document.querySelector('[data-flow-helper="caja"]');
  if (caja && caja.isConnected) {
    caja.focus();
    if (caja.isContentEditable) {
      const sel = window.getSelection();
      sel.selectAllChildren(caja);
      sel.collapseToEnd();
    }
  }
  const btn = document.querySelector('[data-flow-helper="enviar"]');
  if (!btn || !btn.isConnected || !btn.getClientRects().length) return null;
  btn.scrollIntoView({ block: 'nearest' });
  const r = btn.getBoundingClientRect();
  return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
}

// Tras el clic en la flecha: qué hay en la caja del prompt y cuántas tarjetas.
// texto es null si la caja ya no está.
function estadoEnvio() {
  const caja = document.querySelector('[data-flow-helper="caja"]');
  const texto = caja && caja.isConnected ? String(caja.value ?? caja.innerText ?? '').trim() : null;
  return { texto, tarjetas: document.querySelectorAll('flow-batch-info, flow-tile-container, flow-video-tile, flow-image-tile').length };
}

/* ---------- Descarga en 1080p (la calidad más alta que Flow da sin créditos) ---------- */

// Marca con data-fh-clic lo que hay que pulsar, para que la extensión mida su
// punto con el depurador (igual que la flecha: así no importa el zoom).
//   paso 'tarjeta': la tarjeta del video (se pasa el ratón por encima)
//   paso 'menu':    su botón de los tres puntos
//   paso 'item':    la opción del menú abierto cuyo texto cumple `patron`
// Devuelve true si lo encontró.
function marcarParaDescarga(clave, paso, patron = '') {
  document.querySelectorAll('[data-fh-clic]').forEach((el) => el.removeAttribute('data-fh-clic'));
  const TARJETA = 'flow-tile-container, flow-video-tile, flow-image-tile';
  const visible = (el) => el && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden';
  const video = [...document.querySelectorAll('video')].find((v) => String(v.currentSrc || v.src || '').split('?')[0] === clave);
  const tarjeta = video && (video.closest(TARJETA) || video.parentElement);
  let el = null;
  if (paso === 'tarjeta') {
    if (tarjeta) { tarjeta.scrollIntoView({ block: 'center' }); el = tarjeta; }
  } else if (paso === 'menu') {
    // El botón de los tres puntos: por su icono (more_vert) o su nombre
    const botones = tarjeta ? [...tarjeta.querySelectorAll('button, [role="button"]')].filter(visible) : [];
    el = botones.find((b) => /more_vert|more_horiz/.test(b.textContent || ''))
      || botones.find((b) => /more|m[aá]s|opciones|options|men[uú]/i.test(b.getAttribute('aria-label') || ''))
      || null;
  } else if (paso === 'item') {
    // Las opciones del menú abierto (Flow las pinta en una capa aparte)
    const re = new RegExp(patron, 'i');
    const items = [...document.querySelectorAll('[role="menuitem"], .cdk-overlay-container button, .cdk-overlay-container [role="button"]')].filter(visible);
    el = items.find((b) => re.test((b.textContent || '').replace(/\s+/g, ' '))) || null;
  }
  if (!el) return false;
  el.setAttribute('data-fh-clic', '1');
  return true;
}

// Cierra un menú de Flow que se quedó abierto
function cerrarMenus() {
  const fondo = document.querySelector('.cdk-overlay-backdrop');
  if (fondo) fondo.click();
  document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', code: 'Escape', keyCode: 27, bubbles: true }));
}

// Mundo de la página: guarda los archivos que Flow prepara para descargar
// (URL.createObjectURL) y retrasa que los borre, para poder leerlos después
function espiaDescargas() {
  if (window.__fhBlobs) return;
  window.__fhBlobs = new Map();
  const crear = URL.createObjectURL;
  const revocar = URL.revokeObjectURL;
  URL.createObjectURL = function (o) {
    const u = crear.call(URL, o);
    try { if (o instanceof Blob) window.__fhBlobs.set(u, o); } catch (e) { /* no es un archivo */ }
    return u;
  };
  URL.revokeObjectURL = function (u) {
    setTimeout(() => { window.__fhBlobs.delete(u); revocar.call(URL, u); }, 180000);
  };
}

// Mundo de la página: el archivo de una dirección blob: como data: URL (para
// que la extensión lo suba al panel). null si ya no está.
async function blobComoDataUrl(url) {
  try {
    const b = (window.__fhBlobs && window.__fhBlobs.get(url)) || (await (await fetch(url)).blob());
    return await new Promise((ok, mal) => {
      const r = new FileReader();
      r.onload = () => ok(r.result);
      r.onerror = () => mal(r.error);
      r.readAsDataURL(b);
    });
  } catch (e) {
    return null;
  }
}
