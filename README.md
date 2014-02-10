#Maptime Plugin

Maptime is a plugin for the XASECO2 server controller powering Trackmania2 servers. It allows for setting a custom time limit on a per map basis.

##Usage

* /limit &lt;num&gt; -- Sets custom time limit for current map
* /limit remove -- Removes custom time limit from current map
* /limit removeall -- Removes all custom time limits
* maptime.xml -- Can be edited to set default time or modify map times out of game

##Install

1. Copy plugin.maptime.php to the xaseco2/plugins folder
*  Open xaseco2/plugins.xml in a text editor
*  Add an entry for plugin.maptime.php, make sure the entry is __after__
   plugin.rasp\_jukebox.php else this plugin will not work
*  Start (or restart) XASECO2

##TODO

* custom admin rights using XASECO2 api
* replace filename storage with map's uid and add commented map name
* lament usage of XASECO2's xml parser
* add /limit default num to set default time used across maps in game
