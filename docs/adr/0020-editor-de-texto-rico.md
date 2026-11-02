# ADR 0020 — Editor de texto rico para campos `html`

Status: aceito (2026-11-02). Complementa o [ADR 0012](0012-administracao-web.md) (§9–10: limpeza de HTML por lista de permissões; adendo de 2026-11: JavaScript próprio permitido, CSP `script-src 'self'`).

## Contexto

Os campos do tipo `html` ("Texto formatado") eram uma caixa de texto onde a pessoa escrevia HTML. O público do PBL4 (órgãos públicos, pequenas organizações) recebe conteúdo de Word, Google Docs e de páginas HTML, e não escreve marcação à mão. Precisa de: tamanho e títulos, negrito/itálico/sublinhado, listas, alinhamento, links, tabelas, imagens por endereço, desfazer/refazer, limpar formatação, colar do Word e de HTML, e ver o código-fonte.

## Decisão

1. **TinyMCE 6.8.6** (última da série 6.x, licença **MIT**), hospedado no próprio site em `public/_pbl/assets/tinymce-6.8.6/`, sem CDN, sem nuvem, sem chave de licença, sem telemetria (`promotion: false`, `branding: false`; o núcleo não faz chamada de rede). Vai só o necessário: núcleo, tema `silver`, skin `oxide`, ícones, plugins `lists`, `advlist`, `link`, `table`, `image`, `code`, `autolink` e o idioma pt_BR; sem sourcemaps. O `LICENSE.txt` vai junto; origem, integridade e SHA-256 de cada arquivo estão em `PROVENIENCIA.md` e são conferidos por `tests/AssetsAdminTest.php`.
2. **Não usar a 7.x ou posterior** (GPL/comercial). **A série 6.x está em fim de vida**: correções de segurança ficam por nossa conta. Isso é aceitável porque o editor é só conveniência de entrada: **o servidor é a autoridade** (item 4) e a administração exige login. Caminho futuro de migração: Tiptap/ProseMirror (MIT).
3. **Integração sem script inline.** A tela marca o `<textarea>` com `data-pbl-editor="html"` e `data-pbl-editor-base`; `Pagina::resposta` carrega `tinymce.min.js` e `pbl-editor.js` (ambos `?v=hash`, `defer`) **somente** se o corpo da página tem essa marca. Se o JavaScript falhar, o textarea segue funcionando como campo de HTML. Ao salvar, o editor grava o HTML no campo normal do formulário. A CSP dessas telas (e só delas) passa a aceitar `style-src 'self' 'unsafe-inline'` (o TinyMCE aplica `style` em linha no conteúdo e na interface) e `img-src 'self' data: https:` (pré-visualizar imagens por endereço); **`script-src` continua `'self'`**.
4. **O servidor continua a autoridade (`HtmlSeguro`).** Lista de permissões ampliada: elementos de tabela (`colgroup`, `col`, atributos numéricos `border`/`cellpadding`/`cellspacing`) e `style` limitado por elemento a propriedades de uma lista com valores de padrão estrito: `text-align`, `font-size` (número com `px`/`pt`/`em`/`rem`/`%`), e em tabelas `width`, `height`, `border-collapse`. Cor, fonte, margens, `position`, `url(...)` etc. não passam. O `align` antigo vira `text-align`.
5. **Limpeza de lixo do Word/Google Docs no servidor e no cliente.** No servidor: `<style>`, `<meta>`, `<xml>`, comentários condicionais, `<![if ...]>`, marcadores falsos de lista, elementos `o:`/`w:`/`v:`, classes `Mso*` e todo `mso-*` somem; `span` sem efeito é desembrulhado; `font-weight`/`font-style`/`text-decoration`/`vertical-align` em `span` viram `strong`/`em`/`u`/`s`/`sup`/`sub`; o invólucro `<b style="font-weight:normal">` do Google Docs é desembrulhado. No cliente: `paste_data_images: false`, `paste_remove_styles_if_webkit`, `paste_webkit_styles: 'none'`, `paste_word_valid_elements`, `valid_elements`/`valid_styles` iguais à lista do servidor.
6. **Conteúdo já guardado**: `bin/pbl sanitizar-html` usa `HtmlSeguro::limpar`, então já aplica a lista nova (idempotente). Conteúdo anterior continua válido, pois a lista só cresceu.

## Consequências

- O editor ocupa ~1,3 MB em `public/_pbl/assets/` (só carregado nas telas com campo `html`).
- Sem uso de nuvem e sem chave: nada a expirar ou a pagar. Em troca, a série 6.x não recebe correções do fabricante (item 2).
- A CSP das telas com editor é um pouco mais frouxa em estilo e imagem (não em script). Estilos em linha não executam código.
- Imagens são por endereço (sem envio de arquivo pelo editor); imagens do site entram pelos campos `imagem` do conteúdo.
- Conversão de listas coladas do Word (parágrafos "Mso List") em `<ul>/<ol>` fica por conta do TinyMCE no cliente; o servidor só remove o marcador falso.
- Não foi possível validar a interface num navegador real no ambiente de desenvolvimento do rock64 (sem Chromium); fica como verificação manual da primeira versão a usar o editor.
