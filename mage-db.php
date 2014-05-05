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
    private $version = 'v0.02.05';

    protected $searchUpward = true;
    const MAX_SEARCH_STEPS_UPWARD = 10;
    protected $pathPrefix;
    protected $configPath;

    protected $doCommands = true;

    const SHORT_OPTIONS = 'vd';
    protected $longOptions = array(
        'verbose',
        'debug'
    );

    const SHORT_OPTIONS_SINGLE = 'hHt';
    protected $longOptionsSingle = array(
        'help',
        'test'
    );

    const SHORT_OPTIONS_PAIR = 'i:r:x:e::';
    protected $longOptionsPair = array(
        'import:',
        'run:',
        'xml:',
        'export::'
    );

    protected $ignoreTables = array(
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
        'catalogindex_aggregation_to_tag'
    );

    const XPATH_CONNECTION_HOST = 'global/resources/default_setup/connection/host';
    const XPATH_CONNECTION_NAME = 'global/resources/default_setup/connection/dbname';
    const XPATH_CONNECTION_USER = 'global/resources/default_setup/connection/username';
    const XPATH_CONNECTION_PASS = 'global/resources/default_setup/connection/password';
    const XPATH_CONNECTION_PREF = 'global/resources/db/table_prefix';
    const XPATH_BASEURL_UNSECURE = 'global/resources/web/base_url/unsecure';
    const XPATH_BASEURL_SECURE = 'global/resources/web/base_url/secure';

    protected $debug = false;
    protected $verbose = false;

    protected $singleCommandHit = false;
    protected $pairCommandHit = false;

    private $db = false;
    const DB_HOST = 'host';
    const DB_PORT = 'port';
    const DB_NAME = 'name';
    const DB_USER = 'user';
    const DB_PASS = 'pass';
    const DB_PREF = 'pref';
    const DB_HTTP = 'http';
    const DB_HTTPS = 'https';

    protected $xml = null;
    protected $xmlValid = false;
    protected $xmlInfoList = array(
        self::DB_NAME,
        self::DB_HOST,
        self::DB_PORT,
        self::DB_USER,
        self::DB_PASS,
        self::DB_PREF,
        self::DB_HTTP,
        self::DB_HTTPS
    );

    const ACTION_SHOW_XML = 'show_xml';
    const ACTION_EXPORT = 'export';
    const ACTION_IMPORT = 'import';
    const ACTION_EXECUTE = 'run';

    protected $errorMessages = array(
        'import1' => 'import file missing or import file not found',
        'import2' => 'to import more than 1 file makes no sense',
        'run' => 'sql script missing or sql script not found',
        'xml1' => 'magento root directory missing or invalid',
        'xml2' => 'more than 1 magento root entered',
        'export1' => 'could not create export file',
        'export2' => 'directory "var" does not exist',
        'export3' => 'you can\'t export to a directory'
    );

    const NOT_SET = '<empty>';
    const MSG_OK = ", ok\n";
    const MSG_DONE = ", done\n";
    protected $msg_done = self::MSG_DONE;

    const COMMAND_MYSQL = 'mysql';
    const COMMAND_MYSQL_DUMP = 'mysqldump';

    protected $commandStack = array();
    protected $errorCounter = 0;

    /**
     * Init
     */
    public function __construct($argv)
    {
        $this->script = $argv[0];
        $this->pathPrefix = '.' . DS;
        $this->configPath = 'app' . DS . 'etc' . DS . 'local.xml';
        $this->db = array(
            self::DB_HOST => false,
            self::DB_PORT => false,
            self::DB_NAME => false,
            self::DB_USER => false,
            self::DB_PASS => false,
            self::DB_PREF => false,
            self::DB_HTTP => false,
            self::DB_HTTPS => false
        );
    }

    protected function _getGeneralAccessStatement($includeDbName = true)
    {
        $_result = ' --user=' . $this->db[self::DB_USER];
        $_result .= ($this->db[self::DB_PASS] ? ' --password=' . $this->db[self::DB_PASS] : '');
        $_result .= ' --host=' . $this->db[self::DB_HOST];
        $_result .= ($this->db[self::DB_PORT] ? ' --port=' . $this->db[self::DB_PORT] : '');
        $_result .= ($includeDbName ? ' ' . $this->db[self::DB_NAME] : '');

        return $_result;
    }

    protected function _getDumpSchemaStatement($sqlFileName)
    {
        $_result = self::COMMAND_MYSQL_DUMP;
        $_result .= ' --no-data';
        $_result .= $this->_getGeneralAccessStatement();
        $_result .= ' > "' . $sqlFileName . '"';

        return $_result;
    }

    protected function _getDumpDataStatement($sqlFileName)
    {
        $ignoreTables = '';
        foreach ($this->ignoreTables as $table) {
            $ignoreTables .= ' --ignore-table="' . $this->db[self::DB_NAME] . '.' . $this->db[self::DB_PREF] . $table .'"';
        }
        $_result = self::COMMAND_MYSQL_DUMP;
        $_result .= $ignoreTables;
        $_result .= $this->_getGeneralAccessStatement();
        $_result .= ' >> "' . $sqlFileName . '"';

        return $_result;
    }

    protected function _getAllTablesStatement()
    {
        $_result = self::COMMAND_MYSQL;
        $_result .= $this->_getGeneralAccessStatement(false);
        $_result .= ' --skip-column-names --silent --execute="show tables"';
        $_result .= ' ' . $this->db[self::DB_NAME];

        return $_result;
    }

    protected function _getDropTableStatement($table)
    {
        $_result = self::COMMAND_MYSQL;
        $_result .= $this->_getGeneralAccessStatement(false);
        $_result .= ' --execute="SET FOREIGN_KEY_CHECKS = 0; drop table ' . $table .'"';
        $_result .= ' ' . $this->db[self::DB_NAME];

        return $_result;
    }

    protected function _getImportSqlFileStatement($sqlFileName)
    {
        $_result = self::COMMAND_MYSQL;
        $_result .= $this->_getGeneralAccessStatement();
        $_result .= ' < "' . $sqlFileName .'"';

        return $_result;
    }

    protected function _getExecuteSqlFileStatement($sqlFileName)
    {
        $_result = self::COMMAND_MYSQL;
        $_result .= $this->_getGeneralAccessStatement();
        $_result .= ' < "' . $sqlFileName .'"';

        return $_result;
    }

    protected function _getSetBaseUrlUnsecureStatement()
    {
        $_result = self::COMMAND_MYSQL;
        $_result .= $this->_getGeneralAccessStatement(false);
        $_result .= ' --execute="UPDATE core_config_data SET value=\'' . $this->db[self::DB_HTTP] . '\' WHERE path=\'web/unsecure/base_url\'"';
        $_result .= ' ' . $this->db[self::DB_NAME];

        return $_result;
    }

    protected function _getSetBaseUrlSecureStatement()
    {
        $_result = self::COMMAND_MYSQL;
        $_result .= $this->_getGeneralAccessStatement(false);
        $_result .= ' --execute="UPDATE core_config_data SET value=\'' . $this->db[self::DB_HTTPS] . '\' WHERE path=\'web/secure/base_url\'"';
        $_result .= ' ' . $this->db[self::DB_NAME];

        return $_result;
    }

    /**
     * Print a single DB information.
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
     * Run a single command.
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
                    if (is_writeable(basename($sqlFileName))) {
                        echo "db.export('$sqlFileName')";
                        // extract DB schema
                        $_command = $this->_getDumpSchemaStatement($sqlFileName);
                        $this->_executeCommand($_command);

                        // extract DB data
                        $_command = $this->_getDumpDataStatement($sqlFileName);
                        $this->_executeCommand($_command);
                        echo $this->msg_done;
                    } else {
                        $this->handleError('export1', $sqlFileName);
                    }
                }
                break;

            case self::ACTION_IMPORT:
                echo "db.getAllTables()";
                // get all tables in DB
                $_command = $this->_getAllTablesStatement();
                $this->_executeCommand($_command);
                $allTables = explode("\n", shell_exec($_command));
                if (!$this->debug) {
                    echo ", found " . count($allTables) . "table(s)";
                } else {
                    echo "-> found " . count($allTables) . " table(s)";
                }
                echo $this->msg_done;
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
                        $this->_executeCommand($_command, true);
                        if ($this->verbose) {
                            echo $this->msg_done;
                        }
                    }
                }
                if (!$this->verbose) {
                    echo $this->msg_done;
                }

                echo "db.import('$sqlFileName')";
                $_command = $this->_getImportSqlFileStatement($sqlFileName);
                $this->_executeCommand($_command);
                echo $this->msg_done;

                // set shop base urls (if defined in local.xml)
                if ($this->db['http']) {

                    echo "db.setBaseURLs()";
                    $_command = $this->_getSetBaseUrlUnsecureStatement();
                    $this->_executeCommand($_command);

                    $_command = $this->_getSetBaseUrlSecureStatement();
                    $this->_executeCommand($_command);
                    echo $this->msg_done;

                }
                break;

            case self::ACTION_EXECUTE:
                echo "db.run('$sqlFileName')";
                $_command = $this->_getExecuteSqlFileStatement($sqlFileName);
                $this->_executeCommand($_command);
                echo $this->msg_done;
                break;
        }
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
                    echo self::MSG_DONE;
                    break;
                }
            }

            if ($this->searchUpward) {
                if (self::MAX_SEARCH_STEPS_UPWARD == $i) {
                    $this->pathPrefix = '.' . $this->pathPrefix;
                } else {
                    $this->pathPrefix = '..' . DS . $this->pathPrefix;
                }
            } else {
                $i = 0;
            }
            $i--;
        }
    }

    /**
     * Parse information within "local.xml"
     *
     * Step 1: Inform about located "local.xml".
     * Step 2: Parse host and optional port (localhost<:3306>)
     * Step 3: Parse remaining db-settings.
     * Step 4: Check db-data integrity.
     */
    protected function parseLocalXml()
    {
        // Step 1
        if ($this->verbose) {
            printf(", found one at %s", $this->pathPrefix . $this->configPath);
        }

        // Step 2
        if ($_data = $this->xml->xpath(self::XPATH_CONNECTION_HOST)) {
            $host = explode(':', (string)$_data[0]);
            $this->db[self::DB_HOST] = $host[0];
            $this->db[self::DB_PORT] = (count($host) > 1) ? $host[1] : false;
        }

        // Step 3
        if ($_data = $this->xml->xpath(self::XPATH_CONNECTION_NAME)) $this->db[self::DB_NAME] = (string)$_data[0];
        if ($_data = $this->xml->xpath(self::XPATH_CONNECTION_USER)) $this->db[self::DB_USER] = (string)$_data[0];
        if ($_data = $this->xml->xpath(self::XPATH_CONNECTION_PASS)) $this->db[self::DB_PASS] = (string)$_data[0];
        if ($_data = $this->xml->xpath(self::XPATH_CONNECTION_PREF)) $this->db[self::DB_PREF] = (string)$_data[0];
        if ($_data = $this->xml->xpath(self::XPATH_BASEURL_UNSECURE)) $this->db[self::DB_HTTP] = (string)$_data[0];
        if ($_data = $this->xml->xpath(self::XPATH_BASEURL_SECURE)) $this->db[self::DB_HTTPS] = (string)$_data[0];
        if (!$this->db[self::DB_HTTPS]) $this->db[self::DB_HTTPS] = $this->db[self::DB_HTTP];

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

    protected function _executeCommand($command, $check_verbose = false)
    {
        if ($this->debug) {
            if (!$check_verbose || $this->verbose) {
                echo "\n-> command to execute\n$command";
            }
        } else {
            try {
                exec($command);
            } catch (Exception $e) {
                echo "\n\n".$e->getMessage()."\n";
            }
        }
    }

    /**
     * Run db tasks
     *
     * Step 1: Parse script arguments.
     * Step 2: Load xml configuration.
     * Step 3: Check for simple use.
     * Step 4: Process command stack.
     *
     * @see shell command "php mage-db.php --help"
     */
    public function run()
    {
        // Step 1
        $this->parseArguments();

        if (0 == $this->errorCounter) {
            // Step 2
            $this->loadXml();

            // Step 3
            $this->checkDefaultBehaviour();

            // Step 4
            $this->processCommandStack();
        }
    }

    /**
     * Convert command line arguments to options, commands and prepare command stack.
     */
    protected function parseArguments()
    {
        $this->parseOptions();
        $this->parseSingleCommands();
        if (0 == $this->errorCounter) {
            $this->parsePairCommands();
        }
    }

    /**
     * Parse all options from arguments.
     */
    protected function parseOptions()
    {
        $options = getopt(self::SHORT_OPTIONS, $this->longOptions);

        foreach ($options as $option => $value) {
            switch ($option)
            {
                case 'verbose':
                case 'v':
                    $this->verbose = true;
                    break;
                case 'debug':
                case 'd':
                    $this->debug = true;
                    $this->msg_done = "\n";
                    break;
            }
        }
    }

    /**
     * Parse single commands from arguments.
     */
    protected function parseSingleCommands()
    {
        $commandsSingle = getopt(self::SHORT_OPTIONS_SINGLE, $this->longOptionsSingle);

        foreach ($commandsSingle as $command => $file) {
            switch ($command)
            {
                case 'help':
                case 'h':
                case 'H':
                    $this->singleCommandHit = true;
                    $this->showHelp();
                    $this->errorCounter++;
                    break;
                case 'test':
                case 't':
                    $this->singleCommandHit = true;
                    $this->pushCommand(self::ACTION_SHOW_XML, '');
                    break;
            }
            if ($this->errorCounter > 0) {
                break;
            }
        }
    }

    /**
     * Parse pair commands from arguments.
     */
    protected function parsePairCommands()
    {
        $commandsPair = getopt(self::SHORT_OPTIONS_PAIR, $this->longOptionsPair);
        foreach ($commandsPair as $command => $_files) {
            $files = is_array($_files) ? $_files : array($_files);
            switch ($command)
            {
                case 'export':
                case 'e':
                    foreach ($files as $file) {
                        if ($file && is_dir($file)) {
                            $this->handleError('export3', $file);
                        } else {
                            $this->pairCommandHit = true;
                            $this->pushCommand(self::ACTION_EXPORT, $file);
                        }
                        if ($this->errorCounter > 0) {
                            break;
                        }
                    }
                    break;
                case 'import':
                case 'i':
                    if (1 == count($files)) {
                        if (file_exists($_files) && !is_dir($files[0])) {
                            $this->pairCommandHit = true;
                            $this->pushCommand(self::ACTION_IMPORT, $files[0]);
                        } else {
                            $this->handleError('import1', $files[0]);
                        }
                    } else {
                        $this->handleError('import2');
                    }
                    break;
                case 'run':
                case 'r':
                    foreach ($files as $file) {
                        if (file_exists($file)) {
                            $this->pairCommandHit = true;
                            $this->pushCommand(self::ACTION_EXECUTE, $file);
                        } else {
                            $this->handleError('run', $file);
                        }
                        if ($this->errorCounter > 0) {
                            break;
                        }
                    }
                    break;
                case 'xml':
                case 'x':
                    if (1 == count($files)) {
                        if ($files[0] && is_dir($files[0])) {
                            $this->pairCommandHit = true;
                            $this->searchUpward = false;
                            $this->pathPrefix = $files[0];
                            if (DS != substr($this->pathPrefix, -1)) {
                                $this->pathPrefix .= DS;
                            }
                        } else {
                            $this->handleError('xml1', $files[0]);
                        }
                    } else {
                        $this->handleError('xml2');
                    }
                    break;
            }
            if ($this->errorCounter > 0) {
                break;
            }
        }
    }

    /**
     * Check if no arguments were passed, to add default export command.
     */
    protected function checkDefaultBehaviour()
    {
        if ((false == $this->singleCommandHit) && (false == $this->pairCommandHit)) {
            $this->pushCommand(self::ACTION_EXPORT, $this->getDefaultExportFile());
        }
    }

    /**
     * Get default file for export
     */
    protected function getDefaultExportFile()
    {
        if (is_dir($this->pathPrefix . 'var')) {
            return $this->pathPrefix . 'var' . DS . date('Y-m-d-His') . '_' . $this->db[self::DB_NAME] . '.sql';
        } else {
            $this->handleError('export2');
        }
    }

    /**
     * Handle wrapper for command errors.
     *
     * @param $errorCode
     * @param $arg
     */
    protected function handleError($errorCode, $arg = null)
    {
        if (is_null($errorCode)) {
            return;
        }
        $errorMsg = isset($this->errorMessages[$errorCode]) ? $this->errorMessages[$errorCode] : $errorCode;
        printf("error: " . $errorMsg . (!is_null($arg) ? ' (%s)' : '') . "\n", $arg);
        $this->errorCounter++;
    }

    /**
     * Process command stack
     *
     * Step 1: Return, if no valid command stack
     * Step 2: Run all commands from stack.
     */
    protected function processCommandStack()
    {
        // Step 1
        if (!is_array($this->commandStack)) {
            return;
        }

        // Step 2
        foreach ($this->commandStack as $action) {
            if (0 == $this->errorCounter) {
                $this->runCommand($action['cmd'], $action['arg']);
            }
        }
    }

    /**
     * Push a single command to command stack.
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
     * Print help to options and commands.
     */
    protected function showHelp()
    {
        echo "Magento DB-Tool " . $this->version ."\n";
        echo "Usage: [php] ".$this->script." [OPTION]... [PARAMETER]...\n";
        echo "\n";
        echo " Options:\n";
        echo "   -t, --test                : show data found in local.xml\n";
        echo "   -d, --debug               : do not execute any sql command, just show\n";
        echo "   -v, --verbose             : show more details\n";
        echo "\n";
        echo " Parameters:\n";
        echo "   -e, --export [FILENAME]   : dump DB to FILENAME (or to default location)\n";
        echo "   -i, --import FILENAME     : import DB from FILENAME (drop ALL tables first; set BASE_URLS)\n";
        echo "                               define base urls in the local.xml at nodes:\n";
        echo "                               config->global->resources->web->base_url->{unsecure rsp. secure}\n";
        echo "                               (uncomment the nodes or leave blank if not needed)\n";
        echo "   -r, --run FILENAME        : execute sql script FILENAME\n";
        echo "   -x, --xml MAGENTO-DIR     : don't look upward for local.xml, use MAGENTO_DIR/app/etc/local.xml instead\n";
        echo "\n";
        echo "  run without any parameter or --export without FILENAME will dump the DB to\n";
        echo "  default location           : (magento-dir)/var" . DS . "{Y-m-d-His}_{DB.name}.sql\"\n";
    }
}

$mageDatabaseTool = new Mage_Database_Tool($argv);
$mageDatabaseTool->run();
echo "\n";