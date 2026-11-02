# ADR 0018 — Licenciamento: Apache-2.0

Status: aceito (2026-11-02). Fecha a questão 7 do [plano](../plano.md). Complementa os ADRs [0007](0007-galeria-pacotes-peles.md) e [0008](0008-marketplace-comunidade.md).

## Contexto

O Publicare 3 é MIT. O PBL4 nasceu aberto e continua aberto: outras pessoas e empresas podem usar, modificar, hospedar e até lucrar com ele. A pergunta era qual licença permissiva dá mais segurança a quem adota (órgãos públicos, agências, contribuidores) sem fechar o código.

## Decisão

1. **O PBL4 é licenciado sob Apache-2.0** (arquivo `LICENSE`, texto integral e inalterado; `composer.json` com `"license": "Apache-2.0"`).
2. **O Publicare 3 e o código antigo permanecem MIT.** O `NOTICE` dá os créditos ao Publicare 3 e aos coautores históricos (**Manuel Poppe** e **Thiago Borges**) e **preserva o texto integral da licença MIT original**, que deve acompanhar toda redistribuição daquele código. A MIT é compatível com a Apache-2.0: o conjunto pode ser redistribuído sob Apache-2.0 desde que a nota MIT seja mantida.
3. **A marca "Publicare" não é licenciada.** A Apache-2.0 (seção 6) não concede direito sobre nomes e logotipos. Quem distribui versão modificada ou presta serviço pode dizer que é "baseado no Publicare", mas não pode se apresentar como o Publicare oficial nem usar o nome de forma que confunda. Uma política de marca separada será escrita quando houver o primeiro uso de terceiros.
4. **`LICENSE` e `NOTICE` vão em todo pacote** do atualizador (`Pacote::ITENS`), para que a licença acompanhe cada instalação. Quem redistribuir deve manter o `NOTICE`.

## Por que Apache-2.0 e não MIT

- **Concessão expressa de patentes** (e cláusula de retaliação), que a MIT não tem: contribuidores e usuários ficam protegidos de um colaborador que depois alegue patente sobre o que contribuiu.
- **Marca fora da licença, dita por escrito** (seção 6), em vez de depender de interpretação.
- **Aceitação por órgãos públicos e departamentos jurídicos**, que reconhecem e costumam exigir a Apache-2.0 (ou equivalente) para software de terceiros; o público do PBL4 inclui prefeituras, universidades e institutos.
- **Aviso de modificações e `NOTICE`**: mantêm os créditos históricos visíveis em redistribuições.

Alternativas descartadas: manter MIT (sem patentes nem marca explícitas); GPL/AGPL (afastaria agências e quem quer peles e módulos proprietários, e o objetivo é adoção ampla); licença de "código aberto com restrição comercial" (deixaria de ser software livre e contraria a decisão de que outros podem lucrar).

## Como o projeto cobre custos sem fechar o código

O código inteiro é aberto; o dinheiro vem de serviços e de coisas que não precisam ser fechadas:

- **Patrocínio** (pessoas e organizações que dependem do projeto).
- **Hospedagem e suporte opcionais**: o mesmo produto pode ser instalado por conta própria de graça; quem não quer cuidar de servidor paga por hospedagem gerenciada, instalação, migração do Publicare 3 e suporte.
- **Peles e módulos de terceiros**: autores (inclusive o mantenedor) podem vender peles e módulos próprios sob a licença que escolherem, pelo catálogo do [ADR 0008](0008-marketplace-comunidade.md); o núcleo continua Apache-2.0.
- **O canal oficial de atualizações** (`atualizacoes.publicare.org`, [ADR 0019](0019-custodia-da-chave-de-assinatura.md)) é um serviço mantido pelo projeto: quem faz um fork pode operar o seu próprio com as suas chaves.

## Consequências

- Contribuições novas entram sob Apache-2.0 (seção 5 da licença: contribuição submetida é licenciada assim, salvo declaração em contrário); o `CONTRIBUTING.md` e o README apontam para isso.
- Dependências do Composer mantêm as licenças próprias; `composer licenses` lista, e `docs/tecnica/terceiros.md` cobre os arquivos de terceiros distribuídos.
- Quem já usa o código sob MIT (versões do Publicare 3) não perde nada: aquelas versões continuam MIT.
