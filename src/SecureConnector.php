<?php

namespace React\SocketClient;

use React\EventLoop\LoopInterface;
use React\Stream\Stream;
use React\Uri\Uri;

class SecureConnector implements ConnectorInterface
{
    private $connector;
    private $streamEncryption;
    private $cryptoMethods;

    public function __construct(ConnectorInterface $connector, LoopInterface $loop)
    {
        $this->connector = $connector;
        $this->streamEncryption = new StreamEncryption($loop);
        $this->cryptoMethods = $this->getSupportedCryptoMethods();
    }

    private function getSupportedCryptoMethods()
    {
        $result = [
            // any supported protocol version, SSL or TLS
            'ssl' => defined('STREAM_CRYPTO_METHOD_ANY_CLIENT')
                    ? STREAM_CRYPTO_METHOD_ANY_CLIENT // PHP>=5.6.0
                    : STREAM_CRYPTO_METHOD_SSLv23_CLIENT,

            // any supported TLS version
            'tls' => STREAM_CRYPTO_METHOD_TLS_CLIENT,

            // specific protocol versions
            'sslv2' => STREAM_CRYPTO_METHOD_SSLv2_CLIENT,
            'sslv3' => STREAM_CRYPTO_METHOD_SSLv3_CLIENT,
            'tlsv1.0' => defined('STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT')
                    ? STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT // PHP>=5.6.0
                    : STREAM_CRYPTO_METHOD_TLS_CLIENT,
        ];

        if (defined('STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT')) { // PHP>=5.6.0
            $result['tlsv1.1'] = STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
        }

        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) { // PHP>=5.6.0
            $result['tlsv1.2'] = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }

        return $result;
    }

    private function createContextOpts(Uri $uri, $ctx)
    {
        if (!is_resource($ctx)) {
            $options = (array) $ctx;
        } else if (false === $options = stream_context_get_options($ctx)) {
            $options = [];
        }

        if (!isset($options['ssl']['crypto_method'])) {
            // creating streams directly through OpenSSL is blocking, so find the method constant instead

            if (!isset($this->cryptoMethods[$uri->scheme])) {
                throw new UnsupportedUriSchemeException($uri->scheme . ':// URIs are not supported by this connector');
            }

            $options['ssl']['crypto_method'] = $this->cryptoMethods[$uri->scheme];
        }

        if (!isset($options['ssl']['peer_name'])) {
            // required in 5.6, because we resolve DNS ourselves
            $options['ssl']['peer_name'] = $uri->host;
        }

        return $options;
    }

    public function create($uri, $ctx)
    {
        if (!$uri instanceof Uri) {
            $uri = new Uri($uri);
        }

        $tcpUri = $uri->getConnectionString(['scheme', 'host', 'port'], ['scheme' => 'tcp']);
        $ctxOpts = $this->createContextOpts($uri, $ctx);

        return $this->connector->create($tcpUri, $ctxOpts)
            ->then(function (Stream $stream) {
                // (unencrypted) connection succeeded => try to enable encryption
                return $this->streamEncryption->enable($stream)
                    ->then(null, function ($error) use ($stream) {
                        // establishing encryption failed => close invalid connection and return error
                        $stream->close();
                        throw $error;
                    });
            });
    }
}
