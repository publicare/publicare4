<?php

declare(strict_types=1);

/**
 * Pacote da PRÉVIA do manual: o pacote padrão da prévia (previa/gerar-pacote.php) com a pele `manual` ativa e o manual carregado
 * pelo próprio `bin/pbl manual-gerar` (edição estável, sem capturas reais: quadros de texto). Sem rede, sem argumentos, repositório
 * só para leitura; grava `gerado/pbl4.tar.gz` no diretório atual. Contas fictícias e conteúdo de demonstração; nada vai para produção.
 */

$raiz = dirname(__DIR__);
$area = getcwd();
$stage = $area . '/stage-pbl4';

function executar(string $comando, string $falha): void
{
    exec($comando . ' 2>&1', $saida, $codigo);
    if ($codigo !== 0) {
        fwrite(STDERR, $falha . " ({$codigo}):\n" . implode("\n", $saida) . "\n");
        exit(1);
    }
}

function copiarArvore(string $de, string $para): void
{
    if (is_file($de)) {
        @mkdir(dirname($para), 0775, true);
        copy($de, $para);

        return;
    }
    @mkdir($para, 0775, true);
    foreach (scandir($de) ?: [] as $f) {
        if ($f !== '.' && $f !== '..') {
            copiarArvore($de . '/' . $f, $para . '/' . $f);
        }
    }
}

executar(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($raiz . '/previa/gerar-pacote.php'), 'pacote base falhou');

// O compilador do manual lê docs/ e manual/links-externos.json relativos à raiz da instalação.
copiarArvore($raiz . '/docs', $stage . '/docs');
copiarArvore($raiz . '/manual/links-externos.json', $stage . '/manual/links-externos.json');
foreach (['SECURITY.md', 'CHANGELOG.md'] as $arquivo) {
    copiarArvore($raiz . '/' . $arquivo, $stage . '/' . $arquivo);
}

$banco = new PDO('sqlite:' . $stage . '/dados/pbl4.sqlite');
$banco->exec("UPDATE site SET pele = 'manual' WHERE id = 1");
unset($banco);

$pbl = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($stage . '/bin/pbl');
executar($pbl . ' manual-gerar --site=1 --como=admin --sem-capturas --edicao=estavel --confirmar', 'carga do manual falhou');
executar($pbl . ' publicar', 'publicação falhou');

executar('tar -C ' . escapeshellarg($stage) . ' -czf ' . escapeshellarg($area . '/pbl4-manual.tar.gz') . ' .', 'tar falhou');
rename($area . '/pbl4-manual.tar.gz', $area . '/gerado/pbl4.tar.gz');
echo "pacote: {$area}/gerado/pbl4.tar.gz\n";
