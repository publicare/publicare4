# ADR 0026 — Itens menores do PBL3: vencidos, lixeira, registro por objeto, ícone por tipo e QR code

Status: aceito (2026-11-06). Fecha os "menores" da fase 5r. Estende o [ADR 0012](0012-administracao-web.md) (administração). A migração é aditiva ([ADR 0002](0002-schema-evolucao-aditiva.md)).

## Decisão

1. **Vencidos (`/_pbl/vencidos`)**: lista conteúdo `publicado` com `data_validade` no passado, ordenado do mais antigo, até 200 linhas. Filtro por seção (subárvore) e só aparece o que a pessoa pode Ver; "Editar" exige Editar. "Prorrogar ou despublicar" reaproveita a tela de estado existente (`/objeto/{id}/estado`, exige Publicar); não há mecanismo novo.
2. **Lixeira (`/_pbl/lixeira`)**: lista por seção (subárvore) o que está em `apagado`. Restaurar usa o `/objeto/{id}/recuperar` existente.
   - **Apagar de vez** (por objeto): só de item na lixeira, exige Apagar, versão otimista, CSRF, **confirmação com senha** (reautenticação, como nas outras ações destrutivas) e auditoria `objeto.apagar_definitivo`. Apaga a subárvore inteira, que precisa estar toda na lixeira; remove o objeto, versões, índice de busca, termos, tags, histórico de URLs, acessos, revisões e pilha. A auditoria é mantida.
   - **Esvaziar**: confirmação com senha, apaga o que a pessoa pode Apagar dentro do escopo escolhido (até 200 por vez), usando a versão atual de cada item no momento da execução (dúvida de produto: a tela não fixa a versão vista), e audita `lixeira.esvaziar` com a contagem.
   - Não tratado de propósito: propriedades `ref` de outros objetos que apontavam para o apagado ficam pendentes (a leitura já tolera alvo ausente) e as linhas de `arquivo` ficam, sendo os blobs órfãos limpos por `bin/pbl limpar-uploads`.
3. **Registro por objeto (`/_pbl/objeto/{id}/log`)**: só leitura, exige Ver. Mostra a auditoria filtrada pelo objeto (até 200 eventos, com o nome de quem fez, não `usuario:N`) e os passos do fluxo de revisão (com o motivo de devolução). Item "Registro de atividade" no menu do objeto.
4. **Ícone por tipo de conteúdo (migração 24)**: coluna opcional `classe.icone` com a chave de um conjunto fechado de ícones SVG de traço (`IconesDeClasse`, `currentColor`, sem arquivos nem biblioteca). Só administrador define (cartão "Ícone" na edição do tipo, auditoria `classe.icone`); não muda a versão da classe (é apresentação). Aparece na lista de tipos e na coluna "Tipo" da navegação de conteúdo.
5. **QR code (`/_pbl/objeto/{id}/qr`)**: gerador próprio em PHP puro (`CodigoQr`: modo byte, versões 1 a 10, correção M com queda para L, até 271 bytes), com SVG de um só caminho e `?formato=svg` para baixar. Sem dependência nova, sem CDN: por isso não há licença de terceiros a registrar. A saída foi conferida bit a bit contra a biblioteca de referência `qrcode` (Python) em quatro casos (versões 2, 4, 8 e 9, ambos os níveis). Só gera para conteúdo com endereço público; se não está no ar, avisa.
6. **`incluirmenu` do PBL3** corresponde ao `no_menu` do PBL4. O conversor não importa dados (só moldes) e `incluirmenu` não é comando de molde, então não há o que converter; o mapeamento fica registrado para o importador de bases (fase 9).

## Adiado: capa e miniatura de PDF

A imagem de PDF exigiria rasterizar a primeira página. O PHP com GD não lê PDF; seria preciso Imagick com Ghostscript ou `pdftoppm`, binários externos que a hospedagem compartilhada alvo não garante (ADR 0001, sem dependência pesada). Fica adiado até haver decisão sobre dependência opcional com queda para o ícone do tipo.

## Consequências

- O apagamento definitivo é irreversível: por isso a senha, a confirmação e a auditoria que sobrevive.
- Só a lixeira é apagável de vez; nada fora dela.
- A migração 24 só acrescenta uma coluna anulável; a versão anterior do código a ignora.
