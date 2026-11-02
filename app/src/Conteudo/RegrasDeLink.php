<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

/**
 * Regra do tipo `link` (fase 5l, ADR 0015): **exatamente um** destino, interno (`destino_interno`, id de um conteúdo do mesmo
 * site, de onde a URL sai sempre atualizada) ou externo (`destino_externo`, um endereço `http`, `https`, `mailto` ou `tel`).
 * Nada de `javascript:`, `data:` ou qualquer outro esquema. Aplicada pelo `ObjetoRepositorio` ao criar e ao editar, para valer
 * em qualquer porta de entrada (tela, importação, código).
 */
final class RegrasDeLink
{
    public const CLASSE = 'link';
    public const MAX_EXTERNO = 2000;
    private const ESQUEMAS = ['http', 'https', 'mailto', 'tel'];

    /**
     * @param array<string, mixed> $dados valores já validados da propriedade (o interno já é o id de um objeto do site)
     * @throws ViolacaoDeRegra
     */
    public static function exigir(array $dados): void
    {
        $interno = $dados['destino_interno'] ?? null;
        $externo = $dados['destino_externo'] ?? null;
        $temInterno = $interno !== null && $interno !== '' && $interno !== 0;
        $temExterno = is_string($externo) && trim($externo) !== '';
        if ($temInterno && $temExterno) {
            throw new ViolacaoDeRegra(ViolacaoDeRegra::DESTINO_DE_LINK, 'Preencha só um destino: um conteúdo deste site ou um endereço externo, não os dois.', 'destino_interno');
        }
        if (!$temInterno && !$temExterno) {
            throw new ViolacaoDeRegra(ViolacaoDeRegra::DESTINO_DE_LINK, 'Escolha para onde o link aponta: um conteúdo deste site ou um endereço externo.', 'destino_interno');
        }
        if ($temExterno && !self::externoValido((string) $externo)) {
            throw new ViolacaoDeRegra(ViolacaoDeRegra::DESTINO_DE_LINK, 'O endereço externo precisa começar com http://, https://, mailto: ou tel:.', 'destino_externo');
        }
    }

    public static function externoValido(string $endereco): bool
    {
        if ($endereco !== trim($endereco) || strlen($endereco) > self::MAX_EXTERNO || preg_match('/[\x00-\x20\x7F]/', $endereco) === 1 || !mb_check_encoding($endereco, 'UTF-8')) {
            return false;
        }
        if (preg_match('/^([a-z][a-z0-9+.\-]*):(.*)$/Dis', $endereco, $m) !== 1) {
            return false;
        }
        $esquema = strtolower($m[1]);
        if (!in_array($esquema, self::ESQUEMAS, true) || $m[2] === '') {
            return false;
        }
        if ($esquema === 'http' || $esquema === 'https') {
            $partes = parse_url($endereco);

            return $partes !== false && isset($partes['host']) && $partes['host'] !== '' && str_starts_with($m[2], '//');
        }

        return true;
    }
}
