# Licenças de terceiros

O PBL4 é Apache-2.0 (o código herdado do Publicare 3 segue MIT; ver `LICENSE` e `NOTICE`, que vão em todo pacote de atualização). Além das dependências PHP instaladas pelo Composer (cada uma com a licença no próprio pacote em `vendor/`; liste com `composer licenses`), o produto distribui estes arquivos de terceiros:

| O quê | Onde | Versão | Licença | Procedência |
|---|---|---|---|---|
| Bootstrap (só o CSS) | `public/_pbl/assets/bootstrap-5.3.8/bootstrap.min.css` | 5.3.8 | MIT, texto em `public/_pbl/assets/bootstrap-5.3.8/LICENSE` | `public/_pbl/assets/PROVENIENCIA.md` (origem e SHA-256, conferidos por `tests/AssetsAdminTest.php`) |

O Bootstrap dá o visual da administração e do instalador ([ADR 0012](../adr/0012-administracao-web.md)). Ele é servido do próprio site, sem CDN, e copiado sem alteração da release oficial.
