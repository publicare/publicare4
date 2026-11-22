# Instruções para agentes (VSF e outros)

Leia antes de mexer neste repositório.

## Testes — NÃO rode a suíte inteira
- A suíte completa (`vendor/bin/phpunit`, ~1.700 testes) leva **mais de 40 minutos** no ambiente dos agentes e estoura o limite da etapa (timeout), travando a atividade. **O CI do GitHub roda a suíte inteira** em PHP 8.2/8.3/8.4; esse é o portão.
- Rode só o que a sua mudança afeta: `vendor/bin/phpunit tests/ArquivoDoTeste.php` ou `--filter NomeDoTeste`, e **sempre** `vendor/bin/phpstan analyse --memory-limit=512M --no-progress` (rápido e é o que mais reprova no CI).
- Não deixe processos longos em segundo plano esperando terminar; termine a etapa com o que foi verificado e diga o que não foi (ex.: "suíte completa: coberta pelo CI").

## Escopo do PR
- **Nunca apague arquivos fora da demanda.** O diretório `prototipo-ux/` (protótipo clicável do painel) é de referência e **não deve ser removido** por atividades de listagem/texto/QA. Antes de concluir, confira `git diff --stat origin/main`: só arquivos da atividade.
- Não altere `.github/` nem arquivos de infraestrutura sem pedido explícito.

## Convenções
- Textos de interface: voz enxuta (`docs/ux/voz-e-texto.md`); sem enchimento.
- Todo PR atualiza o `CHANGELOG.md` (seção `[Unreleased]`).
