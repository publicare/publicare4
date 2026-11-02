<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/**
 * Separa texto e tags `<@comando argumentos@>`. Uma tag termina no primeiro `@>` fora de aspas.
 * PHP cru (`<?` ou `?>`) é rejeitado em qualquer lugar do molde (fluxo 2, item 6).
 */
final class Lexer
{
    /**
     * @return list<array{tipo: 'texto', valor: string}|array{tipo: 'tag', fecha: bool, nome: string, resto: string, linha: int}>
     */
    public function separar(string $fonte): array
    {
        /** @var list<array{tipo: 'texto', valor: string}|array{tipo: 'tag', fecha: bool, nome: string, resto: string, linha: int}> */
        return $this->tokenizar($fonte, false);
    }

    /**
     * Como `separar`, mas sem descartar os comentários (`<@-- … --@>` vira um token próprio em vez de
     * desaparecer): usado só pelo realce de sintaxe (fase 5j), que precisa reconstruir o molde sem perder nada,
     * nunca pelo parser. O comportamento de `separar` (e de quem já o chama) não muda.
     *
     * @return list<array{tipo: 'texto', valor: string}|array{tipo: 'tag', fecha: bool, nome: string, resto: string, linha: int}|array{tipo: 'comentario', valor: string, linha: int}>
     */
    public function separarComComentarios(string $fonte): array
    {
        return $this->tokenizar($fonte, true);
    }

    /**
     * @return list<array{tipo: 'texto', valor: string}|array{tipo: 'tag', fecha: bool, nome: string, resto: string, linha: int}|array{tipo: 'comentario', valor: string, linha: int}>
     */
    private function tokenizar(string $fonte, bool $comComentarios): array
    {
        if (preg_match('/<\?|\?>/', $fonte, $m, PREG_OFFSET_CAPTURE) === 1) {
            throw new ErroSintaxe('PHP cru não é permitido no molde', $this->linha($fonte, $m[0][1]));
        }

        $tokens = [];
        $pos = 0;
        $n = strlen($fonte);
        while (($ini = strpos($fonte, '<@', $pos)) !== false) {
            if ($ini > $pos) {
                $tokens[] = ['tipo' => 'texto', 'valor' => substr($fonte, $pos, $ini - $pos)];
            }
            $linha = $this->linha($fonte, $ini);
            if (substr($fonte, $ini + 2, 2) === '--') {
                // Comentário: `<@-- texto --@>`; vai até `--@>` e não gera saída (mas o realce guarda o texto).
                $fimComentario = strpos($fonte, '--@>', $ini + 4);
                if ($fimComentario === false) {
                    throw new ErroSintaxe('Comentário não fechado (falta --@>)', $linha);
                }
                if ($comComentarios) {
                    $tokens[] = ['tipo' => 'comentario', 'valor' => substr($fonte, $ini, $fimComentario + 4 - $ini), 'linha' => $linha];
                }
                $pos = $fimComentario + 4;
                continue;
            }
            $fim = $this->fimDaTag($fonte, $ini + 2, $linha);
            $interno = trim(substr($fonte, $ini + 2, $fim - $ini - 2));
            if (preg_match('/^(\/?)([a-z_]+|=)(.*)$/s', $interno, $a) !== 1) {
                throw new ErroSintaxe('Tag malformada', $linha);
            }
            $tokens[] = ['tipo' => 'tag', 'fecha' => $a[1] === '/', 'nome' => $a[2], 'resto' => trim($a[3]), 'linha' => $linha];
            $pos = $fim + 2;
        }
        if ($pos < $n) {
            $tokens[] = ['tipo' => 'texto', 'valor' => substr($fonte, $pos)];
        }

        return $tokens;
    }

    private function fimDaTag(string $fonte, int $de, int $linha): int
    {
        $n = strlen($fonte);
        $aspas = null;
        for ($i = $de; $i < $n; $i++) {
            $c = $fonte[$i];
            if ($aspas !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $aspas) {
                    $aspas = null;
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $aspas = $c;
            } elseif ($c === '@' && ($fonte[$i + 1] ?? '') === '>') {
                return $i;
            }
        }

        throw new ErroSintaxe('Tag não fechada (falta @>)', $linha);
    }

    private function linha(string $fonte, int $deslocamento): int
    {
        return substr_count($fonte, "\n", 0, $deslocamento) + 1;
    }
}
