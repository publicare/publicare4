# Linha de comando (`bin/pbl`)

No ambiente de desenvolvimento: `make pbl ARGS="<comando> …"`. Em um servidor: `php bin/pbl <comando> …`.

| Comando | O que faz |
|---|---|
| `versao` | mostra a versão do PBL4 (`Pbl\Versao::ATUAL`) |
| `instalar [--nome=…] [--host=…]` | cria `dados/`, o banco SQLite, copia a pele básica e cria um site de exemplo. Recusa rodar duas vezes (trava `dados/config/instalado.lock`) |
| `publicar` | renderiza todas as páginas visíveis de todos os sites para o cache e **remove do cache** o que deixou de estar visível (despublicado, vencido, rascunho) ou ficou órfão (a página de um endereço que mudou) |
| `converter-moldes <origem> <destino> [--seguro]` | converte moldes do PBL3 em moldes PBL4 e escreve `RELATORIO.md` |

## `dominio` e `redirecionamento`

```bash
make pbl ARGS="dominio listar"
make pbl ARGS="dominio adicionar antigo.exemplo.org --modo=redirecionar"   # 301 para o principal
make pbl ARGS="dominio adicionar apelido.exemplo.org --modo=servir"         # mesmo conteúdo + Link rel=canonical
make pbl ARGS="dominio principal novo.exemplo.org"                          # o principal anterior passa a redirecionar
make pbl ARGS="redirecionamento adicionar /Sistemas/Agenda.PHP https://agenda.exemplo.org/ --codigo=302"
make pbl ARGS="redirecionamento adicionar /antigo /novo --prefixo"          # /antigo/x → /novo/x
make pbl ARGS="redirecionamento listar"
make pbl ARGS="redirecionamento remover 3"
```

- `--site=N` escolhe o site (padrão: o primeiro). O domínio principal não pode ser removido: defina outro antes.
- Regras de redirecionamento: origem é um caminho (`/antigo`), destino é um caminho do site ou `https://…`; a regra exata vence a de prefixo, e entre prefixos vence o mais longo. Só valem para endereços que **não** são uma página publicada nem o endereço antigo de um conteúdo. A consulta (`?a=1`) do pedido é mantida.

## `converter-moldes`

```bash
make pbl ARGS="converter-moldes /caminho/do/site/html dados/peles/minha-pele"
```

- Lê arquivos `.php`, `.pbl`, `.html`, `.htm` com tags `<@…@>` (e os moldes sem tags que algum outro inclua).
- `--seguro` converte o `eco` do PBL3 (que não escapava HTML) para `eco` (que escapa) em vez de `ecocru`.
- Nunca escreve fora da pasta de destino nem através de link simbólico.
- O `RELATORIO.md` lista, por molde, o que foi convertido, o que exige revisão (`<@-- REVISAR … --@>` no próprio arquivo), campos de classe que precisam ser indexáveis e moldes incluídos que não existem na origem.
- Código de saída diferente de zero se algum resultado for rejeitado pelo parser do PBL4.

Regras completas de conversão: [linguagem-pbl4.md](../linguagem-pbl4.md#conversor-de-moldes-pbl3--pbl4).

## Quando a página aparece para o visitante

`publicar` é o que grava as páginas no cache. Páginas com `data_validade` ganham um arquivo `.expira` ao lado e deixam de ser servidas quando a data passa, mesmo sem nova publicação. Para algo entrar no ar numa data futura (`data_publicacao`), é preciso rodar `publicar` depois dessa data (por exemplo, por agendamento do servidor).
