<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

/**
 * Catálogo das configurações de site (ADR 0017): chaves conhecidas, tipo, padrão, rótulo e validação. Não guarda nada
 * (quem guarda é o `ConfigDoSiteRepositorio`). Chave fora do catálogo é rejeitada; o valor é sempre validado pelo tipo.
 * Nada aqui é segredo: tudo pode aparecer no HTML público.
 */
final class ConfigDoSite
{
    public const TEXTO = 'texto';
    public const IDIOMA = 'idioma';
    public const EMAIL = 'email';
    public const TELEFONE = 'telefone';
    public const IDENTIFICADOR = 'identificador';
    public const HTML = 'html';
    public const INTEIRO = 'inteiro';
    public const BOOLEANO = 'booleano';
    public const ARQUIVO = 'arquivo';
    public const LISTA_URL = 'lista_url';

    public const MAX_REDES = 10;
    public const MAX_HTML = 5000;

    /**
     * @return array<string, array{tipo: string, padrao: mixed, rotulo: string, ajuda: string, max?: int, min?: int}>
     */
    public static function catalogo(): array
    {
        return [
            'nome' => ['tipo' => self::TEXTO, 'padrao' => '', 'rotulo' => 'Nome do site', 'ajuda' => 'Vazio: vale o nome do site cadastrado.', 'max' => 120],
            'slogan' => ['tipo' => self::TEXTO, 'padrao' => '', 'rotulo' => 'Slogan', 'ajuda' => 'Frase curta que acompanha o nome.', 'max' => 200],
            'idioma' => ['tipo' => self::IDIOMA, 'padrao' => 'pt-BR', 'rotulo' => 'Idioma do site', 'ajuda' => 'Código como pt-BR, en ou es-AR (atributo lang do HTML).'],
            'descricao' => ['tipo' => self::TEXTO, 'padrao' => '', 'rotulo' => 'Descrição padrão (SEO)', 'ajuda' => 'Usada quando a página não tem descrição própria.', 'max' => 300],
            'imagem_padrao' => ['tipo' => self::ARQUIVO, 'padrao' => '', 'rotulo' => 'Imagem padrão (SEO / compartilhamento)', 'ajuda' => 'Número de uma imagem já enviada a este site; usada quando a página não tem imagem.'],
            'titulo_padrao' => ['tipo' => self::TEXTO, 'padrao' => '', 'rotulo' => 'Título padrão', 'ajuda' => 'Usado quando a página não tem título.', 'max' => 120],
            'titulo_sufixo' => ['tipo' => self::TEXTO, 'padrao' => '', 'rotulo' => 'Sufixo do título', 'ajuda' => 'Texto depois do título da página, como " | Meu Site".', 'max' => 60],
            'icone' => ['tipo' => self::ARQUIVO, 'padrao' => '', 'rotulo' => 'Ícone (favicon)', 'ajuda' => 'Número de uma imagem já enviada a este site.'],
            'email_contato' => ['tipo' => self::EMAIL, 'padrao' => '', 'rotulo' => 'E-mail de contato', 'ajuda' => 'Aparece publicamente se o molde o usar.'],
            'telefone' => ['tipo' => self::TELEFONE, 'padrao' => '', 'rotulo' => 'Telefone de contato', 'ajuda' => 'Dígitos, espaço, +, parênteses e hífen.'],
            'endereco' => ['tipo' => self::TEXTO, 'padrao' => '', 'rotulo' => 'Endereço', 'ajuda' => 'Uma linha.', 'max' => 300],
            'redes_sociais' => ['tipo' => self::LISTA_URL, 'padrao' => [], 'rotulo' => 'Redes sociais', 'ajuda' => 'Um endereço http ou https por linha (até ' . self::MAX_REDES . ').'],
            'analytics' => ['tipo' => self::IDENTIFICADOR, 'padrao' => '', 'rotulo' => 'Identificador de analytics', 'ajuda' => 'Só o identificador (letras, números, hífen e sublinhado), nunca um script.'],
            'rodape' => ['tipo' => self::HTML, 'padrao' => '', 'rotulo' => 'Rodapé', 'ajuda' => 'Texto com HTML simples; o que for perigoso é removido ao salvar.'],
            'busca_ligada' => ['tipo' => self::BOOLEANO, 'padrao' => true, 'rotulo' => 'Busca ligada', 'ajuda' => 'Desligada, a página /busca responde "não encontrado".'],
            'itens_por_pagina' => ['tipo' => self::INTEIRO, 'padrao' => 10, 'rotulo' => 'Itens por página nas listagens', 'ajuda' => 'De 1 a 50.', 'min' => 1, 'max' => 50],
            'menu_profundidade' => ['tipo' => self::INTEIRO, 'padrao' => 0, 'rotulo' => 'Profundidade do menu', 'ajuda' => 'De 1 a 5, limitada pelo teto global (PBL_MENU_PROFUNDIDADE); 0: vale o teto global.', 'min' => 0, 'max' => 5],
            'robots_indexar' => ['tipo' => self::BOOLEANO, 'padrao' => true, 'rotulo' => 'Permitir indexação por buscadores', 'ajuda' => 'Desmarcado, as páginas saem com "noindex".'],
        ];
    }

    public static function existe(string $chave): bool
    {
        return isset(self::catalogo()[$chave]);
    }

    /** @return list<string> */
    public static function chaves(): array
    {
        return array_keys(self::catalogo());
    }

    public static function padrao(string $chave): mixed
    {
        return (self::catalogo()[$chave] ?? throw new \InvalidArgumentException("Configuração desconhecida: {$chave}"))['padrao'];
    }

    /**
     * Valida e normaliza o valor vindo da tela. `null` = "sem valor" (volta ao padrão); texto vazio também. A existência e a
     * posse do arquivo (tipo `arquivo`) não são conferidas aqui: ficam com o repositório.
     *
     * @throws \InvalidArgumentException mensagem em português, sem dado secreto
     */
    public static function normalizar(string $chave, mixed $bruto): mixed
    {
        $def = self::catalogo()[$chave] ?? throw new \InvalidArgumentException("Configuração desconhecida: {$chave}");
        $r = $def['rotulo'];
        $tipo = $def['tipo'];
        if ($tipo === self::BOOLEANO) {
            if ($bruto === null || $bruto === '') {
                return null;
            }

            return match (true) {
                $bruto === true, $bruto === 1, $bruto === '1' => true,
                $bruto === false, $bruto === 0, $bruto === '0' => false,
                default => throw new \InvalidArgumentException("{$r}: valor inválido."),
            };
        }
        if ($tipo === self::LISTA_URL) {
            return self::urls($r, $bruto);
        }
        if (is_int($bruto) && in_array($tipo, [self::INTEIRO, self::ARQUIVO], true)) {
            $bruto = (string) $bruto;
        }
        if ($bruto === null) {
            return null;
        }
        if (!is_string($bruto)) {
            throw new \InvalidArgumentException("{$r}: valor inválido.");
        }
        $bruto = trim($bruto);
        if ($bruto === '') {
            return null;
        }
        if (!mb_check_encoding($bruto, 'UTF-8')) {
            throw new \InvalidArgumentException("{$r} tem caracteres inválidos.");
        }
        if ($tipo !== self::HTML && preg_match('/[\x00-\x1F\x7F]/', $bruto) === 1) {
            throw new \InvalidArgumentException("{$r} tem caracteres de controle, que não são permitidos.");
        }

        return match ($tipo) {
            self::TEXTO => mb_strlen($bruto) > ($def['max'] ?? 200)
                ? throw new \InvalidArgumentException("{$r} pode ter até " . ($def['max'] ?? 200) . ' caracteres.')
                : $bruto,
            self::IDIOMA => preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8}){0,2}$/D', $bruto) === 1
                ? $bruto
                : throw new \InvalidArgumentException("{$r}: use um código como pt-BR, en ou es-AR."),
            self::EMAIL => mb_strlen($bruto) <= 254 && filter_var($bruto, FILTER_VALIDATE_EMAIL) !== false
                ? $bruto
                : throw new \InvalidArgumentException("{$r}: endereço de e-mail inválido."),
            self::TELEFONE => preg_match('/^[0-9+()\- .]{5,30}$/D', $bruto) === 1
                ? $bruto
                : throw new \InvalidArgumentException("{$r}: use só dígitos, espaço, +, parênteses e hífen (5 a 30 caracteres)."),
            self::IDENTIFICADOR => preg_match('/^[A-Za-z0-9_-]{3,40}$/D', $bruto) === 1
                ? $bruto
                : throw new \InvalidArgumentException("{$r}: use só o identificador (letras, números, hífen e sublinhado, 3 a 40 caracteres), nunca um script."),
            self::HTML => self::html($r, $bruto),
            self::INTEIRO => self::inteiro($r, $bruto, $def['min'] ?? 0, $def['max'] ?? 1000),
            self::ARQUIVO => preg_match('/^[1-9][0-9]{0,9}$/D', $bruto) === 1
                ? (int) $bruto
                : throw new \InvalidArgumentException("{$r}: informe o número do arquivo."),
            default => throw new \LogicException("Tipo desconhecido: {$tipo}"),
        };
    }

    /** O valor como o molde o lê: texto, número ou 1/0; lista vira linhas separadas por quebra de linha. */
    public static function paraMolde(mixed $valor): string|int
    {
        return match (true) {
            is_bool($valor) => $valor ? 1 : 0,
            is_int($valor) => $valor,
            is_array($valor) => implode("\n", array_map('strval', $valor)),
            default => is_scalar($valor) ? (string) $valor : '',
        };
    }

    private static function inteiro(string $r, string $bruto, int $min, int $max): int
    {
        if (preg_match('/^[0-9]{1,4}$/D', $bruto) !== 1 || (int) $bruto < $min || (int) $bruto > $max) {
            throw new \InvalidArgumentException("{$r}: informe um número de {$min} a {$max}.");
        }

        return (int) $bruto;
    }

    private static function html(string $r, string $bruto): string
    {
        if (strlen($bruto) > self::MAX_HTML) {
            throw new \InvalidArgumentException("{$r} pode ter até " . self::MAX_HTML . ' caracteres.');
        }
        try {
            return HtmlSeguro::limpar($bruto);
        } catch (ViolacaoDeRegra $e) {
            throw new \InvalidArgumentException("{$r}: " . rtrim($e->getMessage(), '.') . '.');
        }
    }

    /** @return list<string>|null */
    private static function urls(string $r, mixed $bruto): ?array
    {
        if ($bruto === null || $bruto === '' || $bruto === []) {
            return null;
        }
        if (is_string($bruto)) {
            $bruto = preg_split('/\R/u', $bruto) ?: [];
        }
        if (!is_array($bruto)) {
            throw new \InvalidArgumentException("{$r}: valor inválido.");
        }
        $lista = [];
        foreach ($bruto as $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException("{$r}: valor inválido.");
            }
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            if (strlen($item) > 300 || preg_match('#^https?://#i', $item) !== 1 || !RegrasDeLink::externoValido($item)) {
                throw new \InvalidArgumentException("{$r}: \"" . mb_substr($item, 0, 40) . '" não é um endereço http ou https válido (até 300 caracteres).');
            }
            $lista[] = $item;
        }
        if (count($lista) > self::MAX_REDES) {
            throw new \InvalidArgumentException("{$r}: no máximo " . self::MAX_REDES . ' endereços.');
        }

        return $lista === [] ? null : array_values(array_unique($lista));
    }
}
