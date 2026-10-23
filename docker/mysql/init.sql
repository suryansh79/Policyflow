CREATE DATABASE IF NOT EXISTS policyflow;
CREATE DATABASE IF NOT EXISTS policyflow_testing;
GRANT ALL PRIVILEGES ON policyflow.* TO 'policyflow'@'%';
GRANT ALL PRIVILEGES ON policyflow_testing.* TO 'policyflow'@'%';
FLUSH PRIVILEGES;
