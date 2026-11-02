<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

use Pbl\Caminhos;

/**
 * `dados/config/atualizacao.json`, por exemplo `{"url": "https://exemplo.org/pbl4/latest.json", "canal": "estavel", "verificar": true}`; `php` (opcional) é o caminho absoluto do PHP de linha de comando, usado só pelo botão do painel.
 * `verificar: false` desliga qualquer consulta ao servidor de atualizações (o PBL4 não envia nenhum dado do site; só o agente `PBL4/versão`).
 */
final class Configuracao
{
    public function __construct(public readonly string $url, public readonly string $canal, public readonly bool $verificar, public readonly string $php = '')
    {
    }

    public static function ler(Caminhos $caminhos): self
    {
        $arquivo = $caminhos->config() . '/atualizacao.json';
        $d = is_file($arquivo) ? json_decode((string) file_get_contents($arquivo), true) : null;
        $d = is_array($d) ? $d : [];

        return new self(
            isset($d['url']) && is_string($d['url']) ? $d['url'] : '',
            isset($d['canal']) && in_array($d['canal'], ['estavel', 'beta'], true) ? $d['canal'] : 'estavel',
            ($d['verificar'] ?? true) !== false,
            isset($d['php']) && is_string($d['php']) && strlen($d['php']) <= 300 ? $d['php'] : '',
        );
    }
}
