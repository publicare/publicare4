<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

use Pbl\Caminhos;

/**
 * `dados/config/atualizacao.json`, por exemplo `{"url": "https://exemplo.org/pbl4/latest.json", "canal": "estavel", "verificar": true}`; `canal` é `estavel`, `beta` ou `teste` (demo e versões de teste, com chave própria que a produção não aceita no resto); `url` é o endereço do `latest.json` e pode ser trocado a qualquer hora (por exemplo `https://atualizacoes.publicare.org/latest.json`: nada no produto fixa o endereço do GitHub); `php` (opcional) é o caminho absoluto do PHP de linha de comando, usado só pelo botão do painel.
 * `agendar: false` (chave de liga/desliga; padrão ligado) tira do painel a opção de agendar a atualização para o cron (sobra só o botão que inicia na hora, que exige `proc_open`).
 * `verificar: false` desliga qualquer consulta ao servidor de atualizações (o PBL4 não envia nenhum dado do site; só o agente `PBL4/versão`).
 */
final class Configuracao
{
    /** Endereço do manifesto do canal estável; o mesmo que o `instalar.php` usa para baixar (e o beta é `latest-beta.json` no mesmo lugar). */
    public const URL_ESTAVEL = 'https://atualizacoes.publicare.org/latest.json';

    public function __construct(public readonly string $url, public readonly string $canal, public readonly bool $verificar, public readonly string $php = '', public readonly bool $agendar = true)
    {
    }

    public static function ler(Caminhos $caminhos): self
    {
        $arquivo = $caminhos->config() . '/atualizacao.json';
        $d = is_file($arquivo) ? json_decode((string) file_get_contents($arquivo), true) : null;
        $d = is_array($d) ? $d : [];

        return new self(
            isset($d['url']) && is_string($d['url']) ? $d['url'] : '',
            isset($d['canal']) && in_array($d['canal'], ['estavel', 'beta', 'teste'], true) ? $d['canal'] : 'estavel',
            ($d['verificar'] ?? true) !== false,
            isset($d['php']) && is_string($d['php']) && strlen($d['php']) <= 300 ? $d['php'] : '',
            ($d['agendar'] ?? true) !== false,
        );
    }

    /**
     * Grava `atualizacao.json` com o canal estável e o endereço oficial SE o arquivo ainda não existir (quem instalou pelo
     * `instalar.php` já o recebeu com o canal usado, inclusive beta; isto cobre `bin/pbl instalar`, o assistente e a instalação por git).
     * Nunca sobrescreve: o dono pode ter ajustado.
     */
    public static function garantirPadrao(Caminhos $caminhos): void
    {
        $arquivo = $caminhos->config() . '/atualizacao.json';
        if (is_file($arquivo)) {
            return;
        }
        $json = json_encode(['url' => self::URL_ESTAVEL, 'canal' => 'estavel', 'verificar' => true], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($arquivo, $json . "\n", LOCK_EX) === false) {
            throw new \RuntimeException("Não foi possível gravar {$arquivo}");
        }
        @chmod($arquivo, 0640);
    }
}
