#!/usr/bin/env bash
# Gera o APK do painel (Trusted Web Activity: o app abre o site dentro do Chrome do Android).
# Na primeira vez o Bubblewrap baixa o JDK e o Android SDK e faz perguntas (veja android/README.md).
set -euo pipefail
cd "$(dirname "$0")"

DOMINIO="${DOMINIO:-159-65-184-65.sslip.io}"

# npx roda o Bubblewrap sem instalar nada global (npm install -g pede sudo no Mac).
bubblewrap() { npx -y @bubblewrap/cli "$@"; }

if [ ! -f twa-manifest.json ]; then
  bubblewrap init --manifest "https://${DOMINIO}/manifest.webmanifest"
fi

bubblewrap build

# assetlinks.json: prova para o Android que o app e o site são do mesmo dono (sem ela aparece a barra do Chrome).
bubblewrap fingerprint generateAssetLinks --output=../frontend/public/.well-known/assetlinks.json

echo
echo "APK pronto: android/app-release-signed.apk"
echo "Faça commit de frontend/public/.well-known/assetlinks.json e deploy (só precisa na primeira vez ou se trocar a chave)."
