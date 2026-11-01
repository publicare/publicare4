<?php

declare(strict_types=1);

namespace Pbl\Acesso;

use Doctrine\DBAL\Connection;
use Pbl\Conteudo\Objeto;

/**
 * O único ponto onde se decide se alguém pode fazer algo (ADR 0011). A ação é um valor do enum `Acao` escolhido pelo código
 * da rota, nunca texto da requisição; a decisão usa só o usuário, a ação e o objeto.
 *
 * Um perfil concedido num objeto vale para ele e tudo abaixo (prefixo do `caminho`); vale o mais forte dos que cobrem o objeto.
 * O administrador global pode tudo. Usuário inativo não pode nada.
 */
final class Permissoes
{
    public function __construct(private readonly Connection $conexao)
    {
    }

    public function pode(Usuario $usuario, Acao $acao, Objeto $objeto): bool
    {
        if (!$usuario->ativo) {
            return false;
        }
        if ($usuario->administrador) {
            return true;
        }
        if ($acao === Acao::Administrar) {
            return false;
        }
        $perfil = $this->perfilEm($usuario, $objeto);
        if ($perfil === null || !in_array($acao, $perfil->acoes(), true)) {
            return false;
        }
        if ($perfil->soDoProprio($acao)) {
            return $objeto->criadoPor !== null && $objeto->criadoPor === $usuario->id;
        }

        return true;
    }

    /** Criar algo novo debaixo de `$pai`; na raiz do site (sem pai) só o administrador global. */
    public function podeCriarSob(Usuario $usuario, ?Objeto $pai): bool
    {
        if (!$usuario->ativo) {
            return false;
        }

        return $pai === null ? $usuario->administrador : $this->pode($usuario, Acao::Criar, $pai);
    }

    /**
     * Criar, alterar e apagar classes (tipos de conteúdo). As classes valem para todos os sites da instalação e mudam o que
     * todas as seções podem guardar, então só o administrador global modela tipos; nenhum perfil por subárvore dá esse poder.
     */
    public function podeModelarClasses(Usuario $usuario): bool
    {
        return $usuario->ativo && $usuario->administrador;
    }

    /**
     * O perfil mais forte que o usuário tem sobre o objeto (nele ou em algum ancestral), ou null. A ancestralidade é conferida
     * em PHP pelo prefixo do `caminho` (o mesmo resultado em qualquer banco, sem concatenação de SQL).
     */
    public function perfilEm(Usuario $usuario, Objeto $objeto): ?Perfil
    {
        $melhor = null;
        $linhas = $this->conexao->fetchAllAssociative(
            'SELECT a.perfil, o.caminho FROM acesso a JOIN objeto o ON o.id = a.objeto_id WHERE a.usuario_id = ? AND o.site_id = ?',
            [$usuario->id, $objeto->siteId],
        );
        foreach ($linhas as $l) {
            $perfil = Perfil::tryFrom((string) $l['perfil']);
            if ($perfil !== null && str_starts_with($objeto->caminho, (string) $l['caminho']) && ($melhor === null || $perfil->forca() > $melhor->forca())) {
                $melhor = $perfil;
            }
        }

        return $melhor;
    }
}
