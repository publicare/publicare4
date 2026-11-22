# Runbook — nova listagem de conteúdo (ADR 0032)

Escopo: só código e CSS do painel. Sem migração nova de infra, sem mudança em Dockerfile, compose, `.github` ou `deploy/`.
(A coluna `icone` da classe vem da migração M024, já existente.)

## Verificar antes de publicar
1. `make teste` (ou `vendor/bin/phpunit`): esperado 1715 testes, 0 falhas, 2 ignorados.
2. `make analise` (PHPStan).
3. Abrir Conteúdo, Pendências, Vencidos, Lixeira e Meus envios em 390x844, 844x390, 768x1024 e 1280x800, nos temas claro e escuro.

## Publicar
Fluxo normal de release do projeto (deploy gated por aprovação humana). Nenhum passo extra.

## Rollback
Reverter o merge do PR (`git revert <merge>`) e republicar. Sem alteração de dados: nada a desfazer no banco.
O ícone escolhido por classe (coluna `icone`) permanece e é ignorado pela lista antiga.

## Observação
Sinais de problema: menu ⋯ não abre (é `<details>`, sem JS), ação ausente para um perfil (conferir permissões em `AcaoDeLinha`), ícone genérico em classe com ícone (chave fora da lista fechada).
