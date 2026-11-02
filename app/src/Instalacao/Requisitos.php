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

    /** @param string|null $openBasedir valor do open_basedir (null = o do PHP em uso; só os testes passam outro) */
    public function __construct(private readonly Caminhos $caminhos, private readonly ?string $openBasedir = null)
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
            $itens[] = ['nome' => "Extensão {$extensao}", 'ok' => $ok, 'obrigatorio' => true, 'detalhe' => $ok ? "Presente ({$para})." : "Ausente: necessária para {$para}. " . $this->comoAtivar($extensao)];
        }
        $dados = $this->caminhos->dados();
        $limite = $this->openBasedir ?? (string) ini_get('open_basedir');
        $liberado = self::dentroDoOpenBasedir($dados, $limite);
        if (!$liberado) {
            $itens[] = [
                'nome' => 'Acesso do PHP à pasta dados/ (open_basedir)',
                'ok' => false,
                'obrigatorio' => true,
                'detalhe' => 'O PHP deste servidor só pode acessar certas pastas (open_basedir), e a pasta dados/ do PBL4 está fora delas. '
                    . 'Em painéis como Hestia/VestaCP isso acontece quando o projeto fica fora da pasta do domínio. '
                    . 'Coloque o projeto dentro da pasta do domínio (por exemplo em private/) e faça a raiz do site apontar para a pasta public/ dele (veja "Hestia/VestaCP e hospedagens com open_basedir" no guia de instalação), ou peça à hospedagem para liberar a pasta.',
            ];
        }
        $gravavel = $liberado && (is_dir($dados) ? is_writable($dados) : is_writable(dirname($dados)));
        $itens[] = [
            'nome' => 'Pasta dados/ gravável',
            'ok' => $gravavel,
            'obrigatorio' => true,
            'detalhe' => !$liberado ? 'Não dá para verificar enquanto o item acima não for resolvido.' : ($gravavel ? 'O PBL4 consegue guardar o banco, o cache e os arquivos do site.' : 'O PBL4 precisa gravar na pasta dados/. Dê permissão de escrita a ela (ou à pasta acima, para que seja criada).'),
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

    /** Como ativar uma extensão que falta, no vocabulário de quem usa painel de hospedagem. */
    private function comoAtivar(string $extensao): string
    {
        if ($extensao === 'pdo_sqlite') {
            return 'Peça à hospedagem para ativá-la (em servidores Debian/Ubuntu/Hestia o pacote é o php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-sqlite3; depois reinicie o PHP-FPM).';
        }

        return 'Peça à hospedagem para ativá-la.';
    }

    /** Diz se o caminho está dentro de alguma das pastas do open_basedir (vazio = sem restrição). Funciona mesmo se o caminho ainda não existe. */
    public static function dentroDoOpenBasedir(string $caminho, string $openBasedir): bool
    {
        if (trim($openBasedir) === '') {
            return true;
        }
        // Sobe até a primeira pasta que existe, para resolver links simbólicos como o PHP faz; o que falta é anexado de volta.
        $resto = '';
        $atual = rtrim($caminho, '/');
        while ($atual !== '' && @realpath($atual) === false) {
            $resto = '/' . basename($atual) . $resto;
            $atual = dirname($atual);
            if ($atual === '/' || $atual === '.') {
                break;
            }
        }
        $real = (@realpath($atual) ?: $atual) . $resto;
        foreach (explode(PATH_SEPARATOR, $openBasedir) as $permitida) {
            $permitida = trim($permitida);
            if ($permitida === '') {
                continue;
            }
            $permitida = rtrim(@realpath($permitida) ?: $permitida, '/');
            if ($real === $permitida || str_starts_with($real, $permitida . '/')) {
                return true;
            }
        }

        return false;
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
