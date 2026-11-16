#!/bin/sh
# Instalador do Publicare 4 para terminal (POSIX sh). Equivale ao instalar.php, mas pergunta no terminal.
#
#   mkdir meusite && cd meusite && sh instalar.sh [--beta]
#
# 1. baixa o MESMO instalar.php (https://atualizacoes.publicare.org/instalar.php; só HTTPS) para a pasta atual;
#    se você souber o SHA-256 dele (está no resumo da release), informe em PBL_INSTALAR_SHA256 e ele é conferido antes de rodar;
# 2. roda `php instalar.php`, que confere a ASSINATURA Ed25519 do manifesto (com as chaves embutidas nele) e o SHA-256 do pacote
#    e extrai o Publicare 4 nesta pasta (nada é executado do pacote antes disso);
# 3. roda `php bin/pbl instalar` com as suas respostas. A SENHA nunca vai em argumento: é pedida de forma oculta, ou vem de
#    PBL_ADMIN_SENHA ou da entrada padrão (duas linhas).
#
# Variáveis opcionais (para uso sem teclado): PBL_NOME, PBL_DOMINIO, PBL_ADMIN_LOGIN, PBL_ADMIN_EMAIL, PBL_ADMIN_NOME, PBL_MODELOS,
# PBL_ADMIN_SENHA, PBL_INSTALAR_SHA256, PBL_INSTALAR_URL_BASE (só para testes, e só https).
# Limite honesto: a confiança no próprio instalar.php vem do HTTPS e do SHA-256 publicado; a assinatura Ed25519 protege o pacote.
set -eu

BASE="${PBL_INSTALAR_URL_BASE:-https://atualizacoes.publicare.org}"
CANAL_BETA=""

erro() {
    printf 'Erro: %s\n' "$1" >&2
    exit 1
}

for arg in "$@"; do
    case "$arg" in
        --beta) CANAL_BETA="--beta" ;;
        -h | --ajuda | --help)
            printf 'Uso: sh instalar.sh [--beta]\nInstala o Publicare 4 na pasta atual. Veja docs/usuario/instalar.md.\n'
            exit 0
            ;;
        *) erro "argumento desconhecido: $arg (a senha nunca é aceita por argumento)" ;;
    esac
done

case "$BASE" in
    https://*) ;;
    *) erro "o endereço de download precisa ser https" ;;
esac

command -v php >/dev/null 2>&1 || erro "o PHP não está instalado (ou não está no PATH). Precisa do PHP 8.2 ou mais novo."
php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' || erro "o PHP desta máquina é antigo demais (precisa do 8.2 ou mais novo)."
if [ -e app ] || [ -e dados/config/instalado.lock ]; then
    erro "esta pasta já tem o Publicare. Use uma pasta vazia."
fi

baixar() {
    # $1 = endereço, $2 = arquivo de saída. Só HTTPS, com verificação de certificado.
    if command -v curl >/dev/null 2>&1; then
        curl --proto '=https' --tlsv1.2 --fail --silent --show-error --location --max-redirs 3 --max-time 120 --output "$2" "$1"
    elif command -v wget >/dev/null 2>&1; then
        wget --https-only --quiet --tries=2 --timeout=60 --output-document="$2" "$1"
    else
        erro "preciso do curl ou do wget para baixar."
    fi
}

soma() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | cut -d ' ' -f 1
    elif command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "$1" | cut -d ' ' -f 1
    else
        erro "não achei sha256sum nem shasum para conferir o SHA-256."
    fi
}

TMP="instalar.php.baixando.$$"
trap 'rm -f "$TMP"' EXIT INT TERM
baixar "$BASE/instalar.php" "$TMP" || erro "não foi possível baixar o instalar.php."
if [ -n "${PBL_INSTALAR_SHA256:-}" ]; then
    [ "$(soma "$TMP")" = "$PBL_INSTALAR_SHA256" ] || erro "o SHA-256 do instalar.php baixado não confere com o informado. Nada foi executado."
fi
head -c 5 "$TMP" | grep -q '^<?php' || erro "o arquivo baixado não parece o instalar.php. Nada foi executado."
mv "$TMP" instalar.php

printf 'Conferindo a assinatura e instalando o pacote...\n'
php instalar.php ${CANAL_BETA:+--beta} || erro "a instalação do pacote falhou (veja a mensagem acima). Nada ficou pela metade."
[ -f bin/pbl ] || erro "o pacote não foi extraído."
rm -f instalar.php

perguntar() {
    # $1 = variável, $2 = texto, $3 = padrão. Pergunta só com teclado; sem teclado usa a variável de ambiente ou o padrão.
    valor=$(printenv "$1" 2>/dev/null || true)
    if [ -z "$valor" ] && [ -t 0 ]; then
        printf '%s [%s]: ' "$2" "$3" >&2
        read -r valor || valor=""
    fi
    [ -n "$valor" ] || valor="$3"
    printf '%s' "$valor"
}

NOME=$(perguntar PBL_NOME "Nome do site" "Meu site Publicare")
DOMINIO=$(perguntar PBL_DOMINIO "Endereço (domínio) do site" "localhost")
LOGIN=$(perguntar PBL_ADMIN_LOGIN "Login do administrador" "admin")
EMAIL=$(perguntar PBL_ADMIN_EMAIL "E-mail do administrador" "")
[ -n "$EMAIL" ] || erro "informe o e-mail do administrador (PBL_ADMIN_EMAIL)."
ANOME=$(perguntar PBL_ADMIN_NOME "Nome do administrador" "$LOGIN")
MODELOS=$(perguntar PBL_MODELOS "Modelos opcionais (separados por vírgula, 'todos' ou vazio)" "")

set -- --nome="$NOME" --host="$DOMINIO" --admin-login="$LOGIN" --admin-email="$EMAIL" --admin-nome="$ANOME"
[ -z "$MODELOS" ] || set -- "$@" --modelos="$MODELOS"

if [ -n "${PBL_ADMIN_SENHA:-}" ]; then
    # printf é interno do shell: a senha não aparece na lista de processos.
    printf '%s\n%s\n' "$PBL_ADMIN_SENHA" "$PBL_ADMIN_SENHA" | php bin/pbl instalar "$@"
else
    # No terminal a senha é pedida de forma oculta pelo próprio bin/pbl; sem terminal ela é lida da entrada padrão (duas linhas).
    php bin/pbl instalar "$@"
fi
unset PBL_ADMIN_SENHA

PASTA=$(pwd)
printf '\nPronto! Próximos passos:\n'
printf '  1. Aponte a raiz do site (docroot) para a pasta public/ desta instalação.\n'
printf '  2. Agende o cron (a cada minuto): * * * * * php %s/bin/pbl cron >/dev/null 2>&1\n' "$PASTA"
printf '  3. Entre em https://%s/_pbl/entrar com o login %s.\n' "$DOMINIO" "$LOGIN"
printf '  4. Faça cópia de segurança da pasta dados/. Para atualizar: php bin/pbl atualizar.\n'
