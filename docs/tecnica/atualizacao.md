# Atualização (`bin/pbl atualizar`)

Implementação da primeira metade do [ADR 0006](../adr/0006-atualizador-automatico.md) e do [fluxo 11](../fluxos/11-atualizacao.md): atualização pela **linha de comando**, a partir de um manifesto assinado. A tela "Atualização" do painel (fase 4c) usa o mesmo `Atualizador`; a verificação periódica (cron) ainda não existe.

## Para quem opera o site

```bash
php bin/pbl atualizar --verificar     # só olha; não altera nada
php bin/pbl atualizar                 # verifica e aplica
php bin/pbl atualizar --manifesto=/caminho/latest.json   # offline: pacote copiado por FTP (o .sig e o .zip ficam ao lado)
```

Configuração em `dados/config/atualizacao.json`:

```json
{"url": "https://exemplo.org/pbl4/latest.json", "canal": "estavel", "verificar": true, "php": "/usr/bin/php"}
```

`php` é opcional: o caminho absoluto do PHP de linha de comando, usado só pelo botão do painel (veja abaixo). Não há `url` padrão: sem ela, a CLI e o painel explicam como configurar.

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

## Pelo painel (`/_pbl/atualizacao`)

Só o administrador geral. A tela mostra versão instalada, canal, endereço e a versão disponível (mudanças, correção de segurança), com a consulta guardada por 24 horas e o botão **Verificar agora**. Para atualizar, a pessoa digita a **senha de novo** e marca a confirmação; o painel reconsulta o manifesto, confere que é a mesma versão que ela viu e então inicia, **em segundo plano**, o mesmo `php bin/pbl atualizar`. A requisição web nunca troca código e só escreve em `dados/`.

- **Estado** em `dados/atualizacao-painel/` (não em `dados/atualizacao/`, que o atualizador apaga): `verificacao.json` (cache), `estado.json` (de, para, quando), `saida.txt` (o que o comando escreveu), `codigo` (código de saída; só existe quando terminou) e `trava.lock`. A tela lê esses arquivos a cada recarga: em andamento, concluída, falhou (com o motivo, escapado) ou sem resposta (1 hora sem `codigo`).
- **Uma por vez, de qualquer origem**: o próprio `Atualizador::aplicar` pega um `flock` em `dados/atualizacao.lock` (fora de `dados/atualizacao/`); CLI, painel e cron concorrentes recebem "Outra atualização em andamento". Além disso, no painel, enquanto houver `estado.json` sem `codigo` (e com menos de 1 hora), outro clique recebe 409. A decisão é feita sob `flock`.
- **Durante a troca** o site todo (inclusive esta tela) responde 503; recarregue depois.
- **Requisitos do servidor**: `proc_open` e `sh` disponíveis, o PHP de linha de comando achável (sob FPM/Apache, indique em `php`), pasta do PBL4 gravável pelo usuário do PHP, extensão `zip`. Se faltar algo a tela diz o quê; a alternativa é sempre a linha de comando. Não funciona em Windows (usa `sh`).
- **Sem chave de assinatura** (`Chaves::PUBLICAS` vazia, como hoje): a tela diz "Nenhuma chave de assinatura instalada; atualizações recusadas." e não consulta nem aplica.
- **Voltar atrás**: se a atualização falha, o código anterior volta sozinho. **Depois** de concluída não há comando nem botão para desfazer; é manual (a tela explica): devolver as pastas de `dados/atualizacao/anterior/` e, só se preciso, o banco de `dados/backups/`.

Código: `Admin/TelasDeAtualizacao`, `Atualizacao/EstadoDoPainel`, `Atualizacao/LancadorDeProcesso`. Cobertura: `tests/AdminAtualizacaoTest.php` (permissão, CSRF, origem, senha errada e limite, trava, cache, estados, processo real em segundo plano).

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
