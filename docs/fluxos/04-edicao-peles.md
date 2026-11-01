# Fluxo 4 — Edição de peles, templates e layouts

## Hoje (p3)

- `manage/peles.php` e `peles_post.php` fazem CRUD de **nome/prefixo/pública** na tabela `pele`. Nada além.
- O conteúdo real vive no disco: `html/skin/<prefixo>/{header,footer,view_<classe>,view_basic}.php` e `html/template/*`. O admin não vê nem edita esses arquivos; edição é por FTP.
- Apagar pele não remove arquivos nem confirma no servidor. Sem versionamento, preview ou validação; um erro de PBL derruba a página pública.
- Salvar um molde equivale a executar código (`eval`), então o recurso é sensível.

## PBL4

```mermaid
flowchart TD
    L[Lista de peles] --> P[Pele: arquivos, classes atendidas, objetos que a usam]
    P --> ED[Editor: CodeMirror com realce PBL/HTML]
    ED --> V{Validar}
    V -- erros arquivo:linha --> ED
    V -- ok --> PV[Preview: renderiza objeto escolhido com o rascunho]
    PV --> PB[Publicar]
    PB --> VER[(pele_arquivo_versao)]
    PB --> CC[Invalida cache compilado]
    VER --> RB[Histórico: diff e rollback]
    P --> IE[Importar / exportar .zip]
    P --> DP[Duplicar pele]
```

Funcionalidades (ordem de entrega):
1. **Navegador de arquivos** restrito à pele (caminho canônico, extensões permitidas).
2. **Editor** com realce de PBL, autocompletar de comandos e de propriedades da classe (a partir de `propriedade`).
3. **Validação ao salvar** com o validador do fluxo 2: mesma mensagem, mesmo arquivo e linha.
4. **Rascunho x publicado**: editar não afeta o site até publicar; preview via sessão do editor.
5. **Versionamento**: toda publicação grava versão (usuário, data, hash); diff e rollback de um clique.
6. **Import/export `.zip`** com manifesto (nome, prefixo, versão, classes suportadas). Importação valida caminhos (zip-slip), extensões e rejeita PHP cru.
7. **Galeria de peles iniciais** usadas pelo instalador (fluxo 3).
8. **Assistente "criar pele"**: o leigo escolhe base, cores, logo e menu; o sistema gera header/footer/views. PBL só aparece no modo avançado.
9. **Apagar pele**: mostra quais objetos a usam e exige substituta.

Segurança:
- Somente perfil Administrador; CSRF; registro em `auditoria` (quem, o quê, quando).
- Conteúdo de usuário **nunca** passa pelo compilador; só moldes de pele.
- Preferência: moldes não executam PHP cru (fluxo 2, item 6).

Fonte de verdade: `pele_arquivo` no banco (ADR 0002); o disco é cache compilado. Questão em aberto: política para quem ainda edita por FTP (reimportar do disco ou ignorar).
