<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Conteudo\Classe;

/**
 * Ícones que um tipo de conteúdo pode ter na listagem do painel (ADR 0026). Conjunto fechado de SVGs de traço simples
 * (`currentColor`, sem cor própria: a paleta é a do painel), escritos aqui mesmo, sem arquivo externo nem biblioteca. O banco guarda só a
 * chave; chave desconhecida não desenha nada.
 */
final class IconesDeClasse
{
    /** chave => [rótulo, caminhos do SVG (viewBox 24x24, traço)] */
    private const ICONES = [
        'documento' => ['Documento', '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 12h6M9 16h6"/>'],
        'pasta' => ['Pasta', '<path d="M3 6h6l2 2h10v11H3z"/>'],
        'imagem' => ['Imagem', '<rect x="3" y="5" width="18" height="14"/><circle cx="9" cy="10" r="1.5"/><path d="m3 17 5-4 4 3 3-2 6 4"/>'],
        'calendario' => ['Calendário', '<rect x="3" y="5" width="18" height="16"/><path d="M3 10h18M8 3v4M16 3v4"/>'],
        'noticia' => ['Notícia', '<path d="M4 5h13v14H6a2 2 0 0 1-2-2zM17 9h3v8a2 2 0 0 1-2 2"/><path d="M7 9h7M7 13h7"/>'],
        'link' => ['Link', '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>'],
        'formulario' => ['Formulário', '<rect x="4" y="3" width="16" height="18"/><path d="M8 8h8M8 12h8M8 16h4"/>'],
        'pessoa' => ['Pessoa', '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/>'],
        'local' => ['Local', '<path d="M12 21s7-6.2 7-11a7 7 0 0 0-14 0c0 4.8 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>'],
        'video' => ['Vídeo', '<rect x="3" y="5" width="18" height="14"/><path d="m10 9 5 3-5 3z"/>'],
        'arquivo' => ['Arquivo para baixar', '<path d="M12 3v12M7 10l5 5 5-5M4 20h16"/>'],
        'estrela' => ['Destaque', '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3 6.4 20.2l1.1-6.2L3 9.6l6.2-.9z"/>'],
    ];

    /** Ícone de quem não tem ícone gravado nem é de um tipo fixo; fora do seletor (não se grava). */
    private const GENERICO = '<path d="M12 3 3 7.5 12 12l9-4.5z"/><path d="m3 12 9 4.5 9-4.5M3 16.5 12 21l9-4.5"/>';

    /** Tipo fixo (ADR 0015) => chave do ícone padrão. */
    private const PADRAO_DO_TIPO = [
        'pasta' => 'pasta', 'pagina' => 'documento', 'formulario' => 'formulario',
        'arquivo' => 'arquivo', 'imagem' => 'imagem', 'link' => 'link',
    ];

    /** A chave a desenhar: a gravada (válida), senão a do tipo fixo, senão 'generico'. */
    public static function resolver(?Classe $classe): string
    {
        if ($classe !== null && $classe->icone !== '' && isset(self::ICONES[$classe->icone])) {
            return $classe->icone;
        }

        return $classe !== null && $classe->fixa ? (self::PADRAO_DO_TIPO[$classe->nome] ?? 'generico') : 'generico';
    }

    /**
     * Ícone da classe na listagem: `<span class="pbl-icone" title="Nome" role="img" aria-label="Tipo: Nome">` com o SVG decorativo.
     * O nome é escapado aqui.
     */
    public static function marca(?Classe $classe, string $nome, int $tamanho = 22): string
    {
        $n = htmlspecialchars($nome, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<span class="pbl-icone" role="img" title="' . $n . '" aria-label="Tipo: ' . $n . '">' . self::svg(self::resolver($classe), $tamanho) . '</span>';
    }

    /** @return array<string, string> chave => rótulo */
    public static function opcoes(): array
    {
        return array_map(static fn (array $i): string => $i[0], self::ICONES);
    }

    public static function valido(string $chave): bool
    {
        return $chave === '' || isset(self::ICONES[$chave]);
    }

    /** O SVG inline (decorativo, `aria-hidden`) da chave, ou '' se não há ícone. */
    public static function svg(string $chave, int $tamanho = 16): string
    {
        $caminhos = $chave === 'generico' ? self::GENERICO : (self::ICONES[$chave][1] ?? null);
        if ($caminhos === null) {
            return '';
        }

        return '<svg class="pbl-icone-classe" width="' . $tamanho . '" height="' . $tamanho . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . $caminhos . '</svg>';
    }
}
