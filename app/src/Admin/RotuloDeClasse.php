<?php

declare(strict_types=1);

namespace Pbl\Admin;

/**
 * Nome de um tipo de conteúdo como a pessoa lê no painel ("Página", "Pergunta frequente"). O nome técnico (`pagina`, `faq`)
 * continua sendo o identificador; aqui só se apresenta. Tipos criados pelo administrador caem no nome dele, com a primeira letra
 * maiúscula e os sublinhados como espaço.
 */
final class RotuloDeClasse
{
    private const PADRAO = [
        'pasta' => 'Pasta', 'pagina' => 'Página', 'link' => 'Link', 'arquivo' => 'Arquivo', 'imagem' => 'Imagem',
        'formulario' => 'Formulário', 'noticia' => 'Notícia', 'faq' => 'Pergunta frequente', 'perguntas' => 'Perguntas frequentes',
        'destaque' => 'Destaque', 'bloco' => 'Bloco', 'agenda' => 'Agenda', 'evento' => 'Evento', 'mensagem_contato' => 'Mensagem de contato',
    ];

    public static function de(string $nome): string
    {
        if (isset(self::PADRAO[$nome])) {
            return self::PADRAO[$nome];
        }
        $texto = str_replace('_', ' ', $nome);

        return mb_strtoupper(mb_substr($texto, 0, 1)) . mb_substr($texto, 1);
    }
}
