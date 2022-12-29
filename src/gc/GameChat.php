<?php

class GameChat {

    private string $address;
    private        $connect;
    private string $chat_file;
    private array  $chat_message         = [];
    private int    $chat_message_count   = 0;
    private int    $last_message_id_chat = 0;
    /**
     * Клиент поддерживает ли добавление в чат предмета
     * На старых клиентах ниже Interlude ставить false, если выше то true
     *
     * @var bool
     */
    private bool $item_shift_show = true;
    /**
     * Таймаут повторного обновлени чата (в сек)
     *
     * @var int
     */
    public int $second_new_update = 2;
    /**
     * Последнее время чтения файла лога
     *
     * @var int
     */
    private int $last_time_update_chat = 0;
    /**
     * Вес чата, будет контролировать изменения в чате по изменению его размера
     *
     * @var int
     */
    private int $chat_size = -1;
    /**
     * Пароль для получения списка всех сообщений
     *
     * @var string
     */
    private string $password = "";

    /**
     * @param string $address
     *
     * @return void
     */
    public function address(string $address = "tcp://localhost:17859"): void {
        $this->address = $address;
    }

    function start() {
        $socket = stream_socket_server($this->address, $errno, $errstr);
        if(!$socket) {
            die("$errstr ($errno)\n");
        }
        printf("Chat Server: %s\n", $this->address);
        $connects = [];
        while(true) {
            $read = $connects;
            $read [] = $socket;
            $write = $except = null;

            if(!stream_select($read, $write, $except, null)) {//ожидаем сокеты доступные для чтения (без таймаута)
                break;
            }

            if(in_array($socket, $read)) {
                if(($this->connect = stream_socket_accept($socket, -1)) && $info = $this->handshake($this->connect)) {
                    $connects[] = $this->connect;
                    $this->onOpen($info);
                }
                unset($read[array_search($socket, $read)]);
            }
            $this->assay_chat();
            foreach($read as $this->connect) {
                $data = fread($this->connect, 100000);
                if(!$data) {
                    //Закрывается скрипт
                    //                    fclose($this->connect);
                    //                    unset($connects[array_search($this->connect, $connects)]);
                    //                    $this->onClose($this->connect);
                    continue;
                }
                $this->onMessage($data, $info);
            }
        }
    }

    private function handshake($connect) {
        $info = [];

        $line = fgets($connect);
        $header = explode(' ', $line);
        $info['method'] = $header[0];
        $info['uri'] = $header[1];

        //считываем заголовки из соединения
        while($line = rtrim(fgets($connect))) {
            if(preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
                $info[$matches[1]] = $matches[2];
            } else {
                break;
            }
        }

        $address = explode(':', stream_socket_get_name($connect, true)); //получаем адрес клиента
        $info['ip'] = $address[0];
        $info['port'] = $address[1];

        if(empty($info['Sec-WebSocket-Key'])) {
            return false;
        }
        $SecWebSocketAccept = base64_encode(pack('H*', sha1($info['Sec-WebSocket-Key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
        $upgrade = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" . "Upgrade: websocket\r\n" . "Connection: Upgrade\r\n" . "Sec-WebSocket-Accept:$SecWebSocketAccept\r\n\r\n";
        fwrite($connect, $upgrade);

        return $info;
    }

    private function encode($payload, $type = 'text', $masked = false) {
        $frameHead = [];
        $payloadLength = strlen($payload);

        switch($type) {
            case 'text':
                // first byte indicates FIN, Text-Frame (10000001):
                $frameHead[0] = 129;
                break;

            case 'close':
                // first byte indicates FIN, Close Frame(10001000):
                $frameHead[0] = 136;
                break;

            case 'ping':
                // first byte indicates FIN, Ping frame (10001001):
                $frameHead[0] = 137;
                break;

            case 'pong':
                // first byte indicates FIN, Pong frame (10001010):
                $frameHead[0] = 138;
                break;
        }

        // set mask and payload length (using 1, 3 or 9 bytes)
        if($payloadLength > 65535) {
            $payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
            $frameHead[1] = ($masked === true) ? 255 : 127;
            for($i = 0; $i < 8; $i++) {
                $frameHead[$i + 2] = bindec($payloadLengthBin[$i]);
            }
            // most significant bit MUST be 0
            if($frameHead[2] > 127) {
                return [
                    'type'    => '',
                    'payload' => '',
                    'error'   => 'frame too large (1004)',
                ];
            }
        } elseif($payloadLength > 125) {
            $payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);
            $frameHead[1] = ($masked === true) ? 254 : 126;
            $frameHead[2] = bindec($payloadLengthBin[0]);
            $frameHead[3] = bindec($payloadLengthBin[1]);
        } else {
            $frameHead[1] = ($masked === true) ? $payloadLength + 128 : $payloadLength;
        }

        // convert frame-head to string:
        foreach(array_keys($frameHead) as $i) {
            $frameHead[$i] = chr($frameHead[$i]);
        }
        if($masked === true) {
            // generate a random mask:
            $mask = [];
            for($i = 0; $i < 4; $i++) {
                $mask[$i] = chr(rand(0, 255));
            }

            $frameHead = array_merge($frameHead, $mask);
        }
        $frame = implode('', $frameHead);

        // append payload to frame:
        for($i = 0; $i < $payloadLength; $i++) {
            $frame .= ($masked === true) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
        }

        return $frame;
    }

    private function decode($data) {
        $unmaskedPayload = '';
        $decodedData = [];
        // estimate frame type:
        $firstByteBinary = sprintf('%08b', ord($data[0]));
        $secondByteBinary = sprintf('%08b', ord($data[1]));
        $opcode = bindec(substr($firstByteBinary, 4, 4));
        $isMasked = $secondByteBinary[0] == '1';
        $payloadLength = ord($data[1]) & 127;

        // unmasked frame is received:
        if(!$isMasked) {
            return [
                'type'    => '',
                'payload' => '',
                'error'   => 'protocol error (1002)',
            ];
        }

        switch($opcode) {
            // text frame:
            case 1:
                $decodedData['type'] = 'text';
                break;

            case 2:
                $decodedData['type'] = 'binary';
                break;

            // connection close frame:
            case 8:
                $decodedData['type'] = 'close';
                break;

            // ping frame:
            case 9:
                $decodedData['type'] = 'ping';
                break;

            // pong frame:
            case 10:
                $decodedData['type'] = 'pong';
                break;

            default:
                return [
                    'type'    => '',
                    'payload' => '',
                    'error'   => 'unknown opcode (1003)',
                ];
        }

        if($payloadLength === 126) {
            $mask = substr($data, 4, 4);
            $payloadOffset = 8;
            $dataLength = bindec(sprintf('%08b', ord($data[2])) . sprintf('%08b', ord($data[3]))) + $payloadOffset;
        } elseif($payloadLength === 127) {
            $mask = substr($data, 10, 4);
            $payloadOffset = 14;
            $tmp = '';
            for($i = 0; $i < 8; $i++) {
                $tmp .= sprintf('%08b', ord($data[$i + 2]));
            }
            $dataLength = bindec($tmp) + $payloadOffset;
            unset($tmp);
        } else {
            $mask = substr($data, 2, 4);
            $payloadOffset = 6;
            $dataLength = $payloadLength + $payloadOffset;
        }

        /**
         * We have to check for large frames here. socket_recv cuts at 1024 bytes
         * so if websocket-frame is > 1024 bytes we have to wait until whole
         * data is transferd.
         */
        if(strlen($data) < $dataLength) {
            return false;
        }

        if($isMasked) {
            for($i = $payloadOffset; $i < $dataLength; $i++) {
                $j = $i - $payloadOffset;
                if(isset($data[$i])) {
                    $unmaskedPayload .= $data[$i] ^ $mask[$j % 4];
                }
            }
            $decodedData['payload'] = $unmaskedPayload;
        } else {
            $payloadOffset = $payloadOffset - 4;
            $decodedData['payload'] = substr($data, $payloadOffset);
        }

        return $decodedData;
    }

    //пользовательские сценарии:

    public function set_chat_file($filename): void {
        $this->chat_file = $filename;
    }

    //Анализ чата
    //TODO: Возможно добавить проверку обновления веса файла НЕ чаще чем каждую 1 секунду
    private function assay_chat(): void {
        if(time() >= $this->last_time_update_chat + $this->second_new_update) {
            $chat_size = filesize($this->chat_file);
            clearstatcache();
            if($this->chat_size < $chat_size) {
                $filechat = file($this->chat_file);
                foreach(array_slice($filechat, (int)('-' . abs(count($filechat) - $this->last_message_id_chat))) as $id => $message) {
                    $pattern = '/\[(.*)\]\s(.*)\[(.*)\]\s(.*)/';
                    preg_match($pattern, $message, $sms);
                    if($sms == null) {
                        $last_message = end($this->chat_message);
                        $this->chat_message[] = [
                            'id'      => $this->last_message_id_chat + $id + 1,
                            'time'    => $last_message['time'],
                            'type'    => $last_message['type'],
                            'player'  => $last_message['player'],
                            'message' => $this->check_shift_item($last_message['message']),
                        ];
                    } else {
                        $this->chat_message[] = [
                            'id'      => $this->last_message_id_chat + $id + 1,
                            'time'    => trim($sms[1]),
                            'type'    => mb_strtolower(trim($sms[2])),
                            'player'  => trim($sms[3]),
                            'message' => $this->check_shift_item(htmlspecialchars((trim($sms[4])))),
                        ];
                    }
                    $this->last_time_update_chat = time();
                }
                $this->last_message_id_chat = end($this->chat_message)['id'];
                $this->chat_size = $chat_size;
                $this->chat_message_count = count($this->chat_message);
            }
        }
    }

    private function onOpen($info) {
        printf("Соединение с пользователем установлено\n");
        //        echo "open\n";
        //        fwrite($connect, $this->encode('Соединение установлено!'));
    }

    /**
     * В чате игры можно ли вставлять в чат предмет
     *
     * @return bool
     */
    private function isItemShiftShow(): bool {
        return $this->item_shift_show;
    }

    /**
     * В чате игры можно ли вставлять в чат предмет
     *
     * @param bool $item_shift_show
     */
    public function setItemShiftShow(bool $item_shift_show): void {
        $this->item_shift_show = $item_shift_show;
    }

    /**
     * @return string
     */
    private function get_client_password(): string {
        return $this->client_password;
    }

    private function set_client_password($client_password): void {
        $this->client_password = $client_password;
    }

    private function getPassword(): string {
        return $this->password;
    }

    /**
     * @param string $password
     */
    public function setPassword(string $password): void {
        if(mb_strlen($password) <= 32) {
            die("============================
ERROR [EN]: Password must contain at least 32 characters.
ERROR [RU]: Пароль должен быть от 32 символов.
============================");
        }
        $this->password = $password;
    }

    private function onClose($connect) {
        echo "close\n";
    }

    private function onMessage($data, $info): void {
        $client_command = explode(":", $this->decode($data)['payload']);
        //Проверка админа
        //Обычный пользователь должен слать пустую строку
        //TODO: Если пароль неверны, блокируем IP на N минут...
        if($client_command[0] == "password") {
            $this->set_client_password($client_command[1]);
            $client_command = array_slice($client_command, 2);
        }
        $this->commander($client_command, $info);
    }

    private function commander($client_command, $info): void {
        $name = $client_command[0];
        switch($name) {
            case "last":
                $this->last_message($client_command[1], $info);
                break;
            case "refresh":
                $this->refresh_message($client_command[1], $info);
                break;
            case "player":
                $this->find_player_message($client_command[1], $info);
                break;
            case "text":
                $this->find_text($client_command[1], $info);
                break;
        }
    }

    private function last_message($count_last_message = 30, $info = []): void {
//        $output = array_slice($this->chat_message, -$count_last_message);
        $output = array_reverse($this->chat_message, true);
        $last_chat_message = [];
        foreach($output as $message) {
            if($this->allow_user_type_message($message['type'])) {
                $last_chat_message[] = $message;
            }
            if(count($last_chat_message)>=$count_last_message){
                break;
            }
        }
        if(!empty($last_chat_message)) {
            fwrite($this->connect, $this->encode(json_encode([
                'last_chat_message'    => array_reverse($last_chat_message),
//                'last_chat_message'    => ($last_chat_message),
                'last_chat_message_id' => end($this->chat_message)['id'],
            ])));
        }
    }

    //Последние сообщения
    private function refresh_message($last_message_id, $info): void {
        $this->assay_chat();
        if($this->chat_message_count > $last_message_id) {
            printf("Обновление чата [+%d]\n", $this->chat_message_count - $last_message_id);
            $last_chat_message = [];
            foreach(array_slice($this->chat_message, $last_message_id, $this->chat_message_count) as $message) {
                if($this->allow_user_type_message($message['type'])) {
                    $last_chat_message[] = $message;
                }
            }
            fwrite($this->connect, $this->encode(json_encode([
                'last_chat_message'    => $last_chat_message,
                'last_chat_message_id' => end($this->chat_message)['id'],
            ])));
        }
    }

    //Последние сообщения пользователя
    private function find_player_message($player_name, $info): void {
        if(!$this->is_admin()) {
            return;
        }
        $this->assay_chat();
        printf("Получение последних сообщений персонажа %s\n", $player_name);
        $last_chat_message = [];
        foreach($this->chat_message as $chat) {
            if(mb_strtolower($chat['player']) == mb_strtolower($player_name)) {
                $last_chat_message[] = $chat;
            }
        }
        fwrite($this->connect, $this->encode(json_encode([
            'last_chat_message'    => $last_chat_message,
        ])));
    }

    //Последние сообщения пользователя
    private function find_text($text, $info): void {
        if(!$this->is_admin()) {
            return;
        }
        $this->assay_chat();
        printf("Получение последних сообщений «%s»\n", $text);
        $last_chat_message = [];
        foreach($this->chat_message as $chat) {
             if(str_contains($chat['message'], $text)) {
                $last_chat_message[] = $chat;
            }
        }
        printf("Найдено «%d»\n", count($last_chat_message));
        fwrite($this->connect, $this->encode(json_encode([
            'last_chat_message'    => $last_chat_message,
        ])));
    }

    //Проверка если пользователь шифтует предмет
    private function check_shift_item($message) {
        if($this->isItemShiftShow()) {
            $pattern = "/Type=(\d+)(\s+)ID=(\d+)(\s+)Color=(\d+)(\s+)Underline=(\d+)(\s+)Title=(.*)\z/";
            if(preg_match($pattern, $message, $arr)) {
                return trim(end($arr));
            }
        }
        return $message;
    }

    /**
     * Разрешенный список типо сообщений, который будем отправлять пользователю
     */
    private function allow_user_type_message($type): bool {
        if($this->is_admin()) {
            return true;
        }
        $allow_type = [
            "all",
            "shout",
            "trade",
        ];
        if(in_array($type, $allow_type)) {
            return true;
        }
        return false;
    }

    private function is_admin(): bool {
        return $this->get_client_password() == $this->getPassword();
    }
}
