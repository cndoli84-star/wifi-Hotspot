<?php

class RouterosAPI
{
    private $ip;
    private $port;
    private $user;
    private $pass;
    private $socket;

    public function connect($ip, $user, $pass, $port = 8728)
    {
        $this->ip = $ip;
        $this->user = $user;
        $this->pass = $pass;
        $this->port = $port;

        $this->socket = fsockopen($ip, $port, $errno, $errstr, 5);

        if (!$this->socket) {
            die("MikroTik connection failed: $errstr");
        }

        $this->write("/login");
        $this->write("=name=" . $user);
        $this->write("=password=" . $pass);

        $response = $this->read();

        return true;
    }

    public function write($command)
    {
        fwrite($this->socket, $command . "\n");
    }

    public function read()
    {
        return fgets($this->socket);
    }

    public function disconnect()
    {
        fclose($this->socket);
    }
}