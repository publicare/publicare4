# Instalação e hospedagem

Para quem instala em um servidor. Quem só quer um passo a passo simples: [Como instalar](../usuario/instalar.md).

**Antes de instalar,** abrir o site mostra uma página em português (status 503, sem login) que explica as formas de instalar: `instalar.php`, `bin/pbl instalar` e o assistente em `/_pbl/instalar`. Se a trava de instalação existir mas o banco sumir, a página **não** manda instalar: mostra uma indisponibilidade genérica.

## Requisitos

PHP 8.2+ com `pdo_sqlite` (pacote `php8.x-sqlite3` em Debian/Ubuntu), `mbstring`, `sodium`, `json`, `ctype`, `dom` (para limpar o HTML dos campos) e `fileinfo` (para identificar de verdade o tipo de um arquivo enviado); pasta `dados/` gravável e dentro do `open_basedir` do PHP, se houver. **GD é opcional**: sem ela, upload de imagem ainda funciona (com uma verificação mais fraca, sem recodificar) e não há miniaturas. O 0.1.0 usa só SQLite (o instalador cria sempre um arquivo SQLite). MySQL e PostgreSQL chegam na 0.2, conforme o [ADR 0028](../adr/0028-suporte-mysql-postgresql.md).

## Formas de instalar

| Forma | Quando | Como |
|---|---|---|
| **`instalar.php`** (etapa 1) + assistente (etapa 2) | hospedagem comum, só FTP/painel | envie o `instalar.php`, crie `instalar.liberar` ao lado e abra no navegador; ele leva ao assistente |
| **`instalar.sh`** | terminal simples | `sh instalar.sh` numa pasta vazia: baixa o `instalar.php`, confere a assinatura e roda `bin/pbl instalar` |
| **Assistente no navegador** (já com o código no servidor) | código enviado inteiro (zip do repositório, Git) | `https://site/_pbl/instalar` depois de criar `dados/instalar.liberar` (prova de posse) |
| **Linha de comando** | quem tem terminal e o código | `php bin/pbl instalar --nome=… --host=… --admin-login=… --admin-email=…` (a senha é pedida de forma oculta) |

O instalador é **atômico**: se algo falhar, o banco volta ao estado limpo e dá para tentar de novo. Ao terminar, grava `dados/config/instalado.lock`, publica as páginas de exemplo e some (a rota passa a dar 404).

## Instalação em 2 etapas

### Etapa 1: `instalar.php` (arquivo único)

Fonte: `infra/instalador/instalar.php` (sintaxe de PHP 7.4 de propósito, para um PHP antigo mostrar "seu PHP é velho" em vez de erro de sintaxe). **Sem Composer, sem dependências.** Sequência:

1. **Requisitos** (checklist em português, com "como corrigir"): PHP ≥ 8.2; `pdo_sqlite`, `mbstring`, `sodium`, `json`, `ctype`, `dom`, `fileinfo` (a lista é a de `Requisitos::EXTENSOES`, conferida por teste); escrita na pasta; `open_basedir`; `curl` ou `allow_url_fopen`+`openssl`. A extensão `zip` é opcional: o instalador tem leitor de zip próprio (só `gzinflate`).
2. **Manifesto assinado:** baixa `https://atualizacoes.publicare.org/latest.json` (estável) ou `latest-beta.json` (só se pedido) e o `.sig`, e verifica a assinatura **Ed25519** com `PBLX_CHAVES_USO` e `PBLX_CHAVES_RECUPERACAO` embutidas. **Chaves vindas do manifesto (`chaves.uso`, `chaves.revogadas`) são ignoradas**: trocar de chave exige um `instalar.php` novo. As regras de validação são as de `Manifesto::analisar`.
3. **Pacote:** baixa o `.zip` (só HTTPS, certificado verificado, mesmo host, no máximo 3 redirecionamentos, limite de bytes) e confere **tamanho e SHA-256** do manifesto antes de abrir.
4. **Extração** em `.pbl-instalar-tmp-<aleatório>/` dentro da própria pasta: recusa `..`, caminho absoluto, `\`, byte nulo, link simbólico, nome fora de `Pacote::ITENS`, nomes repetidos, bomba de zip (o tamanho declarado é imposto na descompressão), criptografia e zip64; cada arquivo é conferido contra o `PACOTE.json`.
5. **Mover** os itens com `rename`, com log de desfazer: falhou em qualquer passo, desfaz o que moveu e apaga o temporário. Um `.htaccess` (ou `composer.json` etc.) já existente é guardado como `*.antes-pbl4`. Se já existe `app/`, `vendor/`, `bin/` ou `public/`, recusa.
6. **Layout e proteção:** cria `dados/` com **0711** (o servidor web atravessa a pasta para ler `dados/.htaccess`, mas ninguém a lista; `config/`, `cache/`, `uploads/` e `peles/` ficam 0750, o banco e o segredo 0600) e o `dados/.htaccess` (0644), grava `dados/config/atualizacao.json` (veja abaixo) e passa a prova de posse para `dados/instalar.liberar`. Depois **pergunta ao próprio site** (por HTTP, só o código de resposta) se `/app/src/Versao.php` e `/dados/…` abrem; se abrir, **bloqueia** o avanço e explica (docroot em `public/`, regras de nginx); se não conseguir testar, avisa. Detecta Hestia (`public_html` com `private/` ao lado) e avisa; **nunca escreve fora da pasta do site**.
7. **Apaga a si mesmo** e leva ao assistente (`/_pbl/instalar`). Se o `unlink` falhar, diz para apagar à mão.

**`dados/config/atualizacao.json`.** A instalação grava o endereço e o canal que ELA usou para baixar, para a tela *Atualização* já nascer configurada: `{"url": "https://atualizacoes.publicare.org/latest.json", "canal": "estavel", "verificar": true}` (beta: `latest-beta.json` e `"canal": "beta"`). Quem grava: `instalar.php` (web e terminal, `pblx_gravar_atualizacao`, com a mesma URL de `pblx_baixar_pacote`) e, para `bin/pbl instalar`, o assistente e a instalação por git, `Instalador::instalar` via `Configuracao::garantirPadrao` (estável por padrão). Nunca sobrescreve um arquivo existente; só URL e canal, nada secreto.

**Modo de `dados/` e `.htaccess`.** Em hospedagem comum o Apache roda como outro usuário. Com `dados/` em 0750 ele não lê `dados/.htaccess` e registra `AH00529` a cada pedido por `/dados`. Por isso `dados/` é 0711 (e o `.htaccess` 0644), e o banco SQLite é 0600 (não depende do modo da pasta). A alternativa mais restritiva é manter 0750 e entregar `dados/` ao grupo do servidor web (`chgrp <grupo-do-apache> dados`). A tela final e o terminal avisam (`pblx_htaccess_dados_legivel`) se o `.htaccess` não tem leitura para outros ou `dados/` não tem travessia para outros; é só aviso (se o grupo estiver certo, ignore). A regra que decide `exposto` não mudou.

Segurança do `instalar.php`:

- **Prova de posse própria:** qualquer ação que grava exige o arquivo vazio `instalar.liberar` ao lado dele (a página de requisitos é só leitura). Sem isso, qualquer um que alcançasse a URL entre o envio e a instalação poderia instalar. O arquivo é movido para `dados/instalar.liberar`, a posse da etapa 2.
- CSRF sem sessão (cookie `Secure; HttpOnly; SameSite=Strict` + campo oculto, `hash_equals`) e conferência de `Origin` (ou `Referer`) contra o `Host`: `Origin` com host manda (de outro site, recusa mesmo que o `Referer` confira); `Origin` vazio ou `null` cai no `Referer` do mesmo host; sem nenhum dos dois, recusa. O instalador envia `Referrer-Policy: same-origin` (com `no-referrer` os navegadores mandam `Origin: null` e nenhum `Referer` em formulários POST, e todo pedido seria recusado).
- Nenhum `eval`/`exec`/`include` de conteúdo baixado; nada do pacote é executado antes de a assinatura conferir; mensagens de erro nunca trazem caminhos do servidor; `set_time_limit` e `memory_limit` ajustados; só `--beta` como argumento no terminal.
- **Chaves não são parâmetro.** O arquivo distribuído é **gerado** do modelo por `bin/gerar-instalador` (`GeradorDoInstalador::producao`), que reescreve o bloco `CONFIG` com `Chaves::USO`/`Chaves::RECUPERACAO`. `bin/gerar-instalador --conferir` (no CI e no `release.yml`) falha se o modelo commitado diverge. Os testes usam `GeradorDoInstalador::paraTeste`, que gera um arquivo temporário com um par de chaves de **teste** e um transporte que lê de uma pasta de fixtures; isso nunca é distribuído.
- Limite honesto: quem baixa o `instalar.php` confia no HTTPS e no SHA-256 publicado (resumo da release e campo `instaladores` do manifesto assinado). A assinatura Ed25519 protege o **pacote**. O `instalar.php` por si só não tem como provar a própria autenticidade.
- A sondagem de exposição usa HTTPS sem verificar o certificado (o certificado do próprio site pode ainda ser autoassinado) e só olha o código de resposta; nada do que volta é usado.

Terminal: `php instalar.php [--beta]` faz as mesmas etapas 1 a 5, sem posse nem CSRF (quem está no terminal já tem acesso), cria `dados/` e se apaga.

### `instalar.sh`

POSIX sh (`set -eu`, `shellcheck -s sh` no release). Baixa o **mesmo** `instalar.php` (curl `--proto '=https'` ou `wget --https-only`), confere o SHA-256 se `PBL_INSTALAR_SHA256` estiver definido, roda `php instalar.php` (que confere a assinatura) e depois `php bin/pbl instalar` com as respostas. **Senha:** prompt oculto do próprio `bin/pbl`, ou `PBL_ADMIN_SENHA`, ou entrada padrão (duas linhas); nunca por argumento. Sem `eval`, sem `curl | sh`.

### Etapa 2: assistente web (`/_pbl/instalar`)

Seis passos: *Boas-vindas e requisitos → Site: nome e domínio → Administrador → Modelos opcionais → Revisão e instalar → Pronto* (`PassosDoInstalador`). Mantém a prova de posse, o CSRF e a conferência de `Origin`.

- **Um formulário só**, validado no servidor. `public/_pbl/assets/pbl-instalar.js` (sem script nem estilo em linha: a CSP é `script-src 'self'`) mostra um painel por vez, com Voltar/Continuar, foco no título do passo, revisão montada com `textContent` e medidor de força da senha (só orienta; a regra é `SenhaPolitica`). **Sem JavaScript todos os passos aparecem de uma vez** e o servidor devolve o erro apontando o passo.
- Acessibilidade: `<progress>` com rótulo, `aria-current="step"` na lista, `aria-live` na força da senha e nos erros (`role="alert"`), alvos de toque grandes (`btn-lg`) e layout de coluna única no celular.
- **Erros ensinam:** a mensagem original mais um "como corrigir" (`PassosDoInstalador::comoCorrigir`) e o formulário reabre no passo certo (`data-passo-inicial`).
- **Tela final** (`ProximosPassos`): login, linha do cron (`* * * * * php <pasta>/bin/pbl cron >/dev/null 2>&1`), onde fica a cópia de segurança (`dados/` e `dados/backups/`), como atualizar (painel e `bin/pbl atualizar`) e o aviso sobre a raiz do site, **condicional ao layout** (raiz em `public/`, na pasta inteira ou desconhecida, a partir de `DOCUMENT_ROOT`).

### Publicação

O `release.yml` gera o `instalar.php` de produção (`bin/gerar-instalador`), confere `php -l`, `sh -n` e `shellcheck`, grava o SHA-256 dos dois arquivos no manifesto (`instaladores`, antes de assinar: `bin/pbl empacotar --instaladores=<pasta>`), no resumo do job e nas notas da release, e os publica como assets (caminho assinado e rascunho). O Worker (`infra/atualizacoes/`) serve `/instalar.php` e `/instalar.sh` como `text/plain`. **O deploy do Worker é do dono** ([README](../../infra/atualizacoes/README.md)). Veja também [atualização](atualizacao.md) e [releases](../releases.md).

### Painel: CSP e PHP de linha de comando

- A CSP do painel (`Admin\Pagina::protegida`) tem `connect-src 'self'`: sem ele o `default-src 'none'` bloqueia o `fetch()` do painel (botão Republicar, `pbl-admin.js`). `AssetsAdminTest` confere que os scripts do painel só falam com o próprio site e que a CSP (normal e do editor) permite.
- A tela Atualização acha o PHP de linha de comando em `LancadorDeProcesso::descobrirPhp`: só caminhos absolutos chamados `php*` (descarta `php-fpm8.3`) e dentro do `open_basedir` são testados com `is_file`; candidatos: chave `php` do `atualizacao.json`, `PHP_BINARY`, `PHP_BINDIR/php` e `PHP_BINDIR/phpX.Y`. Sem candidato usável devolve `null` (a tela pede a chave `php`), sem aviso de `open_basedir`.

### Por que a prova de posse

Um instalador aberto deixaria qualquer visitante assumir um site recém-enviado. `dados/instalar.liberar` só pode ser criado por quem tem acesso à pasta (FTP, painel ou SSH). O pedido também exige token CSRF e conferência de `Origin`. A página mostra e escapa tudo o que foi digitado, e a senha nunca volta na resposta.

## Layout no servidor

```
raiz-do-projeto/
  app/        código (substituível pelo atualizador)
  vendor/     dependências
  dados/      banco, cache, peles, uploads, config, segredo  → NUNCA público
  public/     index.php e .htaccess                          → a raiz do site
  .htaccess   protege o resto se a raiz do site for esta pasta
```

**Recomendado:** aponte a raiz do site (`DocumentRoot`) para `public/`. Assim `dados/`, `app/` e `vendor/` nem existem para o navegador.

### Apache (hospedagem compartilhada)

Com `mod_rewrite` e `AllowOverride All`, os arquivos `.htaccess` já cobrem tudo:

- `public/.htaccess`: manda todo endereço que não é arquivo existente (pastas inclusive, o que evita listagem) para `index.php` e nega arquivos ocultos e de configuração.
- `.htaccess` da raiz: só vale se a raiz do site for a pasta inteira. Nega (403) `app/`, `dados/`, `vendor/`, `bin/`, `tests/`, `docs/`, `docker/`, `.git/`, `composer.*` etc. e serve o resto a partir de `public/`, **mas só se `public/index.php` existir sob a raiz do site**. Se a raiz do site já for `public/`, ele é inofensivo (nem é lido, e se for não faz nada).

Os dois arquivos **não usam `Options`** (que exige `AllowOverride Options` e, em hospedagens com painel, dá erro 500), e cada bloco só vale se o módulo existir (`<IfModule>`); a negação usa `Require all denied` (Apache 2.4), com ramo `Order/Deny` só para 2.2. Basta `AllowOverride FileInfo AuthConfig` (ou `All`).

Conferido em Apache 2.4 de verdade (`php:8.3-apache`): com `AllowOverride All` e com `AllowOverride AuthConfig Limit FileInfo` (sem `Options`), a raiz na pasta inteira dá **403** em `app/`, `vendor/`, `dados/`, `.git/`, `composer.json` e `Makefile`, e serve `/` e os estilos; com a raiz em `public/`, o `.htaccess` da raiz não interfere e `composer.json`/`.git` seguem negados. Antes desta correção, `Options -Indexes` no `public/.htaccess` dava **500** com `AllowOverride` restrito (`Options not allowed here`).

### Hestia/VestaCP e hospedagens com `open_basedir`

Nesses painéis o PHP-FPM de cada domínio só enxerga a pasta do domínio (`open_basedir`). Um projeto enviado para `public_html/` fica **todo público**; um projeto fora do domínio fica **inacessível ao PHP**. A solução é o projeto dentro de `private/` do domínio, com o docroot apontando para `public/`:

```bash
# como o usuário do domínio (ex.: usuario, domínio exemplo.org)
cd /home/usuario/web/exemplo.org
mkdir -p private && cd private
# envie/extraia o PBL4 aqui, de modo que exista private/pbl4/public/index.php
cd ../
mv public_html public_html.orig            # guarde a pasta padrão do painel
ln -s private/pbl4/public public_html      # o docroot passa a ser public/
mkdir -p private/pbl4/dados && touch private/pbl4/dados/instalar.liberar
```

Notas:

- O symlink aponta **para dentro** do domínio, então o `open_basedir` (que costuma incluir `.../exemplo.org/` e `/tmp`) é satisfeito. Se o painel recriar `public_html`, refaça o link.
- Instale o SQLite do PHP: `apt install php8.3-sqlite3` (ajuste para a versão do PHP do domínio) e reinicie o PHP-FPM (`systemctl restart php8.3-fpm`). O assistente mostra isso quando falta `pdo_sqlite`.
- Se o `open_basedir` impedir o acesso a `dados/`, o assistente diz isso no item "Acesso do PHP à pasta dados/ (open_basedir)" (sem mostrar caminhos do servidor). Ou o projeto está fora da pasta do domínio, ou o PHP do domínio precisa liberar a pasta (no Hestia, o template do PHP-FPM do domínio).
- Com o docroot em `public/`, o `.htaccess` da raiz do projeto fica fora do alcance do Apache e não causa erro.
- O Apache do Hestia (atrás ou não do nginx) precisa de `AllowOverride` com `FileInfo`; o padrão do painel serve. Se usar só nginx, aplique a configuração de referência abaixo.

### Atrás da Cloudflare com SSL "Full"

Com o modo SSL/TLS **Full** (ou Full strict) a Cloudflare fala HTTPS com a origem: o servidor precisa de um certificado válido para o domínio. Sem ele (ou com o modo **Flexible** e a origem forçando HTTPS), o navegador cai num **laço de redirecionamento 301**. No Hestia: edite o domínio, ative **SSL** e **Let's Encrypt** (o domínio precisa já apontar para o servidor, ou desligue temporariamente o proxy laranja durante a emissão), e marque **Redirecionar HTTP para HTTPS**. Depois crie `dados/config/rede.json` para o IP real e o HTTPS serem reconhecidos ([Segurança](seguranca.md#atrás-de-um-proxy-cloudflare-nginx)).

### nginx (com PHP-FPM)

Esta é a configuração de referência (`docker/nginx-teste/pbl4.conf`), **testada** com nginx 1.30 e PHP-FPM 8.3 (`docker compose --profile nginx up -d --build nginx`, porta 8083): instalação pelo navegador, site, login, `dados/` e o banco inalcançáveis (404) e um `.php` estranho colocado em `public/` **não executa**.

```nginx
# Configuração de referência do PBL4 em nginx (a mesma do guia docs/tecnica/instalacao.md).
server {
    listen 80;
    server_name _;
    root /var/www/pbl4/public;
    index index.php;

    # Código, dados e ferramentas nunca pelo navegador, mesmo que a raiz do site seja a pasta inteira por engano.
    location ~ ^/(app|dados|vendor|bin|tests|docs|docker)(/|$) { return 404; }
    location ~ ^/(composer\.(json|lock)|Makefile|phpunit\.xml\.dist|phpstan\.neon|docker-compose\.yml)$ { return 404; }
    location ~ /\.(?!well-known) { return 404; }

    # Todo endereço que não é um arquivo existente vai para o controlador.
    location / { try_files $uri /index.php?$query_string; }

    # Só o controlador executa PHP. Qualquer outro .php (um upload malicioso, por exemplo) não roda.
    location = /index.php {
        fastcgi_pass fpm:9000;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    }
    location ~ \.php$ { return 404; }
}
```

Os estilos da administração (`/_pbl/assets/…`, Bootstrap e `pbl.css`) são arquivos de verdade em `public/`: o `try_files $uri` os entrega direto, e o mesmo vale para o Apache (o `.htaccess` só manda ao `index.php` o que não é arquivo existente). Conferido no Apache de teste e no servidor embutido: `200 text/css`.

Pontos que importam: a raiz é `public/`; **só `index.php` executa PHP** (qualquer outro `.php`, como um envio malicioso, dá 404); e as regras de `dados/`, `app/`, `vendor/` ficam de proteção extra caso alguém aponte a raiz para a pasta inteira.

### Outros servidores

| Servidor | Situação |
|---|---|
| **Apache** (`mod_rewrite`, `.htaccess`) | testado (acima) |
| **nginx + PHP-FPM** | testado (acima) |
| Servidor embutido do PHP (`php -S`, usado por `make subir`) | só para desenvolvimento (o `index.php` devolve os arquivos existentes de `public/` para ele entregar) |
| LiteSpeed / OpenLiteSpeed | lê `.htaccess`: deve funcionar como o Apache, **não testado** |
| Caddy / FrankenPHP | basta `try_files {path} /index.php` com a raiz em `public/`; **não testado** |

### Atrás de proxy (Cloudflare, nginx na frente)

Crie `dados/config/rede.json` para o IP real e o HTTPS serem reconhecidos: [Segurança](seguranca.md#atrás-de-um-proxy-cloudflare-nginx).

## Testar a hospedagem localmente

```bash
docker compose --profile apache up -d --build apache apache-public
# http://localhost:8081  Apache, raiz = pasta inteira (usa o .htaccess da raiz)
# http://localhost:8082  Apache, raiz = public/
docker compose --profile nginx up -d --build nginx
# http://localhost:8083  nginx + PHP-FPM, raiz = public/
```

Desligue com `docker compose --profile apache --profile nginx down`. Esses ambientes gravam em `dados/`: use uma cópia do projeto, não o seu trabalho.

## O que a instalação cria

Na mesma transação: as classes `pasta`, `pagina`, `formulario`, `arquivo`, `imagem`, `link` (fixas), `noticia` e `mensagem_contato`; a página inicial; **Sobre**, **Notícias** (com uma notícia de exemplo) e **Contato** (formulário), os três no menu; e a pasta privada **Mensagens recebidas**. Ou o site nasce inteiro ou não nasce. Instalações anteriores à fase 5l ganham as colunas novas pela migração 16, mas não as classes novas. Ver [ADR 0015](../adr/0015-classes-fixas-e-pacote-minimo.md).

## Depois de instalar

HTTPS na administração; cópia de segurança de `dados/`; conferir que `/dados/` não abre; guardar a senha. O assistente repete esses lembretes.
