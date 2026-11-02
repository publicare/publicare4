<?php

declare(strict_types=1);

namespace Pbl\Formularios;

use Pbl\Conteudo\Classe;

/**
 * Um formulário público já conferido: no ar, aberto, com destino e tipo de envio válidos e campos que existem. Só se constrói
 * pelo `FormularioRepositorio`; configuração com problema nunca vira `Formulario` (o formulário simplesmente não existe).
 *
 * @phpstan-type Campo array{nome: string, rotulo: string, tipo: string, obrigatorio: bool}
 */
final class Formulario
{
    /** Tipos de propriedade que um formulário público aceita. `ref` fica de fora (deixaria testar quais números de conteúdo existem). */
    public const TIPOS = ['texto', 'numero', 'html', 'data', 'booleano'];
    public const MAX_CAMPOS = 30;
    public const MAX_TEXTO = 2000;
    public const MAX_HTML = 20000;
    public const MAX_MENSAGEM = 500;
    public const MAX_BOTAO = 40;

    /** @param list<Campo> $campos */
    public function __construct(
        public readonly int $id,
        public readonly int $siteId,
        public readonly string $titulo,
        public readonly int $destinoId,
        public readonly Classe $classeDoEnvio,
        public readonly array $campos,
        public readonly string $mensagem,
        public readonly string $botao,
    ) {
    }
}
