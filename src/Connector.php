<?php

namespace React\SocketClient;

use React\EventLoop\LoopInterface;
use React\Dns\Resolver\Resolver;
use React\Stream\Stream;
use React\Promise;
use React\Promise\Deferred;
use React\Uri\Uri;

class Connector implements ConnectorInterface
{
    private $loop;
    private $resolver;

    public function __construct(LoopInterface $loop, Resolver $resolver)
    {
        $this->loop = $loop;
        $this->resolver = $resolver;
    }

    public function create($uri, $ctx = [])
    {
        if (!$uri instanceof Uri) {
            $uri = new Uri($uri);
        }

        if ($uri->scheme === 'tcp') {
            return $this
                ->resolveHostname($uri)
                ->then(function ($host) use ($uri, $ctx) {
                    $address = $uri->getConnectionString(['scheme', 'host', 'port'], ['host' => $host]);
                    return $this->createSocketForAddress($address, $ctx);
                });
        } else if ($uri->scheme === 'unix') {
            $address = $uri->getConnectionString(['scheme', 'path']);
            return $this->createSocketForAddress($address, $ctx);
        }

        throw new UnsupportedUriSchemeException($uri->scheme . ':// URIs are not supported by this connector');
    }

    public function createSocketForAddress($address, $ctx)
    {
        $flags = STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT;
        $socket = stream_socket_client($address, $errno, $errstr, 0, $flags, $ctx);

        if (!$socket) {
            return Promise\reject(new \RuntimeException(
                sprintf("connection to %s failed: %s", $address, $errstr),
                $errno
            ));
        }

        stream_set_blocking($socket, 0);

        // wait for connection

        return $this
            ->waitForStreamOnce($socket)
            ->then(array($this, 'checkConnectedSocket'))
            ->then(array($this, 'handleConnectedSocket'));
    }

    protected function waitForStreamOnce($stream)
    {
        $deferred = new Deferred();

        $loop = $this->loop;

        $this->loop->addWriteStream($stream, function ($stream) use ($loop, $deferred) {
            $loop->removeWriteStream($stream);

            $deferred->resolve($stream);
        });

        return $deferred->promise();
    }

    public function checkConnectedSocket($socket)
    {
        // The following hack looks like the only way to
        // detect connection refused errors with PHP's stream sockets.
        if (false === stream_socket_get_name($socket, true)) {
            return Promise\reject(new ConnectionException('Connection refused'));
        }

        return Promise\resolve($socket);
    }

    public function handleConnectedSocket($socket)
    {
        return new Stream($socket, $this->loop);
    }

    protected function resolveHostname($uri)
    {
        if ($uri->hostType & Uri::HOSTTYPE_IP) {
            return Promise\resolve($uri->host);
        }

        return $this->resolver->resolve($uri->host);
    }
}
