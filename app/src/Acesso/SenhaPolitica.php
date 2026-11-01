<?php

declare(strict_types=1);

namespace Pbl\Acesso;

/** Regras mínimas de senha: tamanho e nada óbvio. Sem exigência de símbolos (tamanho vale mais que complexidade). */
final class SenhaPolitica
{
    public const MINIMO = 10;
    public const MAXIMO = 1024;

    private const COMUNS = [
        'password', 'senha123456', '1234567890', 'qwertyuiop', 'abcdefghij', '0123456789', 'senhasenha',
        'mudar123456', 'administrador', 'brasil12345', 'letmein123', 'password123', 'passw0rd123', '123456789012',
    ];

    /** @return string|null o motivo da recusa, ou null se a senha é aceitável */
    public static function problema(string $senha, string $login = '', string $email = ''): ?string
    {
        $tamanho = mb_strlen($senha);
        if ($tamanho < self::MINIMO) {
            return 'A senha precisa ter pelo menos ' . self::MINIMO . ' caracteres';
        }
        if ($tamanho > self::MAXIMO) {
            return 'A senha é longa demais';
        }
        $normal = mb_strtolower($senha);
        if (in_array($normal, self::COMUNS, true) || preg_match('/^(.)\1+$/u', $senha) === 1) {
            return 'Essa senha é fácil demais de adivinhar';
        }
        if ($login !== '' && $normal === mb_strtolower($login)) {
            return 'A senha não pode ser igual ao login';
        }
        if ($email !== '' && $normal === mb_strtolower($email)) {
            return 'A senha não pode ser igual ao e-mail';
        }

        return null;
    }
}
