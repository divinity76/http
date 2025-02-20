<?php

declare(strict_types=1);

namespace Sabre\HTTP;

class SapiTest extends \PHPUnit\Framework\TestCase
{
    public function testConstructFromServerArray(): void
    {
        $request = Sapi::createFromServerArray([
            'REQUEST_URI' => '/foo',
            'REQUEST_METHOD' => 'GET',
            'HTTP_USER_AGENT' => 'Evert',
            'CONTENT_TYPE' => 'text/xml',
            'CONTENT_LENGTH' => '400',
            'SERVER_PROTOCOL' => 'HTTP/1.0',
        ]);

        self::assertEquals('GET', $request->getMethod());
        self::assertEquals('/foo', $request->getUrl());
        self::assertEquals([
            'User-Agent' => ['Evert'],
            'Content-Type' => ['text/xml'],
            'Content-Length' => ['400'],
        ], $request->getHeaders());

        self::assertEquals('1.0', $request->getHttpVersion());

        self::assertEquals('400', $request->getRawServerValue('CONTENT_LENGTH'));
        self::assertNull($request->getRawServerValue('FOO'));
    }

    public function testConstructFromServerArrayOnNullUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The _SERVER array must have a REQUEST_URI key');

        $request = Sapi::createFromServerArray([
            'REQUEST_METHOD' => 'GET',
            'HTTP_USER_AGENT' => 'Evert',
            'CONTENT_TYPE' => 'text/xml',
            'CONTENT_LENGTH' => '400',
            'SERVER_PROTOCOL' => 'HTTP/1.0',
        ]);
    }

    public function testConstructFromServerArrayOnNullMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The _SERVER array must have a REQUEST_METHOD key');

        $request = Sapi::createFromServerArray([
            'REQUEST_URI' => '/foo',
            'HTTP_USER_AGENT' => 'Evert',
            'CONTENT_TYPE' => 'text/xml',
            'CONTENT_LENGTH' => '400',
            'SERVER_PROTOCOL' => 'HTTP/1.0',
        ]);
    }

    public function testConstructPHPAuth(): void
    {
        $request = Sapi::createFromServerArray([
            'REQUEST_URI' => '/foo',
            'REQUEST_METHOD' => 'GET',
            'PHP_AUTH_USER' => 'user',
            'PHP_AUTH_PW' => 'pass',
        ]);

        self::assertEquals('GET', $request->getMethod());
        self::assertEquals('/foo', $request->getUrl());
        self::assertEquals([
            'Authorization' => ['Basic '.base64_encode('user:pass')],
        ], $request->getHeaders());
    }

    public function testConstructPHPAuthDigest(): void
    {
        $request = Sapi::createFromServerArray([
            'REQUEST_URI' => '/foo',
            'REQUEST_METHOD' => 'GET',
            'PHP_AUTH_DIGEST' => 'blabla',
        ]);

        self::assertEquals('GET', $request->getMethod());
        self::assertEquals('/foo', $request->getUrl());
        self::assertEquals([
            'Authorization' => ['Digest blabla'],
        ], $request->getHeaders());
    }

    public function testConstructRedirectAuth(): void
    {
        $request = Sapi::createFromServerArray([
            'REQUEST_URI' => '/foo',
            'REQUEST_METHOD' => 'GET',
            'REDIRECT_HTTP_AUTHORIZATION' => 'Basic bla',
        ]);

        self::assertEquals('GET', $request->getMethod());
        self::assertEquals('/foo', $request->getUrl());
        self::assertEquals([
            'Authorization' => ['Basic bla'],
        ], $request->getHeaders());
    }

    /**
     * @runInSeparateProcess
     *
     * Unfortunately we have no way of testing if the HTTP response code got
     * changed.
     */
    public function testSend(): void
    {
        if (!function_exists('xdebug_get_headers')) {
            $this->markTestSkipped('XDebug needs to be installed for this test to run');
        }

        $response = new Response(204, ['Content-Type' => 'text/xml;charset=UTF-8']);

        // Second Content-Type header. Normally this doesn't make sense.
        $response->addHeader('Content-Type', 'application/xml');
        $response->setBody('foo');

        ob_start();

        Sapi::sendResponse($response);
        $headers = xdebug_get_headers();

        $result = ob_get_clean();
        header_remove();

        self::assertEquals(
            [
                'Content-Type: text/xml;charset=UTF-8',
                'Content-Type: application/xml',
            ],
            $headers
        );

        self::assertEquals('foo', $result);
    }

    /**
     * @runInSeparateProcess
     *
     * @depends testSend
     */
    public function testSendLimitedByContentLengthString(): void
    {
        $response = new Response(200);

        $response->addHeader('Content-Length', 19);
        $response->setBody('Send this sentence. Ignore this one.');

        ob_start();

        Sapi::sendResponse($response);

        $result = ob_get_clean();
        header_remove();

        self::assertEquals('Send this sentence.', $result);
    }

    /**
     * Tests whether http2 is recognized.
     */
    public function testRecognizeHttp2(): void
    {
        $request = Sapi::createFromServerArray([
            'SERVER_PROTOCOL' => 'HTTP/2.0',
            'REQUEST_URI' => 'bla',
            'REQUEST_METHOD' => 'GET',
        ]);

        self::assertEquals('2.0', $request->getHttpVersion());
    }

    /**
     * @runInSeparateProcess
     *
     * @depends testSend
     */
    public function testSendLimitedByContentLengthStream(): void
    {
        $response = new Response(200, ['Content-Length' => 19]);

        $body = fopen('php://memory', 'w');
        fwrite($body, 'Ignore this. Send this sentence. Ignore this too.');
        rewind($body);
        fread($body, 13);
        $response->setBody($body);

        ob_start();

        Sapi::sendResponse($response);

        $result = ob_get_clean();
        header_remove();

        self::assertEquals('Send this sentence.', $result);
    }

    /**
     * @runInSeparateProcess
     *
     * @depends testSend
     *
     * @dataProvider sendContentRangeStreamData
     */
    public function testSendContentRangeStream(
        string $ignoreAtStart,
        string $sendText,
        int $multiplier,
        string $ignoreAtEnd,
        ?int $contentLength): void
    {
        $partial = str_repeat($sendText, $multiplier);
        $ignoreAtStartLength = strlen($ignoreAtStart);
        $ignoreAtEndLength = strlen($ignoreAtEnd);
        $body = fopen('php://memory', 'w');
        if (!$contentLength) {
            $contentLength = strlen($partial);
        }
        fwrite($body, $ignoreAtStart);
        fwrite($body, $partial);
        if ($ignoreAtEndLength > 0) {
            fwrite($body, $ignoreAtEnd);
        }
        rewind($body);
        if ($ignoreAtStartLength > 0) {
            fread($body, $ignoreAtStartLength);
        }
        $response = new Response(200, [
            'Content-Length' => $contentLength,
            'Content-Range' => sprintf('bytes %d-%d/%d', $ignoreAtStartLength, $ignoreAtStartLength + strlen($partial) - 1, $ignoreAtStartLength + strlen($partial) + $ignoreAtEndLength),
        ]);
        $response->setBody($body);

        ob_start();

        Sapi::sendResponse($response);

        $result = ob_get_clean();
        header_remove();

        self::assertEquals($partial, $result);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function sendContentRangeStreamData(): array
    {
        return [
            ['Ignore this. ', 'Send this.', 10, ' Ignore this at end.'],
            ['Ignore this. ', 'Send this.', 1000, ' Ignore this at end.'],
            ['Ignore this. ', 'S', 4096, ' Ignore this at end.'],
            ['I', 'S', 4094, 'E'],
            ['', 'Send this.', 10, ' Ignore this at end.'],
            ['', 'Send this.', 1000, ' Ignore this at end.'],
            ['', 'S', 4096, ' Ignore this at end.'],
            ['', 'S', 4094, 'En'],
            ['Ignore this. ', 'Send this.', 10, ''],
            ['Ignore this. ', 'Send this.', 1000, ''],
            ['Ignore this. ', 'S', 4096, ''],
            ['Ig', 'S', 4094, ''],

            // Provide contentLength greater than the bytes remaining in the stream.
            ['Ignore this. ', 'Send this.', 10, '', 101],
            ['Ignore this. ', 'Send this.', 1000, '', 10001],
            ['Ignore this. ', 'S', 4096, '', 5000000],
            ['I', 'S', 4094, '', 8095],
            // Provide contentLength equal to the bytes remaining in the stream.
            ['', 'Send this.', 10, '', 100],
            ['Ignore this. ', 'Send this.', 1000, '', 10000],
        ];
    }

    /**
     * @runInSeparateProcess
     *
     * @depends testSend
     */
    public function testSendWorksWithCallbackAsBody(): void
    {
        $response = new Response(200, [], function () {
            $fd = fopen('php://output', 'r+');
            fwrite($fd, 'foo');
            fclose($fd);
        });

        ob_start();

        Sapi::sendResponse($response);

        $result = ob_get_clean();

        self::assertEquals('foo', $result);
    }
}
