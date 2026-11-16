<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\Segredo;
use Pbl\Caminhos;
use PHPUnit\Framework\TestCase;

final class SegredoTest extends TestCase
{
    public function testCriaAPastaDeConfigSemPermissaoParaOutros(): void
    {
        $raiz = sys_get_temp_dir() . '/pbl4-seg-' . bin2hex(random_bytes(4));
        mkdir($raiz);
        $umask = umask(0);
        try {
            $c = new Caminhos($raiz);

            $segredo = Segredo::obter($c);

            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $segredo);
            self::assertSame(0750, fileperms($c->config()) & 0777);
            self::assertSame(0600, fileperms($c->config() . '/segredo') & 0777);
            self::assertSame($segredo, Segredo::obter($c), 'estável nas chamadas seguintes');
        } finally {
            umask($umask);
            Ajuda::apagar($raiz);
        }
    }
}
