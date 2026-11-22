<?php

declare(strict_types=1);

namespace Pbl\Console\Conteudo;

use Pbl\Conteudo\Servico\ConteudoNaoEncontrado;
use Pbl\Conteudo\Site;
use Pbl\Conteudo\SiteRepositorio;

/** `--site=<id|domínio>`. Com um só site na instalação pode faltar; com vários é obrigatório (nunca "o primeiro"). */
final class ResolvedorDeSite
{
    public function __construct(private readonly SiteRepositorio $sites)
    {
    }

    public function resolver(?string $referencia): Site
    {
        $referencia = $referencia === null ? null : trim($referencia);
        if ($referencia === null || $referencia === '') {
            $todos = $this->sites->todos();
            if (count($todos) === 1) {
                return $todos[0];
            }
            throw new UsoInvalido($todos === [] ? 'Não há sites nesta instalação.' : 'Há mais de um site: informe --site=<id|domínio>.');
        }
        $site = preg_match('/^[1-9][0-9]{0,9}$/D', $referencia) === 1 ? $this->sites->porId((int) $referencia) : $this->sites->porHost($referencia);

        return $site ?? throw new ConteudoNaoEncontrado('Site não encontrado.');
    }
}
