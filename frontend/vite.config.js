import { createHash } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

/** Gera o /sw.js com a lista de arquivos do build, para o app abrir sem internet. */
function serviceWorker() {
  return {
    name: 'service-worker',
    apply: 'build',
    enforce: 'post',
    generateBundle(_, bundle) {
      const doBuild = Object.keys(bundle).filter((f) => !f.endsWith('.map') && f !== 'index.html').map((f) => `/${f}`)
      const daPasta = ['/favicon.svg', '/manifest.webmanifest', '/icones/icone.svg', '/icones/icone-192.png']
      const arquivos = ['/', '/index.html', ...daPasta, ...doBuild]
      // Os arquivos de public/ não têm hash no nome: o conteúdo entra na versão, senão o cache antigo
      // continua servindo, por exemplo, o manifest com a orientação velha.
      const hash = createHash('sha256').update(arquivos.join('|'))
      for (const f of daPasta) hash.update(readFileSync(fileURLToPath(new URL(`./public${f}`, import.meta.url))))
      const versao = hash.digest('hex').slice(0, 12)
      const modelo = readFileSync(fileURLToPath(new URL('./sw.js', import.meta.url)), 'utf8')
      this.emitFile({
        type: 'asset',
        fileName: 'sw.js',
        source: modelo.replace('__VERSAO__', versao).replace('__ARQUIVOS__', JSON.stringify(arquivos)),
      })
    },
  }
}

export default defineConfig({
  plugins: [vue(), tailwindcss(), serviceWorker()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  server: {
    port: 5173,
    proxy: { '/api': 'http://127.0.0.1:8000' },
  },
})
