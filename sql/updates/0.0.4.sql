UPDATE `#__passwordcontrol_meta` SET version = '0.0.4' WHERE type='plugin';

-- SET SESSION binlog_format = 'MIXED';
ALTER TABLE `#__passwordcontrol`
 DROP FOREIGN KEY `#__passwordcontrol_ibfk_1`,
 ADD seq_id INT NOT NULL DEFAULT '0' COMMENT 'Sequential password counter' AFTER uid;
ALTER TABLE `#__passwordcontrol`
 ADD CONSTRAINT `#__passwordcontrol_ibfk_1` FOREIGN KEY (uid) REFERENCES `#__users` (id) ON UPDATE RESTRICT ON DELETE CASCADE;
ALTER TABLE `#__passwordcontrol`
  ADD UNIQUE INDEX idx_uid_seqid (uid, seq_id);
ALTER TABLE `#__passwordcontrol` DROP INDEX idx_uid;

COMMIT;