# Procedência dos arquivos de terceiros da administração

Arquivos copiados sem nenhuma alteração da release oficial. Para atualizar: baixe o pacote do npm, confira o `integrity` publicado
no registro, copie os arquivos para uma pasta nova com a versão no nome, atualize esta tabela, `Pbl\Admin\Pagina::BOOTSTRAP` e o
teste `tests/AssetsAdminTest.php` (que confere o SHA-256 abaixo).

| Arquivo | Origem | Versão | SHA-256 | Licença |
|---|---|---|---|---|
| `bootstrap-5.3.8/bootstrap.min.css` | pacote npm `bootstrap@5.3.8` (`https://registry.npmjs.org/bootstrap/-/bootstrap-5.3.8.tgz`), arquivo `package/dist/css/bootstrap.min.css`; idêntico a `https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css` | 5.3.8 | `d85327d99c7a3ee1f9b5d0500d1370acea3ad2db39c163c2f51f232baedbdede` | MIT |
| `bootstrap-5.3.8/LICENSE` | mesmo pacote, `package/LICENSE` | 5.3.8 | `4620c84ad5ce8602ff65640ed6b7c8b78ebb9e036584f0ebc1ccc88206a4bb51` | MIT |

Integridade do pacote conferida em 2026-11-01 contra o registro do npm:
`sha512-HP1SZDqaLDPwsNiqRqi5NcP0SSXciX2s9E+RyqJIIqGo+vJeN5AJVM98CXmW/Wux0nQ5L7jeWUdplCEf0Ee+tg==`.

Só o CSS é usado: a administração não carrega JavaScript (nem o `bootstrap.bundle.js`). O arquivo `.map` citado no fim do CSS não
é distribuído (o navegador só o pede com as ferramentas de desenvolvedor abertas).

`pbl.css` é do próprio PBL4 (licença MIT do projeto).
