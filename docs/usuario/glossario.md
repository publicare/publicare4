# Glossário

As palavras que o Publicare usa, em linguagem simples.

| Palavra | O que significa |
|---|---|
| **Site** | Um conjunto de páginas que responde por um ou mais endereços (domínios). Um mesmo Publicare pode ter vários sites. |
| **Domínio** | O endereço na internet (por exemplo, `meusite.org.br`). |
| **Objeto / conteúdo** | Qualquer coisa que você cria: uma página, uma notícia, um evento, um documento. |
| **Classe (tipo de conteúdo)** | O "molde de formulário" de um tipo de conteúdo: diz quais campos ele tem (título, resumo, data…) e que tipos podem ficar dentro dele. Ex.: "Notícia", "Evento". Vale para todos os sites da instalação; só o administrador cria e altera, em **Tipos de conteúdo**. |
| **Propriedade (campo)** | Cada informação de um tipo de conteúdo: texto, número, data, sim/não ou ligação (referência) para outro conteúdo. Tem um **nome técnico** (o que os moldes usam, não muda depois de criado) e um **rótulo** (o nome que a equipe vê). |
| **Campo arquivado** | Campo que saiu dos formulários mas cujos valores continuam guardados (e os moldes continuam mostrando). É o jeito de "tirar" um campo que já tem valores sem perder nada; dá para voltá-lo a ativo. |
| **Referência** | Uma propriedade que aponta para outro conteúdo (uma notícia aponta para seu autor). Dá para mostrar, filtrar e ordenar pelo conteúdo apontado (por exemplo, notícias ordenadas pelo nome do autor). Só aponta para conteúdo do mesmo site. |
| **Busca por palavras** | Procura todas as palavras digitadas, em qualquer ordem, aceitando só o começo da palavra e o plural; mostra primeiro o que combina melhor (relevância). |
| **Relevância** | A pontuação que ordena os resultados da busca: título vale mais que descrição, que vale mais que o texto. |
| **Busca sem acento** | A busca trata `Presidência`, `presidencia` e `PRESIDÊNCIA` como a mesma palavra. |
| **Consulta** | Um pedido de lista de conteúdos com filtros e ordem (por exemplo, "eventos da Sala 1, em ordem de data"). |
| **Árvore** | A organização em níveis: uma seção contém páginas, que podem conter outras páginas. |
| **Pele** | A aparência do site: cores, layout, cabeçalho e rodapé. Trocar a pele muda o visual, não o conteúdo. |
| **Molde (template)** | O arquivo, escrito na linguagem PBL, que diz como montar uma página dentro de uma pele. |
| **PBL** | A linguagem de moldes do Publicare. "PBL4" é a versão atual dela. |
| **Publicar** | Tornar o conteúdo visível para os visitantes. O Publicare prepara as páginas prontas para entregar rápido. |
| **Rascunho / privado** | Conteúdo que ainda não está visível ao público. Todo conteúdo criado pela administração começa assim. |
| **Lixeira** | Onde fica o conteúdo "apagado" pela administração: some do site e das listas, mas nada se perde, e dá para tirá-lo de lá (ele volta fora do ar). Para ver, filtre a lista pelo estado "Na lixeira". |
| **Número (id)** | O número de cada conteúdo, mostrado na página dele na administração. É usado para apontar uma referência de um campo para outro conteúdo. |
| **Vigência** | O período em que um conteúdo vale: ele só aparece depois da data de publicação e some depois da data de validade. |
| **Agendado** | Conteúdo publicado cuja data de publicação ainda não chegou. Ele entra no ar sozinho na data, quando o servidor rodar o comando `bin/pbl agendados` (que a hospedagem agenda no "cron"). |
| **Cron** | O agendador de tarefas do servidor. O Publicare usa para rodar `bin/pbl agendados` de tempos em tempos. |
| **HTML limpo** | Em campos de texto com HTML, o Publicare guarda só marcações comuns e seguras (parágrafos, títulos, listas, tabelas, links, imagens) e tira scripts, estilos, formulários e eventos. |
| **Formulário (público)** | Um conteúdo do tipo `formulario` que mostra, numa página, os campos de um tipo de conteúdo para qualquer visitante preencher. Cada envio vira um conteúdo privado na seção de destino. |
| **Envio** | O que um visitante mandou por um formulário público. Chega privado (fora do ar); a equipe vê em "Envios recebidos" e, para aprovar, publica. |
| **Campo-armadilha (honeypot)** | Um campo escondido que pessoas não veem e robôs costumam preencher; se vier preenchido, o envio é descartado sem aviso. |
| **Peso** | Número que define a ordem manual de itens (menores primeiro). |
| **Cache** | Cópia pronta de uma página, guardada para ser entregue sem refazer o trabalho. |
| **URL amigável** | Endereço legível de uma página (por exemplo, `/sobre`), em vez de números e símbolos. |
| **Versão (histórico)** | Cada vez que um conteúdo é salvo, o Publicare guarda o estado anterior. Dá para ver o histórico e voltar a uma versão antiga; voltar cria uma versão nova, sem apagar nada. |
| **Papel (perfil)** | O que alguém pode fazer numa seção do site: **leitor** (só vê), **autor** (cria e edita o que ele mesmo criou) ou **editor** (edita tudo, publica e apaga). O papel vale para a seção e tudo que está dentro dela; o **administrador** tem todos os poderes no site inteiro. |
| **Prova de posse** | Um arquivo vazio que você cria no servidor para mostrar que é o dono dele. O instalador só funciona se esse arquivo existir, para que ninguém mais instale o sistema no seu lugar. |
| **Sessão** | O período em que você está "logado". Termina ao sair, depois de 30 minutos sem usar ou 12 horas depois de entrar, e também se a sua senha for trocada ou a conta desativada. |
| **Auditoria** | O registro de quem fez o quê e quando (criar conta, trocar senha, dar acesso…). Só acrescenta, nunca guarda senha, e não pode ser editado pelo sistema. |
| **Administração** | A área do site, em `/_pbl/`, onde a equipe entra com login e senha para cuidar do conteúdo. Cada pessoa só vê e só muda o que o seu papel permite. |
| **Edição simultânea** | Quando duas pessoas abrem a mesma página para editar. Quem salvar depois recebe um aviso, vê o que a outra pessoa salvou e decide se substitui; nada se perde sem aviso. |
| **Domínio principal** | O endereço oficial do site. Os outros domínios do mesmo site mostram o mesmo conteúdo (avisando qual é o oficial) ou levam direto ao principal. |
| **Alias e redirecionador** | Os dois tipos de domínio que não são o principal: o **alias** mostra o mesmo conteúdo do site; o **redirecionador** leva o visitante ao principal (redirecionamento permanente). Muda-se pela administração, em **Domínios**. |
| **Pele** | Pasta com os moldes que dão a aparência ao site. Pelo navegador, o administrador edita o texto dos moldes (a sintaxe é conferida antes de salvar) e escolhe qual pele o site usa. |
| **Regra de redirecionamento** | Uma instrução que você cadastra: "quem pedir `/antigo` vai para `/novo`". Serve para endereços antigos que já não são uma página do site. |
| **Redirecionamento** | Fazer um endereço antigo levar ao endereço novo, para não quebrar links já divulgados. |
| **Migração (de banco)** | Ajuste interno nos dados guardados, feito ao instalar ou atualizar. |
| **Manifesto (de atualização)** | Pequeno arquivo assinado que diz qual é a versão mais nova, o que mudou e como baixá-la. O PBL4 só confia nele se a assinatura conferir. |
| **Cópia de segurança (backup)** | Cópia dos arquivos e dados para voltar atrás em caso de problema. |
| **Voltar atrás (de uma atualização)** | Desfazer uma atualização que já terminou: o código e o banco voltam a como eram antes dela. Diferente da volta atrás **automática** (quando a própria atualização falha no meio), esta é pedida pela pessoa (`bin/pbl reverter` ou o botão na tela Atualização) e perde o que foi criado no site depois da atualização, por isso pede confirmação explícita. |
| **Software livre / MIT** | Licença que permite usar, estudar, modificar e distribuir o programa gratuitamente. |
| **PBL3** | A versão anterior do Publicare. |
