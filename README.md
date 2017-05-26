# Maptime Plugin

Maptime is a plugin for the UASECO and XASECO2 server controllers powering Trackmania2 servers. It allows for setting a custom time limit on a per map basis.

## Usage

* /limit &lt;num&gt; -- Sets custom time limit for current map
* /limit default &lt;num&gt; -- Sets default time limit for all maps
* /limit remove -- Removes custom time limit from current map
* /limit removeall -- Removes all custom time limits
* maptime.xml -- Can be edited to set default time or modify map times out of game

## Install

### UASECO
1. Copy the contents of the uaseco folder to the uaseco install directory
2. Open uaseco/config/plugins.xml in a text editor
3. Add an entry for plugin.maptime.php, make sure the entry is __after__
   plugin.rasp\_jukebox.php else this plugin will not work
4. Start (or restart) UASECO

### XASECO2
1. Copy the contents of the xaseco2 folder to the xaseco2 install directory
2. Open xaseco2/plugins.xml in a text editor
3. Add an entry for plugin.maptime.php, make sure the entry is __after__
   plugin.rasp\_jukebox.php else this plugin will not work
4. Start (or restart) XASECO2
