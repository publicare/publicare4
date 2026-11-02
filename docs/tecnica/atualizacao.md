# Atualização (`bin/pbl atualizar`, `bin/pbl reverter`, `bin/pbl verificar-atualizacao`)

Implementação do [ADR 0006](../adr/0006-atualizador-automatico.md) e do [fluxo 11](../fluxos/11-atualizacao.md): atualização, voltar atrás e a verificação periódica pela **linha de comando**, a partir de um manifesto assinado. A tela "Atualização" do painel (fases 4c e 4d) usa o mesmo `Atualizador`/`Reversor`.

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

Ao terminar, o `Atualizador` grava `dados/atualizacao/aplicada.json` (`RegistroDaAtualizacao`): de qual versão para qual, quando, o nome e o SHA-256 da cópia do banco e a versão do esquema antes e depois. É o que `bin/pbl reverter` confere antes de voltar atrás; some junto com `anterior/` na próxima atualização.

## Voltar atrás de uma atualização concluída (`bin/pbl reverter`)

```bash
php bin/pbl reverter              # só olha: para qual versão voltaria e o que se perde; não altera nada
php bin/pbl reverter --confirmar  # devolve o código e o banco de antes da atualização
```

Diferente da volta atrás **automática** (passo 8 acima, quando a própria atualização falha no meio), este comando desfaz uma atualização que **terminou bem**, mas que a pessoa decidiu não querer. Por isso ele troca o banco inteiro pela cópia de antes, e não só o código: **o que foi criado ou alterado no site depois da atualização se perde**, de propósito, e por isso exige `--confirmar`.

**Antes de tocar em qualquer coisa**, o `Reversor` confere, com o `RegistroDaAtualizacao` que o `Atualizador` deixou:

1. A versão instalada hoje é a que o registro diz ter sido o resultado da última atualização (senão, outra coisa aconteceu depois e o registro não vale mais).
2. O código em `dados/atualizacao/anterior/` existe para cada item trocado e diz mesmo ser da versão anterior (lido do `Versao.php` guardado, nunca executado).
3. A cópia do banco em `dados/backups/` ainda existe, o SHA-256 confere (não foi alterada) e está na versão de esquema que o registro espera.
4. O esquema do banco de hoje é o mesmo de logo depois da atualização (se mais alguma migração rodou desde então, a cópia de antes já não descreve este banco, e a volta é recusada com o motivo: "o banco mudou de forma incompatível depois da atualização").
5. A pasta do PBL4 é gravável.

Sem tudo isso, nada é alterado e o comando explica o motivo. Com `--confirmar` e tudo certo:

1. Copia o banco de **hoje** para `dados/backups/` (cópia de segurança, para o caso de a volta falhar no meio).
2. Liga a **manutenção**.
3. Troca o código: a versão de hoje vai para `dados/atualizacao/revertida/`, a de `anterior/` volta ao lugar.
4. Substitui o banco pela cópia de antes da atualização.
5. Confere a versão com o código restaurado, num processo à parte (como o passo 8 da atualização, mas com o código **antigo**).
6. Se qualquer passo de 3 a 5 falhar, devolve o código de hoje e o banco de hoje (da cópia do passo 1) e explica o motivo; senão, marca o registro como revertido (`anterior/` não é reaproveitado: uma nova atualização limpa tudo) e sai da manutenção.

Sob a **mesma trava** do `Atualizador::aplicar` (`flock` em `dados/atualizacao.lock`): atualizar e reverter nunca correm ao mesmo tempo, de qualquer origem. Depois de revertido, para voltar à versão desfeita é preciso atualizar de novo (o manifesto da release ainda existe no canal).

## Verificação periódica (`bin/pbl verificar-atualizacao`)

```
*/5 * * * * cd /caminho/do/projeto && php bin/pbl verificar-atualizacao >/dev/null 2>&1
```

Atualiza o mesmo cache de 24 h que a tela "Atualização" e a página inicial do painel leem (`EstadoDoPainel`/`verificacao.json`), fora da renderização de qualquer página. **Idempotente**: se o cache ainda vale, não consulta nada. **Silencioso** quando não há versão nova (para não poluir o log do cron); escreve uma linha quando há. Sem `url` configurada, com `verificar: false` ou sem chave de assinatura embutida, não consulta nada (as mesmas condições da tela).

## Pelo painel (`/_pbl/atualizacao`)

Só o administrador geral. A tela mostra versão instalada, canal, endereço e a versão disponível (mudanças, correção de segurança), com a consulta guardada por 24 horas e o botão **Verificar agora**. Para atualizar, a pessoa digita a **senha de novo** e marca a confirmação; o painel reconsulta o manifesto, confere que é a mesma versão que ela viu e então inicia, **em segundo plano**, o mesmo `php bin/pbl atualizar`. Quando há uma atualização concluída para desfazer, a seção "Se algo der errado" mostra o mesmo tipo de formulário (senha de novo, confirmação com o aviso explícito do que se perde) e inicia `php bin/pbl reverter --confirmar`. A requisição web nunca troca código nem banco e só escreve em `dados/`.

- **Estado** em `dados/atualizacao-painel/` (não em `dados/atualizacao/`, que o atualizador apaga): `verificacao.json` (cache), `estado.json` (tipo — `atualizar` ou `reverter` —, de, para, quando), `saida.txt` (o que o comando escreveu), `codigo` (código de saída; só existe quando terminou) e `trava.lock`. A tela lê esses arquivos a cada recarga: em andamento, concluída, falhou (com o motivo, escapado) ou sem resposta (1 hora sem `codigo`).
- **Uma operação por vez, de qualquer origem**: o próprio `Atualizador::aplicar`/`Reversor::reverter` pega um `flock` em `dados/atualizacao.lock` (fora de `dados/atualizacao/`); CLI, painel e cron concorrentes recebem "Outra atualização em andamento". Além disso, no painel, enquanto houver `estado.json` sem `codigo` (e com menos de 1 hora), outro clique recebe 409. A decisão é feita sob `flock`.
- **Durante a troca** o site todo (inclusive esta tela) responde 503; recarregue depois.
- **Requisitos do servidor**: `proc_open` e `sh` disponíveis, o PHP de linha de comando achável (sob FPM/Apache, indique em `php`), pasta do PBL4 gravável pelo usuário do PHP, extensão `zip`. Se faltar algo a tela diz o quê; a alternativa é sempre a linha de comando. Não funciona em Windows (usa `sh`).
- **Sem chave de assinatura** (`Chaves::PUBLICAS` vazia, como hoje): a tela diz "Nenhuma chave de assinatura instalada; atualizações recusadas." e não consulta nem aplica (nem avalia reverter: sem chave, também não há como ter atualizado por aqui).
- **Voltar atrás**: se a atualização falha, o código anterior volta sozinho. **Depois** de concluída, o botão "Voltar para a X" cobre o caso comum, direto pelo painel; a tela só mostra o motivo (em vez do botão) quando o `Reversor` diz que não é seguro.

Código: `Admin/TelasDeAtualizacao`, `Atualizacao/EstadoDoPainel`, `Atualizacao/LancadorDeProcesso`, `Atualizacao/Reversor`. Cobertura: `tests/AdminAtualizacaoTest.php` (permissão, CSRF, origem, senha errada e limite, trava, cache, estados, processo real em segundo plano, botão de reverter), `tests/ReversorTest.php`, `tests/ReverterComandoTest.php`, `tests/VerificarAtualizacaoComandoTest.php`.

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
| Voltar atrás numa instalação que já mudou desde a atualização registrada | `Reversor::avaliar` confere versão instalada, código guardado, SHA-256 e versão de esquema da cópia do banco antes de tocar em qualquer coisa; recusa com o motivo em vez de arriscar |
| Voltar atrás sem querer perder dados | exige `--confirmar` (ou a confirmação marcada e a senha de novo, no painel); sem isso só mostra o que faria |
| Volta atrás que falha no meio | copia o banco de hoje antes de trocar qualquer coisa; se falhar depois, devolve código e banco de hoje |

Cobertura: `tests/AtualizadorTest.php`, `tests/ReversorTest.php`.
