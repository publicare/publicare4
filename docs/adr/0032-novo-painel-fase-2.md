# 0032. Novo painel, fase 2 (adendo ao ADR 0031)

Status: aceito

## Contexto
A fase 1 reorganizou menu, edição de conteúdo e início. Atualização, cópias, tarefas, contas, tipos, peles, domínios e revisão seguiam em "um tripão".

## Decisão
- Reusar os componentes de `pbl-formulario.css` (cabeçalho de tela, abas, seção recolhível, cartão de estado, estado vazio); nenhuma variação nova.
- Cada tela tem uma ação principal visível; o resto vai para abas (um formulário/mesma página) ou seções recolhíveis (`<details>`).
- Quando as partes são telas distintas (modelo de página: Código · Histórico · Pré-visualizar), as abas são links com `aria-current`; sem JavaScript tudo funciona.
- Aprovação em lote em Revisar: `POST /_pbl/revisao/aprovar-lote` com `itens[]=id:versão`, no máximo 50; cada item passa por `FluxoDeRevisao::aprovar` (permissão e versão conferidas individualmente), com origem e CSRF como nas demais ações.
- Ícone do tipo de conteúdo: formulário próprio (`#form-icone`) com os controles na aba Aparência ligados por `form=`, evitando formulários aninhados.

## Consequências
Rotas, permissões e CSRF inalterados (rotas antigas seguem em 303). Testes PHPUnit cobrem as telas tocadas.
