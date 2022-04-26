<?php
$cookie='';// your cookie from keepers.mobi
 $headers[]="Cookie: _glc=$cookie";

$keep=new webSocket('wss://wrap.keepers.mobi:444/socket.io/?EIO=3&transport=websocket', $headers, 30);
if(!empty($keep->errstr)){
 echo $keep->errstr;
 return false;
}
$keep->read();
$keep->read();

$auth=$keep->write('{"cmd":"Auth","num":1}');
