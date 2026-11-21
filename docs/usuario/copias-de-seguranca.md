# Cópias de segurança — passo a passo

> A tela "Cópias de segurança" do painel ainda não está disponível. Hoje há duas formas, ambas sem abrir terminal.

## Ligar a cópia automática
1. Confira em **Tarefas agendadas** que o cron do seu servidor está rodando (tarefa "Cópia de segurança agendada").
2. Edite `dados/config/backup.json` (pelo gerenciador de arquivos da hospedagem) e coloque `"ativo": true`. Escolha `"hora"` (ex.: `"03:00"`) e, se quiser semanal, `"frequencia": "semanal"` e `"dias": [1, 4]` (1 = segunda).
3. As cópias ficam em `dados/backups/`. Baixe uma de vez em quando e guarde em outro lugar: **a cópia no mesmo servidor não protege se o servidor for perdido**.

## Cuidados
- A cópia contém senhas e chaves do site. Não envie por e-mail nem deixe em pasta pública.
- Restaurar apaga tudo o que foi criado depois da data da cópia. O sistema guarda antes uma cópia do estado atual.

## Com terminal
`bin/pbl backup criar` · `bin/pbl backup listar` · `bin/pbl backup restaurar <arquivo> --confirmo` · `bin/pbl backup limpar`


## Pela tela (sem terminal)
1. Entre como administrador e abra **Cópias de segurança** no menu.
2. **Fazer agora:** digite sua senha e clique em *Fazer agora*. Espere a barra terminar; não feche a página.
3. **Automático:** em *Cópia agendada e retenção*, marque *Fazer cópias automáticas*, escolha a frequência, a hora e (se semanal) os dias, e salve. Precisa do cron do servidor (veja *Tarefas agendadas*).
4. **Baixar:** na lista, *Baixar* e guarde o arquivo fora do servidor.
5. **Restaurar:** *Restaurar* na linha da cópia, leia o aviso, digite sua senha e a palavra `RESTAURAR`, marque a confirmação e confirme. O site fica em manutenção por instantes.
6. **Apagar / Limpar antigas:** *Apagar* na tela de confirmação da cópia; *Limpar antigas* aplica o número/dias de retenção.
