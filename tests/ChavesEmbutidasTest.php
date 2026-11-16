<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Atualizacao\Assinatura;
use Pbl\Atualizacao\Chaves;
use PHPUnit\Framework\TestCase;

/** As chaves públicas de produção embutidas (ADR 0019): só o formato e a consistência; as privadas nunca estão aqui. */
final class ChavesEmbutidasTest extends TestCase
{
    public function testUsoERecuperacaoTemUmaChaveHexDe64CaracteresCadaEDistintas(): void
    {
        self::assertCount(1, Chaves::USO);
        self::assertCount(1, Chaves::RECUPERACAO);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', Chaves::USO[0]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', Chaves::RECUPERACAO[0]);
        self::assertNotSame(Chaves::USO[0], Chaves::RECUPERACAO[0]);
    }

    public function testPublicasEhAUniaoENaoHaChaveRevogadaNemDeTesteEntreElas(): void
    {
        self::assertSame([...Chaves::USO, ...Chaves::RECUPERACAO], Chaves::PUBLICAS);
        self::assertSame([], array_intersect(Chaves::PUBLICAS, Chaves::REVOGADAS));
        self::assertSame([], array_intersect(Chaves::PUBLICAS, Chaves::TESTE));
    }

    public function testComAsChavesEmbutidasHaConfiancaNosCanaisDeProducao(): void
    {
        self::assertFalse(Chaves::semConfianca(Chaves::PUBLICAS, 'estavel'));
        self::assertFalse(Chaves::semConfianca(Chaves::PUBLICAS, 'beta'));
        self::assertTrue(Chaves::semConfianca([], 'estavel'), 'um build sem chaves continua recusando');
    }

    public function testCanalDeTesteContinuaSemChaveEmbutida(): void
    {
        self::assertSame([], Chaves::TESTE);
        self::assertTrue(Chaves::semConfianca(Chaves::PUBLICAS, 'teste'));
    }

    public function testAsChavesEmbutidasSaoPublicasValidasEUmParDescartavelNaoEhAceitoPorElas(): void
    {
        foreach (Chaves::PUBLICAS as $publica) {
            self::assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen((string) sodium_hex2bin($publica)));
        }
        $par = Assinatura::gerarPar();
        $texto = '{"x":1}';
        $assinatura = Assinatura::assinar($texto, $par['privada']);

        self::assertNull(Assinatura::quemAssinou($texto, $assinatura, Chaves::PUBLICAS));
    }
}
