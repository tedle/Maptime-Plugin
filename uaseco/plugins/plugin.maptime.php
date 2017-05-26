<?php
// Maptime Plugin:
//     Allows custom time limits on a per map basis for Trackmania2 dedicated
//     servers
// Requirements:
//     Trackmania2 server
//     UASECO server controller
//     Entry into plugins.xml (make sure entry is AFTER plugin.rasp_jukebox.php)
// Author:
//     tedle @ https://github.com/tedle

$_PLUGIN = new PluginMapTime();

class PluginMapTime extends Plugin {
    public $maptime_filename = 'maptime.xml';

    public function __construct () {
        $this->setVersion('1.0.0');
        $this->setBuild('1');
        $this->setAuthor('tedle');
        $this->setCopyright('2017');
        $this->setDescription(new Message('plugin.maptime', 'plugin_description'));

        $this->registerEvent('onEndMap', 'maptime_settime');
        $this->registerEvent('onShutdown', 'maptime_shutdown');
        $this->registerChatCommand(
            'limit',
            'chat_limit',
            new Message('plugin.maptime', 'chat_limit_description'),
            Player::OPERATORS
        );
    }

    // Loads XML config file into poorly structured array
    // Creates default config file if none exists, or if current file is corrupt
    public function maptime_loadconfig ($aseco) {
        $config = null;

        if (file_exists($this->maptime_filename)) {
            $config = $aseco->parser->xmlToArray($this->maptime_filename);
        }
        // Make sure we got the values we need
        if (!$config ||
            !isset($config['MAPTIME']['DEFAULT'][0]) ||
            !isset($config['MAPTIME']['ALLOW_ADMINS'][0]) ||
            !isset($config['MAPTIME']['ALLOW_OPS'][0])) {

            $aseco->console('[Maptime] Could not load config, creating default');
            // Create empty config array for writing
            $config = array(
                'MAPTIME' => array(
                    'DEFAULT' => array('5'),
                    'ALLOW_ADMINS' => array('true'),
                    'ALLOW_OPS' => array('false'),
                    'MAPLIST' => array( 0 => array( 'MAP' => array()))
                )
            );
            if (!$this->maptime_saveconfig($aseco, $config['MAPTIME'])) {
                trigger_error('Could not create maptime config, aborting');
            }
        }

        // Prevents some warnings occasionally caused by aseco XML parser
        if (!is_array($config['MAPTIME']['MAPLIST'][0])) {
            $config['MAPTIME']['MAPLIST'][0] = array( 'MAP' => array());
        }

        // Everything is inside the maptime scope
        $config = $config['MAPTIME'];

        return $config;
    }

    // Saves XML config file with poorly structured array input
    public function maptime_saveconfig ($aseco, $config) {
        // Some quick sanitization in case config was hand edited
        $limit = floatval($config['DEFAULT'][0]);
        $allow_admins = ($config['ALLOW_ADMINS'][0]=='true' ? 'true' : 'false');
        $allow_ops = ($config['ALLOW_OPS'][0]=='true' ? 'true' : 'false');
        // aseco xml parser doesn't support whitespace, comments, etc...
        // So we do uh... this... :(
        $xml_string = "<?xml version=\"1.0\" encoding=\"utf-8\"?".">" . CRLF
            . "<maptime>" . CRLF
            . "\t<!-- Default round timer, in minutes -->" . CRLF
            . "\t<default>" . $limit
            . "</default>" . CRLF
            . "\t<!-- Permissions to set time limits -->" . CRLF
            . "\t<allow_admins>" . $allow_admins
            . "</allow_admins>" . CRLF
            . "\t<allow_ops>" . $allow_ops
            . "</allow_ops>" . CRLF
            . "\t<maplist>" . CRLF;
        foreach($config['MAPLIST'][0]['MAP'] as $map) {
            // Encoding for aseco XML parser, and safety
            $map_filename = rawurlencode(utf8_encode($map['FILENAME'][0]));
            $map_limit = floatval($map['LIMIT'][0]);
            $xml_string .= "\t\t<map>" . CRLF
                . "\t\t\t<filename>" . $map_filename
                . "</filename>" . CRLF
                . "\t\t\t<limit>" . $map_limit . "</limit>" . CRLF
                . "\t\t</map>" . CRLF;
        }
        $xml_string .= "\t</maplist>" . CRLF
            . "</maptime>";
        // (sorry)
        return file_put_contents($this->maptime_filename, $xml_string);
    }

    // Searches config for the index of the current or next map
    // Next map option provided as time limits must be set before map change
    // Returns false on failure
    public function maptime_findmapkey ($aseco, $config, $next=false) {
        $queried_map = array();
        if (!$next) {
            $queried_map = $aseco->client->query('GetCurrentMapInfo');
        } else {
            $queried_map = $aseco->client->query('GetNextMapInfo');
        }

        // Scan config to see if queried map is listed in config
        foreach($config['MAPLIST'][0]['MAP'] as $key => $map) {
            if ($queried_map['FileName'] == $map['FILENAME'][0]) {
                return $key;
            }
        }
        return false;
    }

    // Sets time attack limit on map end
    // $map provided by api but not needed
    // Force $default time limit option for script shutdown event
    public function maptime_settime ($aseco, $map=null, $default=false) {
        // Refresh as needed in case user is manually editing config
        $config = $this->maptime_loadconfig($aseco);

        // Grab next map info as round hasn't transitioned yet
        $key = $this->maptime_findmapkey($aseco, $config, true);
        $limit = floatval($config['DEFAULT'][0]);

        // Next map has a custom time limit
        if ($key !== false && $default == false) {
            $limit = floatval($config['MAPLIST'][0]
                                     ['MAP'][$key]['LIMIT'][0]);
            $aseco->console('[Maptime] Setting time limit to ' . $limit . 'min');
        }

        // Convert to seconds
        $limit *= 60;
        // Configure new time limit
        switch($aseco->server->gameinfo->mode) {
        case Gameinfo::TIME_ATTACK:
            $aseco->server->gameinfo->time_attack['TimeLimit'] = intval($limit);
            break;
        case Gameinfo::LAPS:
            $aseco->server->gameinfo->laps['TimeLimit'] = intval($limit);
            break;
        case Gameinfo::TEAM_ATTACK:
            $aseco->server->gameinfo->team_attack['TimeLimit'] = intval($limit);
            break;
        case Gameinfo::CHASE:
            $aseco->server->gameinfo->chase['TimeLimit'] = intval($limit);
            break;
        }
        // Publish new game settings
        $aseco->plugins['PluginModescriptHandler']->setupModescriptSettings();
    }

    // Revert to default time limit on shutdown
    public function maptime_shutdown ($aseco) {
        $this->maptime_settime($aseco, null, true);
    }

    // Chat command: /limit
    // /limit <num>         - sets custom time limit for current map
    // /limit default <num> - sets custom time limit for all maps
    // /limit remove        - removes custom time limit for current map
    // /limit removeall     - removes all custom time limits
    public function chat_limit ($aseco, $login, $command, $params) {
        // Refresh as needed in case user is manually editing config
        $config = $this->maptime_loadconfig($aseco);
        $args = explode(' ', $params);
        $map_name = $aseco->stripStyles($aseco->server->maps->current->name, false);

        // Check for admin rights with a big ugly if statement
        if ($aseco->isMasterAdminByLogin($login) ||
           ($aseco->isAdminByLogin($login) && $config['ALLOW_ADMINS'][0] == 'true') ||
           ($aseco->isOperatorByLogin($login) && $config['ALLOW_OPS'][0] == 'true')) {
            // Remove current map from config
            if ($args[0] == "remove") {
                $key = $this->maptime_findmapkey($aseco, $config);
                if ($key !== false) {
                    unset($config['MAPLIST'][0]['MAP'][$key]);
                    $aseco->console('[Maptime] Custom limit reverted to default');
                    $message = new Message('plugin.maptime', 'remove_limit');
                    $message->addPlaceholders($map_name);
                    $message->sendChatMessage();
                }
            // Remove all maps from config
            } elseif ($args[0] == "removeall") {
                $config['MAPLIST'][0]['MAP'] = array();
                $aseco->console('[Maptime] All custom limits reverted to default');
                $message = new Message('plugin.maptime', 'remove_all_limits');
                $message->sendChatMessage();
            // Set time limit for current map
            } elseif (is_numeric($args[0])) {
                $limit = floatval($args[0]);
                $key = $this->maptime_findmapkey($aseco, $config);
                // Map is already in config, we do an update
                if ($key !== false) {
                    // The true power of XML reveals itself
                    $config['MAPLIST'][0]['MAP'][$key]['LIMIT'][0] = $limit;
                    $aseco->console('[Maptime] Limit updated to ' . $limit);
                }
                // Map is not in config, we do an insert
                else {
                    $config['MAPLIST'][0]['MAP'][] = array(
                        'FILENAME' => array($aseco->server->maps->current->filename),
                        'LIMIT' => array($limit)
                    );
                    $aseco->console('[Maptime] Limit of ' . $limit . ' set');
                }
                $message = new Message('plugin.maptime', 'set_limit');
                $message->addPlaceholders($limit, $map_name);
                $message->sendChatMessage();
            // Set the default time limit for all maps
            } elseif ($args[0] == "default" && is_numeric($args[1])) {
                $limit = floatval($args[1]);
                $config['DEFAULT'][0] = $limit;
                $aseco->console('[Maptime] Default limit of ' . $limit . ' set');
                $message = new Message('plugin.maptime', 'set_default_limit');
                $message->addPlaceholders($limit);
                $message->sendChatMessage();
            // Incorrect command usage
            } else {
                $message = new Message('plugin.maptime', 'usage_error');
                $message->sendChatMessage($login);
            }
            $this->maptime_saveconfig($aseco, $config);
        // Must be admin to set time limit
        } else {
            $message = new Message('plugin.maptime', 'admin_error');
            $message->sendChatMessage($login);
        }
    }
}
?>
