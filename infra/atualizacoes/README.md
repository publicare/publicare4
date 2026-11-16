# Endpoint de atualizações (Cloudflare Worker)

Proxy estático na frente dos **GitHub Releases** do Publicare 4. As instalações consultam o endereço configurado em
`dados/config/atualizacao.json` (`url`; ADR 0019). O cliente (`BaixadorHttps`) **recusa redirecionamento para outro host**, e o GitHub
redireciona os downloads; por isso este Worker segue o redirecionamento no servidor e serve manifesto, assinatura e pacote sob o mesmo host.

| Rota | Origem (GitHub Releases) | Cache |
|---|---|---|
| `/latest.json`, `/latest.json.sig` | assets da última release **estável** (`releases/latest/download/`) | 5 min (`CACHE_MANIFESTO_SEGUNDOS`) |
| `/latest-beta.json`, `/latest-beta.json.sig` | assets da última **pré-lançamento** publicada (descoberta pela API de releases) | 5 min |
| `/pacotes/pbl4-<versão>.zip` | asset da release `v<versão>` | 1 ano, `immutable` |
| `/instalar.php`, `/instalar.sh` | assets da última release **estável** (`releases/latest/download/`), servidos como `text/plain` (o Worker nunca os interpreta) | 5 min |

Qualquer outro caminho dá 404; só `GET` e `HEAD`. O nome do pacote é validado por expressão regular (sem `..`, `/` nem `%`).
O manifesto aponta o pacote com `"url": "/pacotes/pbl4-X.zip"` (opção `--url-base-pacote=/pacotes/` do `bin/pbl empacotar`, já usada pelo
`release.yml`), então o domínio não fica gravado no manifesto assinado.

## Segurança

O Worker **não é de confiança**: a instalação confere a assinatura Ed25519 do manifesto (chaves embutidas) e o SHA-256 do pacote.
Se o Worker ou o GitHub servirem um manifesto e uma `.sig` de releases diferentes (troca no meio do cache), a assinatura não confere,
a instalação recusa e tenta de novo depois. Nada neste diretório guarda segredo.

## Limites conhecidos

- O canal beta usa `GET /repos/<repo>/releases` da API do GitHub. Sem token são 60 requisições/hora por IP: o cache de minutos cobre isso.
  Se precisar, cadastre um token **somente leitura** como *secret* do Worker (`npx wrangler secret put GITHUB_TOKEN`); nunca no `wrangler.toml`.
- O beta serve a pré-lançamento publicada mais recente, mesmo que exista uma estável mais nova.

## Deploy (passo manual do dono)

> As rotas `/instalar.php` e `/instalar.sh` só passam a existir depois do `wrangler deploy`. **O deploy do Worker é do dono: nenhuma
> mudança do repositório o executa.** Até lá, os dois arquivos podem ser baixados da página da release no GitHub.

1. `cd infra/atualizacoes && npx wrangler deploy` (ajuste `GITHUB_REPO` se o repositório mudar).
2. Aponte o domínio para o Worker (rota ou *custom domain* na Cloudflare) e coloque esse endereço no `url` de `dados/config/atualizacao.json`.
3. Confira: `curl -sI https://SEU-DOMINIO/latest.json` (200) e `.../pacotes/pbl4-<versão>.zip`.
