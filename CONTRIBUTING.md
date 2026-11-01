# Como colaborar com o PBL4

Obrigado pelo interesse. O PBL4 é software livre (MIT) e toda ajuda conta: código, testes, documentação, tradução, revisão, ideias e relatos de uso. Este guia explica como participar sem surpresas.

## Formas de ajudar

| Quero… | Comece por… |
|---|---|
| Relatar um problema | Abra uma [issue](https://github.com/publicare/publicare4/issues/new/choose) com os passos para reproduzir |
| Propor uma ideia ou mudança | Abra uma issue "Proposta". Mudanças grandes viram um [ADR](docs/adr/) antes do código |
| Corrigir ou melhorar a documentação | Pode abrir o PR direto (veja [documentação](#documentação)) |
| Escrever código | Leia [desenvolvimento](docs/tecnica/desenvolvimento.md) e abra um PR pequeno |
| Relatar vulnerabilidade | **Não abra issue pública**: veja [SECURITY.md](SECURITY.md) |
| Testar com um site real | Conte o resultado numa issue (o conversor de moldes `bin/pbl converter-moldes` é um bom começo) |

## Regras do jogo

1. **Nada entra direto na `main`.** Todo trabalho vai em uma branch e chega por Pull Request. A `main` só recebe merge de PR com a pipeline verde (veja [pipeline](#pipeline-automática)).
2. **PR pequeno e focado.** Uma ideia por PR. Se ficou grande, divida.
3. **Teste junto com o código.** Todo comportamento novo vem com teste, inclusive o caso de erro e a entrada hostil.
4. **Segurança é critério de aceite**, não detalhe. Se o PR toca URL, SQL, arquivos, permissões, cache ou moldes, diga na descrição o que você considerou.
5. **Compatibilidade importa.** Mudança que quebra algo precisa estar no `CHANGELOG.md`, em "Alterado", com o que fazer para migrar.

## Fluxo de trabalho

```bash
git switch -c minha-mudanca        # a partir da main atualizada
# ... edite, rode os testes ...
make qa                            # testes (PHPUnit) + análise estática (PHPStan nível 8)
git commit                         # mensagem clara (veja abaixo)
git push -u origin minha-mudanca
# abra o Pull Request no GitHub
```

O ambiente roda em container (PHP 8.3, SQLite): o computador não precisa ter PHP. Passo a passo em [docs/tecnica/desenvolvimento.md](docs/tecnica/desenvolvimento.md).

### Nome da branch

`area/assunto-curto`, por exemplo `fase-1a-motor`, `corrige-regex-url`, `docs-guia-usuario`.

### Mensagem de commit

Primeira linha curta, no imperativo ou descritiva, dizendo **o quê**; o corpo diz **por quê**.

```
corrige regex de URL com backtracking catastrófico

A validação tinha quantificadores sobrepostos: ~90 caracteres estouravam o limite
do PCRE. Agora usa classes disjuntas e quantificadores possessivos.
```

Escreva em português. Prefixos como `docs:`, `corrige`, `fase N:` ajudam a ler o histórico, mas não são obrigatórios.

## Pipeline automática

Todo PR roda o workflow [`CI`](.github/workflows/ci.yml), e a `main` só aceita o merge se ele passar:

| Verificação | O que garante |
|---|---|
| **Testes e análise estática** (PHP 8.2, 8.3 e 8.4) | PHPUnit sem falhas e PHPStan nível 8 sem erros |
| **Dependências** | `composer.json` válido, `composer.lock` em dia e nenhuma vulnerabilidade conhecida nas dependências |
| **CHANGELOG atualizado** | Mudança em `app/`, `bin/`, `public/` ou `composer.json` precisa de uma linha em "Não lançado" (ou o rótulo `sem-changelog`, para mudança só de infra). PRs do Dependabot são dispensados |

Rode `make qa` antes de abrir o PR: é o mesmo que a pipeline roda para testes e análise.

## Revisão

- Um mantenedor revisa todo PR. Também há revisão automática por bot: leia os comentários, são úteis (já pegaram falhas reais), mas decida você mesmo; responda ou corrija.
- Revisão é sobre o código, não sobre a pessoa. Discordar com argumento é bem-vindo.
- Pedimos alterações com motivo. Se algo não ficou claro, pergunte no próprio PR.

## Padrões de código

- PHP 8.2+, `declare(strict_types=1)` em todo arquivo, tipos em tudo, PHPStan nível 8 sem erros.
- Sem framework full-stack ([ADR 0001](docs/adr/0001-base-p3-sem-framework.md)); dependências novas só com justificativa.
- Nomes de domínio em português (`Objeto`, `Classe`, `Pele`), como o resto do projeto.
- **Entrada de fora** (URL, parâmetros de molde, arquivos, formulários) é sempre validada; SQL sempre com parâmetros; nada do molde vira PHP nem SQL em texto livre.
- Regex que valida entrada pública: classes que não se sobrepõem, quantificadores possessivos, `/D` e teto de tamanho antes (veja a lição em [CHANGELOG](CHANGELOG.md)).
- Comentário explica o **porquê**, não repete o código.

## Documentação

A documentação mora em [`docs/`](docs/README.md) e se divide por público:

- [`docs/usuario/`](docs/usuario/README.md): para quem usa o Publicare, sem precisar programar.
- [`docs/tecnica/`](docs/tecnica/README.md): para quem instala, opera e desenvolve.
- [`docs/adr/`](docs/adr/) e [`docs/fluxos/`](docs/fluxos/): decisões e desenho.

Regras: português claro, frases curtas, nada de promessa de recurso que ainda não existe (diga o **estado**), links relativos (um teste confere que não quebram) e, em repositório público, **sem nome de cliente, órgão ou dado de terceiros**.

## Decisões de design

Mudanças que afetam a arquitetura, a segurança ou os contratos públicos (linguagem, API, formato de pacote) passam por um **ADR** em [`docs/adr/`](docs/adr/): contexto, decisão e consequências, em uma ou duas páginas. Abra primeiro uma issue "Proposta".

## Versões e releases

Seguimos [Versionamento Semântico](https://semver.org/lang/pt-BR/) e mantemos um [`CHANGELOG.md`](CHANGELOG.md). Veja [docs/versionamento.md](docs/versionamento.md) e [docs/releases.md](docs/releases.md).

## Licença e autoria

Ao contribuir, você concorda que sua contribuição seja licenciada sob a [licença MIT](LICENSE) do projeto e declara ter o direito de fazê-lo. Os créditos históricos do Publicare são preservados.

## Conduta

Participar do projeto é aceitar o [Código de Conduta](CODE_OF_CONDUCT.md).
