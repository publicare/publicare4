---
titulo: Arquivos e imagens
resumo: Como enviar imagem e arquivo, escrever o texto alternativo, conhecer os tipos e o tamanho aceitos e limpar o que ninguém mais usa.
capitulo: conteudo
ordem: 40
---

# Arquivos e imagens

Imagens e arquivos (PDF, planilhas, documentos) entram no site por **campos de envio** dos tipos de conteúdo. Os tipos **imagem** e **arquivo** já vêm prontos; a notícia aceita ambos dentro dela. Quem envia precisa ter papel de autor ou superior na seção.

## Enviar uma imagem

1. Abra **Conteúdo**, entre na pasta (ou na notícia) onde a imagem ficará.
2. Em **Mais ações**, escolha **Criar conteúdo aqui dentro**, selecione o tipo **imagem** e clique em **Continuar**.
3. Escreva o **Título**.
4. No campo **Imagem (obrigatório)**, clique para escolher o arquivo no seu computador. Em seguida, uma miniatura mostra o que você escolheu.
5. Preencha a **Legenda** (opcional) e o **Texto alternativo (para leitores de tela)**.
6. Clique em **Criar**. Depois, para o conteúdo aparecer no site, publique-o (veja [Escrever e publicar conteúdo](escrever-e-publicar.md)).

Outros tipos também podem ter um campo de imagem, como a **Imagem de destaque** da notícia. O envio funciona do mesmo jeito.

## Enviar um arquivo para baixar

1. Crie um conteúdo do tipo **arquivo**, do mesmo modo que acima.
2. No campo **Arquivo (obrigatório)**, escolha o documento.
3. Clique em **Criar** e publique.

## Trocar ou remover o arquivo

1. Abra o conteúdo e clique em **Editar**.
2. Para trocar, escolha um novo arquivo no campo. Deixado em branco, o arquivo atual continua.
3. Para tirar, marque **Remover o arquivo atual**.
4. Clique em **Salvar**.

## Texto alternativo

O texto alternativo descreve a imagem para quem não a enxerga (leitores de tela) e aparece quando a imagem não carrega. Escreva o que a imagem mostra ou o que ela comunica, em uma frase curta. Aceita até 500 caracteres, só texto simples, sem formatação. Para imagem apenas decorativa, deixe o campo em branco. Veja mais em [Acessibilidade](acessibilidade.md).

## Tipos aceitos e limite de tamanho

Cada campo mostra uma linha de ajuda: **Tipos aceitos: ... Até N MB.** Os formatos aceitos pelo PBL4 são:

| Categoria | Formatos |
|---|---|
| Imagens | jpg, png, gif, webp |
| Documentos | pdf, txt, csv, docx, xlsx, pptx, odt, ods, odp |

Não entram formatos que o navegador executa, como html, svg ou js. O PBL4 olha o conteúdo real do arquivo, não só o nome: um arquivo renomeado para parecer outro tipo é recusado com a mensagem "Tipo de arquivo não permitido".

O tamanho máximo padrão é de 10 MB por campo. Um administrador pode mudar o limite de cada campo, até 100 MB, e restringir os formatos, na aba **Campos** do tipo de conteúdo (veja [Tipos de conteúdo](tipos-de-conteudo.md)). O servidor também tem o seu próprio limite de envio; se ele for menor, vale o menor.

Cuidados que o PBL4 toma:

- Imagens são recriadas ao enviar, o que remove dados escondidos e informações como o local da foto.
- Miniaturas de vários tamanhos são geradas na hora de mostrar a imagem.
- O nome do arquivo no servidor é aleatório, nunca o que você enviou.

## Quando o envio é recusado

| Mensagem | O que fazer |
|---|---|
| O arquivo está vazio. | Escolha outro arquivo. |
| O arquivo tem X MB; o máximo para este campo é Y MB. | Reduza o arquivo ou peça ao administrador para aumentar o limite. |
| Tipo de arquivo não permitido. | Converta para um dos formatos da tabela. |
| O arquivo não parece ser uma imagem válida. | Abra e salve de novo a imagem em um editor. |

## Limpar arquivos sem uso

Quando um arquivo deixa de ser usado por qualquer conteúdo, ele continua ocupando espaço. A limpeza apaga só os arquivos que nenhum conteúdo, **nem versão antiga**, usa mais. Isso inclui o histórico, porque restaurar uma versão antiga pode trazer o arquivo de volta. Arquivos enviados há menos de uma hora nunca são apagados, para não atrapalhar um envio em andamento.

- **Automático:** o cron roda a limpeza uma vez por dia. Confira em **Mais**, **Tarefas agendadas** ([Tarefas agendadas](tarefas-agendadas.md)).
- **Manual**, para quem tem terminal: `php bin/pbl limpar-uploads --simular` mostra o que seria apagado, sem apagar nada. Sem `--simular`, apaga de verdade e informa quantos megabytes foram liberados.

Antes de limpar, vale ter uma cópia recente ([Cópias de segurança](copias-de-seguranca.md)).

## Veja também

- [Escrever e publicar conteúdo](escrever-e-publicar.md)
- [Tipos de conteúdo](tipos-de-conteudo.md)
- [Acessibilidade](acessibilidade.md)
- [Cópias de segurança](copias-de-seguranca.md)
