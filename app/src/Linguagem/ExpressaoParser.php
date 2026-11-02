<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

use Pbl\Conteudo\ConfigDoSite;

/**
 * Gramática de expressões (EBNF em docs/linguagem-pbl4.md):
 *   expr  = termo { ("." | "+" | "-" | "*" | "/") termo }   (precedência: * / > + - > .)
 *   termo = literal | #campo | $var | %MACRO | "(" expr ")" | "-" termo
 */
final class ExpressaoParser
{
    private const PREC = ['.' => 1, '+' => 2, '-' => 2, '*' => 3, '/' => 3];
    private const COMPARACOES = ['==', '!=', '<', '>', '<=', '>=', '='];

    /** @var list<Token> */
    private array $tokens = [];
    private int $pos = 0;
    private int $linha = 0;

    public function __construct(private readonly ExpressaoLexer $lexer = new ExpressaoLexer())
    {
    }

    /** Uma expressão que ocupa o texto inteiro (eco, var, incluir...). */
    public function expressao(string $texto, int $linha): Expr
    {
        $this->iniciar($texto, $linha);
        $e = $this->expr(0);
        $this->fim();

        return $e;
    }

    /** Condição do `se`: `[a == b]`, `a == b` ou só `a` (verdadeiro se não vazio). */
    public function condicao(string $texto, int $linha): Expr
    {
        $this->iniciar($texto, $linha);
        $colchetes = $this->ehOp('[');
        if ($colchetes) {
            $this->pos++;
        }
        $esq = $this->expr(0);
        $cond = $esq;
        foreach (self::COMPARACOES as $op) {
            if ($this->ehOp($op)) {
                $this->pos++;
                $cond = new ExprBin($op === '=' ? '==' : $op, $esq, $this->expr(0));
                break;
            }
        }
        if ($colchetes) {
            $this->exigirOp(']');
        }
        $this->fim();

        return $cond;
    }

    /**
     * Parâmetros nomeados: `nome=expr nome2=expr`.
     *
     * @return array<string, Expr>
     */
    public function parametros(string $texto, int $linha): array
    {
        $this->iniciar($texto, $linha);
        $params = [];
        while ($this->pos < count($this->tokens)) {
            $t = $this->tokens[$this->pos];
            if ($t->tipo !== Token::ID || !$this->ehOp('=', $this->pos + 1)) {
                throw new ErroSintaxe('Esperava parâmetro no formato nome=valor', $linha);
            }
            $nome = (string) $t->valor;
            if (isset($params[$nome])) {
                throw new ErroSintaxe("Parâmetro repetido: {$nome}", $linha);
            }
            $this->pos += 2;
            $params[$nome] = $this->expr(0);
        }

        return $params;
    }

    /**
     * `$var = expr`
     *
     * @return array{string, Expr}
     */
    public function atribuicao(string $texto, int $linha): array
    {
        $this->iniciar($texto, $linha);
        $t = $this->tokens[0] ?? null;
        if ($t === null || $t->tipo !== Token::VAR || !$this->ehOp('=', 1)) {
            throw new ErroSintaxe('Esperava $variavel = valor', $linha);
        }
        $this->pos = 2;
        $e = $this->expr(0);
        $this->fim();

        return [(string) $t->valor, $e];
    }

    /**
     * `$i=[inicio,fim]` do `repetir`.
     *
     * @return array{string, Expr, Expr}
     */
    public function intervalo(string $texto, int $linha): array
    {
        $this->iniciar($texto, $linha);
        $t = $this->tokens[0] ?? null;
        if ($t === null || $t->tipo !== Token::VAR || !$this->ehOp('=', 1) || !$this->ehOp('[', 2)) {
            throw new ErroSintaxe('Esperava $i=[inicio,fim]', $linha);
        }
        $this->pos = 3;
        $ini = $this->expr(0);
        $this->exigirOp(',');
        $fim = $this->expr(0);
        $this->exigirOp(']');
        $this->fim();

        return [(string) $t->valor, $ini, $fim];
    }

    private function iniciar(string $texto, int $linha): void
    {
        $this->linha = $linha;
        $this->tokens = $this->lexer->separar($texto, $linha);
        $this->pos = 0;
    }

    private function fim(): void
    {
        if ($this->pos < count($this->tokens)) {
            throw new ErroSintaxe('Texto inesperado depois da expressão', $this->linha);
        }
    }

    private function expr(int $minimo): Expr
    {
        $esq = $this->termo();
        while (true) {
            $t = $this->tokens[$this->pos] ?? null;
            if ($t === null || $t->tipo !== Token::OP || !isset(self::PREC[(string) $t->valor]) || self::PREC[(string) $t->valor] <= $minimo) {
                return $esq;
            }
            $op = (string) $t->valor;
            $this->pos++;
            $esq = new ExprBin($op, $esq, $this->expr(self::PREC[$op]));
        }
    }

    private function termo(): Expr
    {
        $t = $this->tokens[$this->pos] ?? null;
        if ($t === null) {
            throw new ErroSintaxe('Expressão incompleta', $this->linha);
        }
        $this->pos++;

        return match ($t->tipo) {
            Token::STR, Token::NUM => new ExprLiteral($t->valor),
            Token::CAMPO => new ExprCampo((string) $t->valor),
            Token::VAR => new ExprVar((string) $t->valor),
            Token::MACRO => $this->macro((string) $t->valor),
            Token::OP => $this->termoOperador($t),
            default => throw new ErroSintaxe('Valor inesperado: ' . $t->valor, $this->linha),
        };
    }

    private function macro(string $nome): ExprMacro
    {
        if (str_starts_with($nome, ExprMacro::CONFIG)) {
            $chave = substr($nome, strlen(ExprMacro::CONFIG));
            if (!ConfigDoSite::existe($chave)) {
                throw new ErroSintaxe("Configuração de site desconhecida: %SITE.config.{$chave}", $this->linha);
            }

            return new ExprMacro($nome);
        }
        if (!in_array($nome, Runtime::MACROS, true)) {
            throw new ErroSintaxe("Macro desconhecida: %{$nome}", $this->linha);
        }

        return new ExprMacro($nome);
    }

    private function termoOperador(Token $t): Expr
    {
        if ($t->valor === '(') {
            $e = $this->expr(0);
            $this->exigirOp(')');

            return $e;
        }
        if ($t->valor === '-') {
            return new ExprBin('-', new ExprLiteral(0), $this->termo());
        }

        throw new ErroSintaxe('Valor inesperado: ' . $t->valor, $this->linha);
    }

    private function ehOp(string $op, ?int $pos = null): bool
    {
        $t = $this->tokens[$pos ?? $this->pos] ?? null;

        return $t !== null && $t->tipo === Token::OP && $t->valor === $op;
    }

    private function exigirOp(string $op): void
    {
        if (!$this->ehOp($op)) {
            throw new ErroSintaxe("Esperava '{$op}'", $this->linha);
        }
        $this->pos++;
    }
}
