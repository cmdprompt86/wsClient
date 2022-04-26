<?php
require_once 'wsClient.php';

$cookie='';// your cookie from keepers.mobi
 $headers[]="Cookie: _glc=$cookie";

$keep=new webSocket(
     'wss://wrap.keepers.mobi:444/socket.io/?EIO=3&transport=websocket', //url
     $headers, //additional http headers
     30);//socket timeout

if(!empty($keep->errstr)){//check for errors
 echo $keep->errstr;
 return false;
}
$keep->read();
$keep->read();

$keep->write('42["command",{"cmd":"Auth","num":1}]');
$auth=json_decode($keep->read());
var_dump($auth);
