<?php

declare(strict_types=1);

namespace Pbl\Console\Conteudo;

use Pbl\Conteudo\Servico\EntradaInvalida;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * `imagem` e `arquivo`: anexa um arquivo local a um campo. A validação por conteúdo real, a recodificação e a miniatura são do
 * `ServicoDeUpload` (o mesmo do painel). Aqui só se confere o caminho local: arquivo comum, legível, não-link, dentro do open_basedir.
 */
final class AnexarAcao implements AcaoDeConteudo
{
    /** @param string $tipo `imagem` ou `arquivo` (é o nome do subcomando e o tipo exigido do campo) */
    public function __construct(private readonly string $tipo)
    {
    }

    public function nome(): string
    {
        return $this->tipo;
    }

    public function executar(Contexto $c): Resposta
    {
        $o = $c->objeto();
        $campo = $c->opcao('campo') ?? throw new UsoInvalido('Informe --campo=<propriedade>.');
        $caminho = $c->opcao('arquivo') ?? throw new UsoInvalido('Informe --arquivo=<caminho local>.');
        $alt = $c->opcao('alt');
        if ($this->tipo === 'arquivo' && $alt !== null) {
            throw new EntradaInvalida(['alt' => 'O texto alternativo só vale para imagem.']);
        }
        if ($this->tipo === 'imagem' && $alt === null) {
            throw new EntradaInvalida(['alt' => 'O texto alternativo (--alt) é obrigatório em imagem.']);
        }
        $versao = $c->versao();
        $enviado = self::arquivoLocal($caminho);
        $r = $c->s->conteudo->anexar($c->quem, $o->id, $versao, $campo, $this->tipo, $enviado, $alt);

        return $c->gravada($r, $this->tipo . ' anexado ao campo ' . $campo);
    }

    /** @throws EntradaInvalida caminho que não é um arquivo comum e legível, ou fora do que o PHP deixa ler (open_basedir) */
    public static function arquivoLocal(string $caminho): UploadedFile
    {
        $recusa = new EntradaInvalida(['arquivo' => 'Arquivo não encontrado, ilegível ou fora dos diretórios que o PHP pode ler (open_basedir).']);
        if ($caminho === '' || str_contains($caminho, "\0")) {
            throw $recusa;
        }
        // `@`: com open_basedir o PHP avisa em vez de só devolver false; a mensagem para quem chamou já é a nossa.
        if (@is_link($caminho)) {
            throw new EntradaInvalida(['arquivo' => 'Link simbólico não é aceito; indique o arquivo de verdade.']);
        }
        $real = @realpath($caminho);
        if ($real === false || !@is_file($real) || !@is_readable($real)) {
            throw $recusa;
        }
        $tamanho = @filesize($real);
        if ($tamanho === false || $tamanho <= 0) {
            throw new EntradaInvalida(['arquivo' => 'O arquivo está vazio.']);
        }

        // modo de teste (último argumento): o arquivo não veio de um POST, e o ServicoDeUpload não o move nem o apaga
        return new UploadedFile($real, basename($real), null, \UPLOAD_ERR_OK, true);
    }
}
