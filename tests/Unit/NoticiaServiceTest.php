<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Services/NoticiaService.php';

final class NoticiaServiceTest extends TestCase
{
    private const FEED = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/" xmlns:content="http://purl.org/rss/1.0/modules/content/">
<channel>
  <title>Portal</title>
  <item>
    <title>Ibovespa sobe 1% com alta de bancos</title>
    <link>https://portal.example/ibovespa-sobe</link>
    <pubDate>Wed, 25 Nov 2026 18:00:00 +0000</pubDate>
    <category><![CDATA[Mercados]]></category>
    <description><![CDATA[<p><img src="https://portal.example/img.jpg"/>O índice <b>subiu</b> puxado pelos bancos.</p>]]></description>
  </item>
  <item>
    <title>Time vence clássico no domingo</title>
    <link>https://portal.example/futebol</link>
    <category><![CDATA[Esportes]]></category>
    <description>Resultado do jogo.</description>
  </item>
  <item>
    <title>Dólar cai com fluxo estrangeiro</title>
    <link>https://portal.example/dolar</link>
    <category><![CDATA[Branded]]></category>
    <description>Conteúdo pago.</description>
  </item>
  <item>
    <title>Juros futuros recuam</title>
    <link>javascript:alert(1)</link>
    <description>Link inválido.</description>
  </item>
  <item>
    <title>Selic deve cair em 2027, diz banco</title>
    <link>https://portal.example/selic</link>
    <media:content url="https://portal.example/selic.jpg" medium="image"/>
    <description>Projeção para os juros.</description>
  </item>
</channel>
</rss>
XML;

    public function testInterpretaSoNoticiasDeMercadoComLinkSeguro(): void
    {
        $noticias = interpretarFeedRss(self::FEED, 'Portal');

        $this->assertSame(
            ['Ibovespa sobe 1% com alta de bancos', 'Selic deve cair em 2027, diz banco'],
            array_column($noticias, 'titulo')
        );
    }

    public function testLimpaHtmlEEncontraImagem(): void
    {
        [$ibovespa, $selic] = interpretarFeedRss(self::FEED, 'Portal');

        $this->assertSame('O índice subiu puxado pelos bancos.', $ibovespa['resumo']);
        $this->assertSame('https://portal.example/img.jpg', $ibovespa['imagem']);
        $this->assertSame('https://portal.example/selic.jpg', $selic['imagem']);
        $this->assertSame('2026-11-25T18:00:00Z', $ibovespa['publicada_em']);
        $this->assertSame('externa', $ibovespa['origem']);
    }

    public function testFeedInvalidoNaoQuebra(): void
    {
        $this->assertSame([], interpretarFeedRss('<html>erro</html>', 'Portal'));
    }

    public function testCombinaSemRepetirEOrdenaPorData(): void
    {
        $equipe = [['titulo' => 'Novidade no app', 'publicada_em' => '2026-11-20T10:00:00Z']];
        $externas = [
            ['titulo' => 'Dólar cai', 'publicada_em' => '2026-11-25T10:00:00Z'],
            ['titulo' => 'Dólar cai!', 'publicada_em' => '2026-11-24T10:00:00Z'],
        ];

        $resultado = combinarNoticias($equipe, $externas, 10);

        $this->assertSame(['Dólar cai', 'Novidade no app'], array_column($resultado, 'titulo'));
    }
}
