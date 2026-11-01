# Releases e release notes

Cada versão publicada tem três coisas que andam juntas: a **entrada no `CHANGELOG.md`**, a **tag** no Git e a **release** no GitHub com as *release notes*. As regras de numeração estão em [versionamento.md](versionamento.md).

## Quem escreve o quê

| Documento | Para quem | Quando |
|---|---|---|
| [`CHANGELOG.md`](../CHANGELOG.md) | quem acompanha o projeto e quem desenvolve | **em todo PR** que muda o produto (a pipeline cobra), na seção "Não lançado" |
| *Release notes* (texto da release no GitHub) | quem usa e administra sites | na hora de publicar, a partir do CHANGELOG, com um resumo em linguagem simples |

O CHANGELOG segue o formato [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/): `Adicionado`, `Alterado`, `Descontinuado`, `Removido`, `Corrigido` e `Segurança`. Cada linha diz o que mudou **do ponto de vista de quem usa**, no presente, e cita o PR (`#12`).

## Passo a passo para publicar uma versão

1. **Decidir o número** olhando "Não lançado" (veja [versionamento.md](versionamento.md)).
2. **Preparar a versão** num PR `release-X.Y.Z`:
   - em `CHANGELOG.md`, renomear "Não lançado" para `[X.Y.Z] - AAAA-MM-DD` e abrir uma nova seção "Não lançado" vazia;
   - em `app/src/Versao.php`, trocar `X.Y.Z-dev` por `X.Y.Z`;
   - `make qa` e a pipeline verdes.
3. **Mergear** o PR de release na `main`.
4. **Criar a tag e a release** (sobre o commit do merge):
   ```bash
   git switch main && git pull
   git tag -a vX.Y.Z -m "PBL4 X.Y.Z"
   git push origin vX.Y.Z
   gh release create vX.Y.Z --title "PBL4 X.Y.Z" --notes-file notas.md   # --prerelease para beta/rc
   ```
5. **Voltar a versão de desenvolvimento**: PR pequeno trocando para `X.Y.(Z+1)-dev` (ou a próxima MINOR).
6. Quando existir o atualizador ([ADR 0006](adr/0006-atualizador-automatico.md)): empacotar, **assinar** e publicar o manifesto `latest.json` do canal correspondente por último.

## Modelo de release notes

```markdown
## PBL4 X.Y.Z — AAAA-MM-DD

**Em resumo** — duas ou três frases, sem jargão: o que esta versão traz e para quem importa.

### Novidades
- Descrição curta do que dá para fazer agora (#PR)

### Melhorias e correções
- O que ficou melhor ou deixou de dar problema (#PR)

### Segurança
- Correção de vulnerabilidade, sem detalhes que facilitem ataque antes de todos poderem atualizar (#PR)

### ⚠️ Atenção ao atualizar
- Mudança que quebra algo, e o que fazer (ou "Nenhuma").
- Migrações de banco: sim/não (`bin/pbl` aplica sozinho; faça backup antes).

### Requisitos
PHP 8.2+, SQLite/MySQL/PostgreSQL.

### Obrigado
Quem contribuiu nesta versão.
```

## Conferências antes de publicar

- [ ] Pipeline verde na `main`
- [ ] `CHANGELOG.md` e `Versao::ATUAL` com o mesmo número
- [ ] Seção "Atenção ao atualizar" revisada (quebras, migrações)
- [ ] Nenhum nome de cliente, órgão ou dado de terceiros nas notas
