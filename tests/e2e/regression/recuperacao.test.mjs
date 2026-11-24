// Roda o cenário Gherkin correspondente no PHPUnit (driver "arquivo"; sem navegador nem rede).
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const RAIZ = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');
const phpunit = (metodo) => spawnSync('php', ['vendor/bin/phpunit', '--no-progress', '--filter', `RecuperacaoDeAcessoPontaAPontaTest::${metodo}$`, 'tests/e2e/RecuperacaoDeAcessoPontaAPontaTest.php'], { cwd: RAIZ, encoding: 'utf8' });
const roda = (metodo) => { const r = phpunit(metodo); assert.equal(r.status, 0, r.stdout + r.stderr); assert.match(r.stdout, /OK \(1 test/); };

test('A resposta de esqueci é a mesma para conta que existe e que não existe', () => roda('testRespostaDeEsqueciIdenticaParaTodos'));
test('O e-mail de recuperação traz só o link e o token não fica em claro', () => roda('testEmailTemSoOLinkEOTokenNaoFicaEmClaro'));
test('Um link expirado não redefine a senha', () => roda('testLinkExpiradoNaoRedefine'));
test('Um novo pedido invalida o link anterior', () => roda('testNovoPedidoInvalidaOLinkAnterior'));
test('Depois de usar o link, entrar com a senha nova funciona e o link morre', () => roda('testEntrarComSenhaNovaEOLinkMorre'));
test('Pedidos em excesso não geram e-mails demais nem mudam a resposta', () => roda('testPedidosEmExcessoNaoGeramEmailsDemais'));
test('As telas públicas de recuperação protegem contra script em linha e vazamento do link', () => roda('testTelasPublicasProtegemContraScriptEVazamento'));
test('Um convite define a senha por link de uso único', () => roda('testConviteDefineSenhaPorLinkDeUsoUnico'));
