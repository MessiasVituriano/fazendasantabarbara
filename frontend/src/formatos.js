export const dataBr = (iso) => (iso ? iso.slice(0, 10).split('-').reverse().join('/') : '—')
export const hoje = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10)
export const numero = (v, casas = 2) =>
  v == null ? '—' : Number(v).toLocaleString('pt-BR', { minimumFractionDigits: casas, maximumFractionDigits: casas })
export const novoUuid = () => crypto.randomUUID()

/** Idade legível a partir da data de nascimento: "18 dias", "7 meses", "2 anos e 3 meses". */
export function idade(nascimento) {
  if (!nascimento) return '—'
  const [a, m, d] = nascimento.slice(0, 10).split('-').map(Number)
  const agora = new Date()
  let meses = (agora.getFullYear() - a) * 12 + (agora.getMonth() + 1 - m)
  if (agora.getDate() < d) meses--
  if (meses < 1) {
    const dias = Math.max(0, Math.floor((agora - new Date(a, m - 1, d)) / 86400000))
    return `${dias} dia${dias === 1 ? '' : 's'}`
  }
  if (meses < 12) return `${meses} ${meses === 1 ? 'mês' : 'meses'}`
  const anos = Math.floor(meses / 12)
  const resto = meses % 12
  const txtAnos = `${anos} ano${anos === 1 ? '' : 's'}`
  return resto ? `${txtAnos} e ${resto} ${resto === 1 ? 'mês' : 'meses'}` : txtAnos
}

/**
 * Reduz a foto no navegador antes do envio (maior lado <= ladoMaximo, JPEG).
 * Fotos de celular de 4–12 MB viram ~300–600 KB: upload rápido no campo e dentro do limite do PHP.
 * createImageBitmap já aplica a rotação EXIF.
 */
export async function comprimirImagem(arquivo, ladoMaximo = 1600, qualidade = 0.85) {
  const bitmap = await createImageBitmap(arquivo, { imageOrientation: 'from-image' })
  const fator = Math.min(1, ladoMaximo / Math.max(bitmap.width, bitmap.height))
  const canvas = document.createElement('canvas')
  canvas.width = Math.round(bitmap.width * fator)
  canvas.height = Math.round(bitmap.height * fator)
  canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height)
  bitmap.close()
  const blob = await new Promise((ok) => canvas.toBlob(ok, 'image/jpeg', qualidade))
  return new File([blob], arquivo.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' })
}

/** Telefone para exibição: "11987654321" → "(11) 98765-4321"; "+5535..." → "+55 (35) ...". */
export function formatarTelefone(valor) {
  if (!valor) return ''
  let d = String(valor).replace(/\D/g, '')
  let ddi = ''
  if (String(valor).startsWith('+') && d.length >= 12) {
    ddi = `+${d.slice(0, 2)} ` // DDI de 2 dígitos (Brasil: 55)
    d = d.slice(2)
  }
  return ddi + mascaraTelefone(d)
}

/** Máscara enquanto digita (sem DDI): (11) 9876-5432 ou (11) 98765-4321. */
export function mascaraTelefone(texto) {
  const d = String(texto ?? '').replace(/\D/g, '').slice(0, 11)
  if (!d) return ''
  if (d.length <= 2) return `(${d}`
  const meio = d.length > 10 ? 5 : 4
  return `(${d.slice(0, 2)}) ${d.slice(2, 2 + meio)}${d.length > 2 + meio ? `-${d.slice(2 + meio)}` : ''}`
}

/** Moeda: "R$ 1.234,56". Arredondamento só na exibição; os cálculos vêm prontos da API. */
const _moeda = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' })
export const moeda = (v) => (v == null || v === '' ? '—' : _moeda.format(Number(v)))

/** Moeda compacta para eixos de gráfico: "R$ 12 mil", "R$ 1,2 mi". */
export function moedaCompacta(v) {
  const n = Number(v) || 0
  const abs = Math.abs(n)
  if (abs >= 1e6) return `R$ ${numero(n / 1e6, abs % 1e6 ? 1 : 0)} mi`
  if (abs >= 1e3) return `R$ ${numero(n / 1e3, abs % 1e3 && abs < 1e4 ? 1 : 0)} mil`
  return `R$ ${numero(n, 0)}`
}

/** "2026-11" → "nov/26". */
export const mesCurto = (aaaamm) => {
  const [a, m] = aaaamm.split('-').map(Number)
  return `${['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'][m - 1]}/${String(a).slice(2)}`
}

/** Converte o que o usuário digitou ("1.234,56" ou "1234.56") em string decimal para a API. */
export function valorParaApi(texto) {
  const s = String(texto ?? '').trim()
  if (!s) return null
  return s.includes(',') ? s.replace(/\./g, '').replace(',', '.') : s
}

/** Soma/subtrai meses de uma data AAAA-MM-DD, devolvendo o 1º ou o último dia do mês. */
export function inicioDoMes(iso = hoje(), deslocamento = 0) {
  const [a, m] = iso.slice(0, 7).split('-').map(Number)
  const d = new Date(a, m - 1 + deslocamento, 1)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`
}
export function fimDoMes(iso = hoje(), deslocamento = 0) {
  const [a, m] = iso.slice(0, 7).split('-').map(Number)
  const d = new Date(a, m + deslocamento, 0)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
