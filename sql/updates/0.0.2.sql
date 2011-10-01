CREATE TABLE IF NOT EXISTS `#__passwordcontrol_meta` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Primary Key',
  `version` varchar(100) COMMENT 'Version number of the installed component.',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;

ALTER TABLE `#__users` ENGINE=InnoDB;
-- SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `#__passwordcontrol` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Primary Key',
  `uid` int(11) unsigned NOT NULL COMMENT 'Users Primary Key',
  `last_password_change` datetime NOT NULL DEFAULT '0000-00-00 00:00:00' COMMENT 'Date and Time when password was last changed.',
  `old_password` varchar(100) NOT NULL COMMENT 'Users last encrypted password.',
  PRIMARY KEY (`id`),
  UNIQUE `idx_uid` (`uid`), 
  FOREIGN KEY (`uid`) REFERENCES `#__users` (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ; 

INSERT IGNORE INTO `#__passwordcontrol` (uid, last_password_change, old_password)
SELECT id, registerDate, password FROM `#__users`;

-- SET FOREIGN_KEY_CHECKS=1;

INSERT INTO `#__passwordcontrol_meta` (version) values ("0.0.2");

COMMIT;