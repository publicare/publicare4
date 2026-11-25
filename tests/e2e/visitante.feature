# language: pt
Funcionalidade: Jornada do visitante no site de exemplo do Publicare 4
  Cobertura automatizada: tests/e2e/JornadaDoVisitanteTest.php (HTTP real contra o núcleo, sem navegador).
  Verificação visual (claro/escuro, 360/390/412, teclado): roteiro em notas/roteiro-visual.md (marco do dono).

  Contexto:
    Dado um site novo instalado com `demo-popular` na pele "publicare"

  Cenário: Da home a todas as páginas pelo menu e pelos links
    Quando o visitante abre "/" e segue todos os links internos alcançáveis
    Então toda página responde 200
    E nenhuma página contém "Instituto Exemplo"

  Cenário: Recursos da pele são servidos localmente
    Quando o visitante pede o CSS, o logotipo e os ícones citados pela home
    Então cada um responde 200 com o tipo certo
    E nenhuma página referencia host externo em src, href de estilo ou script

  Cenário: FAQ em acordeão sem JavaScript
    Quando o visitante abre "/perguntas-frequentes"
    Então vê de 8 a 12 perguntas em elementos details
    E a página não tem script nem estilo embutido

  Cenário: Página inexistente
    Quando o visitante abre "/isto-nao-existe"
    Então recebe 404 dentro da pele, com cabeçalho, rodapé e noindex

  Cenário: Busca
    Quando o visitante busca "moldes"
    Então recebe 200 com resultados na pele

  Cenário: Cabeçalhos de segurança
    Quando o visitante abre qualquer página
    Então a política de conteúdo proíbe scripts e estilos embutidos
