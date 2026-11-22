# Auditoria de texto do painel

Palavras de microcopy por tela, medidas por `bin/auditar-texto --comparar=docs/ux/auditoria-texto-antes.json` (linha de base: commit 478af8d). "Corrido" = subtítulo + ajuda + aviso + estado vazio + parágrafo. Meta: −40% de texto corrido.

| Tela | Corrido antes | Corrido depois | Corte | Total antes | Total depois |
|---|---:|---:|---:|---:|---:|
| Entrar | 10 | 10 | 0% | 14 | 14 |
| Início | 35 | 22 | 37% | 84 | 71 |
| Conteúdo | 123 | 41 | 67% | 372 | 290 |
| Edição | 273 | 155 | 43% | 455 | 337 |
| Configurações | 245 | 164 | 33% | 342 | 261 |
| Peles | 61 | 38 | 38% | 99 | 76 |
| Redirecionamentos | 35 | 27 | 23% | 77 | 69 |
| Busca | 38 | 18 | 53% | 55 | 35 |
| Publicação | 34 | 21 | 38% | 43 | 30 |
| Domínios | 72 | 41 | 43% | 101 | 70 |
| Atualização | 43 | 43 | 0% | 110 | 99 |
| Cópias de segurança | 169 | 110 | 35% | 231 | 170 |
| Tarefas agendadas | 334 | 165 | 51% | 504 | 337 |
| Contas | 27 | 27 | 0% | 46 | 46 |
| Nova conta | 38 | 38 | 0% | 65 | 65 |
| Tipos de conteúdo | 25 | 10 | 60% | 207 | 192 |
| Novo tipo | 91 | 30 | 67% | 137 | 76 |
| Moldes | 126 | 55 | 56% | 174 | 103 |
| Revisão | 16 | 10 | 38% | 32 | 26 |
| Vencidos | 29 | 16 | 45% | 44 | 31 |
| Lixeira | 26 | 14 | 46% | 40 | 28 |
| Mais | 5 | 5 | 0% | 82 | 82 |
| **Soma** | 1855 | 1060 | 43% | 3314 | 2508 |

Resultado: 1855 → 1060 palavras de texto corrido (−43%). Avisos de risco (senha, restauração, atualização, cron parado) mantidos, só mais curtos.
