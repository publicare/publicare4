# Agentes e scripts editam o site

Além do painel, o Publicare tem uma forma de **editar conteúdo por linha de comando**, pensada para assistentes automáticos (por exemplo, um assistente que recebe pedidos da administradora do Site Exemplo por mensagem) e para scripts. Ela segue as mesmas regras do painel: ninguém faz por aqui o que não poderia fazer lá.

## O que você precisa

1. **Uma conta de serviço** para o agente. Quem administra o servidor cria uma vez:

   ```
   bin/pbl usuario criar claudim --servico --nome="Claudim"
   bin/pbl usuario conceder claudim editor 12      # 12 = número da seção que ele pode cuidar
   ```

   A conta de serviço **não tem senha e não entra pelo painel**. Ela só pode o que o papel dela na seção permite. Para tirar o acesso a qualquer momento: `bin/pbl usuario desativar claudim` (e `ativar` para devolver).

2. Saber o **número** do conteúdo (aparece no painel) ou o **endereço** dele no site.

## O dia a dia

```
bin/pbl conteudo listar --como=claudim --estado=publicado
bin/pbl conteudo ver quem-somos --como=claudim
bin/pbl conteudo editar quem-somos --como=claudim --versao=3 --campo titulo="Quem somos"
bin/pbl conteudo editar quem-somos --como=claudim --versao=4 --publicar --json=- < novo-texto.json
```

- **Versão:** toda mudança pede `--versao=N`, o número que apareceu no `ver`. Se outra pessoa mexeu enquanto isso, o comando avisa (código 5) e **não grava**; veja de novo e repita. É o mesmo cuidado do painel contra edições simultâneas.
- **Texto longo:** envie por `--json=-` (entrada padrão), não por `--campo`, porque o que vai na linha de comando fica visível para outros usuários do servidor e no histórico. Exemplo de arquivo:

  ```json
  {"titulo": "Quem somos", "dados": {"corpo": "<p>Texto <strong>formatado</strong>.</p>"}}
  ```

- **Texto formatado** aceita HTML simples; scripts e outras coisas perigosas são removidos, como no painel.
- **Revisão:** quem é *autor* não publica: use `--enviar-revisao` e um editor aprova. Quem é *editor* pode usar `--publicar`.
- **Apagar** manda para a **lixeira** (nunca apaga de vez) e pede `--confirmar`; recuperar é pelo painel. Mover, restaurar uma versão antiga e republicar o site também pedem `--confirmar`.
- **Imagem e arquivo:** `bin/pbl conteudo imagem 12 --campo=imagem_destaque --arquivo=/caminho/foto.jpg --alt="Descrição da foto" --versao=5`. O texto alternativo (`--alt`) é obrigatório em imagem. O tipo do arquivo é conferido pelo conteúdo, não pelo nome.

## Para programas: saída e códigos

Acrescente `--json` e a resposta vira uma linha de JSON estável (`{"ok":true,"api":1,…}`), sem texto para interpretar. O programa decide pelo **código de saída**: 0 deu certo, 3 não encontrado, 4 sem permissão, 5 conflito (versão desatualizada), 6 dado inválido (ou falta `--confirmar`), 2 uso errado, 1 erro. Todos os detalhes: [documentação técnica](../tecnica/cli-conteudo.md).

## Quem fez o quê

Cada alteração fica registrada com **quem** fez (a conta de serviço) e que foi **pela linha de comando**. No painel, em "Registro de atividade" do conteúdo, aparece como "Claudim (agente, via linha de comando)". Os textos editados não vão para o registro.

## Cuidados

- Dê ao agente a **menor seção** possível. Ele só enxerga e altera o que o papel dele cobre.
- O comando confia na conta indicada em `--como`: quem tem acesso ao servidor pode indicar qualquer conta. Por isso o acesso ao servidor deve ser restrito a quem é de confiança.
