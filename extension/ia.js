// Guion -> escenas: una IA divide un guion en N prompts de escena, uno por
// línea, listos para la tanda. Usa la API key de la persona; la llamada sale
// del service worker, que tiene permiso para esos dominios.

// Modelo por defecto de cada proveedor si el campo "Modelo" se deja vacío
const IA_MODELOS = {
  claude: 'claude-opus-5',
  openai: 'gpt-5-mini',
  gemini: 'gemini-2.5-flash',
};

function instruccionesEscenas({ guion, escenas, personaje }) {
  return [
    `Divide este guion en exactamente ${escenas} escenas para generar un video corto por escena con Google Veo (unos 8 segundos cada una).`,
    'Cada escena es un prompt visual que se entiende solo: sujeto, acción, lugar, plano de cámara y luz. Una o dos frases, sin numerar.',
    'Escribe los prompts en el mismo idioma que el guion. Mantén el orden de la historia.',
    personaje
      ? `El personaje ya se describe aparte en cada prompt ("${personaje}"): refiérete a él sin repetir su descripción.`
      : null,
    'Responde solo con JSON de esta forma: {"escenas": ["...", "..."]}',
    '',
    'GUION:',
    guion,
  ].filter((l) => l !== null).join('\n');
}

const ESQUEMA_ESCENAS = {
  type: 'object',
  properties: { escenas: { type: 'array', items: { type: 'string' } } },
  required: ['escenas'],
  additionalProperties: false,
};

async function pedirJSON(res) {
  const body = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error((body.error && (body.error.message || body.error)) || `HTTP ${res.status}`);
  return body;
}

async function llamarClaude(clave, modelo, texto) {
  // fallbacks "default": si un filtro de seguridad rechaza la petición, la API
  // la repite sola con otro modelo. Solo existe en los modelos que lo admiten.
  const conFallback = /^claude-(opus-5|fable-5-1)$/.test(modelo);
  const res = await fetch('https://api.anthropic.com/v1/messages', {
    method: 'POST',
    headers: {
      'content-type': 'application/json',
      'x-api-key': clave,
      'anthropic-version': '2023-06-01',
      // La petición sale de una extensión, que Anthropic trata como navegador
      'anthropic-dangerous-direct-browser-access': 'true',
      ...(conFallback ? { 'anthropic-beta': 'server-side-fallback-2026-07-01' } : {}),
    },
    body: JSON.stringify({
      model: modelo,
      max_tokens: 16000,
      ...(conFallback ? { fallbacks: 'default' } : {}),
      output_config: { effort: 'low', format: { type: 'json_schema', schema: ESQUEMA_ESCENAS } },
      messages: [{ role: 'user', content: texto }],
    }),
  });
  const body = await pedirJSON(res);
  if (body.stop_reason === 'refusal') throw new Error('Claude rechazó este guion.');
  return (body.content || []).filter((b) => b.type === 'text').map((b) => b.text).join('');
}

async function llamarOpenAI(clave, modelo, texto) {
  const res = await fetch('https://api.openai.com/v1/chat/completions', {
    method: 'POST',
    headers: { 'content-type': 'application/json', authorization: 'Bearer ' + clave },
    body: JSON.stringify({
      model: modelo,
      response_format: { type: 'json_object' },
      messages: [{ role: 'user', content: texto }],
    }),
  });
  const body = await pedirJSON(res);
  return body.choices?.[0]?.message?.content || '';
}

async function llamarGemini(clave, modelo, texto) {
  const url = `https://generativelanguage.googleapis.com/v1beta/models/${encodeURIComponent(modelo)}:generateContent`;
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'content-type': 'application/json', 'x-goog-api-key': clave },
    body: JSON.stringify({
      contents: [{ parts: [{ text: texto }] }],
      generationConfig: { responseMimeType: 'application/json' },
    }),
  });
  const body = await pedirJSON(res);
  return (body.candidates?.[0]?.content?.parts || []).map((p) => p.text || '').join('');
}

// Devuelve la lista de prompts. Cada uno en una sola línea, porque la tanda
// separa los prompts por saltos de línea.
async function guionAEscenas({ proveedor, clave, modelo, guion, escenas, personaje }) {
  const llamar = { claude: llamarClaude, openai: llamarOpenAI, gemini: llamarGemini }[proveedor];
  if (!llamar) throw new Error('Proveedor de IA desconocido.');
  const texto = await llamar(clave, modelo || IA_MODELOS[proveedor], instruccionesEscenas({ guion, escenas, personaje }));

  const json = (texto.match(/\{[\s\S]*\}|\[[\s\S]*\]/) || [])[0];
  let datos;
  try { datos = JSON.parse(json); } catch { throw new Error('La IA no devolvió una lista de escenas válida. Prueba otra vez.'); }
  const lista = (Array.isArray(datos) ? datos : datos.escenas || [])
    .filter((s) => typeof s === 'string')
    .map((s) => s.replace(/\s+/g, ' ').trim())
    .filter(Boolean);
  if (!lista.length) throw new Error('La IA devolvió cero escenas.');
  return lista;
}
