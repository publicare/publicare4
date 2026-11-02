<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

/**
 * Uma regra de integridade do conteúdo foi violada (ADR 0012 §9): tipo de conteúdo não aceito dentro de outro, campo
 * obrigatório sem valor, data inválida. Estende `\InvalidArgumentException` para que quem já tratava "valor não aceito" continue
 * funcionando; quem quer a causa exata lê `codigo` e `campo`. A mensagem é escrita para pessoas e nunca contém conteúdo digitado.
 */
final class ViolacaoDeRegra extends \InvalidArgumentException
{
    public const TIPO_NAO_ACEITO = 'tipo_nao_aceito';
    public const CAMPO_OBRIGATORIO = 'campo_obrigatorio';
    public const DATA_INVALIDA = 'data_invalida';
    public const HTML_INVALIDO = 'html_invalido';
    public const DESTINO_DE_LINK = 'destino_de_link';

    /**
     * @param string $codigo uma das constantes desta classe
     * @param string|null $campo nome técnico da propriedade envolvida, se houver
     */
    public function __construct(public readonly string $codigo, string $mensagem, public readonly ?string $campo = null)
    {
        parent::__construct($mensagem);
    }
}
