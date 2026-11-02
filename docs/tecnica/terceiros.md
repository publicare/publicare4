# Licenças de terceiros

O PBL4 é Apache-2.0 (o código herdado do Publicare 3 segue MIT; ver `LICENSE` e `NOTICE`, que vão em todo pacote de atualização). Além das dependências PHP instaladas pelo Composer (cada uma com a licença no próprio pacote em `vendor/`; liste com `composer licenses`), o produto distribui estes arquivos de terceiros:

| O quê | Onde | Versão | Licença | Procedência |
|---|---|---|---|---|
| Bootstrap (CSS e `bootstrap.bundle.min.js`) | `public/_pbl/assets/bootstrap-5.3.8/bootstrap.min.css` e `bootstrap.bundle.min.js` | 5.3.8 | MIT, texto em `public/_pbl/assets/bootstrap-5.3.8/LICENSE` | `public/_pbl/assets/PROVENIENCIA.md` (origem e SHA-256, conferidos por `tests/AssetsAdminTest.php`) |
| TinyMCE (editor de texto rico) | `public/_pbl/assets/tinymce-6.8.6/` (só os arquivos usados) | 6.8.6 | MIT, texto em `public/_pbl/assets/tinymce-6.8.6/LICENSE.txt` | `public/_pbl/assets/PROVENIENCIA.md` (origem, integridade npm e SHA-256, conferidos por `tests/AssetsAdminTest.php`) |

O Bootstrap dá o visual da administração e do instalador ([ADR 0012](../adr/0012-administracao-web.md)). Ele é servido do próprio site, sem CDN, e copiado sem alteração da release oficial.

O TinyMCE ([ADR 0020](../adr/0020-editor-de-texto-rico.md)) é o editor dos campos `html`: hospedado aqui, sem CDN nem nuvem, só na série **6.x (MIT)**; a 7+ é GPL/comercial e não deve ser usada. A 6.x está em fim de vida: correções de segurança ficam por nossa conta, e o servidor (`HtmlSeguro`) segue sendo a autoridade sobre o HTML gravado.
