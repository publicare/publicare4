# Changelog

Todas as mudanças relevantes do PBL4 ficam aqui. O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o projeto usa [Versionamento Semântico](https://semver.org/lang/pt-BR/) (regras em [docs/versionamento.md](docs/versionamento.md)). Como publicar uma versão: [docs/releases.md](docs/releases.md).

Enquanto a versão for `0.x`, o projeto ainda não promete estabilidade: mudanças que quebram podem entrar em versões MINOR e aparecem em **Alterado**.

## [Não lançado]

> Primeira versão pública prevista: **0.1.0** (núcleo mínimo: dados, linguagem de moldes, segurança básica, instalador e atualizador).

### Adicionado
- Estrutura do projeto: PHP 8.2+, SQLite por padrão (MySQL e PostgreSQL previstos), ambiente de desenvolvimento em container, testes (PHPUnit) e análise estática (PHPStan nível 8). (#3)
- Instalação por linha de comando (`bin/pbl instalar`) com site de exemplo e pele básica; publicação das páginas (`bin/pbl publicar`) em cache e entrega a partir do cache. (#4)
- Modelo de conteúdo: classes definidas sem código, objetos em árvore, dados validados pela classe, índice de propriedades, vários sites e domínios. (#4)
- **Linguagem de moldes PBL4**: expressões (`#campo`, `$variavel`, `%MACRO`), `eco`/`ecocru`, `var`, `se`/`senao`, `repetir`, `filhos`, `estende`/`bloco` (herança de layout), `incluir`, comentários e escape de HTML por padrão. Referência em [docs/linguagem-pbl4.md](docs/linguagem-pbl4.md). (#6)
- Comandos de consulta nos moldes: `localizar`, `aleatorio`, `localizaraleatorio`, `usarobjeto`, `temfilho`, `naolocalizado`. (#7)
- **Conversor de moldes PBL3 → PBL4** (`bin/pbl converter-moldes`) com relatório do que precisa de revisão. (#8)
- Metadados do objeto (`peso`, `descricao`, `data_publicacao`, `data_validade`) e ordenação por vários campos, inclusive por propriedade indexável. Objetos fora da vigência não aparecem. (#8)
- Documentação de design (ADRs 0003 a 0009, fluxos e plano), documentação para quem usa e técnica, guia de colaboração, política de segurança, versionamento e pipeline automática de testes.

### Alterado
- A sintaxe dos moldes mudou em relação ao rascunho inicial: `<@eco titulo@>` agora é `<@eco #titulo@>` e `<@repetir filhos@>` virou `<@filhos@>`. (#6)

### Corrigido
- Nova linha no fim da URL era aceita como válida. (#9)
- Data impossível (`2026-02-31`) virava 3 de março em `formatadata`; agora dá vazio. (#11)
- `repetir` podia girar sem fim nos limites dos inteiros. (#11)
- Busca por título e `usarobjeto titulo=` não achavam textos com letras acentuadas maiúsculas no SQLite. (#11)
- `aleatorio limite=1` sempre escolhia o primeiro item; agora sorteia antes de aplicar a janela. (#11)
- Bloco aninhado com o mesmo nome estourava a pilha do compilador; agora é um erro claro. (#11)
- Página com `data_validade` vencida continuava sendo servida do cache. (#10)

### Segurança
- A validação de URL tinha *backtracking* catastrófico (~93 caracteres esgotavam o limite do PCRE, ~4 ms de CPU por requisição em endereço público). Reescrita com classes disjuntas, quantificadores possessivos e teto de 300 caracteres. (#9)
- Campos do sistema (`#id`, `#site`, `#pai`…) vencem propriedades antigas da classe com o mesmo nome: impede um molde de ler objetos de outro site. (#11)
- `temfilho id=` e `usarobjeto` só enxergam objetos do mesmo site e dentro da vigência. (#10, #11)
- O conversor de moldes recusa gravar através de link simbólico e fora da pasta de destino. (#10)
- Moldes são compilados para PHP que só chama o *runtime*: PHP cru é rejeitado, variáveis são isoladas e laços e inclusões têm teto. (#6)

[Não lançado]: https://github.com/publicare/publicare4/commits/main
