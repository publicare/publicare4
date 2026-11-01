# Versionamento

O PBL4 usa **[Versionamento Semântico 2.0](https://semver.org/lang/pt-BR/)**: `MAJOR.MINOR.PATCH` (por exemplo `0.3.1`). A versão é explícita, única e rastreável.

## Uma fonte de verdade

| O quê | Onde |
|---|---|
| Versão do produto | `Pbl\Versao::ATUAL` em [`app/src/Versao.php`](../app/src/Versao.php) (mostrada por `bin/pbl versao`) |
| Histórico do que mudou | [`CHANGELOG.md`](../CHANGELOG.md) |
| Marco no Git | tag `vMAJOR.MINOR.PATCH` na `main` (por exemplo `v0.1.0`) |
| Texto para quem usa | a *release* do GitHub, criada a partir da tag (veja [releases](releases.md)) |

Um teste automático (`VersaoTest`) confere que a versão é válida e que o `CHANGELOG.md` tem a seção correspondente. Entre uma release e outra a versão é `X.Y.Z-dev`, e as mudanças ficam em "Não lançado".

## Quando muda cada número

| Número | Sobe quando… | Exemplo |
|---|---|---|
| **MAJOR** | há mudança que **quebra** quem já usa: comando da linguagem removido ou com outro significado, formato de pacote ou de API incompatível, migração destrutiva | `1.x → 2.0` |
| **MINOR** | entra recurso novo **compatível** (comando de molde, tipo de propriedade, tela, comando de linha) | `0.2.x → 0.3.0` |
| **PATCH** | só correção de problema, sem mudar contrato | `0.3.0 → 0.3.1` |

### Enquanto a versão for `0.x`

O projeto está em construção e **ainda não promete estabilidade**: mudanças que quebram podem entrar numa versão MINOR. Elas sempre aparecem no `CHANGELOG.md` em "Alterado", com o que fazer para migrar. A `1.0.0` marca o primeiro conjunto estável (veja o [plano](plano.md)): instalar, atualizar, publicar, linguagem de moldes e segurança básica.

Pré-lançamentos usam sufixo: `0.2.0-beta.1`, `1.0.0-rc.1`.

## O que também é versionado

| Contrato | Como é versionado | Regra |
|---|---|---|
| **Banco de dados** | tabela `schema_versao` (migrações numeradas, `M001`, `M002`…) | só adiciona (compatível com a versão anterior do código); remover coluna só duas versões depois ([ADR 0006](adr/0006-atualizador-automatico.md)) |
| **Linguagem de moldes** | [linguagem-pbl4.md](linguagem-pbl4.md) + `Compilador::FORMATO` | mudança de gramática segue o SemVer do produto; `FORMATO` sobe quando o PHP gerado muda e invalida o cache compilado |
| **API de conteúdo** (futura) | prefixo `/api/v1/` | mudança incompatível cria `v2`; a `v1` continua por um período anunciado ([ADR 0009](adr/0009-peles-de-saida-headless.md)) |
| **Pacotes de pele** (futuro) | `versao` e `versao_minima_pbl4` no `pele.json` | a galeria só oferece peles compatíveis com a versão instalada ([ADR 0007](adr/0007-galeria-pacotes-peles.md)) |
| **Atualizador** (futuro) | manifesto `latest.json` por canal (`estavel`, `beta`) | a versão só avança; nunca há *downgrade* automático ([ADR 0006](adr/0006-atualizador-automatico.md)) |

## Descontinuação

Antes de remover algo (comando, parâmetro, rota), o recurso é marcado como **descontinuado** em uma versão MINOR, com aviso no `CHANGELOG.md` e na documentação, e só sai numa versão MAJOR (ou, em `0.x`, em uma MINOR posterior, com pelo menos uma versão de aviso).

## Suporte a versões

| Versão | Recebe |
|---|---|
| `0.x` | correções na **versão mais recente** |
| `1.0` em diante | correções de segurança na última versão MINOR e na anterior; demais correções na última |

## Quem decide e como se publica

Os mantenedores decidem o número olhando o `CHANGELOG.md` de "Não lançado": qualquer item em "Alterado" que quebre contrato pede MAJOR (ou MINOR em `0.x`); itens em "Adicionado" pedem MINOR; só "Corrigido" e "Segurança" pedem PATCH. O passo a passo da publicação está em [releases.md](releases.md).
