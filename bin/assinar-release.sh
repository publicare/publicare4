#!/usr/bin/env bash
# DONO, na máquina dele: assina e publica o rascunho de uma release criado pelo CI (docs/releases.md, ADR 0019).
#
#   bin/assinar-release.sh vX.Y.Z --chave-privada=/caminho/uso.privada [--repo=dono/repo] [--sim]
#
# 1. confere que a release é um RASCUNHO e baixa o manifesto (latest.json; latest-beta.json no canal beta) e o .zip dele (gh release download);
# 2. confere versão, tamanho e SHA-256 do .zip contra o manifesto;
# 3. assina o latest.json com `php bin/pbl assinar` (a privada vem só de um ARQUIVO local; nunca de variável de ambiente);
# 4. confere a assinatura contra as chaves públicas embutidas (`php bin/pbl verificar-assinatura`); se não confere, para sem subir nada;
# 5. sobe latest.json e latest.json.sig ao rascunho e só então o publica (o manifesto por último).
# A chave privada nunca é impressa nem copiada; os arquivos temporários (inclusive a .sig) são apagados ao sair.
set -euo pipefail

raiz="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# PBL_BIN existe só para os testes automatizados do próprio script (substitui `php bin/pbl`).
pbl() { if [ -n "${PBL_BIN:-}" ]; then "$PBL_BIN" "$@"; else php "$raiz/bin/pbl" "$@"; fi; }
die() { echo "Erro: $*" >&2; exit 1; }

tag="" chave="" repo="" sim=0
for a in "$@"; do
  case "$a" in
    --chave-privada=*) chave="${a#*=}" ;;
    --repo=*) repo="${a#*=}" ;;
    --sim) sim=1 ;;
    -h|--help) sed -n 2,13p "${BASH_SOURCE[0]}"; exit 0 ;;
    -*) die "opção desconhecida: $a" ;;
    *) [ -z "$tag" ] || die "informe uma só tag"; tag="$a" ;;
  esac
done

[[ "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]] || die "informe a tag no formato vX.Y.Z (ou vX.Y.Z-rc.1)"
[ -n "$chave" ] || die "informe --chave-privada=ARQUIVO (a chave nunca vem de variável de ambiente nem da linha de comando)"
[ -f "$chave" ] || die "o arquivo da chave privada não existe"
command -v gh >/dev/null || die "gh (GitHub CLI) não encontrado"
command -v php >/dev/null || die "php não encontrado"
perm="$(stat -c '%a' "$chave" 2>/dev/null || stat -f '%Lp' "$chave")"
case "$perm" in *00) ;; *) echo "Aviso: a chave está com permissão $perm; use chmod 600." >&2 ;; esac

ghr=(gh)
[ -z "$repo" ] || ghr=(gh --repo "$repo")
gh_() { "${ghr[@]}" "$@"; }

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

[ "$(gh_ release view "$tag" --json isDraft -q .isDraft)" = "true" ] || die "a release $tag não é um rascunho (já publicada ou inexistente): recuso assinar"
prerelease="$(gh_ release view "$tag" --json isPrerelease -q .isPrerelease)"

gh_ release download "$tag" -p 'latest*.json' -p 'pbl4-*.zip' -D "$tmp"
# o manifesto do canal estável é latest.json; o do beta, latest-beta.json
manifestos=("$tmp"/latest*.json)
[ "${#manifestos[@]}" -eq 1 ] && [ -f "${manifestos[0]}" ] || die "o rascunho deve ter exatamente um manifesto (latest.json ou latest-beta.json)"
manifesto="${manifestos[0]}"

# versão, nome, tamanho e hash do pacote, lidos do manifesto, conferidos contra o .zip baixado
php -r '
  $m = json_decode((string) file_get_contents($argv[3]), true, 512, JSON_THROW_ON_ERROR);
  $zip = $argv[1] . "/" . basename((string) ($m["pacote"]["url"] ?? ""));
  if ($m["versao"] !== substr($argv[2], 1)) { fwrite(STDERR, "Erro: o manifesto é da versão {$m["versao"]}, não da tag.\n"); exit(1); }
  if (!is_file($zip)) { fwrite(STDERR, "Erro: o rascunho não tem o pacote {$m["pacote"]["url"]}.\n"); exit(1); }
  if (filesize($zip) !== (int) $m["pacote"]["tamanho"] || !hash_equals((string) $m["pacote"]["sha256"], hash_file("sha256", $zip))) {
    fwrite(STDERR, "Erro: o .zip do rascunho não confere com o manifesto (tamanho ou SHA-256).\n"); exit(1);
  }
  echo "Manifesto da versão {$m["versao"]} (canal {$m["canal"]}); o pacote confere.\n";
' "$tmp" "$tag" "$manifesto"

pbl assinar "$manifesto" --chave-privada="$chave" >/dev/null || die "não consegui assinar"
canal="$(php -r '$m = json_decode((string) file_get_contents($argv[1]), true); echo $m["canal"];' "$manifesto")"
pbl verificar-assinatura "$manifesto" --canal="$canal" || die "a assinatura não confere com as chaves embutidas em Chaves.php: nada foi publicado"

if [ "$sim" -ne 1 ]; then
  read -r -p "Publicar $tag agora? [s/N] " r
  [ "$r" = "s" ] || [ "$r" = "S" ] || die "cancelado; o rascunho continua como estava"
fi

gh_ release upload "$tag" "$manifesto" "$manifesto.sig" --clobber
if [ "$prerelease" = "true" ]; then
  gh_ release edit "$tag" --draft=false --prerelease
else
  gh_ release edit "$tag" --draft=false --latest
fi
echo "Release $tag publicada. Confira o endereço de canal das instalações (docs/tecnica/atualizacao.md)."
