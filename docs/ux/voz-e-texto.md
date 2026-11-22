# Voz e texto do painel

Guia curto. O teste `tests/TextoDoPainelTest.php` aplica as regras que dá para medir.
Vocabulário: [glossário](../usuario/glossario.md).

## Voz

- Português do Brasil, direto, frase curta.
- Botão é verbo no imperativo: **Salvar**, **Publicar**, **Apagar**. Nunca "Clique aqui".
- Sem "por favor" e sem "gostaríamos". Sem jargão: diga "pele", "conteúdo", "tipo de conteúdo", como no glossário.
- Não explique o óbvio nem repita o rótulo.
- Número e exemplo valem mais que adjetivo: "Até 2 MB", "como pt-BR", não "um arquivo pequeno".

## Tamanho

| Onde | Limite |
|---|---|
| Ajuda de campo | 1 linha (até 140 caracteres no teste); sem ela, se o rótulo basta |
| Subtítulo de tela | 1 linha, 1 frase |
| Aviso | só se há risco real; causa + o que fazer, numa frase |
| Estado vazio | 1 frase + 1 ação |
| Erro | causa + o que fazer, numa frase |
| "Como funciona" | link **Saiba mais** (docs/usuario) ou `<details>` recolhido |

## Padrões proibidos

O teste falha se aparecer no HTML de uma tela principal (sem diferenciar maiúsculas):

`Aqui você pode` · `Esta tela` · `É importante` · `Lembre-se` · `Basta` · `simplesmente` · `por favor` · `gostaríamos` · `a fim de` · `vale ressaltar`

Evite também, sem teste: gerúndios em cadeia ("clicando, selecionando e confirmando") e três adjetivos seguidos.

Exceção só em `tests/fixtures/texto-excecoes.php`, com a justificativa por escrito.

## Antes e depois (do painel real)

| Antes | Depois |
|---|---|
| Veja como o site aparece para quem o visita. | Veja o site como o visitante vê. |
| Olá, Admin. O que fazer agora: | Olá, Admin. |
| Escolha quando entra no ar e quando sai. Sem isso, vale assim que for publicado e não vence. | Sem agenda, entra no ar ao publicar e não vence. |
| Palavras que agrupam conteúdos parecidos, separadas por vírgula (exemplo: educação, eventos 2026). Cada tag vira uma página que lista tudo o que tem a mesma tag. Até 20 tags de 40 caracteres. | Separe por vírgula (educação, eventos 2026). Até 20 tags de 40 caracteres. |
| Imagem PNG, JPG ou WebP; usada pelas peles com faixa de abertura quando o conteúdo não tem imagem própria. Até 5 MB. Escolher outra imagem substitui a atual. | PNG, JPG ou WebP. Faixa de abertura de algumas peles. Até 5 MB. |
| Valores usados pelas páginas do site (nome, SEO, contato, rodapé, busca, menu). Cada aba grava só o próprio grupo; ao salvar, o site é republicado. Campo vazio volta ao padrão. | Cada aba grava só o seu grupo e republica o site. Campo vazio volta ao padrão. |
| Clique no título para descer um nível; use as migalhas ou "Subir um nível" para voltar. | Clique no título para abrir a pasta. |

## O botão "?"

Ícone de 18 px, área de toque de 44 px, abre a ajuda logo abaixo do rótulo. Esc fecha e devolve o foco. Use só quando há o que dizer além do rótulo.
