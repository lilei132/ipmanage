CREATE TABLE IF NOT EXISTS port_traffic_history (
  id int(11) unsigned NOT NULL AUTO_INCREMENT,
  device_id int(11) unsigned NOT NULL,
  if_index int(11) unsigned NOT NULL,
  if_name varchar(255) NOT NULL,
  if_description varchar(255) DEFAULT NULL,
  in_octets bigint(20) unsigned DEFAULT '0',
  out_octets bigint(20) unsigned DEFAULT '0',
  in_errors int(11) unsigned DEFAULT '0',
  out_errors int(11) unsigned DEFAULT '0',
  speed bigint(20) unsigned DEFAULT '0',
  oper_status varchar(50) DEFAULT 'unknown',
  timestamp datetime NOT NULL,
  PRIMARY KEY (id),
  KEY idx_device_if (device_id,if_index),
  KEY idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Port traffic history';



