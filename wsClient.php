<?php //ws client wo dependecies by cmdprompt 2022
class webSocket{
 public $debug=0,$recieved=0,$sended=0,$saved=0,$inflate,$deflate,$sock,$ssl,$host,$port,$uri,$origin,$errstr='',$errno,$timeout,$headers
 ,$key;
 
public function __construct($url,$headers='',$timeout=30,$compress=1){
 // NEW compression: 1 server to client, 2 client to server, 3 both
 
if(!preg_match('`(wss?://|)([\w\.]+)(:\d+|)/([^#]*)`',$url)){
  $this->errstr = "Wrong url";
 return false;
 }

$uc=parse_url($url);

$this->timeout=$timeout;
$this->ssl=!isset($uc['scheme'])||$uc['scheme']=='wss'?1:0;
$this->host=$uc['host'];
$this->port=intval(empty($uc['port'])?$this->ssl?443:80:$uc['port']);
$addr = ($this->ssl?'ssl://':'')."$uc[host]:$this->port";
$this->origin='http'.($this->ssl?'s':'')."://$uc[host]";
$this->uri=empty($uc['path'])?'/':$uc['path'];
if(!empty($uc['query']))$this->uri.='?'.$uc['query'];
 
 // Add extra headers
if(is_array($headers))
 $headers=implode("\r\n",array_map('trim',$headers));

 // Generate a key (to convince server that the update is not random)
 // The key is for the server to prove it i websocket aware. (We know it is)
 $this->key=base64_encode(openssl_random_pseudo_bytes(16));

 $header = "GET $this->uri HTTP/1.1\r\n"
 ."Host: $uc[host]\r\n"
 ."Pragma: no-cache\r\n"
 ."Upgrade: WebSocket\r\n"
 ."Connection: Upgrade\r\n"
 ."Origin: $this->origin\r\n"
 ."Sec-WebSocket-Key: $this->key\r\n"
 ."Sec-WebSocket-Version: 13\r\n"
 .($compress?"Sec-WebSocket-Extensions: permessage-deflate; client_max_window_bits\r\n":'')
.$headers."\r\n\r\n";

$this->log("$addr\n$header");

 // Connect to server
 $stream_context = stream_context_create([
 'socket'=>['bindto'=>'0:0'] //0:0 - ipv4, [0]:0 - ipv6
]);

 // put in persistant ! if used in php-fpm, no handshare if same.
 $this->sock = stream_socket_client($addr, $this->errno, $this->errstr, $this->timeout, STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT,$stream_context);

 if(!$this->sock){
  $this->errstr = "Unable to connect to websocket server: $this->errstr ($this->errno)";
 return false;
 }

 // Set timeouts
 stream_set_timeout($this->sock,$this->timeout);

 if (ftell($this->sock) === 0) {

 //Request upgrade to websocket
 $rc = fwrite($this->sock,$header);
 if(!$rc){
   $this->errstr = "Unable to send upgrade header to websocket server: $this->errstr ($this->errno)";
  return false;
 }

 // Read response into an assotiative array of headers. Fails if upgrade failes.
// $response_header=fread($this->sock, 2048);
$resp='';
while(($s=fgets($this->sock))&&$s!="\r\n")
 $resp.=$s;
 
if(empty($resp)){
 $s2=fgets($this->sock);
 $inf=stream_get_meta_data($this->sock);
$this->errstr='empty response';
$this->errno=-1;
return false;
}


$this->headers=explode("\r\n",$resp);
unset($this->headers[count($this->headers)-1]);

$accepted=0;
foreach($this->headers as$h){
 $hdra=explode(': ', $h, 2);
 $hdr=strtolower($hdra[0]);
switch($hdr)
{
 case 'sec-websocket-accept':
  $accepted=1;
 break;
 
 case 'sec-websocket-extensions':
  if(strpos($hdra[1],'permessage-deflate')!==false){
   $this->log($h);
   $this->inflate=$compress&1?inflate_init(ZLIB_ENCODING_RAW,['window'=>15]):false;
   $this->deflate=$compress&2?deflate_init(ZLIB_ENCODING_RAW,['window'=>15]):false;
  };
}
}
 // status code 101 indicates that the WebSocket handshake has completed.
 if (stripos($this->headers[0], ' 101 ') === false
  ||!$accepted) {
   $this->errstr = "Server did not accept to upgrade connection to websocket."
  .$resp. E_USER_ERROR;
  return false;
 }
 // The key we send is returned, concatenate with "258EAFA5-E914-47DA-95CA-
 // C5AB0DC85B11" and then base64-encoded. one can verify if one feels the need...
 }
 
}

public function write($data,$text=false,$final=true){
 // Assamble header: FINal 0x80 | Opcode 0x02
 $opcode=$text?1:2;

$datasize=strlen($data);

 if($this->deflate){
  $opcode|=0x40;//compressed bit
  $data=deflate_add($this->deflate,$data,ZLIB_SYNC_FLUSH);
  $datasize=strlen($data)-4;
 }
 $this->sended+=$datasize;
 
 $header=chr(($final?0x80:0) | $opcode); // 0x02 binary

 // Mask 0x80 | payload length (0-125)
 if($datasize<126) $header.=chr(0x80 | $datasize);
 elseif ($datasize<0xFFFF) $header.=chr(0x80 | 126) . pack("n",$datasize);
 else $header.=chr(0x80 | 127) . pack("N",0) . pack("N",$datasize);

 // Add mask
 $mask=pack("N",rand(1,0x7FFFFFFF));
 $header.=$mask;
 
 // Mask application data.
 for($i=0;$i<$datasize;$i++)
 $data[$i]=chr(ord($data[$i])^ord($mask[$i % 4]));

 return fwrite($this->sock,$header.$data,strlen($header)+$datasize);
}

public function read(){
 $data="";
 do{
 // Read header
 $header=fread($this->sock,2);
 if(!$header){
   $this->errstr = "Reading header from websocket failed.";
  return false;
 }
 $opcode = ord($header[0]) & 0x0F;
 if(!isset($compressed))
  $compressed = ord($header[0]) & 0x40;
 $final = ord($header[0]) & 0x80;
 $masked = ord($header[1]) & 0x80;
 $payload_len = ord($header[1]) & 0x7F;

 // Get payload length extensions
 $ext_len = 0;
 if($payload_len >= 0x7E){
  $ext_len = 2;
  if($payload_len == 0x7F) $ext_len = 8;
  $header=fread($this->sock,$ext_len);
  if(!$header){
   $this->errstr = "Reading header extension from websocket failed.";
  return false;
  }

  // Set extented paylod length
  $payload_len= 0;
  for($i=0;$i<$ext_len;$i++)
  $payload_len += ord($header[$i]) << ($ext_len-$i-1)*8;
 }
 
 if($payload_len<0||$payload_len>2e9){
   $this->errstr="Bad payload length.";
  return false;
  }

 // Get Mask key
 if($masked){
  $mask=fread($this->sock,4);
  if(!$mask){
   $this->errstr = "Reading header mask from websocket failed.";
  return false;
  }
 }

 // Get payload

 $frame_data='';
 do{
  $frame= fread($this->sock,$payload_len);
  if(!$frame){
   $this->errstr = "Reading from websocket failed.";
  return false;
  }
  $payload_len -= strlen($frame);
  $frame_data.=$frame;
 }while($payload_len>0);

 // Handle ping requests (sort of) send pong and continue to read
 if($opcode == 9){
  // Assamble header: FINal 0x80 | Opcode 0x0A + Mask on 0x80 with zero payload
  fwrite($this->sock,chr(0x8A) . chr(0x80) . pack("N", rand(1,0x7FFFFFFF)));
  continue;

 // Close
 } elseif($opcode == 8){
  fclose($this->sock);

 // 0 = continuation frame, 1 = text frame, 2 = binary frame
 }elseif($opcode < 3){

  // Unmask data
 if($masked)
  for ($i = 0; $i < strlen($frame_data); $i++)
   $data.= $frame_data[$i] ^ $mask[$i % 4];
  else
  $data.= $frame_data;

 }else
  continue;

 }while(!$final);

  $datasize=strlen($data);
  $this->recieved+=$datasize;

 if($compressed&&$this->inflate){
  $data=inflate_add($this->inflate,$data."\0\0\xFF\xFF",ZLIB_SYNC_FLUSH);
  $this->saved+=strlen($data)-$datasize;
}
return $data;
}

private function log($str){

 if($this->debug)echo "$str\n";
}
}
