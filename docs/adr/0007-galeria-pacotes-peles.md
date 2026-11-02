# ADR 0007 — Galeria e pacotes de peles

Status: proposto (2026-11-01); o editor de moldes (sem galeria, pacotes nem pele filha) está implementado — veja o [ADR 0012](0012-administracao-web.md) §9 (editar, criar e apagar molde) e §14 (histórico, rascunho/pré-visualização e realce de leitura, fase 5j). Complementa os ADRs [0003](0003-plataforma-low-code.md) e [0006](0006-atualizador-automatico.md).

## Contexto

Peles (e templates) são o que dá cara ao site. O leigo precisa **escolher uma pele pronta e instalar com um clique**, e quem desenvolve precisa **importar** uma pele de arquivo. O PBL3 só tinha cópia de arquivos por FTP. O canal assinado do atualizador (ADR 0006) já resolve distribuição e confiança; as peles reaproveitam o mesmo mecanismo.

**Nota (fase 5j):** o editor de moldes (um arquivo `.pbl` por vez, dentro de uma pele já existente) ganhou histórico de versões, diferença entre versões, voltar a uma versão, rascunho com pré-visualização isolada e realce de sintaxe só leitura — tudo pelo navegador, sem JavaScript (ADR 0012 §14). Isto **não** antecipa nada deste ADR: continuam fora, como antes, importar/exportar um pacote de pele, a galeria, o manifesto `pele.json`, pele filha (sobrescritas sobre uma pele instalada) e arquivos que não sejam `.pbl` (CSS, imagens, JS).

## Decisão

1. **Pacote de pele** = `.zip` com `pele.json` (manifesto) + arquivos (molde PBL, CSS, JS, imagens, fontes). Manifesto: nome, identificador único (`autor/nome`), versão, autor, licença, versão mínima do PBL4, classes e campos que a pele espera, imagens de prévia, e lista de arquivos com hash.
2. **Galeria oficial** servida por CDN: um `catalogo.json` por canal lista as peles (miniatura, descrição, versão, tamanho, URL do pacote, hash). Assinado com a chave de releases, ou com uma chave própria do catálogo (a pública vem embutida; rotação como no ADR 0006). Download estático, sem API.
3. **Três origens de instalação**, mostradas ao usuário com o nível de confiança:
   - **Galeria oficial**: assinatura verificada. Instalação com um clique.
   - **Importar arquivo `.zip`** (e, opcionalmente, URL): sem assinatura confiável; entra em modo **"não verificado"** com aviso e relatório de análise (arquivos, extensões, o que o molde faz).
   - **Pele própria** criada no editor (fluxo 4), exportável como pacote.
4. **Instalação em duas etapas**: o pacote é extraído em área de **quarentena**, validado (caminhos, extensões, tamanhos, hashes, **moldes pelo compilador PBL em modo "compilar sem executar"**) e só então publicado em `pele_arquivo`. Falha em qualquer arquivo cancela tudo.
5. **Segurança do conteúdo da pele**: moldes rodam no sandbox do PBL (sem PHP cru, ADR 0003); `.php`, `.phar`, `.htaccess` e executáveis são rejeitados; JS e CSS são servidos como estáticos com CSP do site; SVG sanitizado; sem acesso a funções de sistema. Uma pele não verificada **não pode registrar comandos PBL nem ganchos de código** (isso é privilégio de módulo assinado, ADR 0005).
6. **Compatibilidade declarada**: a pele lista as classes e campos de que depende; na instalação o sistema mostra o que falta ou sobra e **mapeia** campos (ex.: `titulo` → `nome`). Instalar não altera conteúdo do site.
7. **Personalização sem perder atualizações**: o usuário edita uma **cópia filha** (sobrescritas) da pele instalada. A pele original continua intacta e atualizável pelo canal; as sobrescritas são aplicadas por cima. Atualização de pele mostra o diff contra as sobrescritas e permite reverter.
8. **Atualização, remoção e uso**: peles instaladas aparecem com versão e aviso de nova versão; remover mostra os objetos/sites que usam e exige substituta (fluxo 4). Peles são globais, com escolha por site (ADR 0003 §5).
9. **Instalador**: o passo de pele inicial usa esta galeria, com a pele básica embutida como fallback sem internet (fluxo 3).
10. **Publicação por terceiros, peles pagas e comunidades abertas** (futuro): ver [ADR 0008](0008-marketplace-comunidade.md).

## Consequências

- O leigo vai de "instalado" a "site com cara própria" sem tocar em arquivos.
- A confiança se apoia na assinatura, e o sandbox do PBL limita o dano de uma pele maliciosa mesmo sem ela.
- Exige curadoria do catálogo e uma convenção de manifesto estável (versionada).
