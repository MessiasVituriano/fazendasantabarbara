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
