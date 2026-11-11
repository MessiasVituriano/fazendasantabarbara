import http from './http'

/** Baixa um arquivo da API (com o token e a fazenda da sessão) e salva com o nome enviado pelo servidor. */
export async function baixarArquivo(url, nomePadrao = 'arquivo') {
  const resposta = await http.get(url, { responseType: 'blob' })
  const disposicao = resposta.headers['content-disposition'] ?? ''
  const nome = decodeURIComponent(/filename\*=UTF-8''([^;]+)/i.exec(disposicao)?.[1] ?? /filename="?([^";]+)"?/i.exec(disposicao)?.[1] ?? nomePadrao)
  const link = Object.assign(document.createElement('a'), { href: URL.createObjectURL(resposta.data), download: nome })
  document.body.append(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(link.href), 1000)
}
