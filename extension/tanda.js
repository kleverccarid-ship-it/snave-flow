// Modo tanda: la caja de prompt admite varios, uno por linea. Cada
// Alt+Shift+F (o "Rellenar") usa el siguiente que no se haya enviado aun.
// Lo usan el popup y background.js.
//
// En storage.local:
//   prompt   el texto entero de la caja (una o varias lineas)
//   tanda    { hechos: [ ...prompts ya rellenados ] }

function promptsDe(texto) {
  return String(texto || '').split('\n').map((s) => s.trim()).filter(Boolean);
}

// Que prompt toca ahora. Devuelve { lista, hechos, actual, indice } o null si
// no queda ninguno. Un prompt repetido en la lista cuenta cada vez que sale.
async function siguientePrompt() {
  const { prompt, tanda } = await chrome.storage.local.get(['prompt', 'tanda']);
  const lista = promptsDe(prompt);
  const hechos = (tanda && Array.isArray(tanda.hechos)) ? tanda.hechos : [];
  // Si la lista cambio (se editaron lineas), lo hecho que ya no este delante
  // deja de contar: se avanza mientras coincidan
  let i = 0;
  while (i < lista.length && i < hechos.length && lista[i] === hechos[i]) i++;
  if (i >= lista.length) return { lista, hechos: lista.slice(0, i), actual: null, indice: i };
  return { lista, hechos: lista.slice(0, i), actual: lista[i], indice: i };
}

async function marcarHecho(estado) {
  await chrome.storage.local.set({ tanda: { hechos: estado.hechos.concat(estado.actual) } });
}

async function reiniciarTanda() {
  await chrome.storage.local.set({ tanda: { hechos: [] } });
}

// Personaje y estilo fijos: se añaden a cada prompt para que todas las escenas
// salgan coherentes. Vacíos, el prompt queda igual.
function componerPrompt(prompt, { personaje, estilo } = {}) {
  return [personaje, prompt, estilo]
    .map((s) => String(s || '').trim().replace(/[.\s]+$/, ''))
    .filter(Boolean)
    .join('. ');
}
