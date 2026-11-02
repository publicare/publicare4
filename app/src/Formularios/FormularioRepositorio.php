<?php

declare(strict_types=1);

namespace Pbl\Formularios;

use Pbl\Conteudo\Classe;
use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\ObjetoRepositorio;

/**
 * Lê a definição de um formulário (um conteúdo do tipo `formulario`) e só devolve se ela é segura de usar. Falha fechada: se
 * qualquer parte estiver errada, o resultado é `null`, igual a um formulário que não existe (sem dizer ao visitante o que faltou).
 */
final class FormularioRepositorio
{
    public const CLASSE = 'formulario';

    public function __construct(private readonly ObjetoRepositorio $objetos, private readonly ClasseRepositorio $classes)
    {
    }

    public function porId(int $siteId, int $id): ?Formulario
    {
        $objeto = $this->objetos->porId($id);
        $tipo = $this->classes->porNome(self::CLASSE);
        if ($objeto === null || $tipo === null || $objeto->classeId !== $tipo->id || $objeto->siteId !== $siteId || !$objeto->visivel(gmdate('c'))) {
            return null;
        }
        $d = $objeto->dados;
        if (($d['fechado'] ?? 0) === 1) {
            return null;
        }
        $destino = is_int($d['destino'] ?? null) ? $this->objetos->porId($d['destino']) : null;
        if ($destino === null || $destino->siteId !== $siteId || $destino->status === 'apagado') {
            return null;
        }
        $nomeDoTipo = $d['classe_envio'] ?? null;
        $envio = is_string($nomeDoTipo) ? $this->classes->porNome(trim($nomeDoTipo)) : null;
        $tipoDoDestino = $this->classes->porId($destino->classeId);
        if ($envio === null || $tipoDoDestino === null || !$tipoDoDestino->aceita($envio) || $envio->nome === self::CLASSE) {
            return null;
        }
        $campos = $this->campos($envio, (string) ($d['campos'] ?? ''));
        if ($campos === null) {
            return null;
        }
        $mensagem = trim((string) ($d['mensagem'] ?? ''));
        $botao = trim((string) ($d['botao'] ?? ''));

        return new Formulario(
            $objeto->id,
            $siteId,
            $objeto->titulo,
            $destino->id,
            $envio,
            $campos,
            mb_substr($mensagem === '' ? 'Recebemos o seu envio. Obrigado!' : $mensagem, 0, Formulario::MAX_MENSAGEM),
            mb_substr($botao === '' ? 'Enviar' : $botao, 0, Formulario::MAX_BOTAO),
        );
    }

    /**
     * Os campos expostos, na ordem escrita. `null` se algum nome não existe no tipo, está arquivado, é de tipo que formulário
     * público não aceita, se há nome repetido, se não há nenhum, ou se o tipo tem campo obrigatório que o formulário não mostra
     * (todo envio seria recusado).
     *
     * @return list<array{nome: string, rotulo: string, tipo: string, obrigatorio: bool}>|null
     */
    private function campos(Classe $envio, string $lista): ?array
    {
        $porNome = [];
        foreach ($envio->propriedades as $p) {
            $porNome[$p['nome']] = $p;
        }
        $nomes = array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $lista) ?: []), static fn (string $n): bool => $n !== ''));
        if ($nomes === [] || count($nomes) > Formulario::MAX_CAMPOS || count(array_unique($nomes)) !== count($nomes)) {
            return null;
        }
        $campos = [];
        foreach ($nomes as $nome) {
            $p = $porNome[$nome] ?? null;
            if ($p === null || ($p['arquivada'] ?? false) || !in_array($p['tipo'], Formulario::TIPOS, true)) {
                return null;
            }
            $campos[] = ['nome' => $nome, 'rotulo' => Classe::rotuloDe($p), 'tipo' => $p['tipo'], 'obrigatorio' => (bool) ($p['obrigatorio'] ?? false)];
        }
        foreach ($envio->propriedades as $p) {
            if (($p['obrigatorio'] ?? false) && !($p['arquivada'] ?? false) && !in_array($p['nome'], $nomes, true)) {
                return null;
            }
        }

        return $campos;
    }
}
