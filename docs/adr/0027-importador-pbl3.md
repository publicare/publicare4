# ADR 0027 — Importador de bases do PBL3

Status: aceito (2026-11-06). Fase 9 do [plano](../plano.md). Complementa o conversor de moldes ([linguagem PBL4](../linguagem-pbl4.md)): o conversor leva a **aparência**, o importador leva o **conteúdo**. A migração de esquema é aditiva ([ADR 0002](0002-schema-evolucao-aditiva.md)).

## Contexto

O PBL3 guarda tudo em EAV: `objeto` (árvore por `cod_pai`) e um valor por linha em `tbl_string`, `tbl_text`, `tbl_integer`, `tbl_float`, `tbl_date`, `tbl_boolean`, `tbl_objref` e `tbl_blob`, com o tipo vindo de `propriedade.cod_tipodado`. Quem migra tem um banco (MySQL ou PostgreSQL) e a pasta de arquivos (`uploadpath`). O critério de pronto da fase 9 é o do plano: um site real em PBL3 rodando no PBL4.

## Decisão

Comando `bin/pbl importar-pbl3`, **só leitura na origem** (só `SELECT`), com simulação como comportamento padrão.

### Origem e opções

- `--origem-sqlite=ARQUIVO` ou `--origem-mysql=servidor[:porta]/banco` (senha só pela variável `PBL3_BANCO_SENHA`, nunca na linha de comando; usuário em `--origem-usuario`). Bases PostgreSQL entram por um arquivo SQLite exportado (o guia mostra como).
- **Sem `--gravar` nada é gravado**: roda o mesmo caminho dentro de uma transação que é desfeita no fim e imprime o relatório. `--simular` só deixa a intenção explícita; usar `--gravar` e `--simular` juntos é erro. Na simulação nenhum arquivo é copiado: só se confere que ele existe.
- `--site` (obrigatório se houver mais de um), `--raiz` (objeto do PBL3 a importar com a subárvore; sem ele, os objetos de topo), `--pai-destino` (objeto daqui sob o qual a raiz entra; sem ele, a raiz do site), `--uploads` (pasta de arquivos), `--mapa-tabelas` (JSON com nomes físicos diferentes do padrão), `--fonte` (rótulo da base no mapa de ids), `--fuso`, `--pele prefixo=pele` (repetível), `--sem-lixeira`, `--lote`, `--relatorio-json`.

### Idempotência e retomada

Tabela `importacao_pbl3` (migração 25): `(fonte, entidade, id_origem) → id_destino`, única por trio. Cada classe, conta e objeto importado é registrado ali **na mesma transação** que o cria. Rodar de novo com a mesma `--fonte` pula o que já veio e continua do resto (inclusive objetos novos na origem). Uma `--fonte` diferente trata a base como outra e importa tudo de novo.

### Execução em lotes

A árvore é percorrida **por níveis**: os filhos da fronteira vêm da origem em lotes por chave (`cod_objeto > último`, `--lote`, padrão 200), a fronteira é fatiada em grupos de 500 e há guarda contra ciclo. Uma transação por lote e um ponto de salvamento por objeto: um objeto que falha (tipo que não aceita o filho, valor recusado) vira aviso no relatório e **sua subárvore é pulada** ("o pai não foi importado"); o resto segue. Nada carrega a base inteira na memória. Os valores EAV de cada lote são lidos com um `SELECT ... IN` por tabela de tipo.

### Mapeamento

**Classes e propriedades.** As classes de fábrica do PBL3 (pelo prefixo, senão pelo nome) caem nas classes padrão do PBL4: `folder`/pasta → `pasta`; `document`/documento → `pagina` (`texto`→`corpo`, `resumo`→`resumo`); `imagem` → `imagem` (`conteudo`→`imagem`, `legenda`→`legenda`); `arquivo` → `arquivo` (`conteudo`→`arquivo`); `link` → `link` (`endereco`→`destino_externo`); `interlink` → `link` (`endereco`→`destino_interno`); `noticia` → `noticia`. Propriedade sem par na classe padrão vira uma **propriedade extra** dessa classe (ver a revisão de 2026-11-07 ao fim). Qualquer outra classe vira uma **classe nova** (nome técnico a partir do prefixo, sufixo `_pbl3` se já existir, aceita filhos, sem restrição de filhas). Tipos: string → `texto`; text → `html`; integer e float → `numero`; date → `data`; boolean → `booleano`; objref → `ref`; blob → `imagem` se a maioria dos arquivos da propriedade for imagem, senão `arquivo`. Nomes reservados ou repetidos ganham sufixo `_campo` (aviso). Propriedade obrigatória no PBL3 entra como **opcional** (aviso), porque o conteúdo antigo costuma ter lacunas. Limite de 100 propriedades por classe: o excesso é descartado com aviso e motivo.

**Objetos.** Entram pelo mesmo `ObjetoRepositorio::criar` do painel (validação, sanitização do HTML, índice de busca, versão 1, auditoria). A árvore `cod_pai` vira `pai_id` e o caminho materializado. Status: PBL3 1 → privado, 2 → publicado, 3 → rejeitado, 4 → submetido (`Conversao::STATUS`); `apagado = 1` → lixeira (`--sem-lixeira` ignora). Peso, descrição, datas de publicação e validade (inteiros `AAAAMMDDHHMMSS`, em hora local, convertidos de `--fuso` para UTC), pele só por `--pele prefixo=pele` (sem o mapeamento a pele do objeto fica herdada), e autor pelo mapa de contas. **`incluirmenu` → `no_menu`**, lido da coluna `objeto.incluirmenu` quando existe ou de uma propriedade com esse nome. Endereço amigável: o `url_amigavel` quando a base tem a coluna, senão o título; o endereço é único no site, então repetidos ganham `-2`, `-3` (contados no relatório). Objeto de classe desconhecida é pulado com aviso.

**Valores EAV → JSON.** O valor de cada linha vai para a chave da propriedade já convertido ao tipo de destino (número, booleano, data ISO em UTC, HTML sanitizado pelo mesmo caminho do painel). Texto que não é UTF-8 é lido como Latin-1 (contado).

**Referências (`objref`).** O destino é um id do PBL3; só depois de importar tudo ele é conhecido. Uma **segunda passada** sobre o mapa de ids reescreve `objeto.dados`, `objeto_indice` e a versão atual, e é retomável. Referência para objeto que não veio fica vazia (aviso, contado).

**Versões.** `versaoobjeto.conteudo` do PBL3 é, na prática, uma serialização do objeto sem formato estável. Só é aproveitado o que for JSON com `titulo` textual; vira versões `1..k`, e a versão de "criação" do PBL4 é renumerada para `k+1` com o motivo "importação do PBL3". O que não for reconhecido é descartado e contado. Bases sem `versaoobjeto` (as mais antigas) simplesmente ficam só com a versão de criação.

**Contas e perfis.** `usuario` → `usuario`: login normalizado ao padrão do PBL4 (minúsculas, sem espaços e símbolos; sufixo `-N` em colisão), e-mail inválido ou ausente vira `login@importado.invalid`, `valido = 0` vira conta inativa. **Os hashes de senha do PBL3 (MD5) não são importados**: a conta recebe um hash inutilizável e a pessoa precisa de uma nova senha (`bin/pbl usuario senha <login>`; o relatório conta quantas contas exigem isso). Perfis por objeto (`usuarioxobjetoxperfil`) viram acessos por subárvore: Administrador na raiz importada → administrador global; Editor → editor; Autor → autor; Restrito → leitor; qualquer outro perfil é descartado com aviso. Havendo mais de um perfil no mesmo par (conta, objeto), vale o mais forte.

**Arquivos (`tbl_blob`).** O arquivo está em `<uploadpath>/<pasta>/<cod_blob>.<extensão>`, onde a pasta é `0000` para ids de até três dígitos, senão o milhar com quatro dígitos. Cada arquivo passa pelo `ServicoDeUpload` (tipo real pelo conteúdo, limites, recodificação de imagem, ligação ao objeto), como se tivesse sido enviado pelo painel. Arquivo ausente ou recusado vira aviso e o objeto entra sem ele. A coluna `tbl_blob.cod_objeto` aparece como `cod_obeto` no esquema MySQL de referência do PBL3; o importador tenta a grafia alternativa.

**Tags e multi-domínio.** `tag`/`tagxobjeto` → tags do objeto (nome truncado em 40, no máximo 20 por objeto; o excesso é descartado e contado). O PBL3 multi-domínio vive na árvore (um objeto de topo por site): cada importação traz **uma árvore para um site do PBL4**, escolhida por `--raiz`; domínios continuam sendo cadastrados no PBL4 (`bin/pbl dominio`). Várias raízes de um mesmo banco viram várias execuções com `--site`/`--pai-destino` diferentes.

**Nomes físicos.** O PBL3 permite renomear tabelas e colunas na configuração. `--mapa-tabelas=arquivo.json` aceita `{"objeto": {"nome": "obj", "colunas": {"cod_objeto": "id"}}}`; tabela ou coluna lógica inexistente no mapa é recusada.

**Relatório.** Contagens por entidade e avisos agrupados por tipo com poucos exemplos. Contém só números e identificadores técnicos (nunca o conteúdo), para poder ser enviado a quem ajuda. Além do texto na tela, `--relatorio-json`. Cada execução grava um evento de auditoria `importacao.pbl3` (fonte, simulação, contagens).

### Reuso do conversor de moldes

O importador não converte moldes: o conversor (`bin/pbl converter-moldes`) continua sendo um passo separado do guia ([Como migrar um site do PBL3](../tecnica/migrar-do-pbl3.md)), que também orienta a relação entre `--pele` e a pele convertida.

## O que NÃO é importado, e por quê

- **Senhas**: MD5 sem sal não é aceitável como armazenamento; a redefinição é obrigatória.
- **Moldes e `script_exibir`**: são código de apresentação, tratados pelo conversor; o campo por objeto não tem equivalente direto.
- **Histórico de logs** (`logobjeto`, `logworkflow`), pendências, workflow, `classexobjeto` e `infoperfil`: o PBL4 tem auditoria e fluxo de revisão próprios e o histórico antigo não cabe neles sem inventar eventos.
- **Versões sem JSON reconhecível**: o PBL3 não guardava um formato lido de forma confiável.
- **Perfis fora de Administrador/Editor/Autor/Restrito** e **ligações entre classes** (`classexfilhos`): classes novas aceitam qualquer filho; refine em "Tipos de conteúdo".
- **Pele por objeto** sem `--pele`: o nome da pele do PBL3 não diz qual pele do PBL4 usar.
- **Data de criação e de alteração originais**: o PBL3 não as guarda de forma uniforme; o objeto nasce com a data da importação.
- **Campos extras de classes de fábrica**: *revisado em 2026-11-07*, ver a nota ao fim; já não são descartados. `interlink.endereco` (inteiro) e `noticia.imagem` (blob) são aproveitados; o link interno guarda um endereço provisório até a segunda passada resolver o alvo.
- **Usuário do tipo "chefia"/ramal/seção**: não há campo equivalente.

## Consequências

- Importar é seguro de repetir e de interromper; a simulação é grátis e mostra o que vai acontecer antes de qualquer gravação.
- A migração 25 só acrescenta uma tabela; a versão anterior do código a ignora.
- Bases muito antigas ou customizadas aparecem como avisos no relatório, não como falhas: o dono do site decide o que refinar.
- O desempenho é limitado pelo mesmo caminho de criação do painel (validação, índice, versão, auditoria): é lento por objeto de propósito, em troca de nada entrar fora das regras.

## Revisão de 2026-11-07: campos extras de classes de fábrica são preservados

A decisão original descartava (e contava) as propriedades de classes de fábrica do PBL3 sem lugar na classe padrão do PBL4 (notícia: subtítulo, veículo, autor, link, destaque; imagem: crédito; pasta: texto; link: descrição; pasta: `menu_proprio`; interlink: âncora; arquivo: resumo). O dono do projeto recusou perder qualquer campo com valor, então o importador passou a **acrescentar a propriedade à própria classe de destino**.

- **Por que na classe padrão e não numa classe derivada.** As classes fixas (ADR 0015) só protegem as propriedades da base (`ClassesPadrao::nomesDaBase`): acrescentar propriedades é permitido (`ClasseRepositorio::atualizar` só recusa remover ou arquivar a base). A migração 21 e `garantirFixas` são aditivas e casam por nome, então uma propriedade extra não é tocada por atualizações; se uma versão futura trouxer uma propriedade de base com o mesmo nome, a classe já a tem e nada se duplica. Manter os objetos na classe padrão preserva moldes, menu, republicação e o ícone da classe; uma classe nova obrigaria a refazer tudo isso.
- **Tipos.** Os mesmos das classes personalizadas: string → `texto`; text → `html`; integer e float → `numero`; date → `data`; boolean → `booleano`; objref → `ref`; blob → `imagem` ou `arquivo`. Rótulo vem do PBL3 (senão o nome). Campos de texto, número, data e booleano entram no índice de busca; o valor fica no JSON do objeto e o molde o lê por `<@campo@>`.
- **Nomes.** O nome técnico sai do nome da propriedade. Nome reservado (por exemplo `descricao`) ou já usado com outro tipo ganha o sufixo `_campo` (aviso). Uma propriedade de mesmo nome e tipo que já existe na classe é reaproveitada: é isso que torna a importação repetível (reimportar não duplica nem sobe a versão da classe sem necessidade). Uma propriedade nova na origem, numa nova rodada, é acrescentada.
- **Simulação.** Corre o mesmo caminho dentro da transação desfeita: o relatório lista (`propriedade extra criada na classe do PBL4`) as propriedades que seriam criadas.
- **O que ainda é descartado.** Só o que não cabe: a classe de destino já estar no limite de 100 propriedades (aviso com o motivo), mais os casos que nunca foram campo (valor de propriedade com vários valores guarda o primeiro, referência para objeto que não veio fica vazia, arquivo ausente em disco).
