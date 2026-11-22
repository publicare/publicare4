<?php

declare(strict_types=1);

namespace Pbl\Console\Conteudo;

use Pbl\Acesso\Acao;
use Pbl\Acesso\Usuario;
use Pbl\Acesso\UsuarioRepositorio;
use Pbl\Conteudo\Servico\AcessoNegado;
use Pbl\Conteudo\Servico\ConteudoNaoEncontrado;

/**
 * `--como=<login>`: a conta cujas permissões valem. Identifica, não autentica (ADR 0034): quem tem o shell já tem o banco.
 * Conta inexistente = não encontrado; inativa = sem permissão.
 */
final class ResolvedorDeConta
{
    public function __construct(private readonly UsuarioRepositorio $usuarios)
    {
    }

    public function resolver(?string $login): Usuario
    {
        if ($login === null || trim($login) === '') {
            throw new UsoInvalido('Informe a conta com --como=<login>.');
        }
        $conta = $this->usuarios->porLogin($login) ?? throw new ConteudoNaoEncontrado('Conta não encontrada.');
        if (!$conta->ativo) {
            throw new AcessoNegado(Acao::Ver, 'Esta conta está desativada.');
        }

        return $conta;
    }
}
