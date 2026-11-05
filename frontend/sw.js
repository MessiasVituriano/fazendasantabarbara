/* Service worker do painel: guarda o app para abrir sem internet.
 * Gerado no build (vite.config.js) com a lista de arquivos e a versão do deploy.
 * A API não passa por aqui: as leituras e a fila offline ficam no localStorage (src/offline). */
const VERSAO = '__VERSAO__'
const ARQUIVOS = __ARQUIVOS__
const CACHE = `app-${VERSAO}`
const TEMPO_LIMITE_MS = 4000

self.addEventListener('install', (evento) => {
  evento.waitUntil(caches.open(CACHE).then((c) => c.addAll(ARQUIVOS)).then(() => self.skipWaiting()))
})

self.addEventListener('activate', (evento) => {
  evento.waitUntil(caches.keys()
    .then((nomes) => Promise.all(nomes.filter((n) => n.startsWith('app-') && n !== CACHE).map((n) => caches.delete(n))))
    .then(() => self.clients.claim()))
})

const comTempoLimite = (promessa) => Promise.race([
  promessa,
  new Promise((_, rejeitar) => setTimeout(() => rejeitar(new Error('tempo esgotado')), TEMPO_LIMITE_MS)),
])

self.addEventListener('fetch', (evento) => {
  const req = evento.request
  const url = new URL(req.url)
  if (req.method !== 'GET' || url.origin !== self.location.origin) return
  if (/^\/(api|up|storage)(\/|$)/.test(url.pathname)) return

  // Telas (rotas do Vue): rede primeiro, para pegar o deploy novo; sem rede, o index.html guardado.
  if (req.mode === 'navigate') {
    evento.respondWith(comTempoLimite(fetch(req)).catch(() => caches.match('/index.html', { ignoreVary: true })))
    return
  }

  // JS, CSS, fontes e imagens do app: cache primeiro (os nomes têm hash do build).
  // ignoreVary: o servidor pode responder com "Vary: Origin" e os scripts do Vite vão com crossorigin.
  evento.respondWith(caches.match(req, { ignoreVary: true }).then((guardada) => guardada || fetch(req).then((resposta) => {
    if (resposta.ok) {
      const copia = resposta.clone()
      caches.open(CACHE).then((c) => c.put(req, copia))
    }
    return resposta
  })))
})
