<?php
namespace DreamFactory\Core\MongoDb\Services;

use DreamFactory\Core\Components\RequireExtensions;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\MongoDb\Database\Schema\Schema as DatabaseSchema;
use DreamFactory\Core\MongoDb\Resources\Table;
use DreamFactory\Core\Database\Resources\DbSchemaResource;
use DreamFactory\Core\Database\Services\BaseDbService;
use DreamFactory\Core\Utility\Session;
use Illuminate\Database\DatabaseManager;
use Jenssegers\Mongodb\Connection;

/**
 * MongoDb
 *
 * A service to handle MongoDB NoSQL (schema-less) database
 * services accessed through the REST API.
 */
class MongoDb extends BaseDbService
{
    //*************************************************************************
    //	Traits
    //*************************************************************************

    use RequireExtensions;

    //*************************************************************************
    //	Constants
    //*************************************************************************

    /**
     * Connection string prefix
     */
    const DSN_PREFIX = 'mongodb://';
    /**
     * Connection string prefix length
     */
    const DSN_PREFIX_LENGTH = 10;

    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var Connection
     */
    protected $dbConn = null;
    /**
     * @var array
     */
    protected static $resources = [
        DbSchemaResource::RESOURCE_NAME => [
            'name'       => DbSchemaResource::RESOURCE_NAME,
            'class_name' => DbSchemaResource::class,
            'label'      => 'Schema',
        ],
        Table::RESOURCE_NAME  => [
            'name'       => Table::RESOURCE_NAME,
            'class_name' => Table::class,
            'label'      => 'Table',
        ],
    ];

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * Create a new MongoDbSvc
     *
     * @param array $settings
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function __construct($settings = [])
    {
        parent::__construct($settings);

        $config = (array)array_get($settings, 'config');
        Session::replaceLookups($config, true);

        $config['driver'] = 'mongodb';
        if (!empty($dsn = strval(array_get($config, 'dsn')))) {
            // add prefix if not there
            if (0 != substr_compare($dsn, static::DSN_PREFIX, 0, static::DSN_PREFIX_LENGTH, true)) {
                $dsn = static::DSN_PREFIX . $dsn;
                $config['dsn'] = $dsn;
            }
        }

        // laravel database config requires options to be [], not null
        $options = array_get($config, 'options', []);
        if (empty($options)) {
            $config['options'] = [];
        }
        if (empty($db = array_get($config, 'database'))) {
            if (!empty($db = array_get($config, 'options.db'))) {
                $config['database'] = $db;
            } elseif (!empty($db = array_get($config, 'options.database'))) {
                $config['database'] = $db;
            } else {
                //  Attempt to find db in connection string
                $db = strstr(substr($dsn, static::DSN_PREFIX_LENGTH), '/');
                if (false !== $pos = strpos($db, '?')) {
                    $db = substr($db, 0, $pos);
                }
                $db = trim($db, '/');
                $config['database'] = $db;
            }
        }

        if (empty($db)) {
            throw new InternalServerErrorException("No MongoDb database selected in configuration.");
        }

        $driverOptions = (array)array_get($config, 'driver_options');
        if (null !== $context = array_get($driverOptions, 'context')) {
            //  Automatically creates a stream from context
            $driverOptions['context'] = stream_context_create($context);
        }

        // add config to global for reuse, todo check existence and update?
        config(['database.connections.service.' . $this->name => $config]);
        /** @type DatabaseManager $db */
        $db = app('db');
        $this->dbConn = $db->connection('service.' . $this->name);
        $this->schema = new DatabaseSchema($this->dbConn);
        $this->schema->setCache($this);
        $this->schema->setExtraStore($this);
    }

    /**
     * Object destructor
     */
    public function __destruct()
    {
        /** @type DatabaseManager $db */
        $db = app('db');
        $db->disconnect('service.' . $this->name);

        parent::__destruct();
    }

    /**
     * {@inheritdoc}
     */
    public function getResources($only_handlers = false)
    {
        $resources = [
            DbSchemaResource::RESOURCE_NAME => [
                'name'       => DbSchemaResource::RESOURCE_NAME,
                'class_name' => DbSchemaResource::class,
                'label'      => 'Schema',
            ],
            Table::RESOURCE_NAME  => [
                'name'       => Table::RESOURCE_NAME,
                'class_name' => Table::class,
                'label'      => 'Tables',
            ]
        ];

        return ($only_handlers) ? $resources : array_values($resources);
    }
}