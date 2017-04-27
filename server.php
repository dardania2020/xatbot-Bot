<?php

if (empty($argv[1])) {
    exit('Sorry, you forgot to specify a server name');
}

define('IPC_SOCKET', 'sockets/' . strtolower($argv[1]) . '.sock');

// load composer autoload
require_once 'vendor/autoload.php';

// classes
require_once 'classes/xatVariables.php';
require_once 'classes/xatBot.php';
require_once 'classes/xatUser.php';
require_once 'classes/xatConnect4.php';
require_once 'classes/IPC.php';
require_once 'classes/database.php';

// API
require_once 'API/dataAPI.php';
require_once 'API/actionAPI.php';

echo 'Loading variables...' . PHP_EOL;
xatVariables::init();
xatVariables::update();

echo 'Loading API...' . PHP_EOL;
$params     = API::init();
$currentBot = &$params['botID'];
$bot        = &$params['bot'];

echo 'Loading bots...' . PHP_EOL;
$xatBots = [];

echo 'Loading extensions...' . PHP_EOL;
$extensionsList = [];
read();

echo 'Loading IPC...' . PHP_EOL;
$socket = IPC_Start();

echo 'Server is ready!' . PHP_EOL;

while (1) {
    $tmpClient = @socket_accept($socket);
    if (@socket_set_nonblock($tmpClient)) {
        $clients[] = $tmpClient;
    }

    if (!empty($clients)) {
        // Socket's loop
        foreach ($clients as $client) {
            $packet = null;
            do {
                $lastSize = strlen($packet);
                $packet .= socket_read($client, 1024);
            } while ($lastSize != strlen($packet));

            if (strlen($packet) == 0) {
                continue;
            }

            $packet  = explode(' ', trim($packet));
            $command = $packet[0];
            $args    = [];
            $return  = null;

            for ($i = 1; $i < sizeof($packet); $i++) {
                $args[] = $packet[$i];
            }

            switch ($command) {
                case 'start':
                    if (start($args[0]) === true) {
                        $return = 'Success';
                    } else {
                        $return = 'Error';
                    }
                    break;

                case 'restart':
                    if (restart($args[0]) === true) {
                        $return = 'Success';
                    } else {
                        $return = 'Error';
                    }
                    break;

                case 'stop':
                    if (stop($args[0]) === true) {
                        $return = 'Success';
                    } else {
                        $return = 'Error';
                    }
                    break;

                case 'server_status':
                    $return = json_encode([
                        'bots'        => sizeof(getBotsRunning()),
                        'memory'      => round(memory_get_usage(true) / 1024 / 1024),
                        'cpu'         => trim(exec('ps -p ' . getmyid() . ' -o %cpu')),
                        'timestarted' => getTimeStarted()
                    ]);
                    break;

                case 'reload':
                    reloadExtensions();
                    $return = 'Extension reloaded!';
                    break;

                default:
                    $return = 'Unknow command!';
                    break;
            }

            socket_write($client, $return);
            unset($clients);
        }
    } else {
        // Bots loop
        foreach ($xatBots as $botid => $Ocean) {
            $currentBot = $botid;
            $bot        = $Ocean;
            $Ocean->network->tick();

            usleep(5000);
            try {
                while (1) {
                    if (!$Ocean->network->socket->isConnected()) {
                        echo 'Socket not connected!' . PHP_EOL;
                        exit('You have an error in your code or socket died.');
                        break;
                    }

                    $packet = $Ocean->network->socket->read();

                    if ($packet === false) {
                        echo 'ERROR packet false!' . PHP_EOL;
                        $Ocean->network->reconnect();
                        break;
                    }

                    if (empty($packet)) {
                        break;
                    }

                    if (!isset($packet['node'])) {
                        var_dump($packet);
                        break;
                    }

                    $hook   = null;
                    $args   = [];
                    $unknow = false;
                    
                    $parseElements = ['u', 'd'];
                    foreach($parseElements as $parse) {
                        if (isset($packet['elements'][$parse])) {
                            $packet['elements'][$parse] = $Ocean->network->parseID($packet['elements'][$parse]);
                        }
                    }

                    switch ($packet['node']) {
                        case 'abort':
                            $hook   = 'onAbort'; // onAbort($array)
                            $args[] = $packet['elements'];
                            break;
                            
                        case 'a':
                            $hook   = 'onTransfer'; // onTransfer($from, $to, $xats, $days, $message)
                            $args[] = $packet['elements']['u'];
                            $args[] = $packet['elements']['d'];
                            $args[] = $packet['elements']['x'];
                            $args[] = $packet['elements']['s'];
                            $args[] = $packet['elements']['t'];
                            break;

                        case 'bl':
                            $hook   = 'onBlast'; // onBlast($array)
                            $args[] = $packet['elements'];
                            break;

                        case 'c':
                            $hook   = 'onControlMessage'; // onControlMessage($array)
                            $args[] = $packet['elements'];
                            break;

                        case 'done':
                            $hook   = 'onDone'; // onDone($array)
                            $args[] = $packet['elements'];
                            break;

                        case 'dup':
                            $hook   = 'onDup'; // onDup()
                            break;

                        case 'f':
                            $hook   = 'onFriendList'; // onFriendList($array)
                            $args[] = $packet['elements'];
                            break;

                        case 'g':
                            $hook   = 'onOpenApp'; // onOpenApp($who, $app)
                            $args[] = $packet['elements']['u'];
                            $args[] = $packet['elements']['x'];
                            break;   

                        case 'gp':
                            $hook   = 'onGroupPowers'; // onGroupPowers($array)
                            $args[] = $packet['elements'];
                            break;

                        case 'i':
                            $hook   = 'onChatInfo'; // onChatInfo($array)
                            $args[] = $packet['elements'];
                            break;

                        case 'idle':
                            $hook   = 'onIdle'; // onIdle($array)
                            $args[] = $packet['elements'];
                            break;

                        case 'k':
                            $hook   = 'onKick'; // onKick($array)
                            $args[] = $packet['elements'];
                            break;

                        case 'l':
                            $hook   = 'onUserLeave'; // onUserLeave($who)
                            $args[] = $packet['elements']['u'];
                            break;
                            
                        case 'ldone':
                            // meh
                            break;
                            
                        case 'logout':
                            $hook   = 'onLogout'; // onLogout($array)
                            $args[] = $packet['elements'];
                            break;

                        case 'm':
                            if (isset($packet['elements']['u'])) {
                                if (!$bot->done) {
                                    if (isset($packet['elements']['i'])) {
                                        $bot->messageCount = $packet['elements']['i'];
                                    } else {
                                        $bot->messageCount = 0;
                                    }
                                } else {
                                    $bot->messageCount++;
                                }

                                if ($packet['elements']['t'] == '/RTypeOn' || $packet['elements']['t'] == '/RTypeOff') {
                                    continue;
                                }

                                if (!isset($packet['elements']['s'])) {
                                    if (!isset($packet['elements']['p']) && isset($packet['elements']['i'])) {
                                        $hook   = 'onMessage'; // onMessage($who, $message)
                                        $args[] = $packet['elements']['u'];
                                        $args[] = $packet['elements']['t'];
                                    } else if (isset($packet['elements']['p'])) {
                                        $hook   = 'onRankMessage'; // onRankMessage($who, $message, $reason, $array)
                                        $args[] = $packet['elements']['u'];
                                        $args[] = $packet['elements']['t'];
                                        $args[] = $packet['elements']['p'];
                                        $args[] = $packet['elements'];
                                    }
                                } else if ($packet['elements']['s'] & 1) {
                                    $hook   = 'onOldMessage'; // onOldMessage($who, $message)
                                    $args[] = $packet['elements']['d'] ?? $packet['elements']['u'];
                                    $args[] = $packet['elements']['t'];
                                }
                             }
                            break;

                        case 'o':
                            // Old User
                            break;

                        case 'p':
                            if ($packet['elements']['t'] == '/RTypeOn' || $packet['elements']['t'] == '/RTypeOff') {
                                continue;
                            }

                            $hook   = (isset($packet['elements']['s'])) ? 'onPC' : 'onPM';  // onP*($who, $message)
                            $args[] = $packet['elements']['u'];
                            $args[] = $packet['elements']['t'];
                            break;

                        case 'q':
                            $hook   = 'onRedirect'; // onRedirect($array)
                            $args[] = $packet['elements'];
                            break;

                        case 'u':
                            $hook   = 'onUserJoined'; // onUserJoined($array)
                            $args[] = $packet['elements']['u'];
                            $args[] = $packet['elements'];
                            break;
                            
                        case 'v':
                            $hook   = 'onLoginInfo'; // onLoginInfo($array)
                            $args[] = $packet['elements'];
                            break;

                        case 'w':
                            $hook   = 'onPools'; // onPools($array)
                            $args[] = $packet['elements'];
                            break;
                            
                        case 'x':
                            $hook   = 'onApp'; // onApp($who, $app, $elements)
                            $args[] = $packet['elements']['u'];
                            $args[] = $packet['elements']['i'];
                            $args[] = $packet['elements'];
                            break;

                        case 'z':
                            $hook   = 'onTickle'; // onTickle($who, $array)
                            $args[] = $packet['elements']['u'];
                            $args[] = $packet['elements'];
                            break;

                        default:
                            $unknow = true;
                            break;
                    }

                    if (in_array($hook, ['onMessage', 'onPM', 'onPC']) && $args[1][0] == $Ocean->botData['customcommand']) {
                        $args[1] = explode(' ', trim($args[1]));
                        $command = substr($args[1][0], 1);

                        if (isset($Ocean->alias[$command])) {
                            $args[1][0] = $Ocean->botData['customcommand'] . $Ocean->alias[$command];
                            $args[1] = explode(' ', trim(implode(' ', $args[1])));
                            $command = substr($args[1][0], 1);
                        }
                        
                        if ($hook == 'onMessage') {
                            $args[2] = 1;
                        } elseif ($hook == 'onPM') {
                            $args[2] = 2;
                        } elseif ($hook == 'onPC') {
                            $args[2] = 3;
                        }
                        dispatch('commands', $command, $args);
                    } else {
                        if (!$unknow && !empty($hook)) {
                            dispatch('modules', $hook, $args);
                        } elseif ($unknow) {
                            echo 'Unknow node ['.$packet['node'].'] on chat FIXME' . PHP_EOL;
                        }
                    }
                }
            } catch (Exception $e) {
                var_dump($e->getMessage());
                echo 'Error botid: ' . $botid . PHP_EOL;
            }
        }
    }
}

function load($data, $type, $name, $url, $callbacks)
{
    require($url);

    for ($i = 0; $i < sizeof($callbacks); $i++) {
        if (isset(${$callbacks[$i]})) {
            $data[$type][$callbacks[$i]][$name] = ${$callbacks[$i]};
        } else {
            unset($data[$type][$callbacks[$i]][$name]);
        }
    }

    return $data;
}

function dispatch($type, $name, $args)
{
    global $extensionsList;

    if (!isset($extensionsList[$type][$name])) {
        return false;
    }

    foreach ($extensionsList[$type][$name] as $extensionName => $function) {
		try {
			call_user_func_array($function, $args);
		} catch (TypeError $e) {
			var_dump($e->getMessage());
		}
    }
}

function read()
{
    global $extensionsList;
    $extensionsDirectories = ['modules', 'commands'];

    foreach ($extensionsDirectories as $extensionsDir) {
        $callbacks = json_decode(file_get_contents($extensionsDir . '.json', true), true);

        $dir = opendir($extensionsDir);

        while (($file = readdir($dir)) !== false) {
            $url = '.' . DIRECTORY_SEPARATOR . $extensionsDir . DIRECTORY_SEPARATOR . $file;

            if (!is_file($url)) {
                continue;
            }

            $pos = strrpos($file, '.');

            if ($pos === false) {
                continue;
            }

            if (substr($file, $pos + 1) != 'php') {
                continue;
            }

            $extensionsList = load($extensionsList, $extensionsDir, substr($file, 0, $pos), $url, $callbacks);
        }
    }
}

function reloadExtensions()
{
    read();
}

function IPC_Start()
{
    $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
    if (!$socket) {
        exit('Cannot create unix socket');
    }
    
    if (file_exists(IPC_SOCKET)) {
        if (@socket_connect($socket, IPC_SOCKET)) {
            exit($argv[1] . ' server is already started');
        } else {
            unlink(IPC_SOCKET);
        }
    }
    $ret = socket_bind($socket, IPC_SOCKET);
    if (!$ret) {
        exit();
    }
    
    $ret = socket_set_nonblock($socket);
    if (!$ret) {
        exit();
    }
        
    $ret = socket_listen($socket);
    if (!$ret) {
        exit();
    }

    chmod(IPC_SOCKET, 0777);    
    return $socket;
}

function start($botid)
{
    global $xatBots;

    try {
        $bot = new xatBot(Bot::find($botid));
        $xatBots[$botid] = $bot;
    } catch (Exception $e) {
        var_dump($e);
        return false;
    }
    return true;
}

function restart($botid)
{
    stop($botid);
    return start($botid);
}

function stop($botid)
{
    global $xatBots;

    if (isset($xatBots[$botid])) {
        unset($xatBots[$botid]);
        return true;
    }

    return false;
}