<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/**
 * Separa texto e tags `<@comando argumento@>`. PHP cru é rejeitado aqui (fluxo 2, item 6):
 * qualquer `<?` ou `?>` no molde é erro, e tags malformadas também.
 */
final class Lexer
{
    private const TAG = '/<@\s*(\/?)([a-z]+)(?:\s+([a-z_][a-z0-9_]*))?\s*@>/';

    /**
     * @return list<array{tipo: 'texto', valor: string}|array{tipo: 'tag', fecha: bool, nome: string, arg: ?string, linha: int}>
     */
    public function separar(string $fonte): array
    {
        if (preg_match('/<\?|\?>/', $fonte, $m, PREG_OFFSET_CAPTURE) === 1) {
            throw new ErroSintaxe('PHP cru não é permitido no molde', $this->linha($fonte, $m[0][1]));
        }

        preg_match_all(self::TAG, $fonte, $achados, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $tokens = [];
        $cursor = 0;
        foreach ($achados as $a) {
            $inicio = $a[0][1];
            $this->adicionarTexto($tokens, $fonte, $cursor, $inicio);
            $tokens[] = [
                'tipo' => 'tag',
                'fecha' => $a[1][0] === '/',
                'nome' => $a[2][0],
                'arg' => isset($a[3]) && $a[3][1] >= 0 ? $a[3][0] : null,
                'linha' => $this->linha($fonte, $inicio),
            ];
            $cursor = $inicio + strlen($a[0][0]);
        }
        $this->adicionarTexto($tokens, $fonte, $cursor, strlen($fonte));

        return $tokens;
    }

    /** @param list<array{tipo: 'texto', valor: string}|array{tipo: 'tag', fecha: bool, nome: string, arg: ?string, linha: int}> $tokens */
    private function adicionarTexto(array &$tokens, string $fonte, int $de, int $ate): void
    {
        $texto = substr($fonte, $de, $ate - $de);
        $pos = strpos($texto, '<@');
        if ($pos === false) {
            $pos = strpos($texto, '@>');
        }
        if ($pos !== false) {
            throw new ErroSintaxe('Tag malformada', $this->linha($fonte, $de + $pos));
        }
        if ($texto !== '') {
            $tokens[] = ['tipo' => 'texto', 'valor' => $texto];
        }
    }

    private function linha(string $fonte, int $deslocamento): int
    {
        return substr_count($fonte, "\n", 0, $deslocamento) + 1;
    }
}
