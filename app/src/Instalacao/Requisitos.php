<?php

declare(strict_types=1);

namespace Pbl\Instalacao;

use Pbl\Caminhos;

/** O que o servidor precisa ter para o PBL4 rodar, em linguagem que quem instala entende. */
final class Requisitos
{
    public const PHP_MINIMO = '8.2.0';
    private const EXTENSOES = [
        'pdo_sqlite' => 'banco de dados SQLite (o padrão, que não precisa de servidor)',
        'mbstring' => 'textos com acentos',
        'sodium' => 'assinatura das atualizações',
        'json' => 'leitura de dados',
        'ctype' => 'validação de textos',
    ];

    public function __construct(private readonly Caminhos $caminhos)
    {
    }

    /** @return list<array{nome: string, ok: bool, detalhe: string, obrigatorio: bool}> */
    public function verificar(): array
    {
        $itens = [[
            'nome' => 'PHP ' . self::PHP_MINIMO . ' ou mais novo',
            'ok' => version_compare(PHP_VERSION, self::PHP_MINIMO, '>='),
            'detalhe' => 'Este servidor tem o PHP ' . PHP_VERSION . '.',
            'obrigatorio' => true,
        ]];
        foreach (self::EXTENSOES as $extensao => $para) {
            $ok = extension_loaded($extensao);
            $itens[] = ['nome' => "Extensão {$extensao}", 'ok' => $ok, 'obrigatorio' => true, 'detalhe' => $ok ? "Presente ({$para})." : "Ausente: necessária para {$para}. Peça à hospedagem para ativá-la."];
        }
        $dados = $this->caminhos->dados();
        $gravavel = is_dir($dados) ? is_writable($dados) : is_writable(dirname($dados));
        $itens[] = [
            'nome' => 'Pasta dados/ gravável',
            'ok' => $gravavel,
            'obrigatorio' => true,
            'detalhe' => $gravavel ? 'O PBL4 consegue guardar o banco, o cache e os arquivos do site.' : 'O PBL4 precisa gravar na pasta dados/. Dê permissão de escrita a ela (ou à pasta acima, para que seja criada).',
        ];
        $zip = extension_loaded('zip');
        $itens[] = [
            'nome' => 'Extensão zip (atualização automática)',
            'ok' => $zip,
            'obrigatorio' => false,
            'detalhe' => $zip ? 'Presente: o PBL4 poderá se atualizar sozinho.' : 'Ausente: o site funciona, mas a atualização automática não. Peça à hospedagem para ativá-la quando puder.',
        ];
        // O PHP não consegue conferir sozinho como o servidor web está configurado: é um aviso para a pessoa verificar, não um ✔.
        $itens[] = [
            'nome' => 'Confira: pasta dados/ fora do alcance do navegador',
            'ok' => false,
            'obrigatorio' => false,
            'detalhe' => 'Não dá para conferir daqui. Depois de instalar, abra o endereço do site seguido de /dados/pbl4.sqlite: deve dar "proibido" ou "não encontrado". O ideal é a raiz do site ser só a pasta public/.',
        ];

        return $itens;
    }

    public function tudoCerto(): bool
    {
        foreach ($this->verificar() as $item) {
            if ($item['obrigatorio'] && !$item['ok']) {
                return false;
            }
        }

        return true;
    }
}
