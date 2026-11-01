# ADR 0008 — Peles avançadas pagas e comunidade

Status: proposto, **futuro** (2026-11-01). Só se justifica depois de haver base de instalações; nada aqui entra nas fases 0–5. Complementa os ADRs [0006](0006-atualizador-automatico.md) e [0007](0007-galeria-pacotes-peles.md).

## Contexto

O instalador já oferece uma lista pública de peles como ponto de partida (fluxo 3). Se o PBL4 ganhar tração, há espaço para um segundo nível: peles avançadas pagas e peles de autores da comunidade, mantendo o núcleo gratuito e aberto.

## Decisão

1. **Camadas de oferta**, todas no mesmo catálogo e no mesmo formato de pacote (ADR 0007):
   - **Essenciais (gratuitas, oficiais):** vêm no instalador; sempre há uma pele básica **embutida no pacote** para instalar sem internet.
   - **Comunidade (gratuitas ou pagas):** de autores externos, revisadas e assinadas pelo mantenedor do catálogo.
   - **Avançadas (pagas, oficiais ou de parceiros):** peles com recursos extras (modelos de evento, biblioteca, portal institucional completo, widgets).
2. **Licenciamento, não proteção contra cópia.** Moldes PBL e CSS são texto; DRM é inviável. A cobrança é por **licença** (chave por site/domínio): dá direito a download e a atualizações enquanto válida. Chave verificada no ato do download e da atualização, nunca a cada requisição do site; site com licença vencida continua funcionando, só deixa de receber atualização.
3. **Entrega de pacote pago.** Pacote em área privada do bucket; o catálogo lista, mas o download exige a licença e usa URL assinada e de curta duração. Hash e assinatura do pacote seguem valendo.
4. **Loja e pagamento do projeto** são separados do ADR 0005 (que é o site cobrando seus participantes): aqui o projeto cobra o dono do site. Usar um provedor de pagamento externo com checkout hospedado, sem dados de cartão no PBL4; emissão fiscal e impostos fora do escopo deste ADR.
5. **Programa de autores**: cadastro, termos, revisão técnica e de segurança (compilação, análise de arquivos), versão mínima de compatibilidade, política de suporte, divisão de receita definida em termo público. Peles de comunidade gratuitas podem ser aceitas com revisão mais leve.
6. **Comunidades abertas à participação pública**: espaço para autores e usuários trocarem peles, modelos de sistema (consulta pública, evento, biblioteca) e conectores. Moderação, avaliação e relato de abuso. Pacotes de comunidade nunca ganham privilégio de código sem assinatura do mantenedor (continua valendo o ADR 0007, item 5).
7. **Reversibilidade e não bloqueio**: nenhum recurso do núcleo fica atrás de pagamento; o usuário pode sempre criar suas próprias peles e importar de arquivo. A camada paga é conveniência e conteúdo.

## Consequências

- Possível fonte de receita sem comprometer a natureza aberta do núcleo.
- Traz obrigações: suporte, moderação, termos de uso, tributação, privacidade (a verificação de licença identifica o site).
- Só avançar com demanda real. Antes disso, a galeria gratuita e o importador (ADR 0007) bastam.
