<?php

declare(strict_types=1);

namespace Pbl\Importacao;

/** Escolhas de uma importação do PBL3 (as opções de `bin/pbl importar-pbl3`). */
final class Opcoes
{
    /**
     * @param ?int $siteId site de destino (obrigatório se a instalação tem mais de um)
     * @param ?int $raiz objeto do PBL3 a importar com toda a subárvore; sem ele, as raízes (sem pai) da base
     * @param ?int $paiDestino objeto daqui sob o qual a raiz importada entra; sem ele, a raiz do site
     * @param ?string $uploads pasta de arquivos do PBL3 (`uploadpath`); sem ela os arquivos não são importados
     * @param string $fonte rótulo desta base de origem no mapa de ids (repetir o mesmo rótulo retoma a importação)
     * @param string $fuso fuso das datas do PBL3 (que não guardam fuso)
     * @param array<string, string> $peles prefixo/nome da pele no PBL3 => nome da pele no PBL4
     * @param bool $semLixeira não importar o que está na lixeira (apagado) do PBL3
     */
    public function __construct(
        public readonly bool $simular = true,
        public readonly ?int $siteId = null,
        public readonly ?int $raiz = null,
        public readonly ?int $paiDestino = null,
        public readonly ?string $uploads = null,
        public readonly string $fonte = 'pbl3',
        public readonly string $fuso = 'America/Sao_Paulo',
        public readonly array $peles = [],
        public readonly bool $semLixeira = false,
        public readonly int $lote = 200,
    ) {
    }
}
