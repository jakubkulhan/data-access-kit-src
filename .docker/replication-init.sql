-- MySQL/MariaDB Replication Setup for Integration Tests

-- Create replication user
CREATE USER 'replication_test'@'%' IDENTIFIED BY 'replication_test';
GRANT REPLICATION SLAVE, REPLICATION CLIENT ON *.* TO 'replication_test'@'%';

FLUSH PRIVILEGES;