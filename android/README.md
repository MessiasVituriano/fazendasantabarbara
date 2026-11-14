# App Android (APK)

O APK é uma **Trusted Web Activity**: abre `https://159-65-184-65.sslip.io` dentro do Chrome, em tela cheia.
Por isso o NFC (Web NFC) e o modo offline (service worker) funcionam igual ao navegador, e todo deploy
chega ao app sem gerar APK novo. Só é preciso gerar outro APK se mudar nome, ícone, cor ou domínio.

## Gerar

Pré-requisitos: Node e o site já no ar em HTTPS com o `manifest.webmanifest` (deploy desta branch).

```bash
./android/gerar-apk.sh
```

Na primeira vez o `bubblewrap init` pergunta:

| Pergunta | Resposta |
|---|---|
| Install JDK / Android SDK? | Yes (ele baixa sozinho em `~/.bubblewrap`) |
| Domain | `159-65-184-65.sslip.io` |
| Application name / Short name | `Gestão Agropecuária` / `Gestão Agro` |
| Application ID | `io.sslip.gestaoagro` (não muda depois de publicado) |
| Display mode / Orientation | `standalone` / `portrait` |
| Status bar color | `#007A55` |
| Key store location / alias | `./android.keystore` / `android` |
| Senhas | Anote! Sem a chave e a senha não dá para atualizar o app |

Saída:
- `android/app-release-signed.apk`: instale direto no celular (`adb install` ou envie o arquivo).
- `android/app-release-bundle.aab`: para a Play Store, se um dia publicar.

## Depois do primeiro APK

O script grava `frontend/public/.well-known/assetlinks.json` com a impressão digital da chave.
Faça commit e deploy. Enquanto esse arquivo não estiver no ar, o app abre com a barra de endereço do Chrome.
Confira em `https://159-65-184-65.sslip.io/.well-known/assetlinks.json`.

Faça backup do `android/android.keystore` (e da senha) fora do repositório.

O Chrome precisa estar instalado e atualizado no celular.
