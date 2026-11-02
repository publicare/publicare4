<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** Quebra o texto de uma tag em tokens: strings, números, #campo, $var, %MACRO, identificadores e operadores. */
final class ExpressaoLexer
{
    /** @return list<Token> */
    public function separar(string $texto, int $linha): array
    {
        $tokens = [];
        $n = strlen($texto);
        $i = 0;
        while ($i < $n) {
            $c = $texto[$i];
            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if ($c === '"' || $c === "'") {
                [$valor, $i] = $this->string($texto, $i, $linha);
                $tokens[] = new Token(Token::STR, $valor);
                continue;
            }
            if (ctype_digit($c)) {
                $j = $i;
                while ($j < $n && ctype_digit($texto[$j])) {
                    $j++;
                }
                if ($j + 1 < $n && $texto[$j] === '.' && ctype_digit($texto[$j + 1])) {
                    $j++;
                    while ($j < $n && ctype_digit($texto[$j])) {
                        $j++;
                    }
                    $tokens[] = new Token(Token::NUM, (float) substr($texto, $i, $j - $i));
                } else {
                    $tokens[] = new Token(Token::NUM, (int) substr($texto, $i, $j - $i));
                }
                $i = $j;
                continue;
            }
            if ($c === '#' || $c === '$' || $c === '%') {
                $j = $i + 1;
                while ($j < $n && (ctype_alnum($texto[$j]) || $texto[$j] === '_')) {
                    $j++;
                }
                // `#autor.titulo`: o ponto colado a uma letra segue uma referência (um só nível). `#a . "x"` e
                // `#a."x"` continuam sendo concatenação.
                if ($c === '#' && $j + 1 < $n && $texto[$j] === '.' && (ctype_alpha($texto[$j + 1]) || $texto[$j + 1] === '_')) {
                    $j++;
                    while ($j < $n && (ctype_alnum($texto[$j]) || $texto[$j] === '_')) {
                        $j++;
                    }
                }
                // `%SITE.config.chave`: a configuração do site (ADR 0017); a chave é validada contra o catálogo no parser.
                if ($c === '%' && strtoupper(substr($texto, $i + 1, $j - $i - 1)) === 'SITE' && preg_match('/\G\.config\.([A-Za-z_][A-Za-z0-9_]*)/i', $texto, $mc, 0, $j) === 1) {
                    $tokens[] = new Token(Token::MACRO, ExprMacro::CONFIG . strtolower($mc[1]));
                    $i = $j + strlen($mc[0]);
                    continue;
                }
                $nome = substr($texto, $i + 1, $j - $i - 1);
                if ($nome === '') {
                    throw new ErroSintaxe("Esperava um nome depois de {$c}", $linha);
                }
                $tokens[] = match ($c) {
                    '#' => new Token(Token::CAMPO, strtolower($nome)),
                    '$' => new Token(Token::VAR, strtolower($nome)),
                    default => new Token(Token::MACRO, strtoupper($nome)),
                };
                $i = $j;
                continue;
            }
            if (ctype_alpha($c) || $c === '_') {
                $j = $i;
                while ($j < $n && (ctype_alnum($texto[$j]) || $texto[$j] === '_')) {
                    $j++;
                }
                $tokens[] = new Token(Token::ID, strtolower(substr($texto, $i, $j - $i)));
                $i = $j;
                continue;
            }
            $dois = substr($texto, $i, 2);
            if (in_array($dois, ['==', '!=', '<=', '>='], true)) {
                $tokens[] = new Token(Token::OP, $dois);
                $i += 2;
                continue;
            }
            if (str_contains('.+-*/()[],=<>', $c)) {
                $tokens[] = new Token(Token::OP, $c);
                $i++;
                continue;
            }
            throw new ErroSintaxe("Caractere inesperado: {$c}", $linha);
        }

        return $tokens;
    }

    /** @return array{string, int} */
    private function string(string $texto, int $inicio, int $linha): array
    {
        $aspas = $texto[$inicio];
        $n = strlen($texto);
        $valor = '';
        for ($i = $inicio + 1; $i < $n; $i++) {
            $c = $texto[$i];
            if ($c === '\\' && $i + 1 < $n && in_array($texto[$i + 1], ['\\', '"', "'"], true)) {
                $valor .= $texto[++$i];
                continue;
            }
            if ($c === $aspas) {
                return [$valor, $i + 1];
            }
            $valor .= $c;
        }

        throw new ErroSintaxe('Aspas não fechadas', $linha);
    }
}
