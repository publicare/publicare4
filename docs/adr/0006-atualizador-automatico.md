# ADR 0006 — Atualizador automático

Status: proposto (2026-11-01). Complementa o [ADR 0003](0003-plataforma-low-code.md).

## Contexto

O público do PBL4 inclui quem instala por FTP em hospedagem compartilhada e não sabe usar terminal. Hoje atualizar o PBL3 é copiar arquivos por cima e rodar SQL à mão; sites ficam anos em versões com falhas conhecidas. Um sistema que o leigo instala sozinho precisa também se atualizar sozinho, com segurança.

## Decisão

1. **Canal de releases estático.** Para cada canal (`estavel`, `beta`) existe um manifesto `latest.json` e os pacotes `.zip`, hospedados em bucket de objetos atrás de CDN. Nada de API dinâmica no caminho: se o servidor do projeto cair, só a verificação de versão falha, nunca o site. Pacotes pequenos mantêm o tráfego de download dentro da camada gratuita.
2. **Manifesto**: versão, data, canal, PHP e banco mínimos, versão mínima para atualização direta (senão exige passar por versões intermediárias), URL, tamanho e SHA-256 do pacote, resumo das mudanças e link do changelog, flag `seguranca` (urgente), indicação de que há migrações de banco.
3. **Assinatura obrigatória.** Manifesto e pacote são assinados com **Ed25519** (libsodium). A chave pública vai embutida no produto; a privada fica fora do CI comum (assinatura em máquina do mantenedor ou HSM/chave de release). Rotação de chave prevista no próprio manifesto, assinada pela chave antiga. Sem assinatura válida, nada é aplicado. Proteção contra downgrade: versão só avança.
4. **Verificação no admin.** Consulta o manifesto no máximo 1 vez por dia (cache) e sob demanda por botão. Mostra versão atual, nova versão, mudanças e se é correção de segurança; só administradores veem. Sem telemetria por padrão: a requisição não leva dados do site (apenas o `User-Agent` com a versão, e isso pode ser desligado). Modo offline/manual nas configurações.
5. **Três modos de atualização**, por capacidade do servidor:
   - **Assistido (padrão)**: o admin baixa, verifica e aplica com um clique.
   - **CLI/cron** (`bin/pbl atualizar`): para VPS e quem tem terminal; pode rodar sob outro usuário, sem exigir código gravável pelo PHP web.
   - **Manual**: baixa o `.zip` verificado e mostra o passo a passo (FTP).
6. **Aplicação atômica com rollback** (fluxo 11): modo manutenção, checagem de espaço e permissões, backup, staging, troca, migrações, health-check e, se falhar, retorno à versão anterior.
7. **Código separado dos dados.** O pacote só contém o que é substituível (`app/`, `vendor/`, assets, migrações). Configuração, uploads, peles do site, cache e banco SQLite ficam em `dados/`, nunca tocados pela atualização.
8. **Migrações compatíveis (expandir/contrair).** Cada versão aceita o schema da anterior, o que torna o rollback de código possível depois de migrar. Migração destrutiva só duas versões depois.
9. **Escopo futuro**: o mesmo mecanismo serve a módulos (ADR 0005) e peles de galeria, com canal e chave próprios.

## Segurança

- Código gravável pelo PHP é superfície de ataque. Por isso o modo assistido só abre a janela de escrita durante a atualização (token de uso único, exige reautenticação do administrador) e o modo CLI é o recomendado quando houver terminal.
- Verificação de hash e assinatura **antes** de extrair; extração com checagem de caminhos (zip-slip) e lista de arquivos esperada.
- Falha de rede, de assinatura, de espaço ou de permissão nunca deixa o site meio atualizado.

## Consequências

- Exige um pipeline de release (empacotar, assinar, publicar manifesto) e disciplina de migrações compatíveis.
- Sites passam a poder receber correções de segurança rapidamente, o que é um dos principais ganhos do PBL4 sobre o PBL3.
- A estrutura de pastas `app/` + `dados/` entra no desenho do instalador (fluxo 3).
