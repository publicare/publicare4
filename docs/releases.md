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
4. **Criar e empurrar a tag** (sobre o commit do merge):
   ```bash
   git switch main && git pull
   git tag -a vX.Y.Z -m "PBL4 X.Y.Z"
   git push origin vX.Y.Z
   ```
   O workflow [`release.yml`](../.github/workflows/release.yml) roda sozinho (veja a tabela abaixo) e deixa um **rascunho** da release com o `.zip` e o `latest.json` **sem assinatura**. Tags com `-` (`-rc.1`, `-beta.1`) vão para o canal `beta` e o rascunho sai marcado como pré-lançamento.
5. **Assinar e publicar (só o dono, na máquina dele)**, com a chave de uso num arquivo local (permissão 600; nunca em variável de ambiente, no CI ou na nuvem):
   ```bash
   gh auth login                 # uma vez
   bin/assinar-release.sh vX.Y.Z --chave-privada=/volume-cifrado/pbl4/uso.privada
   ```
   O script recusa se a release já não for rascunho, baixa o manifesto (`latest.json`; `latest-beta.json` no canal beta) e o `.zip`, confere versão, tamanho e SHA-256, assina (`php bin/pbl assinar`), **confere a assinatura contra as chaves públicas embutidas** (`php bin/pbl verificar-assinatura`; se não confere, para sem subir nada), sobe o manifesto e a `.sig` e só então publica a release (pré-lançamento sai como pré-lançamento). O manifesto vai por último: o rascunho não é visível ao público, então a publicação libera pacote, manifesto e assinatura de uma vez.
6. **Voltar a versão de desenvolvimento**: PR pequeno trocando para `X.Y.(Z+1)-dev` (ou a próxima MINOR).
7. **Endereço de canal**: as instalações só enxergam a versão se o `latest.json` estiver no endereço configurado em `dados/config/atualizacao.json` (`url`). **Ainda não existe esse endereço**; veja a pendência em [atualização](tecnica/atualizacao.md#para-quem-publica-versões).

### O que o CI faz e o que só o dono faz

| Passo | Quem | Onde |
|---|---|---|
| Conferir a tag × `Versao.php` × seção do `CHANGELOG.md` | CI | `.github/scripts/conferir-release.sh` |
| `composer install --no-dev --optimize-autoloader` numa pasta preparada e `bin/pbl empacotar … --sem-assinar --canal=estavel\|beta` | CI | `release.yml` (PHP 8.2, permissões só de leitura) |
| Criar o **rascunho** com `.zip` + `latest.json` (única etapa com `contents: write`) | CI | `release.yml`, só em push de tag |
| Assinar, verificar e publicar | **dono** | `bin/assinar-release.sh` |

O CI nunca tem chave de assinatura (só o `GITHUB_TOKEN` automático). `--seguranca` é ligado quando a seção da versão no CHANGELOG tem uma subseção "Segurança" com conteúdo; `--a-partir-de` fica no padrão `0.0.0` (para outro valor, empacote à mão). Ensaio sem publicar: Actions → Release → "Run workflow" com uma tag existente; o resultado fica só como artefato.

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
- [ ] `CHANGELOG.md` e `Versao::ATUAL` com o mesmo número (o workflow confere)
- [ ] A assinatura confere com as chaves embutidas (`bin/assinar-release.sh` faz isso antes de publicar)
- [ ] Seção "Atenção ao atualizar" revisada (quebras, migrações)
- [ ] Nenhum nome de cliente, órgão ou dado de terceiros nas notas
