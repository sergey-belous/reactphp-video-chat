<?php
// server.php
require __DIR__.'/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Factory;
use React\Socket\Server as Reactor;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;

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

$server = IoServer::factory(
        new HttpServer(
            new WsServer(
                new SignalingServer()
            )
        ),
        8080
    );

$server->run();