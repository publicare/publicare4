---
titulo: Inventário do produto contra o manual
resumo: Tabela de telas do painel e comandos de bin/pbl, cada um com o guia que o explica, e as lacunas que ainda restam.
---

# Inventário: o que o produto faz contra o que o manual cobre

Este arquivo **não é publicado** no manual. Um teste automático (`tests/Manual/InventarioTest.php`) lê a tabela e **falha** se uma tela
(`app/src/Admin/TelasDe*.php`) ou um comando (`bin/pbl`) não estiver listado, se o guia apontado não existir, ou se o guia de um comando
não citar o comando. Quem cria uma tela ou um comando acrescenta a linha aqui e escreve o guia no mesmo PR.

Em **Guia**, ponha o nome do arquivo em `docs/usuario/`. Para o que não é de quem usa o site (manutenção do projeto, migração), ponha
`fora` e diga o motivo na coluna **Observação**.

## Telas do painel

| Item | Guia | Observação |
|---|---|---|
| TelasDeAtualizacao | atualizar.md | |
| TelasDeBackup | copias-de-seguranca.md | |
| TelasDeClasses | tipos-de-conteudo.md | |
| TelasDeConfiguracoes | primeiros-passos.md | Seção "Ajustar o site: Configurações". |
| TelasDeContas | usuarios-e-permissoes.md | |
| TelasDeConteudo | escrever-e-publicar.md | |
| TelasDeConvite | usuarios-e-permissoes.md | |
| TelasDeCron | tarefas-agendadas.md | |
| TelasDeDominios | dominios-e-varios-sites.md | |
| TelasDeEmail | email-do-site.md | |
| TelasDeLixeira | escrever-e-publicar.md | |
| TelasDeMais | painel.md | |
| TelasDeModelos | modelos-e-demo.md | Modelos opcionais (agenda, perguntas frequentes…). |
| TelasDeNavegacao | painel.md | |
| TelasDeObjetos | escrever-e-publicar.md | |
| TelasDePerfil | usuarios-e-permissoes.md | |
| TelasDeSenhaProvisoria | recuperar-acesso.md | |
| TelasDePilha | escrever-e-publicar.md | Copiar, recortar e colar. |
| TelasDePublicacao | escrever-e-publicar.md | |
| TelasDeRevisao | revisao-e-historico.md | |
| TelasDeSite | peles-e-moldes.md | |
| TelasDeVencidos | escrever-e-publicar.md | |
| TelasPublicasDeConta | recuperar-acesso.md | Esqueci a senha e o usuário, convite aceito pelo link. |

## Comandos de `bin/pbl`

| Item | Guia | Observação |
|---|---|---|
| agendados | tarefas-agendadas.md | |
| assinar | fora | Dono do projeto: assina releases. Está na documentação técnica. |
| atualizar | atualizar.md | |
| backup | copias-de-seguranca.md | |
| conta-anonimizar | privacidade.md | |
| conta-convidar | usuarios-e-permissoes.md | |
| conta-destravar | recuperar-acesso.md | |
| conta-exportar | privacidade.md | |
| conta-recuperar | recuperar-acesso.md | |
| conta-senha | recuperar-acesso.md | |
| conteudo | agentes-e-scripts.md | |
| converter-moldes | pbl4-migrando-do-pbl3.md | |
| cron | tarefas-agendadas.md | |
| demo-popular | modelos-e-demo.md | |
| demo-resetar | modelos-e-demo.md | |
| demo-senha | modelos-e-demo.md | |
| dominio | dominios-e-varios-sites.md | |
| empacotar | fora | Mantenedor: monta o pacote de uma release. |
| gerar-chaves | fora | Dono do projeto: chaves de assinatura. |
| banco-trocar | atualizar.md | |
| importar-pbl3 | fora | Migração do PBL3; está na documentação técnica. |
| instalar | instalar.md | |
| limpar-uploads | arquivos-e-imagens.md | |
| manual-gerar | fora | Gera este manual; está em docs/tecnica/manual.md. |
| publicare-oficial-carregar | fora | Carga do conteúdo do site oficial do Publicare; uso do operador do projeto, procedimento em docs/tecnica/site-oficial.md. |
| migrar | atualizar.md | |
| modelos | modelos-e-demo.md | |
| publicar | tarefas-agendadas.md | |
| redirecionamento | dominios-e-varios-sites.md | |
| reverter | atualizar.md | |
| sanitizar-html | fora | Manutenção de bases antigas; está na documentação técnica. |
| usuario | usuarios-e-permissoes.md | |
| verificar-assinatura | fora | Dono do projeto: confere a assinatura antes de publicar a release. |
| verificar-atualizacao | atualizar.md | |
| versao | atualizar.md | |

## Lacunas encontradas e situação

| Lacuna (antes) | Situação |
|---|---|
| Faltavam guias por tarefa: primeiros passos, escrever e publicar, revisão e histórico, tipos de conteúdo, peles e moldes, domínios, usuários e permissões, e-mail do site, tarefas agendadas, busca, arquivos e imagens, acessibilidade, solução de problemas | Escritos. |
| Os guias antigos traziam número de versão, estado do projeto e promessas de futuro | Reescritos; "estado do projeto" e "como participar" ficaram fora do manual (`publico: contribuicao`). |
| Configurações do site (abas) sem guia | Coberto em primeiros-passos.md. |
| Nenhum guia tinha imagem | Imagens entram por captura automática (`capturas.json`), geradas pela esteira da prévia a cada release. |
| Criar um segundo site depois da instalação não tem tela nem comando de uso geral | Aberta. O manual diz isso em dominios-e-varios-sites.md; o site do manual é criado por `bin/pbl manual-gerar --criar-site`. |
| Lixeira, Vencidos e a tela da busca não têm item de menu | Aberta (é do produto, não do manual); os guias dão o endereço. |
