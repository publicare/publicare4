# Instalação e hospedagem

Para quem instala em um servidor. Quem só quer um passo a passo simples: [Como instalar](../usuario/instalar.md).

## Requisitos

PHP 8.2+ com `pdo_sqlite`, `mbstring`, `sodium`, `json` e `ctype`; pasta `dados/` gravável. MySQL e PostgreSQL estão previstos (hoje o instalador cria SQLite).

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

- `public/.htaccess`: manda todo endereço que não é arquivo para `index.php`, desliga listagem de pastas e nega arquivos ocultos e de configuração.
- `.htaccess` da raiz: se a raiz do site for a pasta inteira, nega `app/`, `dados/`, `vendor/`, `bin/`, `tests/`, `docs/`, `docker/`, `.git/`, `composer.*` etc. e serve o resto a partir de `public/`.

Conferido em Apache 2.4 de verdade (`docker compose --profile apache up -d`): com a raiz na pasta inteira, `dados/pbl4.sqlite`, `dados/config/segredo`, `app/`, `vendor/` e `composer.json` dão **403**; com a raiz em `public/`, ficam inalcançáveis.

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

## Depois de instalar

HTTPS na administração; cópia de segurança de `dados/`; conferir que `/dados/` não abre; guardar a senha. O assistente repete esses lembretes.
