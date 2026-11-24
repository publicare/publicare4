<?php

declare(strict_types=1);

namespace Pbl\Email;

use Pbl\Arquivos\GravacaoAtomica;

/**
 * `dados/config/email.json` (0600), fora do catálogo público `ConfigDoSite`. A senha do SMTP é cifrada em repouso
 * (`sodium_crypto_secretbox`, chave derivada por HMAC do segredo do site, nonce aleatório). Se o segredo do site mudar, a senha
 * não decifra e precisa ser digitada de novo.
 */
final class ConfigDeEmailRepositorio
{
    public function __construct(private readonly string $arquivo, private readonly string $segredoDoSite)
    {
    }

    public function carregar(): ConfigDeEmail
    {
        $json = is_file($this->arquivo) ? json_decode((string) file_get_contents($this->arquivo), true) : null;
        if (!is_array($json)) {
            return new ConfigDeEmail();
        }

        return new ConfigDeEmail(
            (string) ($json['driver'] ?? Transporte::DESLIGADO), (string) ($json['host'] ?? ''), (int) ($json['porta'] ?? 587),
            (string) ($json['seguranca'] ?? ConfigDeEmail::SEGURANCA_STARTTLS), (string) ($json['usuario'] ?? ''),
            $this->decifrar((string) ($json['senha'] ?? '')), (string) ($json['remetente'] ?? ''),
        );
    }

    /**
     * @param string|null $senhaNova `null` ou vazio mantém a senha que já está guardada (a tela mostra só a máscara)
     * @throws \InvalidArgumentException configuração inválida
     */
    public function salvar(ConfigDeEmail $nova, ?string $senhaNova = null): void
    {
        $senha = ($senhaNova === null || $senhaNova === '') ? $this->carregar()->senha : $senhaNova;
        $final = $nova->comSenha($senha);
        $problema = $final->valida();
        if ($problema !== null) {
            throw new \InvalidArgumentException($problema);
        }
        GravacaoAtomica::gravar($this->arquivo, json_encode([
            'driver' => $final->driver, 'host' => $final->host, 'porta' => $final->porta, 'seguranca' => $final->seguranca,
            'usuario' => $final->usuario, 'senha' => $this->cifrar($final->senha), 'remetente' => $final->remetente,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    }

    private function chave(): string
    {
        return hash_hmac('sha256', 'email-config|v1', $this->segredoDoSite, true);
    }

    private function cifrar(string $texto): string
    {
        if ($texto === '') {
            return '';
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($texto, $nonce, $this->chave()));
    }

    private function decifrar(string $guardado): string
    {
        $bruto = $guardado === '' ? false : base64_decode($guardado, true);
        if ($bruto === false || strlen($bruto) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }
        $claro = sodium_crypto_secretbox_open(substr($bruto, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bruto, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->chave());

        return $claro === false ? '' : $claro;
    }
}
