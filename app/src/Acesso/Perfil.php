<?php

declare(strict_types=1);

namespace Pbl\Acesso;

/**
 * Perfis por subárvore. Fixos no código: a regra "quem pode o quê" não é dado editável (no PBL3 era uma regex no banco).
 * `administrador` não é perfil: é uma marca global do usuário.
 */
enum Perfil: string
{
    case Leitor = 'leitor';
    case Autor = 'autor';
    case Editor = 'editor';

    /** Quanto maior, mais forte; vale o perfil mais forte entre os que cobrem o objeto. */
    public function forca(): int
    {
        return match ($this) {
            self::Leitor => 1,
            self::Autor => 2,
            self::Editor => 3,
        };
    }

    /** @return list<Acao> */
    public function acoes(): array
    {
        return match ($this) {
            self::Leitor => [Acao::Ver],
            self::Autor => [Acao::Ver, Acao::Criar, Acao::Editar],
            self::Editor => [Acao::Ver, Acao::Criar, Acao::Editar, Acao::Publicar, Acao::Apagar],
        };
    }

    /** O autor só altera o que ele mesmo criou; os demais perfis, qualquer objeto da subárvore. */
    public function soDoProprio(Acao $acao): bool
    {
        return $this === self::Autor && $acao === Acao::Editar;
    }
}
