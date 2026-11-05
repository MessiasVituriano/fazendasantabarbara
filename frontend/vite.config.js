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
      const arquivos = ['/', '/index.html', '/favicon.svg', ...doBuild]
      const versao = createHash('sha256').update(arquivos.join('|')).digest('hex').slice(0, 12)
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
