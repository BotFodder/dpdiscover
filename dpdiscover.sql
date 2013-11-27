

DROP TABLE IF EXISTS `plugin_dpdiscover_hosts`;
CREATE TABLE `plugin_dpdiscover_hosts` (
  `hostname` varchar(100) NOT NULL default '',
  `description` varchar(100) NOT NULL default '',
  `ip` varchar(17) NOT NULL default '',
  `snmp_community` varchar(100) NOT NULL default '',
  `snmp_version` tinyint(1) NOT NULL default 2,
  `snmp_username` varchar(50),
  `snmp_password` varchar(50),
  `snmp_auth_protocol` char(5) default '',
  `snmp_priv_passphrase` varchar(200) default '',
  `snmp_priv_protocol` char(6) default '',
  `snmp_context` varchar(64) default '',
  `sysName` varchar(100) NOT NULL default '',
  `sysLocation` varchar(255) NOT NULL default '',
  `sysContact` varchar(255) NOT NULL default '',
  `sysDescr` varchar(255) NOT NULL default '',
  `sysUptime` int(32) NOT NULL default '0',
  `os` varchar(64) NOT NULL default '',
  `snmp_status` tinyint(4) NOT NULL default '0',
  `protocol` varchar(10) NOT NULL default '',
  `parent` varchar(100) NOT NULL default '',
  `port` varchar(100) NOT NULL default '',
  `time` int(11) NOT NULL default '0',
  PRIMARY KEY  (`hostname`)
) ENGINE=MyISAM;


-- 
-- Table structure for table `plugin_discover_template`
-- 

DROP TABLE IF EXISTS `plugin_dpdiscover_template`;
CREATE TABLE `plugin_dpdiscover_template` (
  `id` int(8) NOT NULL auto_increment,
  `host_template` int(8) NOT NULL default '0',
  `sysdescr` varchar(255) NOT NULL default '',
  PRIMARY KEY  (`id`)
) ENGINE=MyISAM;

