# Procedência dos arquivos de terceiros da administração

Arquivos copiados sem nenhuma alteração da release oficial. Para atualizar: baixe o pacote do npm, confira o `integrity` publicado
no registro, copie os arquivos para uma pasta nova com a versão no nome, atualize esta tabela, `Pbl\Admin\Pagina::BOOTSTRAP` e o
teste `tests/AssetsAdminTest.php` (que confere o SHA-256 abaixo).

| Arquivo | Origem | Versão | SHA-256 | Licença |
|---|---|---|---|---|
| `bootstrap-5.3.8/bootstrap.min.css` | pacote npm `bootstrap@5.3.8` (`https://registry.npmjs.org/bootstrap/-/bootstrap-5.3.8.tgz`), arquivo `package/dist/css/bootstrap.min.css`; idêntico a `https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css` | 5.3.8 | `d85327d99c7a3ee1f9b5d0500d1370acea3ad2db39c163c2f51f232baedbdede` | MIT |
| `bootstrap-5.3.8/bootstrap.bundle.min.js` | pacote npm `bootstrap@5.3.8` (mesmo tarball), arquivo `package/dist/js/bootstrap.bundle.min.js`; idêntico a `https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js` | 5.3.8 | `e4fd49181388c48ec5040bd3fe66f57c29c8e67fcd8502b3354b96ec7ab47cc7` | MIT |
| `bootstrap-5.3.8/LICENSE` | mesmo pacote, `package/LICENSE` | 5.3.8 | `4620c84ad5ce8602ff65640ed6b7c8b78ebb9e036584f0ebc1ccc88206a4bb51` | MIT |

Integridade do pacote conferida em 2026-11-01 contra o registro do npm:
`sha512-HP1SZDqaLDPwsNiqRqi5NcP0SSXciX2s9E+RyqJIIqGo+vJeN5AJVM98CXmW/Wux0nQ5L7jeWUdplCEf0Ee+tg==`.

A administração carrega o CSS e o `bootstrap.bundle.min.js` (Bootstrap + Popper, para menu recolhível e painéis) como arquivos do próprio
site, com `<script src defer>` e `?v=hash`, nunca inline (CSP `script-src 'self'`; ADR 0012, adendo de 2026-11). Os arquivos `.map` citados
no fim do CSS e do JS não são distribuídos (o navegador só os pede com as ferramentas de desenvolvedor abertas).

`pbl-admin.js` é do próprio PBL4: pequeno, dirigido por atributos `data-*`.

`pbl.css` é do próprio PBL4 (licença MIT do projeto).

## TinyMCE 6.8.6 (editor de texto rico, ADR 0020)

Arquivos copiados sem alteração, só o necessário (núcleo, tema `silver`, skin `oxide`, ícones, plugins `lists`, `advlist`, `link`,
`table`, `image`, `code`, `autolink` e o idioma pt_BR; o plugin de colar já é embutido no núcleo na 6.x). Origem: tarball
`https://registry.npmjs.org/tinymce/-/tinymce-6.8.6.tgz`, integridade conferida em 2026-11-02 contra o registro do npm:
`sha512-++XYEs8lKWvZxDCjrr8Baiw7KiikraZ5JkLMg6EdnUVNKJui0IsrAADj5MsyUeFkcEryfn2jd3p09H7REvewyg==`. Licença **MIT** (a 6.x; a 7+ é
GPL/comercial e **não** deve ser usada): o `LICENSE.txt` vai junto. Sem `.map`, sem arquivos não usados, sem chave de licença, sem
chamadas externas (`promotion: false`, nenhuma CDN ou nuvem; conferido: o núcleo só cita endereços de documentação em mensagens).

**Fim de vida:** a série 6.x não recebe mais correções do fabricante. Correções de segurança ficam por nossa conta (o servidor
continua a autoridade: `HtmlSeguro` limpa tudo ao salvar, então uma falha no editor não vira falha no site). Caminho de migração
futuro: Tiptap/ProseMirror (MIT). Para atualizar dentro da 6.x: baixe o novo tarball, copie a mesma lista de arquivos para pasta
nova com a versão no nome, atualize `Pagina::TINYMCE`, esta tabela e o teste `AssetsAdminTest` (que confere cada SHA-256).

| Arquivo | Origem | Versão | SHA-256 | Licença |
|---|---|---|---|---|
| `tinymce-6.8.6/LICENSE.txt` | pacote npm `tinymce@6.8.6`, `package/license.txt` | 6.8.6 | `55cc2df5c872aa8f94bb8d05839e736003069d84317bda2e4ada4c03b604871a` | MIT |
| `tinymce-6.8.6/icons/default/icons.min.js` | pacote npm `tinymce@6.8.6`, `package/icons/default/icons.min.js` | 6.8.6 | `df42e91e6e15da0ab50513280cba33c8fb6d4e0fbdb4d8edaf3d39209d8d6d18` | MIT |
| `tinymce-6.8.6/langs/pt_BR.js` | pacote npm `tinymce-i18n@26.10.26`, arquivo `package/langs6/pt_BR.js` (pacote de idioma oficial do TinyMCE 6, redistribuído sob os mesmos termos da versão 6) | 6.x | `14ffcf9eb5478d84a9b5607726e49ff52947d6e0f5a9ecb1322697688c545351` | MIT (idem TinyMCE 6) |
| `tinymce-6.8.6/models/dom/model.min.js` | pacote npm `tinymce@6.8.6`, `package/models/dom/model.min.js` | 6.8.6 | `72d8ed7b618ef23c86e958ed45e6ad21068df9fb81fa4f5b6fc3a635df6a5deb` | MIT |
| `tinymce-6.8.6/plugins/advlist/plugin.min.js` | pacote npm `tinymce@6.8.6`, `package/plugins/advlist/plugin.min.js` | 6.8.6 | `6d03fb39ae4fc2d117d4dca11b211c7f050790e7a55488492b1de82372ab2d78` | MIT |
| `tinymce-6.8.6/plugins/autolink/plugin.min.js` | pacote npm `tinymce@6.8.6`, `package/plugins/autolink/plugin.min.js` | 6.8.6 | `4780b8ccacb67460b74e6ad8be8d9f36fbd98c083359c7baee7d6bf4f8d3eebb` | MIT |
| `tinymce-6.8.6/plugins/code/plugin.min.js` | pacote npm `tinymce@6.8.6`, `package/plugins/code/plugin.min.js` | 6.8.6 | `db417463133617b081ab891aa4ace720f244bbbec9e1dbc6783fdf87ca228547` | MIT |
| `tinymce-6.8.6/plugins/image/plugin.min.js` | pacote npm `tinymce@6.8.6`, `package/plugins/image/plugin.min.js` | 6.8.6 | `37839a5c17d537f1c9a4b3f59d453c2e30688415e4aa0b8f01e2926139553a0e` | MIT |
| `tinymce-6.8.6/plugins/link/plugin.min.js` | pacote npm `tinymce@6.8.6`, `package/plugins/link/plugin.min.js` | 6.8.6 | `698e129c7cee6b09d0fe294b6b42f77c16aa5edef6a35006bfa05e12121f526a` | MIT |
| `tinymce-6.8.6/plugins/lists/plugin.min.js` | pacote npm `tinymce@6.8.6`, `package/plugins/lists/plugin.min.js` | 6.8.6 | `4bd8d28534e188c67fdf7fb58c5aa796a9dac5b9d1bbf9b55e22ada843bbc2b2` | MIT |
| `tinymce-6.8.6/plugins/table/plugin.min.js` | pacote npm `tinymce@6.8.6`, `package/plugins/table/plugin.min.js` | 6.8.6 | `6545dbfeb5d5916d2a8c12258321acab17b798adbe5d1bd8a98d32d14c49a2f5` | MIT |
| `tinymce-6.8.6/skins/content/default/content.min.css` | pacote npm `tinymce@6.8.6`, `package/skins/content/default/content.min.css` | 6.8.6 | `bf14065e3e709d6c4377f348b25b2c8cd487546c4174a8f6bb2c22b213df681b` | MIT |
| `tinymce-6.8.6/skins/ui/oxide/content.min.css` | pacote npm `tinymce@6.8.6`, `package/skins/ui/oxide/content.min.css` | 6.8.6 | `9aa79c0ccd52a555df9fce73e398927ea82780ca8e7d07504986fba83ff4364f` | MIT |
| `tinymce-6.8.6/skins/ui/oxide/skin.min.css` | pacote npm `tinymce@6.8.6`, `package/skins/ui/oxide/skin.min.css` | 6.8.6 | `e9e58eb82c861c267cfe55314bfab35e7773d812d0824c8509671343aad61a43` | MIT |
| `tinymce-6.8.6/themes/silver/theme.min.js` | pacote npm `tinymce@6.8.6`, `package/themes/silver/theme.min.js` | 6.8.6 | `105d2de22dccd38801138814dc3579824198abfaaa95d3c597fac14c7aa4aa4a` | MIT |
| `tinymce-6.8.6/tinymce.min.js` | pacote npm `tinymce@6.8.6`, `package/tinymce.min.js` | 6.8.6 | `169a4906a5da18fe54ff99f16eab15b4a14879bf48d5e2b59cea3578f5fd4ecb` | MIT |

`pbl-editor.js` é do próprio PBL4: configura o TinyMCE por atributos `data-*` do `<textarea data-pbl-editor="html">` e só é carregado nas
telas que têm campo `html` (a CSP dessas telas ganha `style-src 'unsafe-inline'` e `img-src https:`; scripts continuam só `'self'`).
