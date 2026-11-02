# Instalação e hospedagem

Para quem instala em um servidor. Quem só quer um passo a passo simples: [Como instalar](../usuario/instalar.md).

## Requisitos

PHP 8.2+ com `pdo_sqlite` (pacote `php8.x-sqlite3` em Debian/Ubuntu), `mbstring`, `sodium`, `json`, `ctype`, `dom` (para limpar o HTML dos campos) e `fileinfo` (para identificar de verdade o tipo de um arquivo enviado); pasta `dados/` gravável e dentro do `open_basedir` do PHP, se houver. **GD é opcional**: sem ela, upload de imagem ainda funciona (com uma verificação mais fraca, sem recodificar) e não há miniaturas. MySQL e PostgreSQL estão previstos (hoje o instalador cria SQLite).

## Formas de instalar

| Forma | Quando | Como |
|---|---|---|
| **Assistente no navegador** | hospedagem comum, quem não usa terminal | `https://site/_pbl/instalar` depois de criar `dados/instalar.liberar` (prova de posse) |
| **Linha de comando** | quem tem terminal | `php bin/pbl instalar --nome=… --host=… --admin-login=… --admin-email=…` (a senha é pedida de forma oculta) |

O instalador é **atômico**: se algo falhar, o banco volta ao estado limpo e dá para tentar de novo. Ao terminar, grava `dados/config/instalado.lock`, publica as páginas de exemplo e some (a rota passa a dar 404).

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
