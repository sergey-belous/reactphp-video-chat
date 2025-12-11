<?php
// server.php
require __DIR__.'/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\WebSocket\WsServer;
use React\Socket\SecureServer;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use React\Socket\SocketServer;
use React\EventLoop\Loop;

class SignalingServer implements MessageComponentInterface {
    protected $clients;
    protected $rooms = [];

    public function __construct() {
        $this->clients = new \SplObjectStorage;
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "New connection: {$conn->resourceId}\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        if (!$data) return;

        $type = $data['type'] ?? null;
        $room = $data['room'] ?? 'default';
        $userId = $data['userId'] ?? null;

        switch ($type) {
            case 'join':
                $this->rooms[$room][$userId] = $from;
                // Отправить новому список других участников
                $others = array_keys($this->rooms[$room]);
                $others = array_filter($others, fn($id) => $id !== $userId);
                $from->send(json_encode([
                    'type' => 'peers',
                    'peers' => array_values($others)
                ]));
                // Сообщить остальным, что кто-то присоединился
                $this->broadcast($room, [
                    'type' => 'user-joined',
                    'userId' => $userId
                ], $from);
                break;

            case 'offer':
            case 'answer':
            case 'candidate':
                $target = $data['target'];
                if (isset($this->rooms[$room][$target])) {
                    $this->sendTo($this->rooms[$room][$target], [
                        'type' => $type,
                        'sender' => $userId,
                        'data' => $data['data']
                    ]);
                }
                break;

            case 'leave':
                unset($this->rooms[$room][$userId]);
                $this->broadcast($room, [
                    'type' => 'user-left',
                    'userId' => $userId
                ]);
                break;
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        echo "Connection closed: {$conn->resourceId}\n";
        
        // Cleanup rooms
        foreach ($this->rooms as $room => $users) {
            foreach ($users as $userId => $client) {
                if ($client === $conn) {
                    unset($this->rooms[$room][$userId]);
                    $this->broadcast($room, [
                        'type' => 'user-left',
                        'userId' => $userId
                    ]);
                }
            }
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "Error: {$e->getMessage()}\n";
        $conn->close();
    }

    private function broadcast($room, $message, $exclude = null) {
        if (!isset($this->rooms[$room])) return;

        foreach ($this->rooms[$room] as $client) {
            if ($client !== $exclude) {
                $client->send(json_encode($message));
            }
        }
    }

    private function sendTo(ConnectionInterface $client, $message) {
        $client->send(json_encode($message));
    }
}

$sslContext = [
    'local_cert' => __DIR__ . '/ssl/cert.pem',     // Путь к SSL сертификату
    'local_pk' => __DIR__ . '/ssl/privkey.pem',   // Путь к приватному ключу
    'allow_self_signed' => true,                  // Разрешить самоподписанные сертификаты
    'verify_peer' => false,                       // Отключить проверку peer (для разработки)
    'verify_peer_name' => false                   // Отключить проверку имени
];

$loop = Loop::get();

$socket = new SocketServer('0.0.0.0:8080', [], $loop);
// Закомментируем SSL-сервер — можно включить при наличии сертификатов
// $socket = new SecureServer($socket, $loop, $sslContext);

$server = new IoServer(
    new HttpServer(
        new WsServer(
            new SignalingServer()
        )
    ),
    $socket,
    $loop
);

$server->run();