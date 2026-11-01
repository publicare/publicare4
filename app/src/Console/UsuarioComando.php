<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Acesso\Perfil;
use Pbl\Caminhos;
use Pbl\Servicos;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'usuario', description: 'Contas da equipe: criar, senha, ativar, desativar, listar, conceder, revogar, acessos, auditoria')]
final class UsuarioComando extends Command
{
    private const ATOR = 'cli';

    public function __construct(private readonly Caminhos $caminhos)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('acao', InputArgument::REQUIRED, 'criar | senha | ativar | desativar | listar | conceder | revogar | acessos | auditoria');
        $this->addArgument('login', InputArgument::OPTIONAL, 'Login do usuário');
        $this->addArgument('a', InputArgument::OPTIONAL, 'conceder: perfil (leitor, autor, editor); revogar: número do objeto');
        $this->addArgument('b', InputArgument::OPTIONAL, 'conceder: número do objeto');
        $this->addOption('email', null, InputOption::VALUE_REQUIRED, 'E-mail (criar)');
        $this->addOption('nome', null, InputOption::VALUE_REQUIRED, 'Nome (criar)');
        $this->addOption('admin', null, InputOption::VALUE_NONE, 'Administrador global (criar)');
        $this->addOption('senha-stdin', null, InputOption::VALUE_NONE, 'Lê a senha da entrada padrão (para scripts). A senha nunca vai na linha de comando.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $s = Servicos::doBancoInstalado($this->caminhos);
            $acao = (string) $input->getArgument('acao');
            $login = (string) $input->getArgument('login');
            if ($acao === 'listar') {
                foreach ($s->usuarios->todos() as $u) {
                    $output->writeln(str_pad($u->login, 24) . str_pad($u->administrador ? 'administrador' : 'equipe', 15) . ($u->ativo ? 'ativo' : 'inativo') . '  ' . $u->email);
                }

                return Command::SUCCESS;
            }
            if ($acao === 'auditoria') {
                foreach ($s->auditoria->recentes(50) as $r) {
                    $output->writeln("{$r['quando']}  {$r['ator']}  {$r['acao']}" . ($r['objeto_id'] === null ? '' : "  objeto {$r['objeto_id']}") . ($r['detalhe'] === null ? '' : "  {$r['detalhe']}"));
                }

                return Command::SUCCESS;
            }
            if ($acao === 'criar') {
                $senha = $this->senha($input, $output);
                $u = $s->usuarios->criar($login, (string) $input->getOption('email'), (string) ($input->getOption('nome') ?? $login), $senha, (bool) $input->getOption('admin'), self::ATOR);
                $output->writeln("Usuário {$u->login} criado" . ($u->administrador ? ' (administrador global).' : '.'));

                return Command::SUCCESS;
            }
            $usuario = $s->usuarios->porLogin($login) ?? throw new \InvalidArgumentException("Usuário não encontrado: {$login}");
            switch ($acao) {
                case 'senha':
                    $s->usuarios->definirSenha($usuario->id, $this->senha($input, $output), self::ATOR);
                    $output->writeln('Senha trocada.');
                    break;
                case 'ativar':
                case 'desativar':
                    $s->usuarios->definirAtivo($usuario->id, $acao === 'ativar', self::ATOR);
                    $output->writeln("Usuário {$usuario->login} " . ($acao === 'ativar' ? 'ativado.' : 'desativado.'));
                    break;
                case 'conceder':
                    $perfil = Perfil::tryFrom((string) $input->getArgument('a')) ?? throw new \InvalidArgumentException('Perfil inválido (leitor, autor ou editor)');
                    $s->usuarios->conceder($usuario->id, (int) $input->getArgument('b'), $perfil, self::ATOR);
                    $output->writeln("{$usuario->login} agora é {$perfil->value} do objeto " . (int) $input->getArgument('b') . ' e de tudo abaixo dele.');
                    break;
                case 'revogar':
                    $s->usuarios->revogar($usuario->id, (int) $input->getArgument('a'), self::ATOR);
                    $output->writeln('Acesso revogado.');
                    break;
                case 'acessos':
                    foreach ($s->usuarios->acessosDe($usuario->id) as $a) {
                        $output->writeln(str_pad($a['perfil']->value, 10) . 'objeto ' . $a['objeto_id']);
                    }
                    break;
                default:
                    throw new \InvalidArgumentException('Ação desconhecida');
            }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /** A senha vem da entrada padrão (`--senha-stdin`) ou de uma pergunta oculta com confirmação; nunca de um argumento. */
    private function senha(InputInterface $input, OutputInterface $output): string
    {
        if ($input->getOption('senha-stdin')) {
            $linha = stream_get_line(STDIN, 2048, "\n");
            if ($linha === false || $linha === '') {
                throw new \InvalidArgumentException('Nenhuma senha na entrada padrão');
            }

            return rtrim($linha, "\r");
        }
        $ajudante = new QuestionHelper();
        $pergunta = (new Question('Senha: '))->setHidden(true)->setHiddenFallback(true);
        $senha = (string) $ajudante->ask($input, $output, $pergunta);
        $confirmacao = (string) $ajudante->ask($input, $output, (new Question('Repita a senha: '))->setHidden(true)->setHiddenFallback(true));
        if (!hash_equals($senha, $confirmacao)) {
            throw new \InvalidArgumentException('As senhas não conferem');
        }

        return $senha;
    }
}
