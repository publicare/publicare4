<?php

declare(strict_types=1);

namespace Pbl\Acesso;

/** Confere credenciais. O padrão é o banco (`AutenticadorDeBanco`); outros (LDAP, por exemplo) entram como outra implementação. */
interface Autenticador
{
    /** @return Usuario|null o usuário ativo com essas credenciais, ou null (sem dizer se foi o login ou a senha) */
    public function autenticar(string $login, string $senha): ?Usuario;
}
