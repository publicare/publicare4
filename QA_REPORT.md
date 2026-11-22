# QA_REPORT.md — PBL4: CLI `bin/pbl conteudo` e conta de serviço

**Veredito: GO condicional.** Nenhum defeito bloqueante. As condições estão em §5 e são de baixo custo.

## 1. Evidências (rodadas nesta etapa)
| Verificação | Resultado |
|---|---|
| Suíte completa `phpunit` | OK: 1644 testes, 46306 asserções, 2 ignorados, 1 deprecation. Duração: 14m54s |
| Testes novos (`ConteudoComandoTest`, `ContaDeServicoTest`, `ConteudoAuditoriaTest`) | OK: 59 testes, 822 asserções |
| `phpstan analyse` | Sem erros |
| `DocumentacaoTest` (links e índices) | OK |
| grep de `proc_open`, `exec`, `shell_exec`, `passthru` e SQL direto (`Connection`, `executeStatement`) em `Console/Conteudo*` e `Conteudo/Servico` | Sem ocorrências |
| grep de segredo literal nos testes novos | Sem ocorrências |
| Docs públicos | Só "Site Exemplo". Sem cliente real |

## 2. Rastreabilidade: requisito → teste
| Requisito | Teste | Status |
|---|---|---|
| Permissão negada | `testPermissaoNegadaELeitorNaoEdita`, `testContaSemPermissaoDeVerRecebeNaoEncontrado` | OK |
| Conflito de versão | `testConflitoDeVersaoDevolveAVersaoAtual`, `testEditarExigeAVersao` | OK |
| Validação de tipo | `testValidacaoPorTipoCampoDesconhecidoEObrigatorio`, `testCriarRecusaTipoQueOPaiNaoAceita…`, `testEnderecoEmUso…` | OK |
| HTML malicioso limpo | `testHtmlMaliciosoPassaPelaMesmaLimpezaDoPainel`, `testHtmlQueFicaVazioEhRecusado` | OK |
| Upload inválido | `testImagemExigeAltEValidaOConteudoReal`, `testUploadInvalidoNaoAlteraOObjeto` | OK |
| Conta de serviço sem login web | `ContaDeServicoTest` (autenticar, sessão, senha, desativação) | OK |
| Auditoria do autor | `ConteudoAuditoriaTest`, `testHistoricoMostraAutorEAgente` | OK |
| Fluxo de revisão | `testAutorSoEnviaParaRevisaoENaoPublica`, `testEditorGravaEPublica…` | OK |
| `--confirmar` e lixeira nunca definitiva | `testOperacoesDestrutivasExigemConfirmar`, `testLixeiraNunca…`, `testNaoExisteCaminhoDeApagar…` | OK |
| Stdin e limites | `testEntradaPorStdin…`, `testEntradaAcimaDoLimite…`, `testCampoAcimaDoLimite…` | OK |
| Saída segura (terminal e JSON) | `testSaidaDeTexto…`, `testSaidaJsonEscapa…` | OK |
| Sem segredo em argumentos | `testComandoNaoTemOpcaoDeSegredo` | OK |
| Painel e CLI equivalentes | Teste de equivalência, mais os `Admin*Test` inalterados | OK |

## 3. Pirâmide e risco
- **Unidade e serviço:** o validador, o serviço e os repositórios têm cobertura indireta pelos testes de CLI e pelos `Admin*` (sem regressão).
- **Integração:** SQLite real com migrações M026 e M027, atualizando os 4 testes que fixavam a versão do esquema em 25.
- **E2E por CLI:** o ciclo `ver` → `editar` → conflito → `ver` → `editar` está coberto.
- **Risco alto, com cobertura boa:** permissão, sanitização, conta de serviço.
- **Risco médio, com cobertura parcial:** ver as lacunas em §4.

## 4. Achados e lacunas (nenhum bloqueante)
| ID | Sev. | Achado | Recomendação |
|---|---|---|---|
| Q1 | Média | `open_basedir` foi verificado só à mão pelo dev. Nenhum teste automatizado cobre `imagem`/`arquivo` nem a leitura de stdin com a restrição ativa (risco R4 do plano). | Criar teste como o `LancadorDePhpOpenBasedirTest`. Não bloqueia o merge, mas deve entrar no próximo PR. |
| Q2 | Média | Dívida de arquitetura documentada no ADR 0030: salvar, criar, mover, apagar e restaurar existem em dois lugares (painel e serviço). A proteção é o teste de equivalência, que cobre editar e criar. | Estender a equivalência a mover, lixeira e restaurar-versão. Planejar a migração do painel para o serviço. |
| Q3 | Baixa | `--json` seguido de id (`ver --json 12`) é tratado de modo especial. A doc manda pôr `--json` por último. | Aceitável, e a doc cobre. Manter o teste de regressão. |
| Q4 | Baixa | Tipo com campo de arquivo ou imagem obrigatório não pode ser criado pelo CLI. | Documentado. Registrar como limitação conhecida nas notas de versão. |
| Q5 | Baixa | `--como` identifica, não autentica. Quem tem shell pode usar `--como=<admin>` (R2). | Risco aceito (P1), e a doc de segurança diz isso. Decisão do dono sobre `--permitir-admin`. |
| Q6 | Info | O texto alternativo de imagem (`arquivo.alt`) é gravado, mas ainda não é exibido nas páginas. | Planejar para o próximo ciclo. Tem impacto de acessibilidade. |
| Q7 | Info | A suíte leva ~15 min neste ambiente, e a suíte inteira passou. | Sem ação. |

## 5. Condições do GO
1. Aprovação do TL e portão do dono (DoD do plano).
2. Registrar Q1, Q2 e Q6 como itens do backlog.
3. Os commits convencionais e o CHANGELOG em `[Não lançado]` já estão no diff. Ainda falta confirmar na hora do commit que `.vsf/` não entra.

## 6. Handoff
- **QA Funcional / E2E:** exploratório manual do ciclo do Claudim com Site Exemplo (conta de serviço editor → `editar` → `--publicar` → página pública e 301 em mudança de endereço). Uma conta autor deve ficar em `submetido`. Desativar a conta deve dar saída 4 em todas as ações. Conferir os códigos de saída 0–6.
- **SEC:** revisar as três camadas de bloqueio de login, o limite de entrada e a regra de "não existe" sem permissão de ver.
