# Perguntas frequentes

## É grátis?

Sim. O Publicare é software livre, com licença MIT: pode usar, estudar, modificar e distribuir sem pagar. Você só paga o que for seu: hospedagem e domínio. (Peles ou serviços opcionais de terceiros, se existirem no futuro, podem ser pagos; o programa em si e o que ele precisa para funcionar continuam livres.)

## Preciso saber programar?

O objetivo do projeto é que **não**: criar páginas, tipos de conteúdo, menus e formulários será por tela, sem código. **Hoje**, porém, ainda não há essa tela, então o uso exige conhecimento técnico. Veja o [estado do projeto](estado-do-projeto.md).

## Onde posso hospedar?

A meta é uma hospedagem comum, daquelas que oferecem PHP e permitem enviar arquivos, sem terminal. Os requisitos são **PHP 8.2 ou mais novo** e um banco de dados (o padrão é SQLite, que não precisa de servidor; MySQL e PostgreSQL também serão suportados).

## E o meu site feito no Publicare antigo?

Há dois caminhos em andamento:

1. **Moldes (a aparência)**: já existe um conversor que lê os moldes do Publicare antigo e gera os do novo, com um relatório do que precisa de revisão manual. Moldes antigos costumam misturar código PHP, e esse trecho **não** é aceito no novo (por segurança); o relatório mostra onde.
2. **Conteúdo (páginas, usuários, arquivos)**: um importador está planejado. Ele vai preservar os números e endereços dos conteúdos, para que os links existentes não quebrem.

Enquanto isso, seu site antigo continua funcionando como está.

## Os endereços (links) do meu site vão quebrar?

A regra do projeto é que **não**. Endereços já divulgados são tratados como compromisso: o plano inclui domínios antigos, histórico de endereços e redirecionamentos. A virada de um site só deve ser feita quando todos os endereços tiverem destino.

## É seguro?

Segurança é critério de aceite do projeto. As peles rodam em ambiente restrito, o texto é protegido contra injeção de HTML, as entradas são validadas e há testes automáticos a cada mudança. Isso não dispensa as boas práticas de quem administra (senhas fortes, atualizações em dia, cópia de segurança). Se achar uma falha, veja a [política de segurança](../../SECURITY.md).

## A busca ignora maiúsculas e acentos?

Sim. Procurar por `presidencia` acha `Presidência`, e `ORGAO` acha `órgão`. Estão no plano a busca com várias palavras e relevância e a correção de erros de digitação ("você quis dizer…"), que o Publicare vai oferecer por configuração; veja o [estado do projeto](estado-do-projeto.md).

## Como fico sabendo das novidades?

Pelo [CHANGELOG](../../CHANGELOG.md) e pelas *releases* em <https://github.com/publicare/publicare4/releases>. Basta clicar em "Watch" → "Custom" → "Releases" no GitHub.

## Posso ajudar?

Pode e é bem-vindo, mesmo sem programar: testar, relatar problemas, escrever e revisar documentação, sugerir ideias. Veja [Como participar](como-participar.md).
