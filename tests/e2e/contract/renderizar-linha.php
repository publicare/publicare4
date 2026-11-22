<?php
// Renderiza LinhaDeConteudo com dados fixos (JSON em stdout); usado só pelos testes de contrato.
declare(strict_types=1);

use Pbl\Admin\AcaoDeLinha;
use Pbl\Admin\DadosDaLinha;
use Pbl\Admin\LinhaDeConteudo;
use Pbl\Conteudo\Classe;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$classe = new Classe(1, 'acomodacao', [], fixa: false, icone: '');
$xss = '<img src=x onerror=alert(1)>';
$acoes = static fn (): array => [
    AcaoDeLinha::link('Editar', 'editar', AcaoDeLinha::PRIMARIA, '/_pbl/editar/7'),
    AcaoDeLinha::post('Tirar do ar', 'publicar', AcaoDeLinha::ESTADO, '/_pbl/acao', '<input type="hidden" name="_csrf" value="tok">', [['ids[]', '7']]),
    AcaoDeLinha::link('Abrir no site', 'externo', AcaoDeLinha::EXTERNA, 'https://x.org/a', true),
    AcaoDeLinha::link('Abrir pasta', 'abrir', AcaoDeLinha::MENU, '/_pbl/objeto/7'),
    AcaoDeLinha::link('Copiar', 'copiar', AcaoDeLinha::MENU, '/c'),
    AcaoDeLinha::link('Recortar', 'recortar', AcaoDeLinha::MENU, '/r'),
    AcaoDeLinha::link('Mover', 'mover', AcaoDeLinha::MENU, '/m'),
    AcaoDeLinha::link('Apagar', 'excluir', AcaoDeLinha::PERIGO, '/apagar'),
];
$completa = new DadosDaLinha(7, 'Varanda com cadeiras junto à parede laranja', '/_pbl/objeto/7', $classe, 'acomodacao', '<span class="badge">publicado</span>',
    '2026-11-22T01:36:00-03:00', '22/11 01:36', '22/11/2026 01:36', 3, 5);
$vazia = new DadosDaLinha(8, 'Sem metadados extras', '/_pbl/objeto/8', $classe, 'acomodacao', '', '', '', '', 0, null);
$hostil = new DadosDaLinha(9, $xss, '/_pbl/objeto/9', $classe, $xss, '', '', '', '', 0, 1);

echo json_encode([
    'completa' => LinhaDeConteudo::renderizar($completa, $acoes()),
    'vazia' => LinhaDeConteudo::renderizar($vazia, array_slice($acoes(), 0, 1)),
    'hostil' => LinhaDeConteudo::renderizar($hostil, $acoes()),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
