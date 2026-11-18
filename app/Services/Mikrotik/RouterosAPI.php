<?php

namespace App\Services\Mikrotik;

use Iterator;
use IteratorAggregate;
use Traversable;

// Pustaka pihak ketiga (RouterOS PHP API v1.6, Denis Basta) disalin apa adanya dari S.NET lama.

class RouterosAPI
{
    public $debug = false; //  Show debug information

    public $connected = false; //  Connection state

    public $port = 8728;  //  Port to connect to (default 8729 for ssl)

    public $ssl = false; //  Connect using SSL (must enable api-ssl in IP/Services)

    public $timeout = 2;     //  Connection attempt timeout and data read timeout (cepat & tidak lag)

    public $attempts = 1;     //  Connection attempt count (1 kali agar tidak menunggu 30 detik jika offline)

    public $delay = 0;     //  Delay between connection attempts in seconds

    public $socket;            //  Variable for storing socket resource

    public $error_no;          //  Variable for storing connection error number, if any

    public $error_str;         //  Variable for storing connection error text, if any

    /* Check, can be var used in foreach */
    public function isIterable($var)
    {
        return $var !== null
                && (is_array($var)
                || $var instanceof Traversable
                || $var instanceof Iterator
                || $var instanceof IteratorAggregate
                );
    }

    /**
     * Print text for debug purposes
     *
     * @param  string  $text  Text to print
     * @return void
     */
    public function debug($text)
    {
        if ($this->debug) {
            echo $text."\n";
        }
    }

    /**
     * @param  string  $length
     * @return void
     */
    public function encodeLength($length)
    {
        if ($length < 0x80) {
            $length = chr($length);
        } elseif ($length < 0x4000) {
            $length |= 0x8000;
            $length = chr(($length >> 8) & 0xFF).chr($length & 0xFF);
        } elseif ($length < 0x200000) {
            $length |= 0xC00000;
            $length = chr(($length >> 16) & 0xFF).chr(($length >> 8) & 0xFF).chr($length & 0xFF);
        } elseif ($length < 0x10000000) {
            $length |= 0xE0000000;
            $length = chr(($length >> 24) & 0xFF).chr(($length >> 16) & 0xFF).chr(($length >> 8) & 0xFF).chr($length & 0xFF);
        } elseif ($length >= 0x10000000) {
            $length = chr(0xF0).chr(($length >> 24) & 0xFF).chr(($length >> 16) & 0xFF).chr(($length >> 8) & 0xFF).chr($length & 0xFF);
        }

        return $length;
    }

    /**
     * Login to RouterOS
     *
     * @param  string  $ip  Hostname (IP or domain) of the RouterOS server
     * @param  string  $login  The RouterOS username
     * @param  string  $password  The RouterOS password
     * @return bool If we are connected or not
     */
    public function connect($ip, $login, $password, $port = null)
    {
        if ($port !== null && (int) $port > 0) {
            $this->port = (int) $port;
        }
        for ($ATTEMPT = 1; $ATTEMPT <= $this->attempts; $ATTEMPT++) {
            $this->connected = false;
            $PROTOCOL = ($this->ssl ? 'ssl://' : '');
            $context = stream_context_create(['ssl' => ['ciphers' => 'ADH:ALL', 'verify_peer' => false, 'verify_peer_name' => false]]);
            $this->debug('Connection attempt #'.$ATTEMPT.' to '.$PROTOCOL.$ip.':'.$this->port.'...');
            $this->socket = @stream_socket_client($PROTOCOL.$ip.':'.$this->port, $this->error_no, $this->error_str, $this->timeout, STREAM_CLIENT_CONNECT, $context);
            if ($this->socket) {
                $sec = (int) floor($this->timeout);
                $usec = (int) round(($this->timeout - $sec) * 1000000);
                @stream_set_timeout($this->socket, $sec, $usec);
                $this->write('/login', false);
                $this->write('=name='.$login, false);
                $this->write('=password='.$password);
                $RESPONSE = $this->read(false);
                if (isset($RESPONSE[0])) {
                    if ($RESPONSE[0] == '!done') {
                        if (! isset($RESPONSE[1])) {
                            // Login method post-v6.43
                            $this->connected = true;
                            break;
                        } else {
                            // Login method pre-v6.43
                            $MATCHES = [];
                            if (preg_match_all('/[^=]+/i', $RESPONSE[1], $MATCHES)) {
                                if ($MATCHES[0][0] == 'ret' && strlen($MATCHES[0][1]) == 32) {
                                    $this->write('/login', false);
                                    $this->write('=name='.$login, false);
                                    $this->write('=response=00'.md5(chr(0).$password.pack('H*', $MATCHES[0][1])));
                                    $RESPONSE = $this->read(false);
                                    if (isset($RESPONSE[0]) && $RESPONSE[0] == '!done') {
                                        $this->connected = true;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
                if (! $this->connected && is_resource($this->socket)) {
                    @fclose($this->socket);
                }
            }
            if ($this->delay > 0) {
                sleep($this->delay);
            }
        }

        if ($this->connected) {
            $this->debug('Connected...');
        } else {
            $this->debug('Error...');
        }

        return $this->connected;
    }

    /**
     * Disconnect from RouterOS
     *
     * @return void
     */
    public function disconnect()
    {
        // let's make sure this socket is still valid.  it may have been closed by something else
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->connected = false;
        $this->debug('Disconnected...');
    }

    /**
     * Parse response from Router OS
     *
     * @param  array  $response  Response data
     * @return array Array with parsed data
     */
    public function parseResponse($response)
    {
        if (is_array($response)) {
            $PARSED = [];
            $CURRENT = null;
            $singlevalue = null;
            foreach ($response as $x) {
                if (in_array($x, ['!fatal', '!re', '!trap'])) {
                    if ($x == '!re') {
                        $CURRENT = &$PARSED[];
                    } else {
                        $CURRENT = &$PARSED[$x][];
                    }
                } elseif ($x != '!done') {
                    $MATCHES = [];
                    if (preg_match_all('/[^=]+/i', $x, $MATCHES)) {
                        if ($MATCHES[0][0] == 'ret') {
                            $singlevalue = $MATCHES[0][1];
                        }
                        $CURRENT[$MATCHES[0][0]] = (isset($MATCHES[0][1]) ? $MATCHES[0][1] : '');
                    }
                }
            }

            if (empty($PARSED) && ! is_null($singlevalue)) {
                $PARSED = $singlevalue;
            }

            return $PARSED;
        } else {
            return [];
        }
    }

    /**
     * Parse response from Router OS
     *
     * @param  array  $response  Response data
     * @return array Array with parsed data
     */
    public function parseResponse4Smarty($response)
    {
        if (is_array($response)) {
            $PARSED = [];
            $CURRENT = null;
            $singlevalue = null;
            foreach ($response as $x) {
                if (in_array($x, ['!fatal', '!re', '!trap'])) {
                    if ($x == '!re') {
                        $CURRENT = &$PARSED[];
                    } else {
                        $CURRENT = &$PARSED[$x][];
                    }
                } elseif ($x != '!done') {
                    $MATCHES = [];
                    if (preg_match_all('/[^=]+/i', $x, $MATCHES)) {
                        if ($MATCHES[0][0] == 'ret') {
                            $singlevalue = $MATCHES[0][1];
                        }
                        $CURRENT[$MATCHES[0][0]] = (isset($MATCHES[0][1]) ? $MATCHES[0][1] : '');
                    }
                }
            }
            foreach ($PARSED as $key => $value) {
                $PARSED[$key] = $this->arrayChangeKeyName($value);
            }

            return $PARSED;
            if (empty($PARSED) && ! is_null($singlevalue)) {
                $PARSED = $singlevalue;
            }
        } else {
            return [];
        }
    }

    /**
     * Change "-" and "/" from array key to "_"
     *
     * @param  array  $array  Input array
     * @return array Array with changed key names
     */
    public function arrayChangeKeyName(&$array)
    {
        if (is_array($array)) {
            foreach ($array as $k => $v) {
                $tmp = str_replace('-', '_', $k);
                $tmp = str_replace('/', '_', $tmp);
                if ($tmp) {
                    $array_new[$tmp] = $v;
                } else {
                    $array_new[$k] = $v;
                }
            }

            return $array_new;
        } else {
            return $array;
        }
    }

    /**
     * Read data from Router OS
     *
     * @param  bool  $parse  Parse the data? default: true
     * @return array Array with parsed or unparsed data
     */
    public function read($parse = true)
    {
        $RESPONSE = [];
        $receiveddone = false;
        $loopCount = 0;
        $maxLoops = 50000; // Circuit breaker to absolutely prevent 100% CPU runaway

        while (true) {
            if (++$loopCount > $maxLoops) {
                $this->connected = false;
                break;
            }

            if (! is_resource($this->socket) || feof($this->socket)) {
                $this->connected = false;
                break;
            }

            // Read the first byte of input which gives us some or all of the length
            // of the remaining reply.
            $rawByte = fread($this->socket, 1);
            if ($rawByte === false || strlen($rawByte) === 0) {
                $STATUS = stream_get_meta_data($this->socket);
                if (! empty($STATUS['timed_out']) || ! empty($STATUS['eof']) || feof($this->socket)) {
                    $this->connected = false;
                    break;
                }
                if (empty($STATUS['unread_bytes'])) {
                    $this->connected = false;
                    break;
                }
                usleep(5000);

                continue;
            }

            $BYTE = ord($rawByte);
            $LENGTH = 0;
            // If the first bit is set then we need to remove the first four bits, shift left 8
            // and then read another byte in.
            // We repeat this for the second and third bits.
            // If the fourth bit is set, we need to remove anything left in the first byte
            // and then read in yet another byte.
            if ($BYTE & 128) {
                if (($BYTE & 192) == 128) {
                    $b2 = fread($this->socket, 1);
                    if ($b2 === false || strlen($b2) === 0) {
                        $this->connected = false;
                        break;
                    }
                    $LENGTH = (($BYTE & 63) << 8) + ord($b2);
                } else {
                    if (($BYTE & 224) == 192) {
                        $b2 = fread($this->socket, 1);
                        $b3 = fread($this->socket, 1);
                        if ($b2 === false || $b3 === false || strlen($b2) === 0 || strlen($b3) === 0) {
                            $this->connected = false;
                            break;
                        }
                        $LENGTH = (($BYTE & 31) << 8) + ord($b2);
                        $LENGTH = ($LENGTH << 8) + ord($b3);
                    } else {
                        if (($BYTE & 240) == 224) {
                            $b2 = fread($this->socket, 1);
                            $b3 = fread($this->socket, 1);
                            $b4 = fread($this->socket, 1);
                            if ($b2 === false || $b3 === false || $b4 === false || strlen($b2) === 0 || strlen($b3) === 0 || strlen($b4) === 0) {
                                $this->connected = false;
                                break;
                            }
                            $LENGTH = (($BYTE & 15) << 8) + ord($b2);
                            $LENGTH = ($LENGTH << 8) + ord($b3);
                            $LENGTH = ($LENGTH << 8) + ord($b4);
                        } else {
                            $b2 = fread($this->socket, 1);
                            $b3 = fread($this->socket, 1);
                            $b4 = fread($this->socket, 1);
                            $b5 = fread($this->socket, 1);
                            if ($b2 === false || $b3 === false || $b4 === false || $b5 === false || strlen($b2) === 0 || strlen($b3) === 0 || strlen($b4) === 0 || strlen($b5) === 0) {
                                $this->connected = false;
                                break;
                            }
                            $LENGTH = ord($b2);
                            $LENGTH = ($LENGTH << 8) + ord($b3);
                            $LENGTH = ($LENGTH << 8) + ord($b4);
                            $LENGTH = ($LENGTH << 8) + ord($b5);
                        }
                    }
                }
            } else {
                $LENGTH = $BYTE;
            }

            $_ = '';

            // If we have got more characters to read, read them in.
            if ($LENGTH > 0) {
                $_ = '';
                $retlen = 0;
                while ($retlen < $LENGTH) {
                    $toread = $LENGTH - $retlen;
                    $chunk = fread($this->socket, $toread);
                    if ($chunk === false || strlen($chunk) === 0) {
                        $STATUS = stream_get_meta_data($this->socket);
                        if (! empty($STATUS['timed_out']) || ! empty($STATUS['eof']) || feof($this->socket)) {
                            $this->connected = false;
                            break 2;
                        }
                        break 2;
                    }
                    $_ .= $chunk;
                    $retlen = strlen($_);
                }
                $RESPONSE[] = $_;
                $this->debug('>>> ['.$retlen.'/'.$LENGTH.'] bytes read.');
            }

            // If we get a !done, make a note of it.
            if ($_ == '!done') {
                $receiveddone = true;
            }

            $STATUS = stream_get_meta_data($this->socket);
            if (! empty($STATUS['timed_out']) || ! empty($STATUS['eof']) || feof($this->socket)) {
                $this->connected = false;
                break;
            }

            if ($LENGTH > 0) {
                $this->debug('>>> ['.$LENGTH.', '.$STATUS['unread_bytes'].']'.$_);
            }

            if ((! $this->connected && ! $STATUS['unread_bytes']) || ($this->connected && ! $STATUS['unread_bytes'] && $receiveddone)) {
                break;
            }
        }

        if ($parse) {
            $RESPONSE = $this->parseResponse($RESPONSE);
        }

        return $RESPONSE;
    }

    /**
     * Write (send) data to Router OS
     *
     * @param  string  $command  A string with the command to send
     * @param  mixed  $param2  If we set an integer, the command will send this data as a "tag"
     *                         If we set it to boolean true, the funcion will send the comand and finish
     *                         If we set it to boolean false, the funcion will send the comand and wait for next command
     *                         Default: true
     * @return bool Return false if no command especified
     */
    public function write($command, $param2 = true)
    {
        if ($command) {
            $data = explode("\n", $command);
            foreach ($data as $com) {
                $com = trim($com);
                fwrite($this->socket, $this->encodeLength(strlen($com)).$com);
                $this->debug('<<< ['.strlen($com).'] '.$com);
            }

            if (gettype($param2) == 'integer') {
                fwrite($this->socket, $this->encodeLength(strlen('.tag='.$param2)).'.tag='.$param2.chr(0));
                $this->debug('<<< ['.strlen('.tag='.$param2).'] .tag='.$param2);
            } elseif (gettype($param2) == 'boolean') {
                fwrite($this->socket, ($param2 ? chr(0) : ''));
            }

            return true;
        } else {
            return false;
        }
    }

    /**
     * Write (send) data to Router OS
     *
     * @param  string  $com  A string with the command to send
     * @param  array  $arr  An array with arguments or queries
     * @return array Array with parsed
     */
    public function comm($com, $arr = [])
    {
        $count = count($arr);
        $this->write($com, ! $arr);
        $i = 0;
        if ($this->isIterable($arr)) {
            foreach ($arr as $k => $v) {
                switch ($k[0]) {
                    case '?':
                        $el = "$k=$v";
                        break;
                    case '~':
                        $el = "$k~$v";
                        break;
                    default:
                        $el = "=$k=$v";
                        break;
                }

                $last = ($i++ == $count - 1);
                $this->write($el, $last);
            }
        }

        return $this->read();
    }

    /**
     * Standard destructor
     *
     * @return void
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}
