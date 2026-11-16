# LGPD: dados pessoais no PBL4 (mínimo do 0.1.0)

O PBL4 **não coleta nada novo** por causa da LGPD. Este documento diz o que já fica guardado de pessoas, por quanto tempo e como atender um pedido do titular. O operador do site (quem instala) é o controlador dos dados; o projeto só entrega as ferramentas.

## Inventário

| Dado | Onde | Conteúdo pessoal | Retenção |
|---|---|---|---|
| Contas da equipe | tabela `usuario` | login, e-mail, nome, hash da senha (argon2id/bcrypt), datas de criação e de último acesso | Até a conta ser anonimizada (`conta-anonimizar`) |
| Papéis | tabela `acesso` | liga a conta a um objeto (seção) e a um perfil | Até revogar ou anonimizar |
| Autoria | `objeto.criado_por`, `objeto_versao.usuario_id` | só o número da conta | Enquanto o conteúdo existir; o número deixa de identificar alguém após a anonimização |
| Auditoria | tabela `auditoria` | número da conta, ator, ação, objeto, **IP só como HMAC** (`ip_hash`); o detalhe de `usuario.criar`/`usuario.importar` traz o login | `ip_hash`: 90 dias (configurável); linha: não apaga por padrão (configurável) |
| Sessões | tabela `sessao` | número da conta e horários (sem IP nem navegador) | Vencem sozinhas (inatividade ou 12 h); o cron apaga as vencidas |
| Tentativas de login | tabela `tentativa_login` | HMAC de login e de IP, nunca em claro | 24 h (cron, `LimpezaTarefa`) |
| Envios de formulário | objetos criados por `EnvioDeFormulario` | o que o visitante digitou nos campos | **Sem prazo automático** (ver limitações) |
| Cópias do banco | `dados/backups/` (atualização) | tudo o que está acima | Sem rodízio automático (ver limitações) |
| Logs do servidor web | fora do PBL4 | IP em claro, dependendo da configuração | Responsabilidade do operador |

Não há cookie de rastreamento, análise de visitas nem dado de visitante além do que o formulário público recebe. O único cookie é o da sessão da administração (`__Host-pbl4`) e um indicador de sessão.

## Retenção configurável

Arquivo opcional `dados/config/privacidade.json`:

```json
{"auditoria_ip_dias": 90, "auditoria_dias": 0}
```

- `auditoria_ip_dias` (padrão 90; `0` = não limpar): passado o prazo, o `ip_hash` da linha de auditoria é zerado. A linha fica, para a trilha de quem fez o quê.
- `auditoria_dias` (padrão `0` = nunca apagar): se maior que zero, apaga a linha inteira mais velha que o prazo. Ligue só se a política da instituição exigir; sem trilha, perde-se a investigação de incidentes.
- Valor inválido volta ao padrão.

A limpeza roda no cron central (tarefa `retencao`, uma vez por dia; veja `bin/pbl cron --listar`), é idempotente e só toca a auditoria. Detalhes do cron em [cron](cron.md).

## Atender o titular

```bash
bin/pbl conta-exportar bia > bia.json          # JSON: conta, papéis, objetos criados, auditoria; nunca a senha
bin/pbl conta-anonimizar bia                   # mostra o que faria, sem alterar
bin/pbl conta-anonimizar bia --confirmar       # anonimiza (sem volta)
```

- **Exportar** inclui o cadastro, os papéis, os números dos objetos criados, a contagem de versões gravadas e as linhas de auditoria da conta (inclusive a linha da criação, onde o login aparece no detalhe). O IP aparece só como hash. Audita `conta.exportar`.
- **Anonimizar** troca login, e-mail e nome por valores neutros (`anonimo-<id>`), invalida a senha, desativa a conta, tira o perfil de administrador, encerra as sessões, remove os papéis, zera o `ip_hash` e o login escrito na auditoria, e libera o contador de tentativas do login antigo. As linhas de auditoria e a autoria do conteúdo **ficam**, apontando para o número da conta, que já não identifica alguém. Recusa anonimizar a última conta de administrador ativa. Audita `conta.anonimizar` sem dado pessoal.
- Os dois comandos só existem na linha de comando (quem tem acesso ao servidor), como `conta-senha` e `conta-destravar` ([segurança](seguranca.md)).

## Limitações conhecidas (não prometer o que não existe)

- **Participantes de formulário** (titular externo, sem conta): o PBL4 ainda não exporta nem apaga esses dados por pessoa, nem tem prazo automático. Hoje o operador apaga o objeto do envio pela administração. Entra numa fase futura.
- **Cópias do banco** em `dados/backups/` guardam tudo; apague as antigas à mão. O rodízio automático ainda não existe, e uma anonimização não alcança cópias já feitas.
- **Logs do servidor web** e backups da hospedagem estão fora do alcance do PBL4.
- Nome e e-mail que alguém tenha escrito dentro do **conteúdo** de uma página não são localizados por estes comandos.
