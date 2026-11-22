# ADR 0030 — CLI de conteúdo, serviço de conteúdo e conta de serviço

Status: aceito.

## Contexto
Agentes e scripts precisam editar conteúdo sem o painel (login, CSRF, formulários). A regra de edição estava em métodos privados de `TelasDeObjetos`, presos a `Request`/`SessaoAtiva`; usar só `ObjetoRepositorio` perderia validação por tipo, limpeza de HTML, republicação e a regra do conteúdo enviado para revisão.

## Decisão
- **Serviço de conteúdo** (`Pbl\Conteudo\Servico`): `ServicoDeConteudo` (editar, criar, publicar, despublicar, enviar, retirar, mover, lixeira, restaurar versão, anexar arquivo, republicar), `ValidadorDeCampos` (limites e leitura dos campos) e `SincronizadorDePaginas` (cache público). Recebe o ator como `Usuario`; devolve dados tipados ou lança `ConteudoNaoEncontrado`, `AcessoNegado`, `EntradaInvalida`, `EstadoInvalido`, `ConflitoDeVersao`. Sem permissão de **ver**, o conteúdo "não existe". Nunca apaga definitivamente.
- **O painel passa a usar** o que foi extraído sem mudar comportamento nem mensagens: `MAX_*` e `lerPropriedade` (validador) e `sincronizar`/`tirarDoAr`/`republicarPorTags`/`visibilidadeMudou`/`urlLivre`. O restante da orquestração de `TelasDeObjetos` (salvar, criar, mover, apagar, restaurar) **continua no painel** nesta entrega: mover essa parte tem risco alto e pouco ganho imediato. Um teste de equivalência (painel × CLI) protege contra divergência; migrar o resto para o serviço fica como próximo passo.
- **CLI** `bin/pbl conteudo <ação>` (`Pbl\Console\ConteudoComando` + uma classe por ação): contrato JSON `api=1` e códigos de saída 0–6 (ver [cli-conteudo.md](../tecnica/cli-conteudo.md)). `--versao` obrigatória em toda mudança; operações destrutivas exigem `--confirmar`; `lixeira` nunca é definitiva.
- **Conta de serviço**: `usuario.tipo` (`pessoa`|`servico`, migração M026). `senha_hash='!servico'`, e-mail sintético, nunca administradora; não autentica, não abre sessão, não troca senha (três camadas). Desativar = `definirAtivo(false)`.
- **Auditoria**: `ator` continua `usuario:N`; `Auditoria` ganha canal opcional (`via: "cli"` no detalhe). Agente = `usuario.tipo='servico'`. Valores de campos nunca vão para auditoria, log ou mensagens.
- **Texto alternativo de imagem**: `arquivo.alt` (migração M027), por uso; `imagem --alt` obrigatório. Exibir o alt nas páginas e no painel fica para depois.
- **`--como` identifica, não autentica**: quem tem shell e banco já pode tudo; a defesa é limitar o que a conta faz, desativá-la e auditar.
- **Feature flag**: o recurso só age para uma conta existente e ativa, com papel por seção; sem conta de serviço, nada muda. O administrador o desliga desativando a conta.

## Consequências
Painel e CLI compartilham validação, limites e cache; a orquestração de gravação ainda existe em dois lugares (a do painel e a do serviço), coberta por teste de equivalência. Os códigos de saída e as chaves JSON viram contrato depois da primeira versão que os publicar. Tipo com campo de arquivo obrigatório não se cria pelo CLI.
