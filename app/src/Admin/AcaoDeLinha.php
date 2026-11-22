<?php

declare(strict_types=1);

namespace Pbl\Admin;

/**
 * Uma ação de uma linha de conteúdo (ADR 0032): quem decide se ela existe (permissão) é a tela; o componente só a distribui.
 * `destaque`: primaria | estado | externa (sempre à vista) ou menu | perigo (dentro do "⋯ Mais"; perigo vai por último, em vermelho).
 */
final class AcaoDeLinha
{
    public const PRIMARIA = 'primaria';
    public const ESTADO = 'estado';
    public const EXTERNA = 'externa';
    public const MENU = 'menu';
    public const PERIGO = 'perigo';

    /**
     * @param string $icone nome em Pagina::ICONES
     * @param list<array{0: string, 1: string}> $campos campos ocultos (nome, valor) de uma ação `post`
     * @param string $csrf campo CSRF já pronto (HTML do `campoCsrf`), só para `post`
     */
    private function __construct(
        public readonly string $rotulo,
        public readonly string $icone,
        public readonly string $destaque,
        public readonly string $url,
        public readonly bool $post = false,
        public readonly array $campos = [],
        public readonly string $csrf = '',
        public readonly bool $externa = false,
    ) {
    }

    public static function link(string $rotulo, string $icone, string $destaque, string $url, bool $externa = false): self
    {
        return new self($rotulo, $icone, $destaque, $url, false, [], '', $externa);
    }

    /** @param list<array{0: string, 1: string}> $campos */
    public static function post(string $rotulo, string $icone, string $destaque, string $url, string $csrf, array $campos = []): self
    {
        return new self($rotulo, $icone, $destaque, $url, true, $campos, $csrf);
    }

    public function aVista(): bool
    {
        return in_array($this->destaque, [self::PRIMARIA, self::ESTADO, self::EXTERNA], true);
    }
}
