<?php

declare(strict_types=1);

namespace danog\MadelineProto\Db\Driver;

use Amp\Postgres\ConnectionConfig;
use Amp\Postgres\Pool;
use Amp\Sql\ConnectionException;
use Amp\Sql\FailureException;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Settings\Database\Postgres as DatabasePostgres;
use Generator;
use Throwable;

use function Amp\Postgres\Pool;

/**
 * Postgres driver wrapper.
 *
 * @internal
 */
class Postgres
{
    /** @var Pool[] */
    private static array $connections = [];

    /**
     * @throws ConnectionException
     * @throws FailureException
     * @throws Throwable
     * @return Generator<Pool>
     */
    public static function getConnection(DatabasePostgres $settings)
    {
        $dbKey = $settings->getKey();
        if (empty(static::$connections[$dbKey])) {
            $config = ConnectionConfig::fromString("host=".\str_replace("tcp://", "", $settings->getUri()))
                ->withUser($settings->getUsername())
                ->withPassword($settings->getPassword())
                ->withDatabase($settings->getDatabase());

            static::createDb($config);
            static::$connections[$dbKey] = new Pool($config, $settings->getMaxConnections(), $settings->getIdleTimeout());
        }

        return static::$connections[$dbKey];
    }

    /**
     * @throws ConnectionException
     * @throws FailureException
     * @throws Throwable
     */
    private static function createDb(ConnectionConfig $config): void
    {
        try {
            $db = $config->getDatabase();
            $user = $config->getUser();
            $connection = pool($config->withDatabase(null));

            $result = $connection->query("SELECT * FROM pg_database WHERE datname = '{$db}'");

            while ($result->advance()) {
                $row = $result->getCurrent();
                if ($row===false) {
                    $connection->query("
                            CREATE DATABASE {$db}
                            OWNER {$user}
                            ENCODING utf8
                        ");
                }
            }
            $connection->query("
                    CREATE OR REPLACE FUNCTION update_ts()
                    RETURNS TRIGGER AS $$
                    BEGIN
                       IF row(NEW.*) IS DISTINCT FROM row(OLD.*) THEN
                          NEW.ts = now(); 
                          RETURN NEW;
                       ELSE
                          RETURN OLD;
                       END IF;
                    END;
                    $$ language 'plpgsql'
                ");
            $connection->close();
        } catch (Throwable $e) {
            Logger::log($e->getMessage(), Logger::ERROR);
        }
    }
}
