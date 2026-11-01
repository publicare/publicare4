# Fluxo 12 — Administração pelo navegador

## Hoje (p3)

- Telas em `manage/*.php`, cada uma decidindo sozinha o que mostrar; a permissão era conferida por uma regex sem âncora sobre o texto da ação (fluxo 6), e esconder o link fazia as vezes de controle de acesso.
- Formulários sem token CSRF; saídas com escape irregular.
- Edição simultânea: quem salvava por último apagava o trabalho do outro, sem aviso.

## PBL4

> **Decisões:** [ADR 0012](../adr/0012-administracao-web.md). **Implementado (fase 5a):** painel com os sites e seções que a pessoa pode ver, navegação na árvore (só leitura, com conteúdo fora do ar) e edição de título e descrição com proteção contra edição simultânea e republicação. **Falta:** criar, mover, mudar estado, apagar, editar propriedades, histórico na tela, contas, domínios, redirecionamentos e peles.

### Ver um conteúdo

```mermaid
sequenceDiagram
    participant V as Navegador
    participant P as Painel
    participant T as TelasDeConteudo
    participant A as Permissoes
    participant M as MotorDeConsulta
    V->>P: GET /_pbl/objeto/45 (cookie de sessão)
    P->>P: id validado (só dígitos, sem zero à esquerda), sessão válida? senão 303 /_pbl/entrar
    P->>T: ver(sessão, 45)
    T->>A: pode(usuário, Acao::Ver, objeto 45)?
    A-->>T: não (ou objeto não existe) → 403, mesma resposta
    A-->>T: sim
    T->>M: filhos de 45 em qualquer estado (paginado, 100 por vez)
    T-->>V: HTML escapado: dados, migalhas (só ancestrais visíveis), filhos
```

### Editar título e descrição

```mermaid
sequenceDiagram
    participant V as Navegador
    participant T as TelasDeConteudo
    participant A as Permissoes
    participant R as ObjetoRepositorio
    participant U as Publicador
    V->>T: GET /_pbl/objeto/45/editar
    T->>A: pode(Acao::Editar)? (autor: só o que criou)
    T-->>V: formulário com _csrf e versao=7
    V->>T: POST titulo, descricao, versao=7, _csrf (+ Origin)
    T->>T: origem e CSRF (403 se falhar, nada gravado)
    T->>A: pode(Acao::Editar)? não → 403 + auditoria objeto.editar.negado
    T->>T: valida texto (422 se inválido), nada mudou → volta sem gravar
    T->>R: atualizar(45, mudanças, "administracao", versaoEsperada=7, usuário)
    R-->>T: ConflitoDeVersao → 409: o seu texto nos campos + o que está salvo
    R-->>T: ok (versão 8, auditoria com nomes dos campos)
    T->>U: republica a página e a do pai, se no ar
    T-->>V: 303 /_pbl/objeto/45?salvo=sim
```

1. **A rota decide a ação** (`Acao::Ver`, `Acao::Editar`), nunca o texto do pedido; a decisão é sempre de `Permissoes::pode`.
2. **Mesma resposta** para "não existe" e "sem permissão" (403): ninguém descobre ids alheios.
3. **Edição simultânea** é recusada com as duas versões à vista; salvar de novo é uma decisão consciente.
4. **Republicação** imediata da página editada e da página-mãe; outras páginas que citam o título (menus em outras seções) mudam na próxima publicação do site.
5. **Visual**: Bootstrap 5 local (só CSS), sem JavaScript, servido direto pelo servidor web a partir de `public/_pbl/assets/`.
