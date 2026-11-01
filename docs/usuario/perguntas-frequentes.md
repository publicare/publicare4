# Perguntas frequentes

## É grátis?

Sim. O Publicare é software livre, com licença MIT: pode usar, estudar, modificar e distribuir sem pagar. Você só paga o que for seu: hospedagem e domínio. (Peles ou serviços opcionais de terceiros, se existirem no futuro, podem ser pagos; o programa em si e o que ele precisa para funcionar continuam livres.)

## Preciso saber programar?

O objetivo do projeto é que **não**: criar páginas, tipos de conteúdo, menus e formulários será por tela, sem código. **Hoje**, porém, ainda não há essa tela, então o uso exige conhecimento técnico. Veja o [estado do projeto](estado-do-projeto.md).

## Como instalo?

Pelo navegador, com um assistente: veja [Como instalar](instalar.md). Hoje ainda é preciso enviar o código você mesmo (o pacote pronto chega na primeira versão, a `0.1.0`).

## Onde posso hospedar?

Funciona em servidores **Apache** (a maioria das hospedagens compartilhadas) e **nginx**, ambos testados. A meta é uma hospedagem comum, daquelas que oferecem PHP e permitem enviar arquivos, sem terminal. Os requisitos são **PHP 8.2 ou mais novo** e um banco de dados (o padrão é SQLite, que não precisa de servidor; MySQL e PostgreSQL também serão suportados).

## E o meu site feito no Publicare antigo?

Há dois caminhos em andamento:

1. **Moldes (a aparência)**: já existe um conversor que lê os moldes do Publicare antigo e gera os do novo, com um relatório do que precisa de revisão manual. Moldes antigos costumam misturar código PHP, e esse trecho **não** é aceito no novo (por segurança); o relatório mostra onde.
2. **Conteúdo (páginas, usuários, arquivos)**: um importador está planejado. Ele vai preservar os números e endereços dos conteúdos, para que os links existentes não quebrem.

Enquanto isso, seu site antigo continua funcionando como está.

## Os endereços (links) do meu site vão quebrar?

A regra do projeto é que **não**. Já funciona assim: quando você muda o endereço de uma página, o endereço antigo passa a levar automaticamente ao novo (redirecionamento permanente). Endereços já divulgados são tratados como compromisso: o plano inclui domínios antigos, histórico de endereços e redirecionamentos. A virada de um site só deve ser feita quando todos os endereços tiverem destino.

## Quem pode editar o quê?

Cada pessoa recebe um **papel numa seção** do site: leitor (só vê), autor (cria e edita o que ele mesmo criou) ou editor (edita tudo, publica e apaga). O papel vale também para tudo dentro da seção, e se a pessoa tiver papéis diferentes em níveis diferentes, vale o mais forte. Só o administrador geral cuida de contas, domínios e aparência. As senhas são guardadas de forma que nem a equipe consegue lê-las. Hoje as contas e os papéis se criam por linha de comando. Na administração pelo navegador, cada pessoa só vê as seções que recebeu, e o sistema confere o papel a cada pedido (não basta esconder o botão).

## Como o login é protegido?

A senha é guardada de um jeito que nem a equipe consegue ler; cada login cria uma sessão nova, que vence sozinha por inatividade ou depois de 12 horas; depois de 5 senhas erradas o login é bloqueado por alguns minutos (mesmo trocando de navegador); e os formulários são protegidos contra pedidos forjados por outros sites. A mensagem de erro é a mesma tanto para login que não existe quanto para senha errada, para não revelar quem tem conta. Ative sempre o HTTPS no site.

## O que já dá para fazer pela administração?

Entre em `/_pbl/` com seu login. Você vê os sites e as seções que pode acessar, anda pelas páginas (inclusive as que ainda não estão no ar) e, se o seu papel permitir, corrige o **título** e a **descrição** de uma página. Ao salvar, a página no ar já mostra a mudança. Se outra pessoa salvou a mesma página enquanto você editava, aparece um aviso com as duas versões, e você escolhe. Criar, mover, publicar e apagar páginas ainda se faz por linha de comando.

## Posso trocar o domínio do meu site sem perder os links?

Pode. O site pode ter vários domínios: o antigo continua funcionando e leva ao novo (redirecionamento permanente) ou mostra o mesmo conteúdo avisando qual é o oficial. Endereços de sistemas que acabaram também podem ser redirecionados. Hoje isso se faz por linha de comando; a tela de administração virá depois.

## É seguro?

Segurança é critério de aceite do projeto. As peles rodam em ambiente restrito, o texto é protegido contra injeção de HTML, as entradas são validadas e há testes automáticos a cada mudança. Isso não dispensa as boas práticas de quem administra (senhas fortes, atualizações em dia, cópia de segurança). Se achar uma falha, veja a [política de segurança](../../SECURITY.md).

## A busca ignora maiúsculas e acentos?

Sim. Procurar por `presidencia` acha `Presidência`, e `ORGAO` acha `órgão`. Estão no plano a busca com várias palavras e relevância e a correção de erros de digitação ("você quis dizer…"), que o Publicare vai oferecer por configuração; veja o [estado do projeto](estado-do-projeto.md).

## Como fico sabendo das novidades?

Pelo [CHANGELOG](../../CHANGELOG.md) e pelas *releases* em <https://github.com/publicare/publicare4/releases>. Basta clicar em "Watch" → "Custom" → "Releases" no GitHub.

## Posso ajudar?

Pode e é bem-vindo, mesmo sem programar: testar, relatar problemas, escrever e revisar documentação, sugerir ideias. Veja [Como participar](como-participar.md).
