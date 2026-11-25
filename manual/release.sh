#!/bin/sh
# Passo que o workflow de release chama (a cada tag v*): compila docs/usuario e carrega no site do manual.
# Uso: manual/release.sh <tag> [pasta-de-capturas]
#   MANUAL_SITE   domínio ou número do site do manual (obrigatório)
#   MANUAL_CONTA  login da conta de serviço que publica (padrão: manual-bot)
# Tag sem hífen (estável): guarda /<versão>/ e atualiza a raiz. Tag com hífen (pré-release): guarda /<versão>/ e atualiza /beta/.
# Sem segredos aqui: a conta é só um login; quem instala o workflow cuida do acesso ao servidor.
set -eu
TAG="${1:?uso: manual/release.sh <tag> [pasta-de-capturas]}"
VERSAO="${TAG#v}"
CAPTURAS="${2:-}"
SITE="${MANUAL_SITE:?defina MANUAL_SITE (domínio ou número do site do manual)}"
CONTA="${MANUAL_CONTA:-manual-bot}"
cd "$(dirname "$0")/.."

if [ -n "$CAPTURAS" ]; then
  exec php bin/pbl manual-gerar --site="$SITE" --como="$CONTA" --edicao=auto --versao="$VERSAO" --capturas="$CAPTURAS" --confirmar
fi
exec php bin/pbl manual-gerar --site="$SITE" --como="$CONTA" --edicao=auto --versao="$VERSAO" --confirmar
