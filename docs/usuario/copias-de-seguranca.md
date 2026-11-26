---
titulo: Cópias de segurança
resumo: Fazer, agendar, baixar e restaurar cópias de segurança, pela tela ou pelo terminal.
capitulo: manutencao
ordem: 10
publico: administrador
---

# Cópias de segurança

Uma cópia de segurança guarda o banco, os arquivos enviados e as peles do seu site. Ela é o que salva o site se algo der errado: uma atualização, um erro de edição, um servidor perdido.

![Tela Cópias de segurança, com a lista de cópias e o botão Fazer agora](captura:painel-copias)

## Pela tela (sem terminal)
1. Entre como administrador e abra **Sistema** › **Cópias de segurança**.
2. **Fazer agora:** digite sua senha e clique em *Fazer agora*. Espere a barra terminar; não feche a página.
3. **Automático:** em *Cópia agendada e retenção*, marque *Fazer cópias automáticas*, escolha a frequência, a hora e (se semanal) os dias, e salve. Precisa do cron do servidor (veja [Tarefas agendadas](tarefas-agendadas.md)).
4. **Baixar:** na lista, *Baixar* e guarde o arquivo fora do servidor. **A cópia no mesmo servidor não protege se o servidor for perdido.**
5. **Restaurar:** *Restaurar* na linha da cópia, leia o aviso, digite sua senha e a palavra `RESTAURAR`, marque a confirmação e confirme. O site fica em manutenção por instantes.
6. **Apagar / Limpar antigas:** *Apagar* na tela de confirmação da cópia; *Limpar antigas* aplica o número ou os dias de retenção.

## Ligar a cópia automática sem a tela
Edite `dados/config/backup.json` (pelo gerenciador de arquivos da hospedagem) e coloque `"ativo": true`. Escolha `"hora"` (por exemplo `"03:00"`) e, se quiser semanal, `"frequencia": "semanal"` e `"dias": [1, 4]` (1 = segunda). As cópias ficam em `dados/backups/`.

## Com terminal
`bin/pbl backup criar` · `bin/pbl backup listar` · `bin/pbl backup restaurar <arquivo> --confirmo` · `bin/pbl backup limpar`

## Cuidados
- A cópia contém senhas e chaves do site. Não envie por e-mail nem deixe em pasta pública.
- Restaurar apaga tudo o que foi criado depois da cópia. O sistema guarda antes uma cópia do estado atual.

## Veja também
- [Atualizar e voltar atrás](atualizar.md)
- [Tarefas agendadas](tarefas-agendadas.md)
- [Solução de problemas](solucao-de-problemas.md)
