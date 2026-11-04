# ADR 0025 — Pilha: copiar, recortar e colar objetos

Status: aceito (2026-11-04). Estende o [ADR 0012](0012-administracao-web.md) (administração) e usa a fila do [ADR 0023](0023-cron-central-e-republicacao-em-lotes.md). A tabela é aditiva ([ADR 0002](0002-schema-evolucao-aditiva.md)).

## Contexto

O PBL3 tinha uma "pilha" por conta: separar objetos e colá-los em outra pasta, movendo, copiando ou criando um link. O PBL4 só movia um objeto por vez (tela de mover) e não copiava. Reorganizar seções inteiras ou reaproveitar um conteúdo exigia refazer tudo à mão.

## Decisão

1. **Tabela `pilha` (migração 23)**: `usuario_id`, `site_id`, `objeto_id`, `modo` (`copiar` ou `recortar`), `versao` (a que a pessoa viu ao separar) e `criado_em`; única por (conta, objeto). Guarda só referências: nada muda até colar. Uma pilha tem um site e um modo; separar de outro site ou no outro modo recomeça a pilha. Limite de 200 itens.
2. **Separar**: na tela Conteúdo (lote e menu "Mais" de cada linha) e na tela do objeto ("Copiar para a pilha" e "Recortar para a pilha"). Copiar exige Ver; recortar exige Editar na origem. Itens sem permissão, na lixeira, de outro site ou a raiz são ignorados e contados no aviso.
3. **Colar aqui** numa pasta de destino, com prévia antes de gravar (o que será feito e o que será recusado, item a item) e confirmação por POST com CSRF. Três modos:
   - **Mover** (pilha de recorte): usa `ObjetoRepositorio::mover` com a versão guardada; se o objeto mudou depois de recortado, o item é recusado.
   - **Colar cópia**: novo objeto "Cópia de …", `privado` (rascunho), endereço amigável único no destino, propriedades copiadas; arquivos e imagens ganham nova linha em `arquivo` apontando para o **mesmo blob** (sem duplicar no disco). Opção de **cópia profunda** (descendentes fora da lixeira, preservando a árvore).
   - **Colar link**: cria um objeto da classe `link` com `destino_interno` apontando para o original (útil onde a classe do original não é aceita).
4. **Regras**: exige Criar no destino; a classe do destino precisa aceitar a do item (`Classe::aceita`); nunca colar dentro de si mesmo ou de um descendente; no máximo 200 objetos criados por colagem (conta a subárvore). Cada item roda em transação própria: o resultado lista sucessos e erros por item e um erro não desfaz os demais. Auditoria `pilha.colar` (e `pilha.colar.negado`).
5. **Recortar exige Editar, e não Apagar** como a tela "mover" existente: a pilha é fluxo de organização e a mudança de pai não destrói conteúdo. Quem só edita o que é seu só recorta o que é seu.
6. **Republicação**: itens publicados movidos ou ligados passam pelo Publicador. Até 25 objetos afetados são republicados na hora; acima disso a colagem entra na `FilaDeRepublicacao` (origem `pilha`), consumida pelo cron.

## Consequências

- Copiar não duplica arquivos no disco; a limpeza de blobs precisa contar por chave (já é assim) e não apagar o blob enquanto outra linha o usar.
- Cópias nascem privadas: nada vai ao ar sem decisão de quem pode publicar.
- Uma pilha por conta, de um site e um modo, mantém a tela simples; copiar e recortar ao mesmo tempo ficam fora.
- **Atenção à numeração**: esta migração é a 23 por reserva (as 21 e 22 estão em PRs paralelos). O `Migrador` só aplica versões maiores que a máxima registrada: se a 23 for aplicada antes da 21 ou 22, estas deixam de rodar num banco já migrado. Integre os PRs na ordem 21, 22, 23.
