# ADR 0011 — Autenticação, sessão, permissões e auditoria

Status: implementado (2026-11-01), fases 3a e 3b. Ficam para depois: recuperação de senha por e-mail, autenticação em dois fatores, visitantes cadastrados (ADR 0004) e permissão por campo. Detalha o [fluxo 6](../fluxos/06-autenticacao-permissoes.md); complementa o [ADR 0004](0004-identidade-publica.md) (visitantes cadastrados, que ficam separados da equipe).

## Contexto

No PBL3 a autenticação e as permissões tinham falhas graves: senha com `md5`, limite de tentativas só na sessão (contornável), sem `session_regenerate_id`, sem CSRF e, principalmente, a permissão era uma **regex sem âncora sobre o texto da ação vindo da requisição**: quem podia `edit` pedia `action=/do/edit/gerusuario` e executava a administração de usuários. O PBL4 só pode ter área administrativa depois de resolver isso por construção.

## Decisão

### 1. Quem é quem
- **`usuario`** é a equipe (quem edita e administra). Visitantes cadastrados são `participante` (ADR 0004), outra população, que nunca acessa a administração.
- Tabela `usuario`: `id`, `login` (único, minúsculo), `email`, `nome`, `senha_hash`, `administrador` (0/1), `ativo` (0/1), `criado_em`, `ultimo_acesso`.

### 2. Perfis, ações e herança pela árvore
- Perfis **fixos no código** (não em tabela editável, para não haver regra escrita em dado): `leitor` (vê conteúdo restrito), `autor` (cria e edita o que **ele** criou), `editor` (edita tudo, publica, despublica, apaga) e `administrador` (global: tudo, mais gerir usuários, domínios, redirecionamentos e peles).
- Ações: `ver`, `criar`, `editar`, `publicar`, `apagar`, `administrar`. A matriz perfil → ações é um mapa em PHP.
- Tabela `acesso (usuario_id, objeto_id, perfil)`: um perfil atribuído a um objeto vale para **toda a subárvore** (herança pelo `caminho` materializado). Vale o perfil mais forte entre os que cobrem o objeto.
- `Permissoes::pode(usuario, acao, objeto)` é o **único** ponto de decisão. A ação é um identificador **fixo da rota** (comparação exata); texto vindo da requisição nunca decide permissão.
- Permissão por campo (fluxo 6) fica para depois, quando houver editor de classes.

### 3. Senhas
`password_hash` com **argon2id** e `password_needs_rehash` a cada login. Mínimo de 10 caracteres (sem limite superior baixo), verificação com `password_verify`, e a senha **nunca** passa por escape de HTML. Onde o PHP não tem Argon2 a reserva é bcrypt, que só considera os 72 primeiros bytes: nesse caso a senha passa antes por SHA-256 (em base64), para senhas longas iguais no começo não serem confundidas. Senhas `md5` do PBL3 são aceitas só pelo importador, marcadas como legadas, e trocadas por argon2id no primeiro acesso.

### 4. Sessão (própria, não a do PHP)
- Tabela `sessao (id_hash, usuario_id, criado_em, expira_em, ultimo_uso)`; o cookie leva um token de 32 bytes aleatórios e o banco guarda só o **hash** dele.
- Cookie `__Host-pbl4`: `HttpOnly`, `Secure` quando HTTPS, `SameSite=Lax`, `Path=/`.
- Expira por **inatividade** (30 min) e por tempo absoluto (12 h). **Token novo a cada login**; sair apaga a sessão; trocar a senha ou desativar o usuário encerra todas as sessões dele.
- Funciona em hospedagem comum (sem depender de arquivos de sessão do PHP nem de bloqueios).

### 5. CSRF e cabeçalhos
- Todo POST/PUT/PATCH/DELETE sob `/_pbl/` exige token CSRF (HMAC do id da sessão com o segredo do site, comparado com `hash_equals`), mais verificação de `Origin`/`Referer` quando presentes.
- Respostas de `/_pbl/`: `Cache-Control: no-store`, CSP restritiva, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`; HSTS opcional quando o site é HTTPS.
- O prefixo **`/_pbl/`** é reservado: o `_` nunca é válido em URL de conteúdo, então nenhuma página publicada colide com a administração.

### 6. Limite de tentativas e resposta uniforme
`tentativa_login (chave_hash, quando)` por login e por IP (guardados como hash), janela de 15 min, bloqueio crescente após 5 falhas. A resposta é a mesma para login inexistente e senha errada, e o tempo também (compara com um hash fictício quando o login não existe).

### 7. Auditoria
`auditoria (id, quando, usuario_id, acao, objeto_id, detalhe, ip_hash)` para entrar, falhar, sair, trocar senha, e toda alteração de conteúdo e de administração. Só acrescenta; sem edição nem exclusão pela aplicação.

### 8. Segredo do site
Gerado na instalação (`random_bytes(32)`) em `dados/config/segredo`, permissão 0600, fora do Git. Usado para o CSRF e para o hash de IP.

### 9. Extensão
Interface `Autenticador` (credenciais → usuário ou nulo). O padrão é o banco; LDAP entra depois como outra implementação, sem mudar sessão nem permissões.

### 10. Operação até haver tela
`bin/pbl usuario` (criar, senha, ativar/desativar, listar, acesso) cobre a administração de contas pela linha de comando.

### 11. Modo elevado para ações sensíveis do painel (ADR 0012, fase 5h)
A tela de Atualização (ADR 0012 §12) já pedia a senha de novo antes de aplicar ou reverter; a fase 5h generaliza isso num componente único, `Pbl\Acesso\ModoElevado`, usado por toda ação sensível do painel (contas, domínios, tipos de conteúdo e peles — detalhe em ADR 0012 §13). Confirmar a senha abre uma janela curta (10 min) guardada na própria sessão (`sessao.elevado_ate`): outra ação sensível da mesma sessão, dentro da janela, não pede a senha de novo. A janela não tem limpeza própria: acaba com a sessão, por **logout**, por **troca de senha** (que já encerra as sessões da conta) ou pela expiração normal (inatividade ou tempo absoluto). O limite de tentativas é o mesmo do login (§6), por login e por IP; falha e bloqueio vão para a auditoria, nunca a senha.

## Consequências

- Nenhuma decisão de permissão depende de texto da requisição; o erro do PBL3 deixa de ser possível por construção.
- A área pública continua sem tocar em sessão nem em usuários: a página em cache é servida como hoje.
- Exige cuidado operacional com o segredo do site e com HTTPS na administração (o cookie `Secure` só funciona assim).
- Visitantes cadastrados, recuperação de senha por e-mail e autenticação em dois fatores ficam para depois (ADR 0004 e fase seguinte).
