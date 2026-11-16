<?php

declare(strict_types=1);

namespace Pbl\Console;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/** Como os comandos de conta obtêm uma senha: entrada padrão ou pergunta oculta com confirmação; nunca um argumento. */
final class SenhaNoTerminal
{
    public static function ler(InputInterface $input, OutputInterface $output): string
    {
        if ($input->hasOption('senha-stdin') && $input->getOption('senha-stdin')) {
            $linha = stream_get_line(STDIN, 2048, "\n");
            if ($linha === false || $linha === '') {
                throw new \InvalidArgumentException('Nenhuma senha na entrada padrão');
            }

            return rtrim($linha, "\r");
        }
        $ajudante = new QuestionHelper();
        $senha = (string) $ajudante->ask($input, $output, (new Question('Senha: '))->setHidden(true)->setHiddenFallback(true));
        $confirmacao = (string) $ajudante->ask($input, $output, (new Question('Repita a senha: '))->setHidden(true)->setHiddenFallback(true));
        if (!hash_equals($senha, $confirmacao)) {
            throw new \InvalidArgumentException('As senhas não conferem');
        }

        return $senha;
    }

    /** Senha aleatória de 24 caracteres (alfabeto sem símbolos ambíguos), para mostrar uma única vez. */
    public static function gerar(): string
    {
        $alfabeto = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $senha = '';
        for ($i = 0; $i < 24; $i++) {
            $senha .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }

        return $senha;
    }
}
