# ADR 0034 — Transporte de e-mail plugável e segredo fora do catálogo público

Status: aceito (domínio implementado; tela de configuração pendente, ver ADR 0033).

## Contexto
`ConfigDoSite` é público por contrato (tudo pode ir ao HTML): a senha SMTP não pode morar nele. Não há `symfony/mailer` no `composer.lock` e esta entrega não adiciona dependência. A escolha do provedor é do dono.

## Decisão
- Interface `Pbl\Email\Transporte` com `enviar(Mensagem)` e `tipo()`: `desligado` (padrão, `TransporteNulo`), `arquivo` (`.eml` 0600 com nome aleatório em `dados/email/caixa/`) e `smtp`.
- `TransporteSmtp`: cliente mínimo, **só com TLS** (implícito ou STARTTLS, certificado validado, timeouts de 10 s), AUTH PLAIN com recuo para LOGIN, um destinatário, *dot-stuffing*. Erros não repetem usuário, senha nem a resposta do servidor. `CanalSmtp` separa a rede para testar o protocolo.
- `Mensagem`: texto puro; rejeita CR, LF e NUL em destinatário, assunto e remetente.
- Configuração em `dados/config/email.json` (0600, `GravacaoAtomica`), `ConfigDeEmailRepositorio`. A senha é cifrada com `sodium_crypto_secretbox` (chave = HMAC do segredo do site, nonce aleatório). `paraTela()` mascara; salvar sem senha nova mantém a antiga. Trocar o segredo do site exige digitar a senha de novo.
- Links nos e-mails usam só o domínio principal (`EnderecoDoSite`), nunca o Host da requisição. Sem domínio principal ou sem transporte, `EmailsDeConta::habilitado()` é falso e a recuperação/convite ficam desabilitados.

## Consequências
- Senha SMTP nunca em log, auditoria, export, e-mail ou tela.
- O backup deve excluir ou avisar sobre `email.json` (QA verifica).
- O driver `arquivo` em produção guarda links válidos em disco: a documentação avisa; o operador usa `bin/pbl`.
- Cliente SMTP próprio aumenta a superfície: trocar por biblioteca é pendência para o dono, e só muda a implementação atrás da interface.
