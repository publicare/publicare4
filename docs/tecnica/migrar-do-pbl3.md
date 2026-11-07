# Como migrar um site do PBL3

Passo a passo para levar um site do PBL3 (conteúdo, contas e arquivos) para o PBL4. As decisões e o mapeamento completo estão no [ADR 0027](../adr/0027-importador-pbl3.md).

Em uma frase: **primeiro simule, leia o relatório, depois grave**. A base do PBL3 nunca é alterada (o importador só faz `SELECT`) e repetir o comando não duplica nada.

## 1. O que juntar

- Acesso de leitura ao banco do PBL3 (MySQL), **ou** um arquivo SQLite com as mesmas tabelas. Se o seu banco é PostgreSQL, exporte as tabelas para SQLite (por exemplo com `pgloader` ou com um dump em `INSERT` carregado numa base SQLite) e aponte o importador para o arquivo.
- A pasta de arquivos do PBL3 (o `uploadpath` da configuração), com as subpastas `0000`, `0001`, ...
- A pasta de moldes e peles do PBL3 (para o passo 3).

## 2. Instalar o PBL4 e preparar

1. Instale o PBL4 ([instalação](instalacao.md)) e rode `bin/pbl migrar` (a migração 25 cria a tabela do importador).
2. Faça backup de `dados/` (se o site de destino já tem conteúdo que você quer manter).

## 3. Converter os moldes (aparência)

```
bin/pbl converter-moldes /caminho/do/pbl3/html dados/peles/minha-pele
```

Leia o `RELATORIO.md` gerado. A pele convertida é independente do importador; o mapeamento entre o nome da pele do PBL3 e a do PBL4 é dado ao importador com `--pele`.

## 4. Simular a importação

```
PBL3_BANCO_SENHA='...' bin/pbl importar-pbl3 \
  --origem-mysql=servidor/banco --origem-usuario=leitura \
  --uploads=/caminho/do/uploadpath \
  --pele publica=minha-pele \
  --relatorio-json=relatorio.json
```

Com SQLite: `--origem-sqlite=/caminho/base.sqlite`. Sem `--gravar` é só simulação: percorre tudo, desfaz no fim e mostra o relatório. Nenhum arquivo é copiado.

Opções úteis:

| Opção | Para quê |
|---|---|
| `--site=ID` | obrigatório se a instalação tem mais de um site |
| `--raiz=N` | importar só o objeto N do PBL3 e o que está abaixo (uma instalação PBL3 multi-site tem um objeto de topo por site) |
| `--pai-destino=N` | objeto daqui sob o qual a raiz entra (padrão: a raiz do site) |
| `--mapa-tabelas=arq.json` | nomes de tabelas e colunas que o seu PBL3 renomeou, ex.: `{"objeto": {"nome": "obj"}}` |
| `--sem-lixeira` | não importar o que estava na lixeira |
| `--fuso=America/Sao_Paulo` | fuso das datas do PBL3 (que não guardam fuso) |
| `--fonte=rotulo` | nome desta base no mapa de ids; reusar o nome retoma |
| `--lote=N` | tamanho do lote de leitura e de transação (padrão 200) |

## 5. Ler o relatório

O relatório tem só números e identificadores técnicos (nunca o conteúdo do site), então pode ser enviado a quem for ajudar. Formato:

```
Relatório da SIMULAÇÃO (nada foi gravado):
  classes criadas                           N
  objetos importados                        N
  objetos importados: <tipo>                N
  arquivos importados                       N
  usuários importados                       N
  contas que exigem redefinição de senha   N
  referências resolvidas                    N
  ...
Avisos (N):
  [quantidade] tipo do aviso (ex.: exemplo 1, exemplo 2, ...)
```

O que olhar:

- **objeto não importado**: o tipo não aceita aquele filho, valor recusado ou classe desconhecida. A subárvore daquele objeto fica de fora; o aviso diz qual objeto.
- **propriedade extra criada na classe do PBL4**: campo de classe de fábrica (subtítulo e veículo de notícia, crédito de imagem, texto de pasta...) que não tem par na classe padrão. Ele vira uma propriedade extra da classe, com o valor guardado em cada objeto. Na simulação é a lista do que seria criado.
- **propriedade do PBL3 sem lugar no PBL4 (descartada)**: só quando a classe de destino já está no limite de 100 propriedades; o aviso diz o motivo.
- **propriedade renomeada** e **obrigatória importada como opcional**: informativos.
- **arquivo ausente**: o `uploadpath` não tem o arquivo. Imagens e arquivos para download exigem o arquivo: **sem `--uploads` (ou com o arquivo ausente) esses objetos não são importados**. Informe sempre `--uploads` numa importação real.
- **perfil do PBL3 sem equivalente**: acesso descartado; conceda de novo com `bin/pbl usuario conceder`.
- **versões em formato não reconhecido**: sem consequência para o conteúdo atual.

## 6. Gravar

Se o relatório está aceitável, rode o mesmo comando com `--gravar`. É seguro interromper e repetir: o que já veio é pulado e o resto continua. Para trazer objetos criados depois no PBL3, rode de novo com o mesmo `--fonte`.

## 7. Depois de gravar

1. **Senhas**: nenhuma senha do PBL3 é importada. Defina uma nova para cada conta: `bin/pbl usuario senha <login>`. As contas inválidas no PBL3 entram inativas; e-mail inválido vira `login@importado.invalid` (corrija em Contas).
2. **Tipos de conteúdo**: as classes personalizadas nascem aceitando qualquer filho. Refine em "Tipos de conteúdo".
3. **Pele e menu**: confira o `no_menu` (veio de `incluirmenu`), a pele por seção e republique (`bin/pbl publicar`).
4. **Domínios**: cadastre-os (`bin/pbl dominio`).
5. Confira por amostragem: páginas, imagens, arquivos para download, links entre objetos (as referências são reescritas para os novos números).

## Limites conhecidos

Campos extras das classes de fábrica (por exemplo subtítulo, veículo, autor e destaque de notícia, crédito de imagem, texto de pasta) **são preservados**: viram propriedades extras da classe padrão do PBL4 (aparecem em "Tipos de conteúdo" e os moldes os leem por `<@campo@>`); os moldes padrão não os exibem, então ajuste o molde se quiser mostrá-los. Hashes de senha, moldes e `script_exibir`, logs e workflow antigos, `classexfilhos` e perfis fora de Administrador/Editor/Autor/Restrito não são importados; a lista completa e o motivo de cada um estão no ADR 0027. Bases muito antigas (sem `url_amigavel`, `versaoobjeto` ou `incluirmenu`) são aceitas: o que falta é só deixado de lado.

## Desempenho

A importação passa pelas mesmas regras do painel (validação, sanitização, índice de busca, versão e auditoria), então é mais lenta que uma cópia direta: em hardware modesto conte com alguns objetos por segundo. Rode dentro de `screen`/`tmux` e use `--raiz` para importar por partes em bases grandes.
