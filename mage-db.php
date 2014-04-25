<?php

define('DS', DIRECTORY_SEPARATOR);
libxml_use_internal_errors(true);

class Mage_Database_Tool
{
    private $version = 'v0.02.02';

    protected $pathPrefix = false;
    protected $configPath = false;

    protected $xml = null;
    const XPATH_CONNECTION_HOST = 'global/resources/default_setup/connection/host';
    const XPATH_CONNECTION_NAME = 'global/resources/default_setup/connection/dbname';
    const XPATH_CONNECTION_USER = 'global/resources/default_setup/connection/username';
    const XPATH_CONNECTION_PASS = 'global/resources/default_setup/connection/password';
    const XPATH_CONNECTION_PREF = 'global/resources/db/table_prefix';
    const XPATH_BASEURL_UNSECURE = 'global/resources/web/base_url/unsecure';
    const XPATH_BASEURL_SECURE = 'global/resources/web/base_url/secure';

    protected $debug = false;
    protected $verbose = false;

    private $db = false;
    const DB_HOST = 'host';
    const DB_PORT = 'port';
    const DB_NAME = 'name';
    const DB_USER = 'user';
    const DB_PASS = 'pass';
    const DB_PREF = 'pref';
    const DB_HTTP = 'http';
    const DB_HTTPS = 'https';

    const ACTION_SHOW_XML = 'show_xml';
    const ACTION_EXPORT = 'export';
    const ACTION_IMPORT = 'import';
    const ACTION_EXECUTE = 'execute';

    const NOT_SET = '<empty>';
    const MSG_OK = ", ok\n";
    const MSG_DONE = ", done\n";
    protected $msg_done = self::MSG_DONE;

    const COMMAND_MYSQL = 'mysql';
    const COMMAND_MYSQL_DUMP = 'mysqldump';

    protected $command_stack = array();

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
        foreach (
            array(
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
            ) as $table) {
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

    protected function _run($action_code, $sqlFileName = false)
    {
        if (!$this->xml) {

            // first looking for local.xml
            $i = 10;
            while ($i-- > 0) {
                if (is_null($this->xml)) {
                    echo "looking for local.xml";
                }
                $this->xml = simplexml_load_file($this->pathPrefix . $this->configPath, NULL, LIBXML_NOCDATA);

                if (false !== $this->xml) {

                    if ($this->verbose) {
                        echo ", found at '" . $this->pathPrefix . $this->configPath . "'\n";
                        echo "looking for database information";
                    } else {
                        echo ", found";
                    }

                    if ($_data = $this->xml->xpath(self::XPATH_CONNECTION_HOST)) {
                        $host = explode(':', (string)$_data[0]);
                        $this->db[self::DB_HOST] = $host[0];
                        $this->db[self::DB_PORT] = (count($host) > 1) ? $host[1] : false;
                    }
                    if ($_data = $this->xml->xpath(self::XPATH_CONNECTION_NAME)) {
                        $this->db[self::DB_NAME] = (string)$_data[0];
                    }
                    if ($_data = $this->xml->xpath(self::XPATH_CONNECTION_USER)) {
                        $this->db[self::DB_USER] = (string)$_data[0];
                    }
                    if ($_data = $this->xml->xpath(self::XPATH_CONNECTION_PASS)) {
                        $this->db[self::DB_PASS] = (string)$_data[0];
                    }
                    if ($_data = $this->xml->xpath(self::XPATH_CONNECTION_PREF)) {
                        $this->db[self::DB_PREF] = (string)$_data[0];
                    }
                    if ($_data = $this->xml->xpath(self::XPATH_BASEURL_UNSECURE)) {
                        $this->db[self::DB_HTTP] = (string)$_data[0];
                    }
                    if ($_data = $this->xml->xpath(self::XPATH_BASEURL_SECURE)) {
                        $this->db[self::DB_HTTPS] = (string)$_data[0];
                    }
                    if (!$this->db[self::DB_HTTPS]) {
                        $this->db[self::DB_HTTPS] = $this->db[self::DB_HTTP];
                    }

                    if ($this->db[self::DB_HOST] && $this->db[self::DB_NAME] && $this->db[self::DB_USER] && (false !== $this->db[self::DB_PASS])) {
                        if ($this->verbose) {
                            echo ", found";
                        }
                        echo self::MSG_OK;
                        break;

                    } else {
                        if ($this->verbose) {
                            echo ", invalid data\n";
                        } else {
                            echo " but invalid data\n";
                        }
                        $this->xml = null;
                    }

                }

                if (9 == $i) {
                    $this->pathPrefix = '.' . $this->pathPrefix;
                } else {
                    $this->pathPrefix = '..' . DS . $this->pathPrefix;
                }
            }
        }

        if ($this->xml) {

            // default dump name
            if (!$sqlFileName) {
                $sqlFileName = $this->pathPrefix . 'var' . DS . date('Y-m-d-His') . '_' . $this->db[self::DB_NAME] . '.sql';
            }

            // print data from local.xml
            if (self::ACTION_SHOW_XML == $action_code) {

                echo "\n";
                echo "file     = " . $this->pathPrefix . $this->configPath . "\n";
                echo "db.name  = " . ($this->db[self::DB_NAME] ? $this->db[self::DB_NAME] : self::NOT_SET) . "\n";
                echo "db.host  = " . ($this->db[self::DB_HOST] ? $this->db[self::DB_HOST] : self::NOT_SET) . "\n";
                echo "db.port  = " . ($this->db[self::DB_PORT] ? $this->db[self::DB_PORT] : self::NOT_SET) . "\n";
                echo "db.user  = " . ($this->db[self::DB_USER] ? $this->db[self::DB_USER] : self::NOT_SET) . "\n";
                echo "db.pass  = " . ($this->db[self::DB_PASS] ? $this->db[self::DB_PASS] : self::NOT_SET) . "\n";
                echo "db.pref  = " . ($this->db[self::DB_PREF] ? $this->db[self::DB_PREF] : self::NOT_SET) . "\n";

                echo "db.http  = " . ($this->db[self::DB_HTTP] ? $this->db[self::DB_HTTP] : self::NOT_SET) . "\n";
                echo "db.https = " . ($this->db[self::DB_HTTPS] ? $this->db[self::DB_HTTPS] : self::NOT_SET) . "\n";

            }

            // export
            if (self::ACTION_EXPORT == $action_code) {

                echo "db.export('$sqlFileName')";
                // extract DB schema
                $_command = $this->_getDumpSchemaStatement($sqlFileName);
                $this->_executeCommand($_command);

                // extract DB data
                $_command = $this->_getDumpDataStatement($sqlFileName);
                $this->_executeCommand($_command);
                echo $this->msg_done;

            }

            // import
            if (self::ACTION_IMPORT == $action_code) {

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
            }

            // execute
            if (self::ACTION_EXECUTE == $action_code) {

                echo "db.execute('$sqlFileName')";
                $_command = $this->_getExecuteSqlFileStatement($sqlFileName);
                $this->_executeCommand($_command);
                echo $this->msg_done;

            }

            return true;

        } else {

            echo "\nno valid local.xml found, therefore this is over now.\n";
            return false;

        }
    }

    protected function _executeCommand($command, $check_verbose = false)
    {
        if ($this->debug) {
            if (!$check_verbose || $this->verbose) {
                echo "\n-> command to execute\n$command";
            }
        } else {
            exec($command);
        }
    }

    public function run($argv)
    {
        $this->pathPrefix = '.' . DS;
        $this->configPath = 'app' . DS . 'etc' . DS . 'local.xml';
        $this->xml = null;
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
        $this->debug = false;
        $this->verbose = false;

        $this->command_stack = array();

        $_error_message = null;
        $_do_commands = true;
        if (count($argv) == 1) {
            $this->_pushCmd(self::ACTION_EXPORT, '');
        } else {
            $i = 1;
            while (isset($argv[$i])) {
                $arg = $argv[$i++];
                if (('--help' == $arg) || ('-h' == $arg) || ('-H' == $arg) || ('-?' == $arg)) {
                    $this->showHelp();
                    $_do_commands = false;
                    break;
                } elseif (('--test' == $arg) || ('-t' == $arg)) {
                    if ($this->_run(self::ACTION_SHOW_XML, '') == false) {
                        $_do_commands = false;
                        break;
                    }
                } elseif (('--export' == $arg) || ('-e' == $arg)) {
                    $file = isset($argv[$i]) ? $argv[$i] : '';
                    if ('-' != substr($file, 0, 1)) {
                        $i++;
                    } else {
                        $file = '';
                    }
                    $this->_pushCmd(self::ACTION_EXPORT, $file);
                } elseif (('--import' == $arg) || ('-i' == $arg)) {
                    $file = isset($argv[$i]) ? $argv[$i++] : '';
                    if (file_exists($file)) {
                        $this->_pushCmd(self::ACTION_IMPORT, $file);
                    } else {
                        $_error_message = "import file missing or import file not found";
                        break;
                    }
                } elseif (('--execute' == $arg) || ('-x' == $arg)) {
                    $file = isset($argv[$i]) ? $argv[$i++] : '';
                    if (file_exists($file)) {
                        $this->_pushCmd(self::ACTION_EXECUTE, $file);
                    } else {
                        $_error_message = "sql script missing entered or sql script not found";
                        break;
                    }
                } elseif (('--verbose' == $arg) || ('-v' == $arg)) {
                    $this->verbose = true;
                } elseif (('--debug' == $arg) || ('-d' == $arg)) {
                    $this->debug = true;
                    $this->msg_done = "\n";
                    if (count($argv) == 2) {
                        $this->_pushCmd(self::ACTION_EXPORT, '');
                    }
                }
            }
        }

        if (!is_null($_error_message)) {
            echo $_error_message . "\n";
        }

        if ($_do_commands && (count($this->command_stack) > 0)) {
            foreach ($this->command_stack as $action) {
                if (false == $this->_run($action['cmd'], $action['arg'])) {
                    break;
                }
            }
        }
    }

    protected function _pushCmd($cmd, $arg)
    {
        $this->command_stack[] = array(
            'cmd' => $cmd,
            'arg' => $arg
        );
    }

    protected function showHelp()
    {
        echo "Magento DB-Tool " . $this->version .": \n";
        echo "usage: \n";
        echo "   without any parameter will dump DB to \"var" . DS . "{Y-m-d-His}_{DB.name}.sql\"\n";
        echo "   -t, --test              : show data found in app/etc/local.xml\n";
        echo "   -v, --verbose           : show more details\n";
        echo "   -d, --debug             : do not execute sql commands, just show them\n";
        echo "   -e, --export FILENAME   : dump DB to FILENAME (overwrite any existing file)\n";
        echo "   -i, --import FILENAME   : import DB from FILENAME (drop ALL tables first; set BASE_URLS, if defined)\n";
        echo "    (define base urls at local.xml->config->global->resources->web->base_url->{unsecure / secure})\n";
        echo "   -x, --execute FILENAME  : execute sql script FILENAME\n";
    }
}

$mageDatabaseTool = new Mage_Database_Tool();
$mageDatabaseTool->run($argv);
echo "\n";
