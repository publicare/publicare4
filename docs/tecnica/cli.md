# Linha de comando (`bin/pbl`)

No ambiente de desenvolvimento: `make pbl ARGS="<comando> …"`. Em um servidor: `php bin/pbl <comando> …`.

| Comando | O que faz |
|---|---|
| `versao` | mostra a versão do PBL4 (`Pbl\Versao::ATUAL`) |
| `instalar [--nome=…] [--host=…]` | cria `dados/`, o banco SQLite, copia a pele básica e cria um site de exemplo. Recusa rodar duas vezes (trava `dados/config/instalado.lock`) |
| `publicar` | renderiza todas as páginas visíveis de todos os sites para o cache e **remove do cache** o que deixou de estar visível (despublicado, vencido, rascunho) ou ficou órfão (a página de um endereço que mudou) |
| `agendados [--simular]` | aplica a passagem do tempo no cache: põe no ar o que teve a `data_publicacao` liberada e tira o que passou da `data_validade`, republicando o site só se houve mudança. **Idempotente e silencioso** quando não há nada a fazer, feito para o cron (veja abaixo). `--simular` só mostra quantos entrariam e sairiam |
| `converter-moldes <origem> <destino> [--seguro]` | converte moldes do PBL3 em moldes PBL4 e escreve `RELATORIO.md` |

## `usuario`

```bash
make pbl ARGS="usuario criar ana --email=ana@exemplo.org --nome='Ana Souza' --admin"   # pede a senha duas vezes (oculta)
make pbl ARGS="usuario conceder bia editor 12"     # bia é editora do objeto 12 e de tudo abaixo dele
make pbl ARGS="usuario acessos bia"
make pbl ARGS="usuario revogar bia 12"
make pbl ARGS="usuario senha bia"
make pbl ARGS="usuario desativar bia"              # e "ativar"
make pbl ARGS="usuario listar"
make pbl ARGS="usuario auditoria"                  # as últimas 50 ações registradas
```

- A senha **nunca** vai em argumento (apareceria no histórico e em `ps`): é pedida de forma oculta e repetida, ou lida da entrada padrão com `--senha-stdin` (`printf '%s\n' "$SENHA" | php bin/pbl usuario criar ana --senha-stdin …`).
- Perfis: `leitor`, `autor`, `editor`. `--admin` cria administrador global (todos os poderes, inclusive contas, domínios e redirecionamentos).
- Cada ação fica na auditoria com o ator `cli`.
- As mesmas operações existem pelo navegador para o administrador geral (`/_pbl/contas`), com regras a mais: a tela não desativa a própria conta nem a última de administrador ativa (a linha de comando não tem essa trava).

## `dominio` e `redirecionamento`

Os domínios também se gerenciam pelo navegador (`/_pbl/site/{id}/dominios`); aqui ficam os comandos, que também alcançam o redirecionamento de endereços antigos (ainda sem tela).

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
- As mesmas regras também se gerenciam pelo navegador (`/_pbl/site/{id}/redirecionamentos`, só administrador), com aviso de laço e de encadeamento.
- Regras de redirecionamento: origem é um caminho (`/antigo`), destino é um caminho do site ou `https://…`; a regra exata vence a de prefixo, e entre prefixos vence o mais longo. Só valem para endereços que **não** são uma página publicada nem o endereço antigo de um conteúdo. A consulta (`?a=1`) do pedido é mantida.

## `atualizar`, `migrar`, `empacotar`, `chaves:gerar`

`atualizar [--verificar] [--manifesto=ARQ] [--canal=…]` verifica e aplica uma atualização assinada; `migrar` aplica as migrações pendentes; `empacotar` e `chaves:gerar` são do mantenedor. Tudo em [atualização](atualizacao.md).

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

`publicar` é o que grava as páginas no cache. Páginas com `data_validade` ganham um arquivo `.expira` ao lado e deixam de ser servidas quando a data passa, mesmo sem nova publicação. Para algo entrar no ar numa data futura (`data_publicacao`), é preciso rodar `publicar` ou **`agendados`** depois dessa data.

## `agendados` (publicação por data)

Datas de publicação e de validade são sempre gravadas em UTC (ISO 8601). A consulta pública e os moldes só enxergam o que está dentro da vigência, mas a **página pronta** só existe no cache depois de publicada; `agendados` fecha essa lacuna: compara o banco com o cache (`Publicador::pendentes`) e, se algo deveria estar no ar e não está (ou o contrário), republica o site inteiro (assim as listas dos pais também mudam). Sem pendências não grava nada, então pode rodar com frequência. Exemplo de cron a cada 5 minutos, a partir da raiz do projeto:

```
*/5 * * * * cd /caminho/do/projeto && php bin/pbl agendados >/dev/null 2>&1
```

Quando o comando age, escreve uma linha por site (`Meu Site: 1 entrou(aram) no ar, 0 saiu(saíram) do ar; site republicado`); `--simular` mostra o que mudaria sem publicar. Código de saída diferente de zero se algo falhar.
