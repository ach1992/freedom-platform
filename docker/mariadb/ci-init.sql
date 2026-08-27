CREATE DATABASE IF NOT EXISTS `freedom_platform_hidden_fk`;

CREATE USER IF NOT EXISTS 'freedom_ci_metadata'@'%' IDENTIFIED BY 'ci-only-metadata-password';
GRANT PROCESS ON *.* TO 'freedom_ci_metadata'@'%';

CREATE USER IF NOT EXISTS 'freedom_ci_metadata_unprivileged'@'%' IDENTIFIED BY 'ci-only-metadata-unprivileged-password';

CREATE USER IF NOT EXISTS 'freedom_ci_metadata_broad'@'%' IDENTIFIED BY 'ci-only-metadata-broad-password';
GRANT PROCESS ON *.* TO 'freedom_ci_metadata_broad'@'%';
GRANT SELECT ON `freedom_platform_ci`.* TO 'freedom_ci_metadata_broad'@'%';

-- Test-only authority used to construct a child table in a schema the ordinary
-- application principal cannot inspect. It exists only inside disposable CI.
CREATE USER IF NOT EXISTS 'freedom_ci_fk_builder'@'%' IDENTIFIED BY 'ci-only-fk-builder-password';
GRANT CREATE, ALTER, DROP, INDEX ON `freedom_platform_hidden_fk`.* TO 'freedom_ci_fk_builder'@'%';
GRANT SELECT ON `freedom_platform_ci`.* TO 'freedom_ci_fk_builder'@'%';

FLUSH PRIVILEGES;
