# ADR 0024 — Tags nos objetos

Status: aceito (2026-11-04). Fase 5r (paridade com o PBL3). Complementa o [ADR 0009](0009-peles-de-saida-headless.md) (peles) naquilo que toca listas e páginas.

## Contexto

O PBL3 tinha tags nos objetos (`localizar tags=…`, `#tags`, nuvem). O conversor as marcava para revisão porque não existiam no PBL4, e sites migrados perdiam navegação por assunto.

## Decisão

1. **Modelo**: tabelas `tag` (por site: `nome` e `slug`, único por `site_id`+`slug`) e `objeto_tag` (migração M020, só adiciona tabelas). A tag se identifica pelo **slug** (sem acento, minúsculo, hífens): "Eventos 2026" e "eventos-2026" são a mesma, e vale a primeira grafia digitada. Criada ao salvar o objeto; a que fica sem objeto é apagada.
2. **Limites**: 20 tags por objeto, 40 caracteres por tag; excesso vira erro de formulário e nada é gravado.
3. **Edição**: campo "Tags" (separadas por vírgula, `;` ou linha) no cartão Identificação, com ajuda no "?". Mesma permissão, versão otimista e auditoria de editar (a auditoria registra que `tags` mudou, nunca os nomes). Campo ausente no POST não mexe nas tags.
4. **Histórico**: a versão do objeto guarda `_tags` junto de `_menu`; restaurar volta as tags. Versão antiga sem `_tags` não mexe nas atuais.
5. **Moldes**: parâmetro `tag="a,b"` em `localizar` e `filhos` (todas as tags valem, máximo 3, só publicado e vigente do mesmo site); comando `<@tags@>` (lista de links `/tag/<slug>`) e `<@nuvem [limite=n]@>` (lista com `peso-1` a `peso-5`, conta só objetos visíveis).
6. **Página `/tag/<slug>`**: dinâmica (como `/busca`), paginada, molde `tag` da pele (existe na básica e no portal); sem molde, tag inexistente ou slug inválido dá 404. Um objeto real com essa URL tem precedência.
7. **Republicação**: mudar as tags de um objeto visível republica o site por `republicarPorConfiguracao` (nuvem e listas por tag são estáticas); a página `/tag/<slug>` nunca fica velha.
8. **Conversor PBL3**: `tags=` vira `tag=`; `#tags` impresso vira `<@tags@>`.

## Consequências

- Tags não entram no índice de busca por texto (fica para depois; a página da tag cobre o caso).
- A lista de versões não mostra as tags de cada versão (só a restauração as devolve).
- Renomear ou mesclar tags pelo painel não existe: edita-se por objeto.
