/**
 * Cache das leituras da API (GET) no localStorage, para as telas abrirem sem internet
 * com os últimos dados vistos neste aparelho. Guarda as respostas mais recentes e,
 * se o espaço acabar, descarta as mais antigas.
 */
const PREFIXO = 'cache-api:'
const MAX_ENTRADAS = 80

const chaves = () => Object.keys(localStorage).filter((k) => k.startsWith(PREFIXO))

function lerEntrada(chave) {
  try { return JSON.parse(localStorage.getItem(PREFIXO + chave)) } catch { return null }
}

function removerMaisAntigas(quantas) {
  const ordenadas = chaves()
    .map((k) => ({ k, em: (() => { try { return JSON.parse(localStorage.getItem(k))?.em ?? 0 } catch { return 0 } })() }))
    .sort((a, b) => a.em - b.em)
  ordenadas.slice(0, quantas).forEach(({ k }) => localStorage.removeItem(k))
  return ordenadas.length > 0
}

export function guardarLeitura(chave, dados) {
  const valor = JSON.stringify({ em: Date.now(), dados })
  try {
    const existentes = chaves()
    if (existentes.length >= MAX_ENTRADAS && !existentes.includes(PREFIXO + chave)) removerMaisAntigas(existentes.length - MAX_ENTRADAS + 1)
    for (let tentativa = 0; tentativa < 5; tentativa++) {
      try { return localStorage.setItem(PREFIXO + chave, valor) } catch {
        if (!removerMaisAntigas(10)) return // sem espaço nem o que descartar
      }
    }
  } catch { /* sem storage: segue sem cache */ }
}

/** @returns {{ em: number, dados: any } | null} */
export const lerLeitura = (chave) => { try { return lerEntrada(chave) } catch { return null } }

export function limparLeituras() {
  try { chaves().forEach((k) => localStorage.removeItem(k)) } catch { /* sem storage */ }
}
