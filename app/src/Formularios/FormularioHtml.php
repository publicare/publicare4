<?php

declare(strict_types=1);

namespace Pbl\Formularios;

/**
 * O HTML do formulário público. Funciona sem JavaScript, nada de estilo ou script embutido (vale em site com CSP rígida), tudo
 * escapado. Usado pelo comando `formulario` dos moldes e pela página de erro de validação.
 */
final class FormularioHtml
{
    /** Nome do campo-armadilha (honeypot). Começa com `_`: nenhum campo de tipo de conteúdo pode ter esse nome. */
    public const ARMADILHA = '_mel';
    public const CSRF = '_csrf';
    public const ROTA = '/_pbl/enviar/';

    /**
     * @param array<string, string> $valores o que a pessoa já digitou (para refazer a tela depois de um erro)
     * @param list<string> $erros mensagens para pessoas, sem conteúdo digitado
     */
    public static function renderizar(Formulario $f, string $token, array $valores = [], array $erros = []): string
    {
        $h = '';
        if ($erros !== []) {
            $h .= '<div class="pbl-erros" role="alert"><p>Confira o que foi preenchido:</p><ul>';
            foreach ($erros as $e) {
                $h .= '<li>' . self::e($e) . '</li>';
            }
            $h .= '</ul></div>';
        }
        $h .= '<form method="post" action="' . self::ROTA . $f->id . '" class="pbl-formulario" accept-charset="utf-8">'
            . '<input type="hidden" name="' . self::CSRF . '" value="' . self::e($token) . '">'
            . '<div hidden aria-hidden="true"><label>Deixe em branco <input type="text" name="' . self::ARMADILHA . '" value="" tabindex="-1" autocomplete="off"></label></div>';
        foreach ($f->campos as $c) {
            $id = 'pbl-f' . $f->id . '-' . $c['nome'];
            $nome = self::e($c['nome']);
            $valor = $valores[$c['nome']] ?? '';
            $req = $c['obrigatorio'] ? ' required' : '';
            $rotulo = self::e($c['rotulo']) . ($c['obrigatorio'] ? ' <span aria-hidden="true">*</span>' : '');
            $h .= '<div class="pbl-campo">';
            if ($c['tipo'] === 'booleano') {
                $h .= '<label><input type="checkbox" id="' . $id . '" name="' . $nome . '" value="1"' . ($valor === '1' ? ' checked' : '') . $req . '> ' . $rotulo . '</label>';
            } else {
                $h .= '<label for="' . $id . '">' . $rotulo . '</label> ';
                $h .= match ($c['tipo']) {
                    'html' => '<textarea id="' . $id . '" name="' . $nome . '" rows="6" maxlength="' . Formulario::MAX_HTML . '"' . $req . '>' . self::e($valor) . '</textarea>',
                    'data' => '<input type="date" id="' . $id . '" name="' . $nome . '" value="' . self::e($valor) . '"' . $req . '>',
                    'numero' => '<input type="text" inputmode="decimal" id="' . $id . '" name="' . $nome . '" value="' . self::e($valor) . '" maxlength="30"' . $req . '>',
                    default => '<input type="text" id="' . $id . '" name="' . $nome . '" value="' . self::e($valor) . '" maxlength="' . Formulario::MAX_TEXTO . '"' . $req . '>',
                };
            }
            $h .= '</div>';
        }

        return $h . '<div class="pbl-acoes"><button type="submit">' . self::e($f->botao) . '</button></div></form>';
    }

    public static function e(string $texto): string
    {
        return htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
