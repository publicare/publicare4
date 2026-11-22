# Guia visual do painel (rodada 2)

Escrito **antes** das telas. Tudo aqui vira `assets/proto.css` (tokens e componentes) e é mostrado ao vivo em `guia.html`. Sensação buscada: Ghost Admin, Linear, Stripe, Notion. Nada copiado.

## Princípios
1. **Uma cor de ação.** O resto é neutro. Cor só diz "isto é clicável" ou "atenção".
2. **Superfícies planas, bordas finas (1 px).** Sem sombra, sem gradiente. Só a barra de ações fixa tem uma linha de separação.
3. **Poucos tamanhos, poucos pesos.** Quatro tamanhos, dois pesos.
4. **Tudo na grade de 4 px.** Espaço entre grupos é maior que espaço dentro do grupo.
5. **Texto humano e curto.** Verbo de tarefa no botão. Sem "Bem-vindo".
6. **Uma ação principal por tela.** As outras são secundárias ou links.

## Cores (tokens `--pbl-*`)
| Token | Claro | Escuro | Uso |
|---|---|---|---|
| `--pbl-fundo` | `#faf9f7` | `#151514` | fundo da página |
| `--pbl-superficie` | `#ffffff` | `#1d1d1b` | campos, listas, barra |
| `--pbl-suave` | `#f3f2ef` | `#262624` | faixas, hover, selos neutros |
| `--pbl-linha` | `#e4e2dd` | `#34332f` | divisores (decorativos) |
| `--pbl-borda` | `#85837d` | `#8a8883` | bordas de controles (≥ 3:1) |
| `--pbl-texto` | `#1c1b19` | `#eceae5` | texto |
| `--pbl-texto-2` | `#5a5853` | `#aaa8a2` | ajuda, metadados |
| `--pbl-acao` | `#0b6657` | `#5dc2ae` | botão principal, link, foco, interruptor ligado |
| `--pbl-sobre-acao` | `#ffffff` | `#06201a` | texto sobre `--pbl-acao` |
| `--pbl-acao-suave` | `#e3f1ed` | `#1b2e2a` | item ativo do menu |
| `--pbl-erro` | `#b3261e` | `#f4a09a` | erro (sempre com texto e ícone) |
| `--pbl-erro-fundo` | `#fcecea` | `#3a1f1c` | caixa de erro |
| `--pbl-aviso` | `#7a5200` | `#e6c06a` | aviso, "não salvo" |
| `--pbl-aviso-fundo` | `#fbf1dc` | `#352a12` | caixa de aviso |
| `--pbl-ok` | `#1d6a3b` | `#7fd29d` | salvo, publicado |
| `--pbl-ok-fundo` | `#e5f3ea` | `#17301f` | selo publicado |

Contraste testado nos dois temas (texto 4,5:1; bordas e foco 3:1) por `tests/`.

## Tipografia
Fonte do sistema (`system-ui`). Sem arquivo de fonte, sem CDN.

| Papel | Tamanho / linha | Peso |
|---|---|---|
| Título da tela (h1) | 28/36 (24/32 no celular) | 600 |
| Subtítulo e título de seção (h2) | 18/28 | 600 |
| Corpo, campos, botões, rótulos | 16/24 | 400 (rótulo 600) |
| Ajuda, metadados, selos | 14/20 | 400 |

Nada abaixo de 14 px. Campo com 16 px (o iOS não amplia a tela).

## Espaço (escala de 4)
`4 · 8 · 12 · 16 · 24 · 32 · 48` → `--pbl-e1` a `--pbl-e12`.
- Rótulo → campo: 8. Campo → ajuda: 4. Campo → campo: 20.
- Entre seções de formulário: 40 + linha fina. Título da seção → corpo: 16.
- Margem lateral: 16 (celular), 32 (desktop).

## Medidas
- Formulário: **coluna de 680 px** (máx.), alinhada à esquerda da área de conteúdo.
- Listas e tabelas: até 960 px.
- Campos curtos (data, número): 240 px. Campo de texto: 100% da coluna.
- Menu lateral: 232 px (≥ 1024 px). Abaixo disso, botão "Menu" no cabeçalho.
- Alvo de toque: 44 px de altura em botões e linhas clicáveis no celular.
- Raio: 6 px (controles), 4 px (selos). Nada de pílula.

## Componentes
**Cabeçalho de tela.** Migalha (14 px, cinza; o último item é o título, sem link) → h1 → uma linha de descrição → ação principal à direita (celular: abaixo, largura total). Sempre nessa ordem, sempre nesse lugar.

**Campo.** Rótulo acima (600), ajuda abaixo (14 px cinza), erro abaixo da ajuda com ícone e texto. Contador no canto do rótulo. Borda 1 px `--pbl-borda`, fundo `--pbl-superficie`, foco com contorno de 3 px na cor de ação.

**Seção.** Título (h2), uma linha de descrição e os campos. Separada da anterior por linha fina e 40 px de respiro. Sem cartão por campo.

**Interruptor (toggle).** Linha com texto à esquerda (título 600 + explicação 14 px) e o interruptor à direita. Desligado: campos escondidos. Ligado: os campos aparecem logo abaixo, recuados 16 px com uma linha à esquerda. O que foi digitado não se perde ao desligar. Quando já há valor, nasce ligado. **Bloco ligável:** o mesmo interruptor, com título maior, serve para blocos secundários de uma tela (“Mexidos por último”, “Seções do site”, “Atalhos”, “Dentro desta página”): desligado, só o título e a explicação ficam. No formulário, tudo que é opcional (resumo, imagem, tags, endereço próprio, local, posição nas listas) fica atrás de interruptor.

**Abas.** Texto com sublinhado de 2 px na cor de ação na ativa. Marcador em texto ("•1") quando há erro. Sem abas dentro de abas.

**Lista.** Linhas de 56 px com divisor fino: título (link) + metadado cinza abaixo, selo e ação à direita. No celular, mesma linha empilhada; nunca tabela com rolagem lateral.

**Selo.** Texto sempre ("Privado", "Publicado", "Agendado", "Na lixeira"), 14 px, fundo suave. Estado nunca só por cor.

**Barra de ações.** Fixa no pé da área de conteúdo. À esquerda o estado ("Salvo às 14:32" ou "Alterações não salvas", com ponto + texto). À direita: link "Voltar", **Salvar** (secundário) e **Publicar** (principal, só se Privado). Quando já está publicado, "Salvar" vira o botão principal.

**Botões.** Principal (cor de ação, texto claro), secundário (borda), discreto (só texto). Altura 40 (44 no celular). Perigo só em texto vermelho + confirmação.

**Ícones.** Traço de 1,5 px, 16 px, só onde ajudam: busca, tema, link externo, aviso, ok. Menu sem ícones (o texto basta).

## Lista "cara de IA" e o que fizemos
| Sinal | Como evitamos |
|---|---|
| Grade de cartões idênticos com ícone + título + texto | Início é uma lista de tarefas; Mais é lista de linhas; nenhuma grade de cartões |
| Gradiente, sombra, brilho | Zero. Só linha de 1 px |
| Roxo ou azul-néon | Uma cor verde-petróleo, contida |
| Emoji como ícone | Nenhum emoji. Poucos ícones de traço |
| Tudo centralizado | Tudo alinhado à esquerda numa grade só |
| Bordas muito redondas | 6 px e 4 px |
| Texto de enchimento ("Bem-vindo ao seu painel") | "Olá, Ana." e o que há para fazer |
| Ícone sem função | Sem ícone no menu |
| Muitas cores de selo | Neutro, verde, âmbar, vermelho, sempre com texto |
| Rótulos genéricos ("Enviar", "OK") | Verbos da tarefa: "Publicar", "Salvar rascunho", "Enviar para revisão" |

## Nomes de tarefas, não de tabelas
Início · Conteúdo · Revisar · Site · Mais. Dentro: "Criar conteúdo", "Publicar e agendar", "Enviar para revisão", "Restaurar da lixeira", "Aparência do site", "Cópias de segurança".
