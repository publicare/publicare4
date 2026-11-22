<?php

declare(strict_types=1);

/**
 * Monta o pacote da PRÉVIA do novo painel (PBL4 já instalado, com conteúdo de exemplo e contas fictícias de demonstração).
 * Roda sem rede, sem argumentos, com o repositório só para leitura; grava `gerado/pbl4.tar.gz` no diretório atual.
 * Contas (todas fictícias, só para a prévia descartável): admin, editor e autor, com a senha de demonstração do próprio PBL4
 * (`DemoPopularComando::SENHA_DEMO`). Nada daqui vai para o repositório nem para produção.
 */

$raiz = dirname(__DIR__);
$area = getcwd();
$stage = $area . '/stage-pbl4';
$saida = $area . '/gerado/pbl4.tar.gz';

function copiar(string $de, string $para): void
{
    if (is_file($de)) {
        @mkdir(dirname($para), 0775, true);
        copy($de, $para);

        return;
    }
    @mkdir($para, 0775, true);
    foreach (scandir($de) ?: [] as $f) {
        if ($f !== '.' && $f !== '..') {
            copiar($de . '/' . $f, $para . '/' . $f);
        }
    }
}

foreach (['app', 'bin', 'public', 'composer.json', 'composer.lock'] as $p) {
    copiar($raiz . '/' . $p, $stage . '/' . $p);
}
if (is_file($raiz . '/vendor/autoload.php')) {
    // vendor só com o que roda em produção (o pacote tem limite de tamanho)
    $inst = json_decode((string) file_get_contents($raiz . '/vendor/composer/installed.json'), true);
    $dev = array_flip($inst['dev-package-names'] ?? []);
    copiar($raiz . '/vendor/composer', $stage . '/vendor/composer');
    copiar($raiz . '/vendor/autoload.php', $stage . '/vendor/autoload.php');
    foreach ($inst['packages'] as $pacote) {
        if (!isset($dev[$pacote['name']])) {
            copiar($raiz . '/vendor/' . $pacote['name'], $stage . '/vendor/' . $pacote['name']);
        }
    }
    // tira dos mapas do autoload o que apontava para pacotes só de desenvolvimento
    foreach (['composer/autoload_static.php', 'composer/autoload_files.php', 'composer/autoload_psr4.php', 'composer/autoload_classmap.php', 'composer/autoload_namespaces.php'] as $mapa) {
        $arq = $stage . '/vendor/' . $mapa;
        if (!is_file($arq)) {
            continue;
        }
        $linhas = [];
        foreach (explode("\n", (string) file_get_contents($arq)) as $linha) {
            if (preg_match("#(?:\\\$vendorDir|__DIR__ \\. '/\\.\\.') \\. '(/[^']+)'#", $linha, $m) === 1 && !file_exists($stage . '/vendor' . $m[1])) {
                continue;
            }
            $linhas[] = $linha;
        }
        file_put_contents($arq, implode("\n", $linhas));
    }
} else {
    // Clone limpo do repositório (vendor/ não é versionado): instala só as dependências de produção a partir do composer.lock.
    // Causa mais provável de a prévia da rodada 1 nunca ter iniciado: o gerador morria aqui, sem pacote.
    $codigo = 0;
    $saidaComposer = [];
    exec('cd ' . escapeshellarg($stage) . ' && composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader 2>&1', $saidaComposer, $codigo);
    if ($codigo !== 0 || !is_file($stage . '/vendor/autoload.php')) {
        fwrite(STDERR, "vendor/ ausente e 'composer install --no-dev' falhou (código {$codigo}):\n" . implode("\n", $saidaComposer) . "\n");
        exit(1);
    }
}

require $stage . '/vendor/autoload.php';

$caminhos = new Pbl\Caminhos($stage);
$senha = Pbl\Console\DemoPopularComando::SENHA_DEMO;
(new Pbl\Instalacao\Instalador($caminhos))->instalar('Instituto Exemplo', 'localhost', [
    'login' => 'admin', 'email' => 'admin@exemplo.invalid', 'nome' => 'Ana Admin (demonstração)', 'senha' => $senha,
], Pbl\Conteudo\Modelos::NOMES);

$saidaPopular = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($stage . '/bin/pbl') . ' demo-popular --forcar 2>&1', $saidaPopular, $codigo);
if ($codigo !== 0) {
    fwrite(STDERR, implode("\n", $saidaPopular) . "\n");
    exit(1);
}

// Marcador do modo prévia (ver Pbl\Previa\ModoPrevia): o host `pv-…` da prévia passa a atender o site e `/_pbl/previa-entrar` abre a
// sessão das contas fictícias. Só existe neste pacote descartável; nunca em instalação real.
file_put_contents($stage . '/dados/' . Pbl\Previa\ModoPrevia::MARCADOR, "previa descartavel\n");

// O servidor da prévia pode responder por um nome que não é "localhost": os nomes locais mais comuns servem o mesmo site
// (modo "servir", sem redirecionar), senão o painel responderia "Domínio não configurado".
$banco = new PDO('sqlite:' . $stage . '/dados/pbl4.sqlite');
$siteId = (int) $banco->query("SELECT site_id FROM dominio WHERE host = 'localhost'")->fetchColumn();
$alias = $banco->prepare('INSERT OR IGNORE INTO dominio (host, site_id, modo) VALUES (?, ?, ?)');
foreach (['127.0.0.1', '0.0.0.0', 'previa', 'web', 'app', 'php', 'site', 'nginx', 'host.docker.internal'] as $host) {
    $alias->execute([$host, $siteId, 'servir']);
}
unset($alias, $banco);

@mkdir(dirname($saida), 0775, true);
// Sem PharData: a imagem da prévia (PHP alpine) não traz a extensão phar; tar e gzip existem.
$cmd = 'tar -C ' . escapeshellarg($stage) . ' -czf ' . escapeshellarg($area . '/pbl4.tar.gz') . ' . 2>&1';
exec($cmd, $saidaTar, $codigoTar);
if ($codigoTar !== 0) {
    fwrite(STDERR, "tar falhou ({$codigoTar}): " . implode("\n", $saidaTar) . "\n");
    exit(1);
}
rename($area . '/pbl4.tar.gz', $saida);
echo "pacote: {$saida} (" . round(filesize($saida) / 1048576, 1) . " MB)\n";
