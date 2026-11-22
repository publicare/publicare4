# QA_REPORT — PBL4 novo painel, fase 1 (rodada 2)

## Veredito: NO-GO para mesclar até a prévia subir e o dono responder
O código e os testes estão em ordem. Mas **não consegui subir nem observar a prévia**: este ambiente não tem a ferramenta que envia o pedido de prévia à VSF, não tem navegador (nem Chromium nem Playwright) e não tem `curl`. Por isso as capturas (lacuna 2) não existem. A parada para o dono (lacuna 3) continua pendente: o sistema abre o marco quando a prévia sobe. Não declaro pronto.

## Lacuna 1 — por que a prévia não iniciou
Só tenho a trilha como relato (`PreviaSolicitada` às 04:38, sem `PreviaIniciada`). Não vi os logs da VSF. **A causa exata não está confirmada.** O que verifiquei:

| Hipótese | Resultado |
|---|---|
| O gerador depende de `vendor/`, que o `.gitignore` exclui. Num clone limpo ele morria antes de gerar o pacote. | **Causa mais provável.** Corrigido: sem `vendor/`, o gerador roda `composer install --no-dev` a partir do `composer.lock` e falha com mensagem clara se não conseguir. Simulei um clone limpo (sem `vendor/`): o pacote saiu, 2,5 MB. |
| Host `pv-…` recusado (Q6) | Já resolvido: `Aplicacao` aceita `pv-…` só com o marcador `dados/previa.flag`. Rodei o pacote com o servidor embutido do PHP e `Host: pv-abc123.previa.test`: entrada de prévia 303, `/_pbl/mais` 200 logado, `editor` e `autor` 303, host `outro.example` 404. |
| Formato do pedido | `notas/previa-pedido.json` é v2 (`gerar_pacote`, `extrair_pacote`, docroot `pbl4/public`, `dados` gravável). 8 páginas × 2 viewports = 16, no limite que o teste assume. |
| Pacote grande demais | 2,5 MB. Não é a causa. |

Se a prévia falhar de novo, o primeiro lugar para olhar é a saída do passo `gerar_pacote`: o gerador agora escreve no stderr o código e a saída do composer.

## O que mudou nesta rodada
- `previa/gerar-pacote.php`: instala `vendor/` de produção quando falta e copia o `composer.lock`.
- Pedido de prévia: a página `mais-escuro` virou `mais-escuro-menu-aberto` (`/_pbl/mais?menu=aberto`). O JS recolhe o menu no celular, então a captura precisava de um jeito de mantê-lo aberto. O limite de 16 capturas não deixa espaço para uma página a mais.
- `pbl-admin.js`: `?menu=aberto` mantém o menu aberto (só no cliente, sem endpoint novo).
- Q2 (seletor de conteúdo com busca): já estava implementado no código desta árvore. `TelasDeObjetos::controleDeReferencia` entrega um `<select data-pbl-seletor>` e o JS ganha o campo de busca. Sem JS vale a lista nativa. Sem conteúdo do tipo, ou acima do teto, volta ao campo numérico. A lista só traz o que a conta pode ver (teste de não vazamento).
- Testes do `pbl-formulario.js`: `tests/unit/pbl-formulario.test.mjs` (37 testes) e `dom-falso.mjs`, na técnica `node:test` + `node:vm` do repositório. Cobrem abas, interruptores, contador, prévias, miniatura, aviso ao sair, validação ao vivo e seletor. Mais `pbl-admin-menu.test.mjs` (2 testes) e 2 testes novos do pedido e do gerador.

## Resultado dos testes
| Comando | Resultado |
|---|---|
| `node --test tests/unit/*.test.mjs tests/smoke/*.test.mjs tests/integration/*.test.mjs` | 68 passaram, 0 falharam, 0 ignorados (o teste do pedido de prévia roda porque `notas/previa-pedido.json` existe) |
| `vendor/bin/phpunit tests/AdminObjetosTest.php` | passou (39 testes, 571 asserções) |
| `vendor/bin/phpunit tests/PreviaDoPainelTest.php` | passou (12 testes, 51 asserções) |
| `vendor/bin/phpunit tests/NovoPainelFase1Test.php` | passou (37 testes, 198 asserções) |
| `vendor/bin/phpunit tests/AssetsAdminTest.php` | passou (11 testes, 173 asserções) |
| Suíte PHPUnit completa e PHPStan | **não rodei nesta rodada.** Só mexi em JS, no gerador e em testes JS. A rodada anterior os registrou verdes; a CI do PR confirma. |
| Verificação manual com servidor embutido | pacote gerado, entrada de prévia e as 4 telas do pedido respondem 200 logadas (Início, Conteúdo/1, Editar com aba Publicação e 5 interruptores ligados, Mais com `?menu=aberto`) |

## Capturas e navegador
**Não feitas.** Sem navegador aqui não vi layout, tema escuro, JS no navegador nem o menu aberto no celular. Sem JavaScript: a aba padrão e o menu aberto são cobertos só por testes de HTML do servidor (`NovoPainelFase1Test`), não por captura. A ferramenta de captura da prévia faz só GET, então a captura sem JS depende de ela permitir desligar o JS. Não sei se permite.

## Mantido
Nada em `.github/` ou `deploy/` foi alterado. A CSP continua sem inline (testes verdes). As rotas antigas seguem com 303 (`NovoPainelFase1Test`).

## Riscos abertos
| # | Risco | Ação |
|---|---|---|
| R1 | A causa da falha da prévia é hipótese. | Conferir o log do `gerar_pacote` se subir de novo sem `PreviaIniciada`. |
| R2 | Sem captura, tema escuro e celular não foram vistos. | O dono vê na prévia. |
| R3 | O ambiente de prévia pode não ter `composer` nem rede. | O gerador falha com mensagem clara. Alternativa: pedir um passo de preparo que instale as dependências. |

## Como validar
1. Rode `node --test tests/unit/*.test.mjs tests/smoke/*.test.mjs tests/integration/*.test.mjs`. Espere 68 passando e 0 falhas.
2. Rode `vendor/bin/phpunit tests/PreviaDoPainelTest.php tests/NovoPainelFase1Test.php tests/AdminObjetosTest.php`. Espere OK.
3. Num clone limpo (sem `vendor/`), na pasta de trabalho, rode `php previa/gerar-pacote.php`. Espere `pacote: …/gerado/pbl4.tar.gz`.
4. Extraia o pacote e sirva `public/` com `php -S 127.0.0.1:8089 index.php`. Abra `http://pv-teste.localhost:8089/_pbl/previa-entrar?conta=admin&ir=/_pbl/mais?menu=aberto`. Espere o painel logado em "Mais" com o menu aberto. `conta=editor` e `conta=autor` também entram. As contas e a senha `demo-2026-pbl4` são fictícias.
5. Na prévia da VSF, confira as 16 capturas (celular 390 px e desktop 1280 px, claro e escuro). Em Editar, a aba Publicação mostra interruptores ligados.
6. Aceite quando: a prévia sobe e responde 200 logada; o marco do dono abre; o dono responde; a CI do PR continua verde.

**Ainda não pode ser validado:** a prévia no ar, as capturas e o comportamento no navegador. Falta a prévia subir.
