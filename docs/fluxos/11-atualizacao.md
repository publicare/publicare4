# Fluxo 11 — Atualização automática

## Hoje (PBL3)

Sem mecanismo: copiar os arquivos novos por cima do site (FTP), aplicar SQL à mão, torcer para nada ter sido sobrescrito (config, peles). Não há aviso de versão nova nem como voltar atrás.

## PBL4 (ADR 0006)

```mermaid
flowchart TD
    A[Admin abre o painel] --> B[Consulta latest.json do canal - cache de 24h]
    B --> C{Versão nova?}
    C -- não --> Z[Nada a fazer]
    C -- sim --> D[Aviso: versão, mudanças, segurança?]
    D --> E[Administrador confirma e reautentica]
    E --> F[Pré-checagens: espaço, permissões, PHP/banco mínimos, caminho de atualização]
    F --> G[Modo manutenção]
    G --> H[Backup: arquivos de app + dump do banco quando pequeno ou instrução ao admin]
    H --> I[Baixa o pacote e verifica SHA-256 e assinatura Ed25519]
    I --> J[Extrai em staging, confere lista de arquivos]
    J --> K[Troca atômica de app/]
    K --> L[Roda migrações]
    L --> M{Health-check OK?}
    M -- sim --> N[Limpa caches, sai da manutenção, registra na auditoria]
    M -- não --> R[Rollback: restaura app/ anterior e, se possível, reverte migração]
    R --> S[Sai da manutenção e mostra o motivo]
```

> **Estado:** o caminho pela linha de comando (verificar, baixar, conferir, extrair, backup, manutenção, troca, migrar, conferir, volta atrás) está implementado; veja [atualização](../tecnica/atualizacao.md). Na troca real usamos `rename` de cada pasta (`app/`, `vendor/`, …) com o código antigo guardado em `dados/atualizacao/anterior/`, em vez de `releases/` com links; o botão no painel existe (fase 4c, [ADR 0012](../adr/0012-administracao-web.md) §12): ele só inicia o mesmo comando em segundo plano. Falta a chave de assinatura de produção e a verificação sem abrir o painel (por cron).

### Pelo painel (fase 4c)

```mermaid
sequenceDiagram
    participant V as Navegador (administrador)
    participant T as TelasDeAtualizacao
    participant E as EstadoDoPainel (dados/atualizacao-painel)
    participant P as Processo em segundo plano
    V->>T: GET /_pbl/atualizacao
    T->>E: cache de 24 h vencido? consulta o manifesto assinado e guarda
    T-->>V: versão instalada, canal, versão nova, mudanças, segurança
    V->>T: POST /_pbl/atualizacao/aplicar (senha, confirmar, versão, CSRF, origem)
    T->>T: senha confere (limite de tentativas)? manifesto ao vivo = mesma versão?
    T->>E: trava (flock): já há uma em andamento? 409
    T->>P: sh -c "php bin/pbl atualizar" em segundo plano
    T-->>V: 303 de volta à tela (andamento)
    P->>P: o MESMO Atualizador da CLI (manutenção, troca, migra, confere)
    P->>E: saida.txt e codigo (quando acabou)
    V->>T: recarrega: concluída, falhou (motivo) ou sem resposta
```

- A requisição web nunca troca código: ela consulta, valida a senha e **dispara** `bin/pbl atualizar`. O processo grava `saida.txt` e, só ao terminar, `codigo` (0 = sem erro); a tela lê os dois a cada recarga. Sem `codigo` depois de 1 hora, a trava é considerada solta ("sem resposta").
- Durante a troca o site inteiro responde 503 (manutenção), inclusive a própria tela; basta recarregar depois.
- O estado fica em `dados/atualizacao-painel/` e não em `dados/atualizacao/`, que o atualizador apaga a cada execução.

### Layout de pastas

```
raiz/
  app/           código e assets do produto (substituível)
  vendor/        dependências (substituível)
  dados/         config, uploads, peles do site, cache, SQLite (nunca tocado)
  releases/      versões anteriores guardadas para rollback (as 2 últimas)
  public/        index.php (front controller)
```

### Troca atômica
- **Com symlink** (VPS, hospedagens que permitem): `app` é um link para `releases/<versão>`; a troca é um único `rename` do link.
- **Sem symlink** (compartilhada): `rename` de diretório (`app` → `app.old`, `app.new` → `app`), operação rápida e quase atômica; o modo manutenção cobre a janela. Se o `rename` não for permitido, o atualizador cai para o modo manual com instruções.

### Segurança das atualizações
| Ameaça | Defesa |
|---|---|
| CDN ou bucket comprometido | assinatura Ed25519 com chave fora do CDN; chave pública embutida |
| Pacote adulterado em trânsito | SHA-256 no manifesto assinado; HTTPS |
| Downgrade malicioso | versão só avança; manifesto traz versão mínima |
| Zip-slip | extração em staging, caminhos normalizados, lista de arquivos esperada |
| Escrita indevida de código | janela de escrita só durante a atualização, token de uso único e reautenticação; CLI recomendado quando houver terminal |
| Atualização que quebra o site | modo manutenção, backup, health-check, rollback automático |

### Publicação de uma release (lado do mantenedor)
1. Tag da versão no repositório; CI roda testes e empacota (`app/` + `vendor/` sem dev).
2. Assinatura do pacote e do manifesto (chave de release).
3. Envio ao bucket; `latest.json` do canal por último (publicação só vira visível quando tudo está no ar).
4. Changelog e flag `seguranca` quando for o caso.

### Pontos a decidir
- Tamanho típico do pacote e se vale oferecer pacote diferencial (patch) além do completo.
- Política de rollback de migrações (reversíveis vs. só compatíveis).
- Frequência e modo de checagem para sites sem cron (checagem no acesso do admin).
- Canal para atualizar módulos e peles de galeria.
