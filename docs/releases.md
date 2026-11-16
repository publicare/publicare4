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
   O workflow [`release.yml`](../.github/workflows/release.yml) roda sozinho (veja a tabela abaixo): empacota, **assina**, **confere** e **publica**. Só tag `v*` dispara (nunca merge na `main`). Tags com `-` (`-rc.1`, `-beta.1`) vão para o canal `beta` e a release sai marcada como pré-lançamento.
5. **Aprovar a assinatura (automático depois disso)**: o job `assinar` usa o Environment `release` e fica esperando a **aprovação do dono no GitHub** (Actions → a execução → *Review deployments*; confira antes o commit e a tag). Aprovado, ele grava a chave de uso (segredo `PBL4_CHAVE_USO`) num arquivo 0600, assina o `latest*.json` (`bin/pbl assinar`), **confere a assinatura contra `Chaves::USO`** (`bin/pbl verificar-assinatura`), apaga a chave e o job `publicar` cria a release com `.zip`, `.sig` e manifesto (o manifesto por último). Se a conferência falhar, nada é publicado e o job fica vermelho. **Sem o segredo** (ensaio ou fork) só sai um **rascunho** sem assinatura, com aviso no resumo do job.
6. **Voltar a versão de desenvolvimento**: PR pequeno trocando para `X.Y.(Z+1)-dev` (ou a próxima MINOR).
7. **Endereço de canal**: as instalações só enxergam a versão se o `latest.json` estiver no endereço configurado em `dados/config/atualizacao.json` (`url`). O Worker de [`infra/atualizacoes/`](../infra/atualizacoes/README.md) serve as releases nesse endereço; o deploy e o DNS são do dono (veja [atualização](tecnica/atualizacao.md#para-quem-publica-versões)).

### O que o CI faz e o que só o dono faz

| Passo | Quem | Onde |
|---|---|---|
| Conferir a tag × `Versao.php` × seção do `CHANGELOG.md` | CI | `.github/scripts/conferir-release.sh` |
| `composer install --no-dev --optimize-autoloader` e `bin/pbl empacotar … --sem-assinar --canal=estavel\|beta --url-base-pacote=/pacotes/` | CI | job `empacotar` (permissões só de leitura) |
| **Aprovar** o job de assinatura | **dono** | GitHub, Environment `release` |
| Assinar com a chave de uso e conferir contra `Chaves::USO` | CI | job `assinar` (`environment: release`, só tag `v*`, `contents: read`) |
| Publicar `.zip` + `.sig` + manifesto (única etapa com `contents: write`) | CI | job `publicar`, só com assinatura conferida |
| Girar a chave de uso se vazar (manifesto assinado pela **recuperação**) | **dono** | máquina offline ([ADR 0019](adr/0019-custodia-da-chave-de-assinatura.md)) |

A chave de **recuperação nunca entra no CI**. `--seguranca` é ligado quando a seção da versão no CHANGELOG tem uma subseção "Segurança" com conteúdo; `--a-partir-de` fica no padrão `0.0.0` (para outro valor, empacote à mão). Ensaio sem publicar: Actions → Release → "Run workflow" com uma tag existente; não assina nem publica, o resultado fica só como artefato.

### Contingência manual (`bin/assinar-release.sh`)

Use quando o CI não puder assinar: o Environment ou o segredo estão indisponíveis, a release saiu só como **rascunho** (ensaio/fork) ou há suspeita de vazamento da chave e o segredo foi removido. O script só aceita **rascunho**; ele baixa, confere hash e tamanho do pacote, assina localmente (chave num arquivo 0600), confere contra as chaves embutidas e publica:

```bash
bin/assinar-release.sh vX.Y.Z --chave-privada=/volume-cifrado/pbl4/uso.privada
```

### Passos manuais do dono (uma vez, depois de mesclar a automação)

1. Criar o Environment `release` (Settings → Environments) com **aprovador obrigatório**, restrito às tags `v*`, e cadastrar nele o segredo `PBL4_CHAVE_USO` (chave privada de uso em hex). O Claude Code cadastra a pedido.
2. Proteger as tags `v*` (ruleset: só quem o dono definir cria, move ou apaga).
3. Fazer o deploy do Worker de `infra/atualizacoes/` e apontar o domínio para ele.
4. Empurrar uma tag de ensaio (`vX.Y.Z-rc.1`), aprovar o job `assinar` e conferir a pré-lançamento no canal `beta`.

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
- [ ] A assinatura confere com as chaves embutidas (o job `assinar` e o `bin/assinar-release.sh` fazem isso antes de publicar)
- [ ] Seção "Atenção ao atualizar" revisada (quebras, migrações)
- [ ] Nenhum nome de cliente, órgão ou dado de terceiros nas notas
