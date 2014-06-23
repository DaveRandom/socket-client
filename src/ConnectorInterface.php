<?php

namespace React\SocketClient;

use React\Promise\Promise;
use React\Uri\Uri;

interface ConnectorInterface
{
    /**
     * Create a new connection
     *
     * @param string|Uri $uri Local address of the listen socket
     * @param array|resource $ctx An array of context options or a context/stream
     * @return Promise
     */
    public function create($uri, $ctx);
}
