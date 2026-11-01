<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

/**
 * Assinatura Ed25519 (libsodium) do manifesto de cada versão. A chave pública vai embutida no produto; a privada fica fora do
 * repositório, com o mantenedor (ADR 0006). Sem assinatura válida de uma chave de confiança, nada é baixado nem aplicado.
 */
final class Assinatura
{
    /**
     * @return array{publica: string, privada: string} chaves em hexadecimal (publica 64, privada 128 caracteres)
     */
    public static function gerarPar(): array
    {
        $par = sodium_crypto_sign_keypair();

        return [
            'publica' => sodium_bin2hex(sodium_crypto_sign_publickey($par)),
            'privada' => sodium_bin2hex(sodium_crypto_sign_secretkey($par)),
        ];
    }

    /** @return string assinatura destacada em base64 */
    public static function assinar(string $mensagem, string $chavePrivadaHex): string
    {
        if (preg_match('/^[0-9a-f]{128}$/D', $chavePrivadaHex) !== 1) {
            throw new \InvalidArgumentException('Chave privada inválida (esperado: 128 caracteres hexadecimais)');
        }

        /** @var non-empty-string $chave */
        $chave = sodium_hex2bin($chavePrivadaHex);

        return base64_encode(sodium_crypto_sign_detached($mensagem, $chave));
    }

    /**
     * @param list<string> $chavesPublicasHex as chaves em que se confia (mais de uma permite trocar de chave sem quebrar quem atualiza)
     */
    public static function verificar(string $mensagem, string $assinaturaBase64, array $chavesPublicasHex): bool
    {
        $assinatura = base64_decode(trim($assinaturaBase64), true);
        if ($assinatura === false || strlen($assinatura) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        foreach ($chavesPublicasHex as $chave) {
            if (preg_match('/^[0-9a-f]{64}$/D', $chave) !== 1) {
                continue;
            }
            /** @var non-empty-string $binaria */
            $binaria = sodium_hex2bin($chave);
            if (sodium_crypto_sign_verify_detached($assinatura, $mensagem, $binaria)) {
                return true;
            }
        }

        return false;
    }
}
