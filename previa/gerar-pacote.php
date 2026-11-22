<?php

declare(strict_types=1);

/**
 * Gera o pacote descartável da PRÉVIA do painel (nunca vai para produção; `previa/` está em export-ignore).
 * Roda sem argumentos e sem rede: monta uma instalação do PBL4 com o conteúdo de demonstração, as contas fictícias
 * (admin, editor, autor) e o marcador `dados/previa.flag`, e grava `gerado/pbl4.tar.gz` no diretório atual.
 */

$repo = dirname(__DIR__);
$destino = getcwd() . '/gerado/pbl4.tar.gz';
$stage = sys_get_temp_dir() . '/pbl4-previa-' . bin2hex(random_bytes(4));

function copiarPasta(string $de, string $para, array $ignorar = []): void
{
    if (!is_dir($para) && !mkdir($para, 0755, true) && !is_dir($para)) {
        throw new RuntimeException("Não criei {$para}");
    }
    foreach (new DirectoryIterator($de) as $item) {
        if ($item->isDot() || in_array($item->getFilename(), $ignorar, true) || $item->isLink()) {
            continue;
        }
        $alvo = $para . '/' . $item->getFilename();
        $item->isDir() ? copiarPasta($item->getPathname(), $alvo, $ignorar) : copy($item->getPathname(), $alvo);
    }
}

foreach (['app', 'public'] as $pasta) {
    copiarPasta($repo . '/' . $pasta, $stage . '/' . $pasta);
}
foreach (['composer.json', 'composer.lock'] as $arquivo) {
    copy($repo . '/' . $arquivo, $stage . '/' . $arquivo);
}

if (is_dir($repo . '/vendor')) {
    // Copia o vendor/ existente sem os pacotes de desenvolvimento (o limite do pacote é 20 MB) e refaz o autoload só de produção, sem rede.
    copiarPasta($repo . '/vendor', $stage . '/vendor', ['bin']);
    $instalados = json_decode((string) file_get_contents($repo . '/vendor/composer/installed.json'), true);
    foreach ($instalados['dev-package-names'] ?? [] as $pacote) {
        if (is_dir($stage . '/vendor/' . $pacote)) {
            exec('rm -rf ' . escapeshellarg($stage . '/vendor/' . $pacote));
        }
    }
    $saida = [];
    exec('cd ' . escapeshellarg($stage) . ' && composer dump-autoload --no-dev --optimize --no-interaction 2>&1', $saida, $codigo);
    if ($codigo !== 0) {
        fwrite(STDERR, "composer dump-autoload falhou ({$codigo}):\n" . implode("\n", $saida) . "\n");
        exit(1);
    }
} else {
    // Clone limpo: instala só as dependências de produção a partir do composer.lock (precisa de rede).
    $saida = [];
    exec('cd ' . escapeshellarg($stage) . ' && composer install --no-dev --no-interaction --prefer-dist 2>&1', $saida, $codigo);
    if ($codigo !== 0) {
        fwrite(STDERR, "composer install falhou ({$codigo}):\n" . implode("\n", $saida) . "\n");
        exit(1);
    }
}

require $stage . '/vendor/autoload.php';

use Pbl\Acesso\Perfil;
use Pbl\Caminhos;
use Pbl\Console\DemoPopularComando;
use Pbl\Instalacao\Instalador;
use Pbl\Previa\ModoPrevia;
use Pbl\Servicos;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

$caminhos = new Caminhos($stage);
(new Instalador($caminhos))->instalar('Instituto Exemplo', 'previa.exemplo.test');
$servicos = Servicos::doBancoInstalado($caminhos);
$site = $servicos->sites->todos()[0];
$inicio = (int) $site->objetoRaizId;
$senha = DemoPopularComando::SENHA_DEMO;
$servicos->usuarios->criar('admin', 'admin@exemplo.test', 'Ana Administradora', $senha, true);
$editor = $servicos->usuarios->criar('editor', 'editor@exemplo.test', 'Eduardo Editor', $senha);
$autor = $servicos->usuarios->criar('autor', 'autor@exemplo.test', 'Aline Autora', $senha);
$servicos->usuarios->conceder($editor->id, $inicio, Perfil::Editor);
$servicos->usuarios->conceder($autor->id, $inicio, Perfil::Autor);
unset($servicos);

$demo = new DemoPopularComando($caminhos);
$demo->run(new ArrayInput([]), new NullOutput());

file_put_contents($stage . '/dados/' . ModoPrevia::MARCADOR, "previa\n");
foreach (glob($stage . '/dados/*.sqlite-*') ?: [] as $lateral) {
    @unlink($lateral);
}

if (!is_dir(dirname($destino)) && !mkdir(dirname($destino), 0755, true)) {
    throw new RuntimeException('Não criei a pasta do pacote');
}
$tar = new PharData(substr($destino, 0, -3));
$tar->buildFromDirectory($stage);
$tar->compress(Phar::GZ);
unset($tar);
@unlink(substr($destino, 0, -3));
echo 'Pacote gerado: ' . $destino . ' (' . round(filesize($destino) / 1048576, 1) . " MB)\n";
