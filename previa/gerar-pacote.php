<?php

declare(strict_types=1);

/**
 * Monta o pacote da PRÉVIA do painel (PBL4 instalado, com conteúdo de exemplo e contas fictícias de demonstração).
 * Roda sem rede e sem argumentos, com o repositório só para leitura; grava `gerado/pbl4.tar.gz` no diretório atual.
 * Contas fictícias (admin, editor, autor) usam a senha de demonstração do próprio PBL4. Nada daqui vai para produção.
 */

// Host do site de demonstração: a fábrica (VSF) envia sempre `Host: previa:8080` à prévia; o PBL4 vê `previa`.
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

function copiarVendorDeProducao(string $raiz, string $stage): void
{
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
    foreach (['autoload_static.php', 'autoload_files.php', 'autoload_psr4.php', 'autoload_classmap.php', 'autoload_namespaces.php'] as $mapa) {
        $arq = $stage . '/vendor/composer/' . $mapa;
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
}

foreach (['app', 'bin', 'public', 'composer.json', 'composer.lock'] as $p) {
    copiar($raiz . '/' . $p, $stage . '/' . $p);
}
if (!is_file($raiz . '/vendor/autoload.php')) {
    // Clone limpo (vendor/ não é versionado): instala só as dependências de produção a partir do composer.lock.
    exec('cd ' . escapeshellarg($stage) . ' && composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader 2>&1', $saidaComposer, $codigoComposer);
    if ($codigoComposer !== 0 || !is_file($stage . '/vendor/autoload.php')) {
        fwrite(STDERR, "vendor/ ausente e 'composer install --no-dev' falhou ({$codigoComposer}):\n" . implode("\n", $saidaComposer) . "\n");
        exit(1);
    }
} else {
    copiarVendorDeProducao($raiz, $stage);
}

require $stage . '/vendor/autoload.php';

$caminhos = new Pbl\Caminhos($stage);
(new Pbl\Instalacao\Instalador($caminhos))->instalar('Instituto Exemplo', 'previa', [
    'login' => 'admin', 'email' => 'admin@exemplo.invalid', 'nome' => 'Ana Admin (demonstração)', 'senha' => Pbl\Console\DemoPopularComando::SENHA_DEMO,
], Pbl\Conteudo\Modelos::NOMES);

exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($stage . '/bin/pbl') . ' demo-popular --forcar 2>&1', $saidaPopular, $codigo);
if ($codigo !== 0) {
    fwrite(STDERR, implode("\n", $saidaPopular) . "\n");
    exit(1);
}

// Conteúdo extra para a prévia da listagem (ADR 0032): títulos longos, vários tipos e estados (publicado, privado, agendado).
$servicos = Pbl\Servicos::doBancoInstalado($caminhos);
$siteExemplo = $servicos->sites->porHost('previa');
if ($siteExemplo !== null && $siteExemplo->objetoRaizId !== null) {
    $raizId = (int) $siteExemplo->objetoRaizId;
    $exemplos = [
        ['pasta', 'Acomodações e quartos da pousada, com todas as fotos e descrições', 'publicado', [], []],
        ['pagina', 'Varanda com cadeiras junto à parede laranja, vista para o jardim e para a serra ao entardecer', 'publicado', [], []],
        ['link', 'Reservas pelo site parceiro', 'publicado', [], ['destino_externo' => 'https://exemplo.org/reservas']],
        ['formulario', 'Fale conosco', 'privado', [], ['destino' => $raizId]],
        ['pagina', 'Promoção de verão (agendada)', 'publicado', ['data_publicacao' => gmdate('c', time() + 86400 * 20)], []],
    ];
    foreach ($exemplos as $i => [$tipo, $titulo, $status, $meta, $dados]) {
        $classe = $servicos->classes->porNome($tipo);
        if ($classe !== null) {
            try {
                $servicos->objetos->criar($siteExemplo->id, $classe, $raizId, $titulo, 'previa-lista-' . $i, $dados, $status, $meta);
            } catch (Throwable) {
                // exemplo opcional: a prévia segue sem ele
            }
        }
    }
}

// Dados de exemplo das telas de conta (só neste pacote de prévia): convite pendente, contas ativas/inativas/de serviço,
// sessões ativas do admin e tentativas de login falhas. Cada passo é opcional: a prévia segue sem ele se falhar.
$tentar = static function (callable $f): void {
    try {
        $f();
    } catch (Throwable $e) {
        fwrite(STDERR, 'exemplo de conta ignorado: ' . $e->getMessage() . "\n");
    }
};
$senhaDemo = Pbl\Console\DemoPopularComando::SENHA_DEMO;
$tentar(static function () use ($servicos, $senhaDemo): void {
    $servicos->usuarios->criar('bruno.inativo', 'bruno@exemplo.invalid', 'Bruno Souza (conta desativada)', $senhaDemo);
    $servicos->usuarios->definirAtivo((int) $servicos->usuarios->porLogin('bruno.inativo')?->id, false, 'previa');
});
$tentar(static function () use ($servicos, $senhaDemo): void {
    $servicos->usuarios->criar('carla.revisora', 'carla@exemplo.invalid', 'Carla Mendes', $senhaDemo, true);
});
$tentar(static function () use ($servicos): void {
    $servicos->usuarios->criarContaDeServico('agente.publicador', 'Agente de publicação (conta de serviço)');
});
$tentar(static function () use ($servicos): void {
    $servicos->convites()->convidar('diego.convidado', 'Diego Almeida (convite pendente)', 'diego@exemplo.invalid', false, 'admin', false);
});
$tentar(static function () use ($servicos): void {
    $servicos->convites()->convidar('elisa.convidada', 'Elisa Prado (convite pendente)', 'elisa@exemplo.invalid', false, 'admin', false);
});
$tentar(static function () use ($servicos): void {
    $admin = $servicos->usuarios->porLogin('admin');
    if ($admin !== null) {
        $sessoes = $servicos->sessoes();
        for ($i = 0; $i < 3; $i++) {
            $sessoes->criar($admin->id); // outros dispositivos do admin, listados no perfil
        }
    }
});
$tentar(static function () use ($servicos): void {
    $limite = $servicos->limiteDeTentativas();
    foreach (['203.0.113.7' => 3, '198.51.100.23' => 2] as $ip => $vezes) {
        for ($i = 0; $i < $vezes; $i++) {
            $limite->registrarFalha('admin', $ip);
        }
    }
    $limite->registrarFalha('editor', '203.0.113.7');
});
unset($servicos);

// Marcador do modo prévia (ver Pbl\Previa\ModoPrevia): o host `pv-…` da prévia passa a atender o site e `/_pbl/previa-entrar` abre a
// sessão das contas fictícias. Só existe neste pacote descartável; nunca em instalação real.
file_put_contents($stage . '/dados/' . Pbl\Previa\ModoPrevia::MARCADOR, "previa descartavel\n");

@mkdir(dirname($saida), 0775, true);
exec('tar -C ' . escapeshellarg($stage) . ' -czf ' . escapeshellarg($area . '/pbl4.tar.gz') . ' . 2>&1', $saidaTar, $codigoTar);
if ($codigoTar !== 0) {
    fwrite(STDERR, "tar falhou ({$codigoTar}): " . implode("\n", $saidaTar) . "\n");
    exit(1);
}
rename($area . '/pbl4.tar.gz', $saida);
echo "pacote: {$saida} (" . round(filesize($saida) / 1048576, 1) . " MB)\n";
