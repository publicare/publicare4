# Estado do projeto

Última atualização: **novembro de 2026** (primeiras telas da administração). Versão atual: **em desenvolvimento** (a primeira versão pública prevista é a `0.1.0`).

## Legenda

✅ funciona hoje · 🔨 em construção · 🗓️ planejado · 💡 ideia para depois

## Resumo honesto

O Publicare 4 está nos **primeiros passos**. Hoje ele serve a quem **desenvolve** e a quem quer **acompanhar ou testar**; ainda **não** serve para colocar um site no ar sem ajuda técnica. Já dá para instalar e entrar pelo navegador, ver os sites, andar pelas páginas e corrigir o título e a descrição de uma página. Criar, mover, publicar ou apagar páginas, mudar os campos de um conteúdo e cuidar das contas ainda se faz por linha de comando.

## O que já funciona

| Recurso | Estado |
|---|---|
| Instalar (por linha de comando) com um site de exemplo | ✅ |
| Criar tipos de conteúdo e conteúdos em árvore | ✅ (por código; sem tela ainda) |
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
| Entrar e sair pelo navegador, com sessão segura e proteção contra tentativas em massa e contra pedidos forjados | ✅ |
| Administração pelo navegador: ver os sites que você pode acessar e andar pelas páginas de cada um (inclusive as que ainda não estão no ar) | ✅ |
| Corrigir o título e a descrição de uma página pelo navegador, com aviso se outra pessoa mudou a página ao mesmo tempo; a página no ar é atualizada na hora | ✅ |
| Busca que ignora maiúsculas e acentos (`presidencia` acha `Presidência`) | ✅ |
| Ligar conteúdos entre si (uma notícia aponta para seu autor) e, nos moldes, mostrar, filtrar e ordenar pelo conteúdo apontado | ✅ |
| Consultas com até três condições, comparações (maior, menor, contém) e carga rápida de listas | ✅ |

## O que vem a seguir

| Recurso | Estado |
|---|---|
| Busca com várias palavras, relevância e correção de erros de digitação ("você quis dizer…") | 🗓️ |
| Atualização automática com cópia de segurança | 🗓️ |
| Restante da administração: criar, mover, publicar e apagar páginas, editar todos os campos, ver o histórico, cuidar de contas e domínios | 🔨 |
| Editor de peles | 🗓️ |
| Galeria de peles prontas para instalar | 🗓️ |
| Formulários, inscrições e consultas públicas por configuração | 🗓️ |
| Importar um site feito no Publicare antigo | 🗓️ |
| Inscrição paga e outros pagamentos | 💡 |
| Peles avançadas e comunidade de autores | 💡 |

O plano completo, por fases, está em [docs/plano.md](../plano.md). O que mudou em cada versão fica no [CHANGELOG](../../CHANGELOG.md).

## Posso usar para um site de verdade?

**Ainda não** sem apoio técnico. Se você tem um site feito no Publicare antigo e quer saber o que acontece com ele, veja as [perguntas frequentes](perguntas-frequentes.md#e-o-meu-site-feito-no-publicare-antigo).
