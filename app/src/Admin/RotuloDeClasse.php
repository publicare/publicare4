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

    /** Frase curta de cada tipo padrão (a do banco é técnica e longa). Tipo criado pelo administrador usa a descrição dele. */
    private const DESCRICAO = [
        'pasta' => 'Agrupa outros conteúdos: seções, listas e caixas de entrada.',
        'pagina' => 'Texto com título. Pode ser a página inicial do site.',
        'link' => 'Atalho para uma página do site ou para um endereço externo.',
        'arquivo' => 'Arquivo para baixar: PDF, planilha, documento.',
        'imagem' => 'Imagem avulsa, para galerias ou para ilustrar outros conteúdos.',
        'formulario' => 'Formulário público. Cada envio vira uma mensagem privada.',
        'noticia' => 'Notícia ou artigo, com imagem de destaque.',
        'faq' => 'Uma pergunta e a resposta, dentro de uma lista de perguntas frequentes.',
        'perguntas' => 'Página que lista perguntas e respostas em sanfona.',
        'destaque' => 'Banner da página inicial, com imagem, texto e link.',
        'bloco' => 'Trecho de texto reutilizável, como o rodapé.',
        'agenda' => 'Lista de eventos, em ordem de data.',
        'evento' => 'Evento com data, local e inscrição.',
        'mensagem_contato' => 'Mensagem recebida pelo formulário de contato.',
    ];

    public static function descricao(string $nome, string $doBanco = ''): string
    {
        return self::DESCRICAO[$nome] ?? $doBanco;
    }

    public static function de(string $nome): string
    {
        if (isset(self::PADRAO[$nome])) {
            return self::PADRAO[$nome];
        }
        $texto = str_replace('_', ' ', $nome);

        return mb_strtoupper(mb_substr($texto, 0, 1)) . mb_substr($texto, 1);
    }
}
