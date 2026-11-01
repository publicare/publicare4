# Política de segurança

## Como relatar uma vulnerabilidade

**Não abra uma issue pública.** Use o relato privado do GitHub:

1. Abra <https://github.com/publicare/publicare4/security/advisories/new>.
2. Descreva o problema, como reproduzir, o impacto que você enxerga e a versão (`bin/pbl versao`).

Se não conseguir usar o GitHub, escreva para **diogocorazolla@gmail.com** com o assunto "Segurança PBL4".

## O que esperar

| Prazo | O que acontece |
|---|---|
| até 3 dias úteis | confirmamos o recebimento |
| até 10 dias úteis | avaliamos e dizemos se é uma vulnerabilidade, a gravidade e o plano |
| conforme a gravidade | corrigimos, publicamos uma versão e damos crédito a quem relatou (se quiser) |

Pedimos que você não divulgue o problema antes da correção estar disponível. Não tomamos medida contra quem age de boa-fé e dentro do razoável (sem acessar dados de terceiros nem prejudicar sistemas em produção).

## Versões com correção de segurança

O projeto está em desenvolvimento inicial (versões `0.x`). Correções de segurança saem **na versão mais recente**. A partir da `1.0`, a última versão menor e a anterior recebem correções (veja [docs/versionamento.md](docs/versionamento.md)). Correções de segurança aparecem no [CHANGELOG](CHANGELOG.md) na seção **Segurança**.

## Boas práticas já adotadas

Moldes são executados em um ambiente restrito (sem PHP cru, sem acesso a arquivos ou funções do PHP); saída é escapada por padrão; SQL sempre com parâmetros; entradas de URL e de arquivo são validadas; a atualização automática exigirá pacotes assinados. Detalhes em [docs/tecnica/seguranca.md](docs/tecnica/seguranca.md).
