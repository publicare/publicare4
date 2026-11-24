# ADR 0039 — SMTP sem TLS somente no loopback

Status: aceito. Complementa o [ADR 0034](0034-transporte-de-email.md).

## Contexto
Em hospedagens com HestiaCP o Exim local (127.0.0.1:25/465/587) já tem SPF/DKIM do domínio, mas usa certificado autoassinado. O cliente SMTP sempre verifica o certificado, então não era possível enviar pelo servidor local sem instalar certificado.

## Decisão
- Nova segurança `nenhuma` (`ConfigDeEmail::SEGURANCA_NENHUMA`): conexão TCP sem TLS e sem STARTTLS. Vale **apenas** quando o host é `localhost`, `127.0.0.1` ou `::1` (`ConfigDeEmail::hostEhLoopback`, comparação exata: `localhost.exemplo.org` e `127.0.0.1.x` não valem).
- Defesa em profundidade: recusa em `ConfigDeEmail::valida()`, em `TransporteSmtp::enviar` e em `CanalSmtpSocket` (lançam `FalhaDeEmail` de categoria `configuracao`, antes de abrir conexão ou enviar dados).
- A verificação de certificado (`verify_peer`, `verify_peer_name`) para `tls`/`starttls` **não muda**; não há opção de aceitar autoassinado em hosts não-loopback.
- AUTH continua opcional: usuário vazio não dispara AUTH (relay local de 127.0.0.1).
- `FalhaDeEmail` (subclasse de `RuntimeException`) carrega categoria `rede|tls|autenticacao|recusado|configuracao`; o botão de teste mostra um texto fixo por categoria, nunca a resposta do servidor, usuário ou senha.
- Remetente com domínio próprio já era aceito; SPF/DKIM/DMARC documentados no guia.

## Consequências
- Se a instalação for movida para outra máquina com `127.0.0.1` configurado, o envio falha de forma clara (rede) em vez de degradar a segurança.
- Tráfego sem TLS nunca sai da máquina.
