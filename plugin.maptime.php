<?php
// Maptime Plugin:
//     Allows custom time attack limits on a per map basis for Trackmania2
//     dedicated servers
// Requirements:
//     Trackmania2 server
//     XASECO2 server controller
//     Entry into plugins.xml (make sure entry is AFTER plugin.rasp_jukebox.php)
// Author:
//     tedle @ https://github.com/tedle

// Must use onEndMap as time limit changes only go into effect on map change
Aseco::registerEvent('onEndMap', 'maptime_settime');
Aseco::registerEvent('onShutdown', 'maptime_shutdown');
Aseco::addChatCommand('limit', 'Adds a new time limit for this map');

// Common variable between load and save config functions
global $maptime_filename;
$maptime_filename = 'maptime.xml';

// Loads XML config file into poorly structured array
// Creates default config file if none exists, or if current file is corrupt
function maptime_loadconfig ($aseco) {
    $config = null;
    global $maptime_filename;

    if (file_exists($maptime_filename)) {
        $config = $aseco->xml_parser->parseXml($maptime_filename);
    }
    if (!$config) {
        $aseco->console('[Maptime] Could not load config, creating default');
        // Create empty config array for writing
        $config = array(
            'MAPTIME' => array(
                'DEFAULT' => array('5'),
                'MAPLIST' => array( 0 => array( 'MAP' => array()))
            )
        );
        if (!maptime_saveconfig($aseco, $config)) {
            trigger_error('Could not create maptime config, aborting');
        }
    }

    // Prevents some warnings occasionally caused by aseco XML parser
    if (!is_array($config['MAPTIME']['MAPLIST'][0])) {
        $config['MAPTIME']['MAPLIST'][0] = array( 'MAP' => array());
    }

    return $config;
}

// Saves XML config file with poorly structured array input
function maptime_saveconfig ($aseco, $config) {
    global $maptime_filename;
    // aseco xml parser doesn't support whitespace, comments, etc...
    // So we do uh... this... :(
    $xml_string = "<?xml version=\"1.0\" encoding=\"utf-8\"?".">" . CRLF
                . "<maptime>" . CRLF
                . "\t<!-- Default round timer, in minutes -->" . CRLF
                . "\t<default>" . $config['MAPTIME']['DEFAULT'][0]
                . "</default>" . CRLF
                . "\t<maplist>" . CRLF;
    foreach($config['MAPTIME']['MAPLIST'][0]['MAP'] as $map) {
        // Encoding for aseco XML parser, and safety
        $map_filename = rawurlencode(utf8_encode($map['FILENAME'][0]));
        $map_limit = floatval($map['LIMIT'][0]);
        $xml_string .= "\t\t<map>" . CRLF
            ."\t\t\t<filename>" . $map_filename
            . "</filename>" . CRLF
            . "\t\t\t<limit>" . $map_limit . "</limit>" . CRLF
            . "\t\t</map>" . CRLF;
    }
    $xml_string .= "\t</maplist>" . CRLF
        . "</maptime>";
    // (sorry)
    return file_put_contents($maptime_filename, $xml_string);
}

// Searches config for the index of the current or next map
// Next map option provided as time limits must be set before map change
// Returns false on failure
function maptime_findmapkey ($aseco, $config, $next=false) {
    if (!$next) {
        $aseco->client->query('GetCurrentMapInfo');
    } else {
        $aseco->client->query('GetNextMapInfo');
    }

    $queried_map = $aseco->client->getResponse();
    // Scan config to see if queried map is listed in config
    foreach($config['MAPTIME']['MAPLIST'][0]['MAP'] as $key => $map) {
        if ($queried_map['FileName'] == $map['FILENAME'][0]) {
            return $key;
        }
    }
    return false;
}

// Sets time attack limit on map end
// $map provided by api but not needed
// Force $default time limit option for script shutdown event
function maptime_settime ($aseco, $map=null, $default=false) {
    // Refresh as needed in case user is manually editing config
    $config = maptime_loadconfig($aseco);

    // Grab next map info as round hasn't transitioned yet
    $key = maptime_findmapkey($aseco, $config, true);
    $limit = floatval($config['MAPTIME']['DEFAULT'][0]);

    // Next map has a custom time limit
    if ($key !== false && $default == false) {
        $limit = floatval($config['MAPTIME']['MAPLIST'][0]
                                 ['MAP'][$key]['LIMIT'][0]);
        $aseco->console('[Maptime] Setting time limit to ' . $limit . 'min');
    }
 
    // Convert to milliseconds
    $limit *= 60 * 1000;
    // Set new time limit
    $aseco->client->query('SetTimeAttackLimit', intval($limit));
}

// Revert to default time limit on shutdown
function maptime_shutdown ($aseco) {
    maptime_settime($aseco, null, true);
}

// Chat command: /limit
// /limit <num>     - sets custom time limit for current map
// /limit remove    - removes custom time limit for current map
// /limit removeall - removes all custom time limits
function chat_limit ($aseco, $command) {
    // Refresh as needed in case user is manually editing config
    $config = maptime_loadconfig($aseco);

    $user = $command['author'];
    $args = explode(' ', $command['params']);
    $login = $user->login;

    // Check for admin rights
    if ($aseco->isMasterAdmin($user) || $aseco->isAdmin($user)) {
        // Remove current map from config
        if ($args[0] == "remove") {
            $key = maptime_findmapkey($aseco, $config);
            if ($key !== false) {
                unset($config['MAPTIME']['MAPLIST'][0]['MAP'][$key]);
                $aseco->console('[Maptime] Custom limit reverted to default');
                $message = $aseco->formatColors('{#server}> {#highlite}'
                    . 'Map time limit reverted to default');
                $aseco->client->query('ChatSendServerMessage', $message);
            }
        // Remove all maps from config
        } elseif ($args[0] == "removeall") {
            $config['MAPTIME']['MAPLIST'][0]['MAP'] = array();
            $aseco->console('[Maptime] All custom limits reverted to default');
            $message = $aseco->formatColors('{#server}> {#highlite}'
                . 'Time limit for all maps reverted to default');
            $aseco->client->query('ChatSendServerMessage', $message);
        // Set time limit for current map
        } elseif (is_numeric($args[0])) {
            $limit = floatval($args[0]);
            $key = maptime_findmapkey($aseco, $config);
            // Map is already in config, we do an update
            if ($key !== false) {
                // The true power of XML reveals itself
                $config['MAPTIME']['MAPLIST'][0]
                       ['MAP'][$key]['LIMIT'][0] = $limit;
                $aseco->console('[Maptime] Limit updated to ' . $limit);
            }
            // Map is not in config, we do an insert
            else {
                $config['MAPTIME']['MAPLIST'][0]['MAP'][] = array(
                    'FILENAME' => array($aseco->server->map->filename),
                    'LIMIT' => array($limit)
                );
                $aseco->console('[Maptime] Limit of ' . $limit . ' set');
            }
            $message = $aseco->formatColors('{#server}> {#highlite}'
                . 'Custom time limit of ' . $limit . 'min set for this map');
            $aseco->client->query('ChatSendServerMessage', $message);
        // Set the default time limit for all maps
        } elseif ($args[0] == "default" && is_numeric($args[1])) {
            $limit = floatval($args[1]);
            $config['MAPTIME']['DEFAULT'][0] = $limit;
            $aseco->console('[Maptime] Default limit of ' . $limit . ' set');
            $message = $aseco->formatColors('{#server}> {#highlite}'
                . 'Server default time limit of ' . $limit . 'min set');
            $aseco->client->query('ChatSendServerMessage', $message);
        // Incorrect command usage
        } else {
            $message = $aseco->formatColors('{#server}> {#error}'
                . 'Usage: /limit <num>, /limit remove, '
                . '/limit removeall, /limit default <num>');
            $aseco->client->query('ChatSendServerMessageToLogin',
                                   $message, $login);
        }
        maptime_saveconfig($aseco, $config);
    // Must be admin to set time limit
    } else {
        $message = $aseco->formatColors('{#server}> {#error}'
                        . 'You must be an admin to set a time limit');
        $aseco->client->query('ChatSendServerMessageToLogin', $message, $login);
    }
}
?>
