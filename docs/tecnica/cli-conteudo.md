# `bin/pbl conteudo`: editar conteúdo por linha de comando

Interface para **agentes** (por exemplo um assistente que recebe pedidos por mensagem) e para **scripts** editarem conteúdo sem passar pelo painel (login, CSRF e formulários). Decisões: [ADR 0030](../adr/0030-cli-de-conteudo-e-conta-de-servico.md). Guia para quem usa: [Agentes e scripts editam o site](../usuario/agentes-e-scripts.md).

Tudo passa pelo `Pbl\Conteudo\Servico\ServicoDeConteudo` (o mesmo caminho de regras do painel: `Permissoes::pode`, validação por tipo, limpeza de HTML, versões, endereços e 301, auditoria, fluxo de revisão, cache e republicação). Não há SQL direto na camada do CLI (um teste confere).

## Opções comuns

| Opção | Significado |
|---|---|
| `--site=<id\|domínio>` | O site. Obrigatório se a instalação tiver mais de um (nunca assume "o primeiro"). |
| `--como=<login>` | A conta cujas permissões valem. Obrigatória. Inexistente → saída 3; inativa → saída 4. |
| `--json` | Saída em JSON estável (`api=1`, uma linha). Sem ela, texto legível. |
| `--json=-` | Lê o conteúdo a gravar do **stdin** (e liga a saída em JSON). |
| `--campo nome=valor` | Repetível. Vence o que veio no stdin. |
| `--versao=N` | **Obrigatória** em toda mudança de conteúdo existente: a versão que você viu em `ver`. |
| `--confirmar` | Obrigatória nas operações destrutivas. Sem ela nada é feito (saída 6). |

> Ponha `--json` depois dos argumentos (`ver 12 --como=claudim --json`). O Symfony pode tomar o argumento seguinte como valor da opção; o comando devolve esse valor ao argumento que faltava, mas a ordem recomendada evita surpresa.

`<id|caminho>`: um número é o id; qualquer outra coisa é o endereço no site (`noticias/aviso`; `/` é a página inicial).

## Subcomandos

| Comando | O que faz |
|---|---|
| `listar [--pai=] [--arvore] [--estado=] [--tipo=] [--busca=] [--limite=]` | Conteúdo que a conta pode ver. Sem `--pai`: os filhos da página inicial (ou tudo que a conta vê, se ela não vê a inicial). `--estado`: `privado`, `submetido`, `rejeitado`, `publicado`, `apagado` (padrão: tudo menos a lixeira). `--limite` padrão 50, teto 200. |
| `ver <id\|caminho>` | Todos os campos, estado, versão, endereço, pai, filhos, imagens, permissões da conta. |
| `historico <id>` | Versões, com autor (e se é agente). |
| `editar <id\|caminho> --versao=N [--campo …] [--json=-] [--publicar \| --enviar-revisao]` | Grava. `--publicar` grava e publica numa só operação (só quem pode publicar; senão saída 4 e **nada** é gravado). `--enviar-revisao` grava e envia para revisão. Sem diferença real: saída 0, `alterado:false`, nenhuma versão nova. |
| `criar --pai= --tipo=<classe> [--campo …] [--json=-]` | Cria **privado**. Respeita tipos aceitos no pai, obrigatórios e endereço único (sem `url`, gera do título: `-2`, `-3`…). |
| `publicar`, `despublicar <id> --versao=N` | Estado. `publicar` de conteúdo enviado para revisão aprova (registra no fluxo de revisão). Idempotentes. |
| `enviar`, `retirar <id> --versao=N` | Fluxo de revisão (autor envia; quem enviou retira). |
| `mover <id> --para=<id\|caminho> --versao=N --confirmar` | Muda o pai (o endereço antigo passa a redirecionar). |
| `lixeira <id> --versao=N --confirmar` | Manda para a lixeira. **Nunca apaga de vez** (não existe caminho para isso no CLI). Recusa a página inicial e conteúdo com filhos fora da lixeira. |
| `restaurar-versao <id> --para=<versão> --versao=N --confirmar` | Volta o conteúdo de uma versão antiga como versão nova; o estado atual (publicado ou não) é mantido. |
| `imagem <id> --campo= --arquivo=<caminho> --alt="…" --versao=N` | Anexa imagem local ao campo. `--alt` obrigatório. |
| `arquivo <id> --campo= --arquivo=<caminho> --versao=N` | Anexa arquivo local ao campo. |
| `republicar --confirmar [--orcamento=S]` | A republicação em lotes do painel, retomável (`concluido:false` → rode de novo). Padrão 25 s, teto 120. Exige poder administrar o site (como na tela "Publicação"). |

### Campos

Nomes reservados do conteúdo: `titulo`, `descricao`, `url`, `peso`, `no_menu`, `rotulo_menu`, `tags` (separadas por vírgula). Os demais são propriedades do tipo (`resumo`, `corpo`…); `dados.<nome>` desambigua. Campo que o tipo não tem é **erro** (6), nunca ignorado. Valor vazio remove o campo. Campos `arquivo`/`imagem` só se anexam com `imagem`/`arquivo`.

Texto formatado (`html`) passa pela **mesma** `HtmlSeguro::limpar` do painel; HTML que fica vazio depois da limpeza é recusado.

Entrada por stdin (`--json=-`), objeto JSON; chaves desconhecidas são erro:

```json
{"titulo": "Novo título", "tags": ["um", "dois"], "dados": {"corpo": "<p>Texto</p>", "resumo": "Resumo"}}
```

`campos` é aceito como sinônimo de `dados` (a mesma forma do `ver`). Valores: texto, número ou booleano (`true` → `1`).

## Códigos de saída

| Código | Significa | `erro.codigo` (JSON) |
|---|---|---|
| 0 | Sucesso (inclui "nada a alterar") | — |
| 1 | Erro geral inesperado | `erro` |
| 2 | Uso inválido (ação desconhecida, falta `--como`/`--pai`/…, site ambíguo) | `uso` |
| 3 | Não encontrado: site, conta, conteúdo, **ou conteúdo que a conta não pode ver** | `nao_encontrado` |
| 4 | Sem permissão: conta inativa, ação não permitida | `sem_permissao` |
| 5 | Conflito: versão desatualizada, árvore (filhos, destino na lixeira), estado | `conflito_de_versao`, `conflito_de_arvore`, `estado_invalido` |
| 6 | Validação: campo/tipo inválido, obrigatório, tipo não aceito, endereço em uso, upload inválido, entrada grande, falta `--versao`, falta `--confirmar` | `validacao`, `confirmacao_necessaria` |

Os números são parte do contrato a partir da primeira versão publicada com este comando.

## Contrato `--json` (`api=1`)

Sempre um objeto, uma linha, UTF-8, com `JSON_HEX_TAG|AMP|APOS|QUOT` (conteúdo com `<` e `&` sai escapado). Tudo em stdout, inclusive erros.

```json
{"ok":true,"api":1,"acao":"editar","dados":{"id":12,"titulo":"…","tipo":"pagina","estado":"publicado","versao":8,"url":"/quem-somos","alterado":true,"cache_atualizado":true}}
{"ok":false,"api":1,"acao":"editar","erro":{"codigo":"conflito_de_versao","mensagem":"…","versao_atual":7}}
{"ok":false,"api":1,"acao":"editar","erro":{"codigo":"validacao","mensagem":"…","campos":{"titulo":"O título é obrigatório."}}}
```

`ver` devolve `id, titulo, tipo, estado, versao, url, visivel_agora, endereco_publico, descricao, peso, no_menu, rotulo_menu, tags, data_publicacao, data_validade, pai, filhos, filhos_total, campos, esquema, permissoes{editar,publicar,apagar,criar}, atualizado_em`. Campo `arquivo`/`imagem` em `campos` vem como `{arquivo_id, nome, mime, tamanho, alt}`. Regra de evolução: só se **acrescentam** chaves; remover ou renomear exige `api=2` (teste de contrato confere).

Ciclo recomendado para um agente: `ver` → `editar --versao` → (saída 5 com `versao_atual`) → `ver` → `editar`.

## Segurança

- **`--como` identifica, não autentica.** Quem tem shell e acesso ao banco já pode tudo; o que o CLI garante é que a conta escolhida **nunca** faz mais do que faria no painel e que tudo fica na auditoria. Prefira uma conta de serviço com papel só na seção necessária e evite `--como` de administrador global.
- Nenhuma opção recebe segredo (um teste confere os nomes). Valores em `--campo` aparecem em `ps` e no histórico do shell: **texto longo ou sensível vai por `--json=-`**.
- Entrada por stdin: teto de 2 MiB, lida em blocos; UTF-8 inválido, caracteres de controle e JSON malformado são recusados. Cada campo obedece aos mesmos limites do painel.
- Saída de texto remove ESC e demais controles (conteúdo não injeta sequências de terminal); o JSON é escapado. A saída não é interpretada como estilo do console.
- Erros nunca repetem valores digitados; a auditoria guarda só nomes de campos (nunca valores). O CLI não grava log próprio.
- Funciona em hospedagem comum: sem `proc_open`, `exec`, `popen`, sem `/tmp` fixo; `imagem`/`arquivo` usam `realpath`, só arquivo comum e legível (links simbólicos recusados) e mensagem clara se o caminho está fora do `open_basedir`.
- Upload: o mesmo `ServicoDeUpload` do painel (tipo pelo conteúdo real, recodificação de imagem, miniatura). O arquivo de origem não é tocado.
- Limitação: tipo com campo `arquivo`/`imagem` **obrigatório** (por exemplo `imagem`) não pode ser criado pelo CLI, porque o arquivo só pode ser anexado depois que o conteúdo existe. Crie pelo painel.

## Conta de serviço e auditoria

`bin/pbl usuario criar claudim --servico --nome="Claudim"` cria uma conta sem senha, sem e-mail real (`claudim@servico.invalid`), que nunca é administradora e **não entra pelo painel** (três camadas: `autenticar`, criação e validação de sessão, troca de senha). Papéis por seção: `bin/pbl usuario conceder claudim editor <id-da-seção>`. Desativar: `bin/pbl usuario desativar claudim` (encerra sessões; toda ação do CLI passa a sair 4).

A auditoria mantém `ator = usuario:N` e o autor real; o detalhe ganha `via: "cli"` (só nas ações do `conteudo`). O registro de atividade do conteúdo mostra "Fulano (via linha de comando)" e, para conta de serviço, "Claudim (agente, via linha de comando)". Esquema: `usuario.tipo` (M026) e `arquivo.alt` (M027), ambos colunas aditivas.

## Testes

`tests/ConteudoComandoTest.php` (contrato, códigos, permissões, versão, validação, HTML malicioso, upload, destrutivas, funções proibidas), `tests/ContaDeServicoTest.php` (sem login web, desativar, privilégio), `tests/ConteudoAuditoriaTest.php` (autor, canal, nada de conteúdo, equivalência com o painel).
