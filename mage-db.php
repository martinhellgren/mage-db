<?php
/**
 * @package Mage_Database_Tool
 * @origin https://gist.github.com/ceckoslab/4495889
 * @author Thomas Uhlig <tuhlig@mediarox.de>
 */

define('DS', DIRECTORY_SEPARATOR);
libxml_use_internal_errors(true);

/**
 * Magento Mysql tool for recurring import and export tasks.
 */
class Mage_Database_Tool
{
    private $script = 'mage-db.php';
    private $version = 'v0.02.40';

    private $args = array();
    private $argCount = 0;

    protected $searchUpward = true;
    const MAX_SEARCH_STEPS_UPWARD = 10;
    protected $pathPrefix;
    protected $configPath;

    protected $doCommands = true;

    protected $tablesToIgnore = array(
        'adminnotification_inbox',
        'aw_core_logger',
        'dataflow_batch_export',
        'dataflow_batch_import',
        'log_customer',
        'log_quote',
        'log_summary',
        'log_summary_type',
        'log_url',
        'log_url_info',
        'log_visitor',
        'log_visitor_info',
        'log_visitor_online',
        'index_event',
        'report_event',
        'report_viewed_product_index',
        'report_compared_product_index',
        'catalog_compare_item',
        'catalogindex_aggregation',
        'catalogindex_aggregation_tag',
        'catalogindex_aggregation_to_tag',
    );

    protected $debug = false;
    protected $verbose = false;

    const XPATH_CONNECTION_MODEL = 'global/resources/default_setup/connection/model';
    const XPATH_CONNECTION_TYPE = 'global/resources/default_setup/connection/type';
    const XPATH_CONNECTION_ACTIVE = 'global/resources/default_setup/connection/active';
    const XPATH_CONNECTION_HOST = 'global/resources/default_setup/connection/host';
    const XPATH_CONNECTION_NAME = 'global/resources/default_setup/connection/dbname';
    const XPATH_CONNECTION_USER = 'global/resources/default_setup/connection/username';
    const XPATH_CONNECTION_PASS = 'global/resources/default_setup/connection/password';
    const XPATH_CONNECTION_PREF = 'global/resources/db/table_prefix';

    const XPATH_BASEURL_UNSECURE = 'global/web/base_url/unsecure';
    const XPATH_BASEURL_SECURE = 'global/web/base_url/secure';

    const XPATH_REMOTE_CONNECTION_MODEL = 'global/resources/default_setup/connection/model';
    const XPATH_REMOTE_CONNECTION_TYPE = 'global/resources/default_setup/connection/type';
    const XPATH_REMOTE_CONNECTION_ACTIVE = 'global/remote_access/default_setup/connection/active';
    const XPATH_REMOTE_CONNECTION_HOST = 'global/remote_access/default_setup/connection/host';
    const XPATH_REMOTE_CONNECTION_NAME = 'global/remote_access/default_setup/connection/dbname';
    const XPATH_REMOTE_CONNECTION_USER = 'global/remote_access/default_setup/connection/username';
    const XPATH_REMOTE_CONNECTION_PASS = 'global/remote_access/default_setup/connection/password';
    const XPATH_REMOTE_CONNECTION_PREF = 'global/remote_access/db/table_prefix';

    private $db = false;
    const DB_MODEL = 'model';
    const DB_TYPE = 'type';
    const DB_ACTIVE = 'active';
    const DB_HOST = 'host';
    const DB_PORT = 'port';
    const DB_NAME = 'name';
    const DB_USER = 'user';
    const DB_PASS = 'pass';
    const DB_PREF = 'pref';
    const DB_HTTP = 'http';
    const DB_HTTPS = 'https';

    protected $xmlXpath = array(
        'local' => array(
            self::DB_TYPE => self::XPATH_CONNECTION_TYPE,
            self::DB_MODEL => self::XPATH_CONNECTION_MODEL,
            self::DB_ACTIVE => self::XPATH_CONNECTION_ACTIVE,
            self::DB_NAME => self::XPATH_CONNECTION_NAME,
            self::DB_HOST => self::XPATH_CONNECTION_HOST,
            self::DB_USER => self::XPATH_CONNECTION_USER,
            self::DB_PASS => self::XPATH_CONNECTION_PASS,
            self::DB_PREF => self::XPATH_CONNECTION_PREF,
            self::DB_HTTP => self::XPATH_BASEURL_UNSECURE,
            self::DB_HTTPS => self::XPATH_BASEURL_SECURE,
        ),
        'remote' => array(
            self::DB_TYPE => self::XPATH_REMOTE_CONNECTION_TYPE,
            self::DB_MODEL => self::XPATH_REMOTE_CONNECTION_MODEL,
            self::DB_ACTIVE => self::XPATH_REMOTE_CONNECTION_ACTIVE,
            self::DB_NAME => self::XPATH_REMOTE_CONNECTION_NAME,
            self::DB_HOST => self::XPATH_REMOTE_CONNECTION_HOST,
            self::DB_USER => self::XPATH_REMOTE_CONNECTION_USER,
            self::DB_PASS => self::XPATH_REMOTE_CONNECTION_PASS,
            self::DB_PREF => self::XPATH_REMOTE_CONNECTION_PREF,
            self::DB_HTTP => 'none',
            self::DB_HTTPS => 'none',
        )
    );

    const DB_MODEL_MYSQL = 'mysql4';

    const DB_TYPE_LOCAL = 'local';
    const DB_TYPE_REMOTE = 'remote';
    protected $dbType = self::DB_TYPE_LOCAL;

    protected $xml = null;
    protected $xmlValid = false;
    protected $xmlInfoList = array(
        self::DB_MODEL,
        self::DB_TYPE,
        self::DB_NAME,
        self::DB_HOST,
        self::DB_PORT,
        self::DB_USER,
        self::DB_PASS,
        self::DB_PREF,
        self::DB_HTTP,
        self::DB_HTTPS,
    );

    const ACTION_SHOW_XML = 'show_xml';
    const ACTION_EXPORT = 'export';
    const ACTION_IMPORT = 'import';
    const ACTION_EXECUTE = 'run';
    const ACTION_CLEAR = 'clear';
    const ACTION_TEST = 'test';

    protected $errorMessages = array(
        'arg1' => "unknown argument found",
        'import1' => "import file missing or import file not found",
        'run1' => "sql script missing or sql script not found",
        'xml1' => "magento root directory missing or invalid",
        'export1' => "could not create export file",
        'export2' => "directory 'var' does not exist",
        'export3' => "you can't export to a directory",
        'access' => "remote mode allows read db only",
        'exec1' => "can't create process",
        'exec2' => "execute command returned with error",
        'get_tables1' => "unable to read tables from database",
        'get_tables2' => "execute sql is disabled, use -v to get tables in debug mode",
    );

    const NOT_SET = '(not set)';
    const MSG_OK = ", ok\n";
    const MSG_DONE = ", done\n";
    protected $msg_done = self::MSG_DONE;

    const COMMAND_MYSQL = 'mysql';
    const COMMAND_MYSQL_DUMP = 'mysqldump';

    protected $commandStack = array();
    protected $errorCounter = 0;

    /**
     * Constructor
     */
    public function __construct($argv)
    {
        $this->script = $argv[0];
        $this->args = $argv;
        $this->argCount = count($argv) - 1;
        $this->pathPrefix = '';
        $this->configPath = 'app' . DS . 'etc' . DS . 'local.xml';
        $this->db = array(
            self::DB_MODEL => false,
            self::DB_TYPE => false,
            self::DB_HOST => false,
            self::DB_PORT => false,
            self::DB_NAME => false,
            self::DB_USER => false,
            self::DB_PASS => false,
            self::DB_PREF => false,
            self::DB_HTTP => false,
            self::DB_HTTPS => false,
        );
    }

    protected function _getGeneralAccessStatement($includeDbName = true)
    {
        switch ($this->db[self::DB_MODEL])
        {
            case self::DB_MODEL_MYSQL:
                $_result = ' --user=' . $this->db[self::DB_USER];
                $_result .= ($this->db[self::DB_PASS] ? ' --password=' . $this->db[self::DB_PASS] : '');
                $_result .= ' --host=' . $this->db[self::DB_HOST];
                $_result .= ($this->db[self::DB_PORT] ? ' --port=' . $this->db[self::DB_PORT] : '');
                $_result .= ($includeDbName ? ' ' . $this->db[self::DB_NAME] : '');

                return $this->_getStatementFinish($_result);
                break;

            default:
                return null;
                break;
        }
    }

    protected function _getDumpSchemaStatement($sqlFileName)
    {
        switch ($this->db[self::DB_MODEL])
        {
            case self::DB_MODEL_MYSQL:
                $_result = self::COMMAND_MYSQL_DUMP;
                $_result .= ' --no-data';
                $_result .= $this->_getGeneralAccessStatement();
                $_result .= ' > "' . $sqlFileName . '"';

                return $this->_getStatementFinish($_result);
                break;

            default:
                return null;
                break;
        }
    }

    protected function _getDumpDataStatement($sqlFileName)
    {
        switch ($this->db[self::DB_MODEL])
        {
            case self::DB_MODEL_MYSQL:
                $tablesToIgnore = '';
                foreach ($this->tablesToIgnore as $table) {
                    $tablesToIgnore .= ' --ignore-table="' . $this->db[self::DB_NAME] . '.' . $this->db[self::DB_PREF] . $table .'"';
                }
                $_result = self::COMMAND_MYSQL_DUMP;
                $_result .= $tablesToIgnore;
                $_result .= $this->_getGeneralAccessStatement();
                $_result .= ' >> "' . $sqlFileName . '"';

                return $this->_getStatementFinish($_result);
                break;

            default:
                return null;
                break;
        }
    }

    protected function _getAllTablesStatement()
    {
        switch ($this->db[self::DB_MODEL])
        {
            case self::DB_MODEL_MYSQL:
                $_result = self::COMMAND_MYSQL;
                $_result .= $this->_getGeneralAccessStatement(false);
                $_result .= ' --skip-column-names --silent --execute="show tables"';
                $_result .= ' ' . $this->db[self::DB_NAME];

                return $this->_getStatementFinish($_result);
                break;

            default:
                return null;
                break;
        }
    }

    protected function _getDropTableStatement($table)
    {
        switch ($this->db[self::DB_MODEL])
        {
            case self::DB_MODEL_MYSQL:
                $_result = self::COMMAND_MYSQL;
                $_result .= $this->_getGeneralAccessStatement(false);
                $_result .= ' --execute="SET FOREIGN_KEY_CHECKS = 0; drop table ' . $table .'"';
                $_result .= ' ' . $this->db[self::DB_NAME];

                return $this->_getStatementFinish($_result);
                break;

            default:
                return null;
                break;
        }
    }

    protected function _getImportSqlFileStatement($sqlFileName)
    {
        switch ($this->db[self::DB_MODEL])
        {
            case self::DB_MODEL_MYSQL:
                $_result = self::COMMAND_MYSQL;
                $_result .= $this->_getGeneralAccessStatement();
                $_result .= ' < "' . $sqlFileName .'"';

                return $this->_getStatementFinish($_result);
                break;

            default:
                return null;
                break;
        }
    }

    protected function _getExecuteSqlFileStatement($sqlFileName)
    {
        switch ($this->db[self::DB_MODEL])
        {
            case self::DB_MODEL_MYSQL:
                $_result = self::COMMAND_MYSQL;
                $_result .= $this->_getGeneralAccessStatement();
                $_result .= ' < "' . $sqlFileName .'"';

                return $this->_getStatementFinish($_result);
                break;

            default:
                return null;
                break;
        }
    }

    protected function _getSetBaseUrlUnsecureStatement()
    {
        switch ($this->db[self::DB_MODEL])
        {
            case self::DB_MODEL_MYSQL:
                $_result = self::COMMAND_MYSQL;
                $_result .= $this->_getGeneralAccessStatement(false);
                $_result .= ' --execute="UPDATE core_config_data SET value=\'' . $this->db[self::DB_HTTP] . '\' WHERE path=\'web/unsecure/base_url\'"';
                $_result .= ' ' . $this->db[self::DB_NAME];

                return $this->_getStatementFinish($_result);
                break;

            default:
                return null;
                break;
        }
    }

    protected function _getSetBaseUrlSecureStatement()
    {
        switch ($this->db[self::DB_MODEL])
        {
            case self::DB_MODEL_MYSQL:
                $_result = self::COMMAND_MYSQL;
                $_result .= $this->_getGeneralAccessStatement(false);
                $_result .= ' --execute="UPDATE core_config_data SET value=\'' . $this->db[self::DB_HTTPS] . '\' WHERE path=\'web/secure/base_url\'"';
                $_result .= ' ' . $this->db[self::DB_NAME];

                return $this->_getStatementFinish($_result);
                break;

            default:
                return null;
                break;
        }
    }

    protected function _getTestDatabaseConnectionStatement()
    {
        switch ($this->db[self::DB_MODEL])
        {
            case self::DB_MODEL_MYSQL:
                $_result = self::COMMAND_MYSQL;
                $_result .= $this->_getGeneralAccessStatement(false);
                $_result .= ' --execute="exit"';
                $_result .= ' ' . $this->db[self::DB_NAME];

                return $this->_getStatementFinish($_result);
                break;

            default:
                return null;
                break;
        }
    }

    protected function _getStatementFinish($statement)
    {
        switch ($this->db[self::DB_MODEL])
        {
            case self::DB_MODEL_MYSQL:
                return $statement; // ' 2>&1' ' 2>/dev/null'
                break;

            default:
                return $statement;
                break;
        }
    }

    /**
     * Print a single DB information
     *
     * @param $value
     */
    public function printXmlLine($value)
    {
        printf("db.%s = %s\n",
            $value,
            ($this->db[$value] ? $this->db[$value] : self::NOT_SET)
        );
    }

    /**
     * Run a single command
     *
     * @param string $action_code
     * @param string $sqlFileName
     * @return bool
     */
    protected function runCommand($action_code, $sqlFileName = null)
    {
        switch ($action_code)
        {
            case self::ACTION_SHOW_XML:
                if ($this->xmlValid) {
                    printf("\nfile = %s\n", $this->pathPrefix . $this->configPath);
                    foreach ($this->xmlInfoList as $info)
                    {
                        $this->printXmlLine($info);
                    }
                }
                break;

            case self::ACTION_EXPORT:
                if (!$sqlFileName) {
                    $sqlFileName = $this->getDefaultExportFile();
                }
                if (0 == $this->errorCounter) {
                    if (is_writeable(dirname($sqlFileName))) {
                        echo "db.export('$sqlFileName')";
                        // extract DB schema
                        $_command = $this->_getDumpSchemaStatement($sqlFileName);
                        $this->executeCommand($_command);

                        if (0 == $this->errorCounter) {
                            // extract DB data
                            $_command = $this->_getDumpDataStatement($sqlFileName);
                            $this->executeCommand($_command);
                            echo $this->msg_done;
                        }
                    } else {
                        $this->handleError('export1', $sqlFileName);
                    }
                }
                break;

            case self::ACTION_IMPORT:
                if ($this->isLocalAccess()) {
                    echo "db.import('$sqlFileName')";
                    $_command = $this->_getImportSqlFileStatement($sqlFileName);
                    $this->executeCommand($_command);
                    echo $this->msg_done;

                    // set shop base urls (if defined in local.xml)
                    if ($this->db['http']) {
                        echo "db.setBaseURLs()";
                        $_command = $this->_getSetBaseUrlUnsecureStatement();
                        $this->executeCommand($_command);

                        $_command = $this->_getSetBaseUrlSecureStatement();
                        $this->executeCommand($_command);
                        echo $this->msg_done;
                    }
                } else {
                    $this->handleError('access');
                }
                break;

            case self::ACTION_EXECUTE:
                if ($this->isLocalAccess()) {
                    echo "db.run('$sqlFileName')";
                    $_command = $this->_getExecuteSqlFileStatement($sqlFileName);
                    $this->executeCommand($_command);
                    echo $this->msg_done;
                } else {
                    $this->handleError('access');
                }
                break;

            case self::ACTION_CLEAR:
                if ($this->isLocalAccess()) {
                    echo "db.getAllTables()";
                    // get all tables in DB
                    $_command = $this->_getAllTablesStatement();
                    $allTables = $this->executeCommand($_command, (true == $this->verbose), true);
                    if (is_array($allTables)) {
                        $countTables = count($allTables);
                        if ($this->debug) {
                            echo "\n-> found " . $countTables . " table(s)";
                        } else {
                            echo ", found " . $countTables . " table(s)";
                        }
                        echo $this->msg_done;
                        if ($countTables > 0) {
                            // drop each table in DB
                            echo "db.dropTables()";
                            if ($this->verbose) {
                                echo "\n";
                            }
                            foreach ($allTables as $table) {
                                if ($table) {
                                    $_command = $this->_getDropTableStatement($table);
                                    if ($this->verbose) {
                                        echo "db.dropTable('$table')";
                                    }
                                    $this->executeCommand($_command);
                                    if ($this->verbose) {
                                        echo $this->msg_done;
                                    }
                                }
                            }
                            if (!$this->verbose) {
                                echo $this->msg_done;
                            }
                        }
                    } else {
                        if ($this->debug) {
                            $this->handleError('get_tables2', null, true);
                        } else {
                            $this->handleError('get_tables1');
                        }
                    }
                } else {
                    $this->handleError('access');
                }
                break;
            case self::ACTION_TEST:
                echo "db.testConnection()";
                $_command = $this->_getTestDatabaseConnectionStatement();
                if (false !== $this->executeCommand($_command)) {
                    echo ", ok";
                    echo $this->msg_done;
                } else {
                    echo ", failed";
                    echo $this->msg_done;
                }
                break;
        }
    }

    /**
     *
     * @return bool
     */
    protected function isLocalAccess()
    {
        return (self::DB_TYPE_LOCAL == $this->dbType);
    }

    /**
     * Load "local.xml" if not loaded yet.
     */
    protected function loadXml()
    {
        if (!$this->xmlValid) {
            $this->searchLocalXml();
        }
        if (!$this->xmlValid) {
            echo ", no valid local.xml found, therefore this is it.\n";
        }
    }

    /**
     * Search "local.xml" wrapper
     */
    protected function searchLocalXml()
    {
        $i = self::MAX_SEARCH_STEPS_UPWARD;
        while ($i > 0) {
            if (is_null($this->xml)) {
                echo "looking for local.xml";
            }

            $this->xml = simplexml_load_file($this->pathPrefix . $this->configPath, NULL, LIBXML_NOCDATA);

            if (false !== $this->xml) {
                $this->parseLocalXml();
                if ($this->xmlValid) {
                    switch ($this->db[self::DB_MODEL])
                    {
                        case self::DB_MODEL_MYSQL:
                            break;

                        default:
                            $this->db[self::DB_MODEL] = self::DB_MODEL_MYSQL;
                            break;
                    }

                    echo self::MSG_DONE;
                    break;
                }
            }

            if ($this->searchUpward) {
                $this->pathPrefix = '..' . DS . $this->pathPrefix;
            } else {
                $i = 0;
            }
            $i--;
        }
    }

    /**
     * Parse information within "local.xml"
     *
     * Step 1: Inform about located "local.xml"
     * Step 2: Parse host and optional port (localhost<:3306>)
     * Step 3: Parse remaining db-settings
     * Step 4: Check db-data integrity
     */
    protected function parseLocalXml()
    {
        // Step 1
        if ($this->verbose) {
            printf(", found one at %s", $this->pathPrefix . $this->configPath);
        }

        if (($_data = $this->xml->xpath($this->xmlXpath[$this->dbType][self::DB_ACTIVE])) && ('1' == (string)$_data[0])) {
            // Step 2
            if ($_data = $this->xml->xpath($this->xmlXpath[$this->dbType][self::DB_HOST])) {
                $host = explode(':', (string)$_data[0]);
                $this->db[self::DB_HOST] = $host[0];
                $this->db[self::DB_PORT] = (count($host) > 1) ? $host[1] : false;
            }

            // Step 3
            if ($_data = $this->xml->xpath($this->xmlXpath[$this->dbType][self::DB_MODEL])) {
                $this->db[self::DB_MODEL] = (string)$_data[0];
            }
            if ($_data = $this->xml->xpath($this->xmlXpath[$this->dbType][self::DB_TYPE])) {
                $this->db[self::DB_TYPE] = (string)$_data[0];
            }
            if ($_data = $this->xml->xpath($this->xmlXpath[$this->dbType][self::DB_NAME])) {
                $this->db[self::DB_NAME] = (string)$_data[0];
            }
            if ($_data = $this->xml->xpath($this->xmlXpath[$this->dbType][self::DB_USER])) {
                $this->db[self::DB_USER] = (string)$_data[0];
            }
            if ($_data = $this->xml->xpath($this->xmlXpath[$this->dbType][self::DB_PASS])) {
                $this->db[self::DB_PASS] = (string)$_data[0];
            }
            if ($_data = $this->xml->xpath($this->xmlXpath[$this->dbType][self::DB_PREF])) {
                $this->db[self::DB_PREF] = (string)$_data[0];
            }
            if ($_data = $this->xml->xpath($this->xmlXpath[$this->dbType][self::DB_HTTP])) {
                $this->db[self::DB_HTTP] = (string)$_data[0];
            }
            if ($_data = $this->xml->xpath($this->xmlXpath[$this->dbType][self::DB_HTTPS])) {
                $this->db[self::DB_HTTPS] = (string)$_data[0];
            }
            if (!$this->db[self::DB_HTTPS]) {
                $this->db[self::DB_HTTPS] = $this->db[self::DB_HTTP];
            }
        }

        // Step 4
        if ($this->db[self::DB_HOST] &&
            $this->db[self::DB_NAME] &&
            $this->db[self::DB_USER] &&
            (false !== $this->db[self::DB_PASS])) {
            $this->xmlValid = true;
        } else {
            if ($this->verbose) {
                echo ", but invalid\n";
            }
        }
    }

    /**
     * @param $command
     * @param bool $forceExecute
     * @param bool $retStdOutAsArray
     * @return bool|mixed
     */
    protected function executeCommand($command, $forceExecute = false, $retStdOutAsArray = false)
    {
        if ($this->debug) {
            echo "\n-> command to execute:\n$command";
        }
        if ((($this->debug == $forceExecute) || $forceExecute) && 0 == $this->errorCounter) {
            $descriptorSpec = array(
                0 => array('pipe', 'r'),
                1 => array('pipe', 'w'),
                2 => array('pipe', 'w')
            );
            $process = proc_open($command, $descriptorSpec, $pipes);
            if (is_resource($process)) {
                stream_set_blocking($pipes[0], false);
                stream_set_blocking($pipes[1], false);

                $stdOut = '';
                while (!feof($pipes[1])) {
                    $stdOut .= fgets($pipes[1], 1024);
                }
                $stdErr = '';
                while (!feof($pipes[2])) {
                    $stdErr .= fgets($pipes[2], 1024);
                }

                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);

                $retVal = proc_close($process);
                if (!$retVal) {
                    if ($retStdOutAsArray) {
                        $stdOut = explode("\n", $stdOut);
                        $stdOut = is_array($stdOut) ? $stdOut : array($stdOut);
                        foreach ($stdOut as $key => $table) {
                            if (!$table) {
                                unset($stdOut[$key]);
                            }
                        }
                    }
                    return $stdOut;
                } else {
                    $this->handleError('exec2', null, true);
                    echo $stdErr."\n";
                    die();
                }

            } else {
                $this->handleError('exec1');
            }
            return false;
        }
    }

    /**
     * Run db tasks
     *
     * Step 1: Parse script arguments
     * Step 2: Load xml configuration
     * Step 3: Process command stack
     */
    public function run()
    {
        // Step 1
        $this->parseArguments();

        if ($this->doCommands && 0 == $this->errorCounter) {
            // Step 2
            $this->loadXml();

            if ($this->doCommands && 0 == $this->errorCounter && $this->xmlValid) {
                // Step 3
                $this->processCommandStack();
            }
        }
    }

    /**
     * Parse command line arguments and prepare command stack
     */
    protected function parseArguments()
    {
        if (0 == $this->argCount) {
            $this->pushCommand(self::ACTION_EXPORT, null);
        } else {
            $i = 1;
            while (isset($this->args[$i]) && 0 == $this->errorCounter) {
                $arg = $this->args[$i++];
                $file = isset($this->args[$i]) ? $this->args[$i] : '';
                switch ($arg)
                {
                    case '--help':
                    case '-h':
                    case '-H':
                    case '-?':
                        $this->showHelp();
                        $this->errorCounter++;
                        break;

                    case '--verbose':
                    case '-v':
                        $this->verbose = true;
                        break;

                    case '--debug':
                    case '-d':
                        $this->debug = true;
                        $this->msg_done = "\n";
                        break;

                    case '--show':
                    case '-s':
                        $this->pushCommand(self::ACTION_SHOW_XML, false);
                        break;

                    case '--export':
                    case '-e':
                        if ($file && '-' != substr($file, 0, 1)) {
                            $i++;
                        } else {
                            $file = '';
                        }
                        if ($file && is_dir($file)) {
                            $this->handleError('export3', $file);
                        } else {
                            $this->pushCommand(self::ACTION_EXPORT, $file);
                        }
                        break;

                    case '--import':
                    case '-i':
                        if ($file && file_exists($file) && !is_dir($file)) {
                            $this->pushCommand(self::ACTION_CLEAR, null);
                            $this->pushCommand(self::ACTION_IMPORT, $file);
                            $i++;
                        } else {
                            $this->handleError('import1', $file);
                        }
                        break;

                    case '--run':
                    case '-r':
                        if (file_exists($file)) {
                            $this->pushCommand(self::ACTION_EXECUTE, $file);
                            $i++;
                        } else {
                            $this->handleError('run1', $file);
                        }
                        break;

                    case '--xml':
                    case '-x':
                        if ($file && is_dir($file)) {
                            $this->searchUpward = false;
                            $this->pathPrefix = $file;
                            if (DS != substr($this->pathPrefix, -1)) {
                                $this->pathPrefix .= DS;
                            }
                            $i++;
                        } else {
                            $this->handleError('xml1', $file);
                        }
                        break;

                    case '--clear':
                    case '-c':
                        $this->pushCommand(self::ACTION_CLEAR, null);
                        break;

//                    case '--remote':
//                    case '-r':
//                        $this->dbType = self::DB_TYPE_REMOTE;
//                        break;

//                    case '--force':
//                    case '-f':
//                        $this->executeWithoutAsk = true;
//                        break;

                    case '--test':
                    case '-t':
                        $this->pushCommand(self::ACTION_TEST, null);
                        break;

                    default:
                        $this->handleError('arg1', $arg);
                        $this->doCommands = false;
                        break;
                }
            }
        }
    }

    /**
     * Get default file for export
     *
     * @return string|null
     */
    protected function getDefaultExportFile()
    {
        if (is_dir($this->pathPrefix . 'var')) {
            return $this->pathPrefix . 'var' . DS . date('Y-m-d-His') . '_' . $this->db[self::DB_NAME] . '.sql';
        } else {
            $this->handleError('export2');
            return null;
        }
    }

    /**
     * Handle wrapper for command errors
     *
     * @param string $errorCode
     * @param string $arg
     * @param bool $printNL
     */
    protected function handleError($errorCode, $arg = null, $printNL = false)
    {
        if (is_string($errorCode)) {
            $errorMsg = isset($this->errorMessages[$errorCode]) ? $this->errorMessages[$errorCode] : $errorCode;
            if ($printNL) {
                printf("\n");
            }
            printf("error: " . $errorMsg . (is_null($arg) ? '' : " ('%s')") . "\n", $arg);
            $this->errorCounter++;
            $this->doCommands = false;
        }
    }

    /**
     * Push a single command and its arguments to command stack
     *
     * @param $cmd
     * @param $arg
     */
    protected function pushCommand($cmd, $arg)
    {
        $this->commandStack[] = array(
            'cmd' => $cmd,
            'arg' => $arg
        );
    }

    /**
     * Process command stack
     */
    protected function processCommandStack()
    {
        if (is_array($this->commandStack)) {
            foreach ($this->commandStack as $command) {
                if (0 == $this->errorCounter) {
                    $this->runCommand($command['cmd'], $command['arg']);
                }
            }
        }
    }

    /**
     * Print help to options and commands
     */
    protected function showHelp()
    {
        echo "Magento DB-Tool " . $this->version ."\n";
        echo "Usage: [php] ".$this->script." [OPTION]... [PARAMETER]...\n";
        echo "\n";
        echo " Options:\n";
        echo "   -d, --debug               : do not execute any sql command (just show commands)\n";
//        echo "   -r, --remote              : use db access data from config->global->remote_access->[same structure as local]\n";
//        echo "  coll run                             (when using --remote 'import' and 'run' are disabled)\n";
        echo "   -h, --help                : show this help\n";
        echo "   -s, --show                : show data found in local.xml\n";
//        echo "   -t, --test                : test DB connection\n";
        echo "   -v, --verbose             : show more details\n";
        echo "                               (if debug active: enable sql command to get all tables)\n";
        echo "\n";
        echo " Parameters:\n";
        echo "   -c, --clear               : clear DB (drop all tables)\n";
        echo "   -e, --export [FILENAME]   : dump DB to FILENAME (or to default location)\n";
        echo "   -i, --import FILENAME     : import DB from FILENAME (drop ALL tables first; set BASE_URLS optional)\n";
        echo "                               define base urls in the local.xml at nodes:\n";
        echo "                               config->global->web->base_url->{unsecure resp. secure}\n";
        echo "                               (uncomment nodes or leave blank if not needed)\n";
        echo "   -r, --run FILENAME        : execute sql script FILENAME\n";
        echo "   -x, --xml MAGENTO-ROOT    : don't look upward for local.xml, use MAGENTO-ROOT/app/etc/local.xml instead\n";
        echo "\n";
        echo " Note: run without any parameter or --export without parameter will dump the DB to default location\n";
        echo "                             : (magento-dir)/var" . DS . "{Y-m-d-His}_{DB.name}.sql\"\n";
    }
}

$mageDatabaseTool = new Mage_Database_Tool($argv);
$mageDatabaseTool->run();
echo "\n";