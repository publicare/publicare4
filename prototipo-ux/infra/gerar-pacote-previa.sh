#!/usr/bin/env bash
# Gera o pacote da prévia VSF (protocolo v2, estático): docroot prototipo-ux, entrada index.html, sem PHP.
# Uso: prototipo-ux/infra/gerar-pacote-previa.sh [saida.tar.gz]   (o binário fica fora do Git)
set -euo pipefail
cd "$(dirname "$0")/../.."
saida="${1:-${TMPDIR:-/tmp}/pbl4-prototipo-previa.tar.gz}"
[ -f prototipo-ux/index.html ] || { echo "prototipo-ux/index.html não existe" >&2; exit 1; }
sem=$(grep -L 'name="robots" content="noindex' prototipo-ux/*.html || true)
if [ -n "$sem" ]; then echo "telas sem noindex:" >&2; echo "$sem" >&2; exit 1; fi
tar --sort=name --mtime='2000-01-01' --owner=0 --group=0 --numeric-owner -czf "$saida" \
  prototipo-ux/*.html prototipo-ux/assets
sha256sum "$saida"
echo "pedido: {\"versao\":2,\"modo\":\"estatico\",\"raiz\":\"prototipo-ux\",\"entrada\":\"index.html\",\"php\":false,\"noindex\":true}"
