<?php
require_once 'vendor/autoload.php';

use React\EventLoop\Loop;
use React\Datagram\Socket;
use React\Datagram\Factory;

class SimpleStunServer {
    private $loop;
    private $socket;
    private $clients = [];

    public function __construct($host = '0.0.0.0', $port = 3478) {
        $this->loop = Loop::get();
        $this->startServer($host, $port);
    }

    private function startServer($host, $port) {
        $factory = new Factory($this->loop);
        
        $factory->createServer($host . ':' . $port)->then(
            function (Socket $socket) {
                $this->socket = $socket;
                echo "STUN Server started on " . $socket->getLocalAddress() . "\n";
                
                $socket->on('message', function ($message, $address, $socket) {
                    $this->handleStunMessage($message, $address, $socket);
                });
                
                $socket->on('error', function ($error, $socket) {
                    echo "Error: " . $error->getMessage() . "\n";
                });
            },
            function (\Exception $error) {
                echo "Error starting server: " . $error->getMessage() . "\n";
            }
        );
    }

    private function handleStunMessage($message, $address, $socket) {
        echo "[" . date('H:i:s') . "] STUN request from: $address\n";
        
        // Простая обработка STUN binding request
        if ($this->isStunBindingRequest($message)) {
            $response = $this->createStunBindingResponse($message, $address);
            $socket->send($response, $address);
            echo "Sent STUN response to: $address\n";
        }
    }

    private function isStunBindingRequest($message) {
        // Проверяем что это STUN Binding Request (тип 0x0001)
        return strlen($message) >= 20 && 
               unpack('n', substr($message, 0, 2))[1] === 0x0001;
    }

    private function createStunBindingResponse($request, $clientAddress) {
        // Берем transaction ID из запроса
        $transactionId = substr($request, 4, 12);
        
        // Создаем STUN Binding Success Response (тип 0x0101)
        $response = pack('n', 0x0101); // Message Type
        $response .= pack('n', 0);     // Message Length (пока 0)
        $response .= pack('n', 0x2112); // Magic Cookie
        $response .= pack('n', 0xA442);
        $response .= $transactionId;
        
        // Добавляем XOR-MAPPED-ADDRESS attribute
        $xorMappedAddress = $this->createXorMappedAddress($clientAddress, $response);
        $response .= $xorMappedAddress;
        
        // Обновляем длину сообщения
        $length = strlen($response) - 20;
        $response = substr_replace($response, pack('n', $length), 2, 2);
        
        return $response;
    }

    private function createXorMappedAddress($address, $response) {
        list($ip, $port) = explode(':', $address);
        
        // XOR с magic cookie
        $xorPort = $port ^ 0x2112;
        $ipParts = explode('.', $ip);
        $xorIp = '';
        
        foreach ($ipParts as $i => $part) {
            $xorIp .= chr($part ^ ord(substr($response, 4 + $i, 1)));
        }
        
        $attribute = pack('n', 0x0020); // XOR-MAPPED-ADDRESS
        $attribute .= pack('n', 8);     // Length
        $attribute .= pack('C', 0);     // Reserved
        $attribute .= pack('C', 1);     // IPv4 family
        $attribute .= pack('n', $xorPort);
        $attribute .= $xorIp;
        
        return $attribute;
    }

    public function run() {
        echo "STUN Server is running...\n";
        echo "Press Ctrl+C to stop\n";
        
        $this->loop->run();
    }
}

// Запуск сервера
$server = new SimpleStunServer();
$server->run();
?>