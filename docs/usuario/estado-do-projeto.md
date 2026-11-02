# Estado do projeto

Última atualização: **novembro de 2026** (telas de conteúdo da administração). Versão atual: **em desenvolvimento** (a primeira versão pública prevista é a `0.1.0`).

## Legenda

✅ funciona hoje · 🔨 em construção · 🗓️ planejado · 💡 ideia para depois

## Resumo honesto

O Publicare 4 está nos **primeiros passos**. Hoje ele serve a quem **desenvolve** e a quem quer **acompanhar ou testar**; ainda **não** serve para colocar um site no ar sem ajuda técnica. Já dá para instalar e entrar pelo navegador, ver os sites, andar pelas páginas, corrigir o título e a descrição de uma página e, se você é administrador, criar e ajustar os **tipos de conteúdo** (quais campos uma notícia ou um evento tem). Criar, mover, publicar ou apagar páginas, preencher os campos de um conteúdo e cuidar das contas ainda se faz por linha de comando.

## O que já funciona

| Recurso | Estado |
|---|---|
| Instalar (por linha de comando) com um site de exemplo | ✅ |
| Criar conteúdos em árvore | ✅ (por código; sem tela ainda) |
| Tipos de conteúdo pelo navegador (só administrador): criar, descrever, dizer que tipos podem ficar dentro, adicionar campos, mudar rótulo, ordem e obrigatório, arquivar campo e apagar tipo sem uso, sem nunca apagar dado guardado | ✅ (a exigência de "obrigatório" e de "tipos que podem ficar dentro" ao salvar um conteúdo vem com as telas de conteúdo) |
| Linguagem de moldes para montar páginas (listas, menus, herança de layout) | ✅ |
| Publicar as páginas e entregá-las prontas, com cache | ✅ |
| Vários sites e domínios no mesmo programa | ✅ |
| Conversor de moldes do Publicare antigo (PBL3) para o novo, com relatório | ✅ |
| Proteções de segurança básicas nos moldes e nas URLs | ✅ |
| Histórico de versões de cada conteúdo, com restaurar e proteção contra edição simultânea | ✅ |
| Vários domínios para o mesmo site (um principal e os outros mostrando o mesmo conteúdo ou levando ao principal) | ✅ |
| Redirecionar endereços antigos que não são páginas atuais (de sistemas que acabaram, com `.php` ou maiúsculas) | ✅ (por linha de comando) |
| Mudar o endereço de uma página sem quebrar o antigo (o antigo passa a levar ao novo) | ✅ |
| Mover uma página (com tudo que está dentro dela) na árvore | ✅ |
| Contas da equipe com senha segura e papéis por seção do site (leitor, autor, editor) e administrador geral, com registro de quem fez o quê (por linha de comando) | ✅ |
| Instalar pelo navegador (assistente com requisitos, conta de administrador e site de exemplo), com proteção para ninguém instalar no seu lugar | ✅ |
| Instalar em hospedagem com painel Hestia/VestaCP (`open_basedir`), com guia e avisos claros no assistente (falta de SQLite, pasta fora do alcance do PHP) | ✅ |
| Entrar e sair pelo navegador, com sessão segura e proteção contra tentativas em massa e contra pedidos forjados | ✅ |
| Administração pelo navegador: ver os sites que você pode acessar e andar pelas páginas de cada um (inclusive as que ainda não estão no ar) | ✅ |
| Corrigir o título e a descrição de uma página pelo navegador, com aviso se outra pessoa mudou a página ao mesmo tempo; a página no ar é atualizada na hora | ✅ |
| Pelo navegador: criar páginas e outros conteúdos, editar todos os campos, publicar ou tirar do ar (com datas), mover, mandar para a lixeira e tirar de lá, ver o histórico e voltar a uma versão antiga; ordenar e filtrar a lista de conteúdo | ✅ |
| Busca que ignora maiúsculas e acentos (`presidencia` acha `Presidência`) | ✅ |
| Ligar conteúdos entre si (uma notícia aponta para seu autor) e, nos moldes, mostrar, filtrar e ordenar pelo conteúdo apontado | ✅ |
| Consultas com até três condições, comparações (maior, menor, contém) e carga rápida de listas | ✅ |

## O que vem a seguir

| Recurso | Estado |
|---|---|
| Busca com várias palavras, relevância e correção de erros de digitação ("você quis dizer…") | 🗓️ |
| Atualização automática com cópia de segurança | 🔨 (já funciona por linha de comando, com assinatura, cópia do banco e volta atrás; falta o botão no painel e a primeira versão publicada) |
| Restante da administração: contas, domínios, redirecionamentos e peles | 🔨 |
| Editor de peles | 🗓️ |
| Galeria de peles prontas para instalar | 🗓️ |
| Formulários, inscrições e consultas públicas por configuração | 🗓️ |
| Importar um site feito no Publicare antigo | 🗓️ |
| Inscrição paga e outros pagamentos | 💡 |
| Peles avançadas e comunidade de autores | 💡 |

O plano completo, por fases, está em [docs/plano.md](../plano.md). O que mudou em cada versão fica no [CHANGELOG](../../CHANGELOG.md).

## Posso usar para um site de verdade?

**Ainda não** sem apoio técnico. Se você tem um site feito no Publicare antigo e quer saber o que acontece com ele, veja as [perguntas frequentes](perguntas-frequentes.md#e-o-meu-site-feito-no-publicare-antigo).
