<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Banco\CarregadorDeConfigBanco;
use Pbl\Banco\Conexao;
use Pbl\Caminhos;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Event\TestSuite\Started;
use PHPUnit\Event\TestSuite\StartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Com `PBL_BANCO_DRIVER=mysql|pgsql` (e `PBL_BANCO_*`) a suíte inteira roda no banco de servidor, como a aplicação (a config vem
 * do ambiente). Cada teste começa de um banco vazio; no SQLite cada raiz de teste já tem o seu arquivo e nada disso acontece.
 */
final class ExtensaoBancoDeServidor implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if (!in_array(getenv('PBL_BANCO_DRIVER') ?: 'sqlite', ['mysql', 'pgsql'], true)) {
            return;
        }
        // Classe com `setUpBeforeClass` monta a instalação uma vez para todos os testes: o banco é zerado ao começar a classe.
        // As demais começam cada teste de um banco vazio.
        $facade->registerSubscriber(new class () implements StartedSubscriber {
            public function notify(Started $event): void
            {
                $suite = $event->testSuite();
                if ($suite->isForTestClass() && ExtensaoBancoDeServidor::temPreparoDeClasse($suite->name())) {
                    ExtensaoBancoDeServidor::zerar();
                }
            }
        });
        $facade->registerSubscriber(new class () implements PreparationStartedSubscriber {
            public function notify(PreparationStarted $event): void
            {
                $teste = $event->test();
                if (!$teste->isTestMethod() || !ExtensaoBancoDeServidor::temPreparoDeClasse($teste->className())) {
                    ExtensaoBancoDeServidor::zerar();
                }
            }
        });
    }

    public static function temPreparoDeClasse(string $classe): bool
    {
        if (!class_exists($classe) || !method_exists($classe, 'setUpBeforeClass')) {
            return false;
        }

        return (new \ReflectionMethod($classe, 'setUpBeforeClass'))->getDeclaringClass()->getName() !== \PHPUnit\Framework\TestCase::class;
    }

    public static function zerar(): void
    {
        $pasta = sys_get_temp_dir() . '/pbl4-zerar-' . bin2hex(random_bytes(4));
        mkdir($pasta, 0775, true);
        try {
            $config = (new CarregadorDeConfigBanco(new Caminhos($pasta)))->carregar();
            $c = Conexao::abrir($config);
            $plataforma = $c->getDatabasePlatform()::class;
            $mysql = str_contains($plataforma, 'MySQL') || str_contains($plataforma, 'MariaDB');
            if ($mysql) {
                $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            }
            foreach ($c->createSchemaManager()->listTableNames() as $tabela) {
                $c->executeStatement('DROP TABLE ' . $c->quoteSingleIdentifier($tabela) . ($mysql ? '' : ' CASCADE'));
            }
            if ($mysql) {
                $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            }
            $c->close();
        } finally {
            @rmdir($pasta . '/dados/config');
            @rmdir($pasta . '/dados');
            @rmdir($pasta);
        }
    }
}
