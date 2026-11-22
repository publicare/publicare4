# Auditoria de texto do painel

Palavras de microcopy por tela, medidas por `bin/auditar-texto` (linha de base: commit 478af8d, `auditoria-texto-antes.json`). "Corrido" = subtítulo + ajuda + aviso + estado vazio + parágrafo. Meta: −40% de texto corrido.

## Onda 1 (Início, Conteúdo, Edição, Configurações)

| Tela | Corrido antes | Corrido depois | Corte | Total antes | Total depois |
|---|---:|---:|---:|---:|---:|
| Início | 35 | 26 | 26% | 84 | 75 |
| Conteúdo | 123 | 41 | 67% | 372 | 290 |
| Edição | 273 | 155 | 43% | 455 | 337 |
| Configurações | 245 | 164 | 33% | 342 | 261 |

Onda 1: 676 → 386 palavras de texto corrido (−43%). Avisos de risco (cron parado) mantidos, só mais curtos.

## Todas as telas (onda 2 ainda não feita)

| Tela | Corrido antes | Corrido depois | Corte | Total antes | Total depois |
|---|---:|---:|---:|---:|---:|
|---|---:|---:|---:|---:|---:|
| Entrar | 10 | 10 | 0% | 14 | 14 |
| Início | 35 | 26 | 26% | 84 | 75 |
| Conteúdo | 123 | 41 | 67% | 372 | 290 |
| Edição | 273 | 155 | 43% | 455 | 337 |
| Configurações | 245 | 164 | 33% | 342 | 261 |
| Peles | 61 | 61 | 0% | 99 | 99 |
| Redirecionamentos | 35 | 35 | 0% | 77 | 77 |
| Busca | 38 | 38 | 0% | 55 | 55 |
| Publicação | 34 | 34 | 0% | 43 | 43 |
| Domínios | 72 | 72 | 0% | 101 | 101 |
| Atualização | 43 | 43 | 0% | 110 | 110 |
| Cópias de segurança | 169 | 169 | 0% | 231 | 231 |
| Tarefas agendadas | 334 | 334 | 0% | 504 | 504 |
| Contas | 27 | 27 | 0% | 46 | 46 |
| Nova conta | 38 | 38 | 0% | 65 | 65 |
| Tipos de conteúdo | 25 | 25 | 0% | 207 | 207 |
| Novo tipo | 91 | 91 | 0% | 137 | 137 |
| Moldes | 126 | 126 | 0% | 174 | 174 |
| Revisão | 16 | 16 | 0% | 32 | 32 |
| Vencidos | 29 | 29 | 0% | 44 | 44 |
| Lixeira | 26 | 26 | 0% | 40 | 40 |
| Mais | 5 | 5 | 0% | 82 | 82 |
| **Soma** | 1855 | 1565 | 16% | 3314 | 3024 |
