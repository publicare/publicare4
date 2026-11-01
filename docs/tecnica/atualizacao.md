# Atualização (`bin/pbl atualizar`)

Implementação da primeira metade do [ADR 0006](../adr/0006-atualizador-automatico.md) e do [fluxo 11](../fluxos/11-atualizacao.md): atualização pela **linha de comando**, a partir de um manifesto assinado. O botão no painel e a verificação periódica virão com a administração.

## Para quem opera o site

```bash
php bin/pbl atualizar --verificar     # só olha; não altera nada
php bin/pbl atualizar                 # verifica e aplica
php bin/pbl atualizar --manifesto=/caminho/latest.json   # offline: pacote copiado por FTP (o .sig e o .zip ficam ao lado)
```

Configuração em `dados/config/atualizacao.json`:

```json
{"url": "https://exemplo.org/pbl4/latest.json", "canal": "estavel", "verificar": true}
```

`verificar: false` desliga qualquer consulta de rede. O PBL4 só envia o agente `PBL4`; nenhum dado do site.

**O que acontece, em ordem**

1. Baixa `latest.json` e `latest.json.sig` e confere a assinatura Ed25519 com a chave pública embutida (`app/src/Atualizacao/Chaves.php`). Sem assinatura válida, para ali.
2. Confere canal, versão (só avança), versão mínima de partida e versão mínima do PHP.
3. Baixa o pacote **do mesmo servidor do manifesto**, por HTTPS, e confere tamanho e SHA-256 contra o manifesto assinado.
4. Extrai numa área de preparo (`dados/atualizacao/`) conferindo cada arquivo contra o `PACOTE.json`.
5. Copia o banco para `dados/backups/`.
6. Liga a **manutenção** (o site responde 503 com `Retry-After`; a marca vence sozinha em 30 minutos).
7. Troca `app/`, `vendor/`, `bin/`, `public/` e os arquivos da raiz por `rename`; o código antigo fica em `dados/atualizacao/anterior/`.
8. Roda `bin/pbl migrar` e `bin/pbl versao` **com o código novo**, em outro processo. Se falhar, o código antigo volta sozinho. As migrações só acrescentam, então o código antigo funciona com o banco já migrado (ADR 0006).

`dados/` nunca entra na troca. Precisa de permissão de escrita na pasta do PBL4 e da extensão `zip`; sem elas o comando explica o que falta, sem tocar em nada.

## Para quem publica versões

```bash
php bin/pbl chaves:gerar                       # uma vez; a PRIVADA fica só com o mantenedor, fora do repositório
php bin/pbl empacotar PASTA_PREPARADA SAIDA --chave-privada=arquivo --canal=estavel --resumo="…" --notas=https://…
```

A pasta preparada é o projeto com `composer install --no-dev --optimize-autoloader`. O empacotador leva **só** `app/ vendor/ bin/ public/ composer.* .htaccess LICENSE`, lê a versão de `app/src/Versao.php`, recusa `-dev` e pré-lançamento no canal estável, grava hashes de todos os arquivos, gera o zip de forma reproduzível e assina o manifesto. Publique o `.zip` primeiro e o `latest.json` + `.sig` por último.

Trocar de chave sem quebrar quem atualiza: publique uma versão que confie nas duas chaves (`Chaves::PUBLICAS` aceita lista) e só depois retire a antiga.

## Defesas

| Ameaça | Defesa |
|---|---|
| Servidor de atualização comprometido | assinatura Ed25519; a chave privada nunca fica no servidor |
| Pacote adulterado ou trocado | SHA-256 e tamanho no manifesto assinado; hash de cada arquivo no `PACOTE.json` |
| Downgrade | só avança; manifesto traz versão mínima de partida |
| Zip-slip, link simbólico, zip bomb | nomes normalizados e restritos a pastas permitidas; sem links; teto de arquivos e de bytes contado na leitura |
| Redirecionamento ou SSRF | HTTPS, sem usuário/porta, redirecionamento só no mesmo host, pacote só no host do manifesto |
| Atualização que quebra o site | manutenção, cópia do banco, conferência com o código novo, volta atrás automática |
| Sem chave embutida | o atualizador se recusa a aplicar qualquer coisa |

Cobertura: `tests/AtualizadorTest.php`.
