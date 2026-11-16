#!/usr/bin/env bash
# Confere a tag de release contra Versao.php e o CHANGELOG e decide canal e marca de segurança.
# Uso: conferir-release.sh TAG [RAIZ_DO_PROJETO] [ARQUIVO_DE_NOTAS]
# Saída (stdout), uma por linha: versao=X.Y.Z, canal=estavel|beta, seguranca=true|false. Erro: mensagem em stderr e código 1.
# Não usa segredo nenhum. A tag chega por argumento (nunca interpolada no YAML).
set -euo pipefail

tag="${1:-}"
raiz="${2:-.}"
notas="${3:-}"

if ! [[ "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]]; then
  echo "Tag inválida: use vX.Y.Z ou vX.Y.Z-rc.1 / -beta.1." >&2
  exit 1
fi
versao="${tag#v}"

atual="$(sed -n "s/.*const ATUAL = '\([^']*\)'.*/\1/p" "$raiz/app/src/Versao.php" | head -n1)"
if [ "$atual" != "$versao" ]; then
  echo "A tag $tag não bate com app/src/Versao.php (${atual:-vazio}). Ajuste a versão antes de marcar." >&2
  exit 1
fi

secao="$(awk -v v="$versao" '
  index($0, "## [" v "]") == 1 { dentro = 1; print; next }
  dentro && /^## / { exit }
  dentro { print }
' "$raiz/CHANGELOG.md")"
if [ -z "$secao" ]; then
  echo "CHANGELOG.md não tem a seção '## [$versao]'." >&2
  exit 1
fi
if [ -n "$notas" ]; then
  printf '%s\n' "$secao" > "$notas"
fi

canal=estavel
if [[ "$versao" == *-* ]]; then
  canal=beta
fi

# "### Segurança" com pelo menos uma linha de conteúdo marca a versão como correção de segurança
seguranca=false
if printf '%s\n' "$secao" | awk '
  /^### Seguran/ { dentro = 1; next }
  dentro && /^#{2,3} / { exit }
  dentro && NF { achou = 1 }
  END { exit achou ? 0 : 1 }'; then
  seguranca=true
fi

echo "versao=$versao"
echo "canal=$canal"
echo "seguranca=$seguranca"
