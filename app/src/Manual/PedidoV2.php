<?php

declare(strict_types=1);

namespace Pbl\Manual;

/**
 * Monta o pedido de captura (formato v2 da esteira da prévia) a partir de `docs/usuario/capturas.json`. O formato está TODO aqui,
 * em `montar`: se o contrato da esteira mudar, só este método muda (ADR 0042). Sem dependências: o `manual/capturas.php` carrega só este arquivo.
 *
 * Cada captura vira uma página do pedido: `/_pbl/previa-entrar?conta=<papel>&ir=<caminho do painel>` (a entrada de prévia abre a sessão da
 * conta FICTÍCIA e redireciona), então só contas de demonstração aparecem; nunca uma instalação real. O pedido aceita 8 páginas por vez,
 * por isso o resultado é uma lista de pedidos (lotes).
 */
final class PedidoV2
{
    public const PAGINAS_POR_PEDIDO = 8;
    public const PAPEIS = ['admin', 'editor', 'autor'];

    /**
     * @param list<array{id: string, pagina_do_painel: string, papel: string}> $capturas
     * @return list<array<string, mixed>> um pedido por lote
     */
    public static function montar(array $capturas): array
    {
        $paginas = [];
        foreach ($capturas as $c) {
            $id = (string) ($c['id'] ?? '');
            $caminho = (string) ($c['pagina_do_painel'] ?? '');
            $papel = (string) ($c['papel'] ?? '');
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $id) !== 1 || strlen($id) > 40) {
                throw new \InvalidArgumentException("Captura com id inválido: {$id}");
            }
            if (preg_match('#^/_pbl(?:/[A-Za-z0-9_.-]+)*/?$#D', $caminho) !== 1 || str_contains($caminho, '..')) {
                throw new \InvalidArgumentException("Captura {$id}: a página precisa ser um caminho do painel (/_pbl/...).");
            }
            if (!in_array($papel, self::PAPEIS, true)) {
                throw new \InvalidArgumentException("Captura {$id}: papel desconhecido.");
            }
            $entrada = '/_pbl/previa-entrar?conta=' . $papel . '&ir=' . $caminho;
            if (strlen($entrada) > 120) {
                throw new \InvalidArgumentException("Captura {$id}: caminho longo demais para o pedido.");
            }
            $paginas[] = ['caminho' => $entrada, 'nome' => $id];
        }
        $pedidos = [];
        foreach (array_chunk($paginas, self::PAGINAS_POR_PEDIDO) as $lote) {
            $pedidos[] = [
                'versao' => 2, 'raiz' => '.', 'modo' => 'php', 'docroot' => 'plataforma/public', 'gravaveis' => ['plataforma/dados'],
                'preparo' => [
                    ['acao' => 'gerar_pacote', 'script' => 'previa/gerar-pacote.php', 'para' => 'gerado/pbl4.tar.gz'],
                    ['acao' => 'extrair_pacote', 'de' => 'gerado/pbl4.tar.gz', 'para' => 'plataforma'],
                ],
                'paginas' => $lote, 'viewports' => ['desktop'], 'pagina_inteira' => false,
                'resumo' => 'Capturas do painel para o manual de uso',
            ];
        }

        return $pedidos;
    }
}
