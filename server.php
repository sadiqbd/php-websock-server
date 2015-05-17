<?php

class wsocket{

    protected $showMessage = true;
    
    protected $maxBufferSize;        
    protected $sockets  = array();
    protected $users    = array();
    protected $inActiveTimeToSendPing = 30;
    
    private $lastPingBroadCastTime = 0;
            
   function __construct($addr, $port, $bufferLength = 2048) {
        $this->maxBufferSize = $bufferLength;
        $this->sockets['parent'] = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)  or die("Failed: socket_create()\n");
        socket_set_option($this->sockets['parent'], SOL_SOCKET, SO_REUSEADDR, 1) or die("Failed: socket_option()\n");
        socket_bind($this->sockets['parent'], $addr, $port)                      or die("Failed: socket_bind()\n");
        socket_listen($this->sockets['parent'],20)                               or die("Failed: socket_listen()\n");
        $this->message("Server started\nListening on: $addr:$port\nParent socket: ".$this->sockets['parent']);
    }
    
    function run()
    {
        while(true)  {
            
            $this->broadCastPing();
            
            $read = $this->sockets;
            $write = null;
            $except = null;
            @socket_select($read,$write,$except,null);

            foreach($read as $socket)
            {
                if($socket == $this->sockets['parent'])
                {
                    $client = socket_accept($socket);
                    if ($client < 0) {
                        $this->message("Failed: socket_accept()");
                        continue;
                    }else{
                        $this->acceptUser($client);
                        $this->message("New user connected: ".$client);
                        continue;
                    } 
                }else{
                    $bytes = @socket_recv($socket,$buffer,$this->maxBufferSize,0);
                    
                    if ($bytes === false) {
                        $this->message('Socket error: ' . socket_strerror(socket_last_error($socket)));
                    }elseif(($bytes == 0)){
                        $this->disconnectUserBySocket($socket);
                        $this->message("Client disconnected. TCP connection lost: " . $socket);  
                    }else{
			$user = $this->findUserBySocket($socket);
			if(!$user->isReady)
                        {
                            $this->handshake($user, $buffer);
                        }else{
                            $data = $this->parseFrame($buffer, $user);
                            $this->handle($user, $data);
                        }
                    }
                }
            }
        }
    }

    function handle($user, $data)
    {
        switch($data['opcode'])
        {
            case 1:
                break;
            case 8:
                $this->message("Received close opcode.");
                $this->disconnectUser($user);
                break;
            case 9:
                $this->message("Received ping.");
                $this->pong($user, $data['message']);
                break;
            case 10:
                $this->message("Received pong.");
                break;
            default:
                $this->message("Unsupported opcode.");
                return false;
                break;
        }
        
        if($data['message'] == "send_ping")
        {
            
            $this->ping($user);
        }
        
        if($data['message'] == "send_pong")
        {
            $this->pong($user,"pong");
        }
        
        if($data['message'] == "send_close")
        {
            $this->disconnectUser($user);
        }
        
        $this->message($data['message']);
        
    }
    
    function handshake($user, $buffer){
        $this->message("Requesting handshake...");
        $this->message($buffer);
        $data = $this->parseHeader($buffer, $user);

        $this->message("Handshaking...");

        if(isset($data['Sec-WebSocket-Key']) && !empty($data['Sec-WebSocket-Key']))
        {
            $upgrade  = "HTTP/1.1 101 Switching Protocols\r\n" .
                        "Upgrade: websocket\r\n" .
                        "Connection: Upgrade\r\n" .
                        "Sec-WebSocket-Accept: " . base64_encode(hex2bin(sha1($data['Sec-WebSocket-Key']."258EAFA5-E914-47DA-95CA-C5AB0DC85B11"))) . "\r\n" .
                        "\r\n";
        }else{
            $this->disconnectUser($user->socket);
        }
        socket_write($user->socket,$upgrade,strlen($upgrade));
        $user->isReady=true;
        $user->headers = $data;
        $this->message("Done handshaking...");
        return true;
    }

    function parseHeader($req, $user){
        
        $final = array();
        
        if(socket_getpeername($user->socket, $ip_address))
        {
            $final['client_ip'] = $ip_address;
        }else{
            $final['client_ip'] = NULL;
        }
        
        $data = explode("\r\n\r\n", $req,2);
        
        $headersLine = explode("\r\n", $data[0]);
        
        $firstLineData = explode(" ",$headersLine[0]);
        $final['method'] = $firstLineData[0];
        $final['path'] = $firstLineData[1];
        unset($headersLine[0]);
        
        foreach($headersLine as $line)
        {
            $lineData = explode(" ", $line, 2);
            $final[rtrim($lineData[0],":")] = $lineData[1];
        }
        
        
        $final['body'] = $data[1];
        
        return $final;
    }

    function acceptUser($socket) {
        $user = new stdClass();
        $user->id = uniqid("user");
        $user->socket = $socket;
        $user->isReady = false;
        $user->lastActive = time();
        
        $this->users[$user->id] = $user;
        $this->sockets[$user->id] = $socket;
        
    }
    
    function disconnectUserBySocket($socket)
    {
            $user = $this->findUserBySocket($socket);
            return $this->disconnectUser($user);
    }
	
    function disconnectUser($user)
    {
        $this->send("Close connection",$user,"close");
        socket_close($this->users[$user->id]->socket);
        $this->message($user->id." disconnected. ".$this->users[$user->id]->socket);
	unset($this->users[$user->id]);
    }
    
    function findUserBySocket($socket)
    {
        foreach($this->users as $user)
        {
            if($user->socket == $socket)
                return $user;
        }
    }
    
    
    function message($message) {
        if ($this->showMessage) {
            echo $message."\n";
        }
    }
    
    private function broadCastPing()
    {
        
        if( ($this->lastPingBroadCastTime + $this->inActiveTimeToSendPing) > time() )
        {
            return;
        }else{
            foreach($this->users as $user)
            {
                if( ($user->lastActive + $this->inActiveTimeToSendPing ) < time() )
                {
                    $this->ping($user);
                }
            }
            
            $this->lastPingBroadCastTime = time();
        }
        
    }
    
    private function ping($user)
    {
        $this->message("Sending PING to ".$user->id);
        
        $text = "are you there?";      
        $this->send($text, $user, "ping");
    }
 
    private function pong($user, $message)
    {
        $this->message("Sending PONG to ".$user->id);

        $this->send($message, $user, "pong");
    }
    
    
    private function send($message, $user, $type="text")
    {
        @socket_write($user->socket, $this->frame($message, $type));
    }

    function frame($text, $type="text")
    {
        switch($type)
        {
            case "text":
                $b1 = 0x80 | (0x1 & 0x0f);
                break;
            case "ping":
                $b1 = 0x80 | 0x09;
                break;
            case "pong":
                $b1 = 0x80 | 0xA;
                break;
            case "close":
                $b1 = 0x80 | 0x8;
                break;
            default:
                return false;
        }
        
        $length = strlen($text);

        if( $length <= 125)
            $header = pack('CC', $b1, $length);
        elseif ($length > 125 && $length < 65536)
            $header = pack('CCS', $b1, 126, $length);
        elseif ($length >= 65536)
            $header = pack('CCN', $b1, 127, $length);

        return $header.$text;
    }

    function parseFrame($payload, $user) 
    {
        
        $data = array_values(unpack("C", $payload));
        $opcode = $data[0]&0x0F;
       
        $length = ord($payload[1]) & 127;

        if($length == 126) {
            $masks = substr($payload, 4, 4);
            $data = substr($payload, 8);
            $len = (ord($payload[2]) << 8) + ord($payload[3]);
        }
        elseif($length == 127) {
            $masks = substr($payload, 10, 4);
            $data = substr($payload, 14);
            $len = (ord($payload[2]) << 56) + (ord($payload[3]) << 48) +
                (ord($payload[4]) << 40) + (ord($payload[5]) << 32) + 
                (ord($payload[6]) << 24) + (ord($payload[7]) << 16) + 
                (ord($payload[8]) << 8) + ord($payload[9]);
        }
        else {
            $masks = substr($payload, 2, 4);
            $data = substr($payload, 6);
            $len = $length;
        }

        $text = '';
        for ($i = 0; $i < $len; ++$i) {
            $text .= $data[$i] ^ $masks[$i%4];
        }
        
        return array("opcode"=> $opcode, "message"=> $text);
    }

//    private function parseFrame1($data, $user) {
//        
//        $this->users[$user->id]->lastActive = time();
//        # unpack data
//        $data = array_values(unpack("C*", $data));
//        # assign the first byte to $i
//        echo $i = $data[0]; die();
//        # extract the FIN bit (last frame in message indicator)
//        $fin = $i & 0x80;
//        # extract opcodes
//        $opcode = $i&0x0F;
//        # whinge if single-frame message limitation is exceeded
//        if(!$fin)
//        {
//            $this->message("unsupported fin");
//            $this->disconnectUser($user);
//            return;
//        }
//        # abort on unknown opcodes (required by the standard)
//
//        if($opcode == 0x8)
//        {
//            $this->disconnectUser($user);
//            return;
//        }elseif($opcode == 0x9)
//        {
//            $this->message("Received PING from ".$user->id);
//            $this->pong($this->users[$user->id]->socket);
//        }elseif($opcode == 0xA){
//            $this->message("Received PONG from ".$user->id);
//            $this->users[$user->id]->lastActive = time();
//        }
//        
//        if($opcode != 0x1)
//        {
//            $this->message("unsupported opcode");
//            $this->disconnectUser($user);
//            return;
//        } 
//
//        # assign the second byte to $i
//        $i = $data[1];
//        # the first bit of the byte is the masking indicator bit
//        $masked = $i&0x80;
//        # the subsequent 7 bits of the byte are the payload length
//        $len = $i&0x7F;
//        # whinge if masked indicator is unset (server->client
//        # frames may have this unset, client->server frames must
//        # have this set, as of draft-15, 2011-09-17)
//        if(!$masked)
//        {
//            $this->message("unsupported should be masked");
//            $this->disconnectUser($user);
//            return;
//        }        
//        # whinge if payload length exceeds 126. this is a bug,
//        # as a value of 127 should enable 64-bit lengths
//        if($len>=126)
//        {
//            $this->message("unsupported len");
//            $this->disconnectUser($user);
//            return;
//        }
//        # get 32-bit mask value from the four subsequent bytes
//        $mask = array_slice($data, 2, 4);
//        $str = "";
//        # apply the mask to the message frame
//        for($i=0;$i<$len;$i++)
//            $str .= chr($data[6+$i] ^ $mask[$i%4]);
//        # return the unmasked frame value
//        return $str;
//    }

}


$server = new wsocket("127.0.0.1", 9292);
try {
  $server->run();
}
catch (Exception $e) {
  $server->message($e->getMessage());
}
