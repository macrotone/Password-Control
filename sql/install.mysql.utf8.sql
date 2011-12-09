CREATE TABLE IF NOT EXISTS `#__passwordcontrol_meta` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Primary Key',
  `version` varchar(100) COMMENT 'Version number of the installed component.',
  `type`    varchar(10) COMMENT 'Type of extension.',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;

-- Using InnoDB engine since Foreign Keys do not work with users table created with MyIsam engine.
-- So let us change the engine used by the users table.

ALTER TABLE `#__users` ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS `#__passwordcontrol` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Primary Key',
  `uid` int(11) NOT NULL COMMENT 'Users Primary Key',
  `seq_id` int(11) NOT NULL DEFAULT '0' COMMENT 'Sequential password id',
  `last_password_change` datetime NOT NULL DEFAULT '0000-00-00 00:00:00' COMMENT 'Date and Time when password was last changed.',
  `next_password_change` datetime NOT NULL DEFAULT '0000-00-00 00:00:00' COMMENT 'Date and Time when next change required.  Default value if never.',
  `old_password` varchar(100) DEFAULT NULL COMMENT 'Previous encrypted password',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_uid_seqid` (`uid`,`seq_id`),
  CONSTRAINT `#__passwordcontrol_users_ibfk_1` FOREIGN KEY (`uid`) REFERENCES `#__users` (`id`) ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;

INSERT IGNORE INTO `#__passwordcontrol` (uid, seq_id, last_password_change, old_password)
SELECT id, 0, registerDate, password FROM `#__users`;

INSERT INTO `#__passwordcontrol_meta` (version, type) values ("0.0.4", "plugin");

COMMIT; 
