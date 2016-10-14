<?php
namespace Pineapple\DB\Driver;

use Pineapple\DB;
use Pineapple\DB\Error;

use PDO;
use PDOStatement;
use PDOException;

/**
 * A PEAR DB driver that uses PDO as an underlying database layer.
 */
class PdoDriver extends Common
{
    /**
     * The capabilities of this DB implementation
     *
     * Meaning of the 'limit' element:
     *   + 'emulate' = emulate with fetch row by number
     *   + 'alter'   = alter the query
     *   + false     = skip rows
     *
     * @var array
     */
    protected $features = [
        'limit' => 'alter',
        'numrows' => true,
        'prepare' => false,
        'transactions' => true,
    ];

    /**
     * A mapping of native error codes to DB error codes
     * @var array
     */
    protected $errorcode_map = [
        1004 => DB::DB_ERROR_CANNOT_CREATE,
        1005 => DB::DB_ERROR_CANNOT_CREATE,
        1006 => DB::DB_ERROR_CANNOT_CREATE,
        1007 => DB::DB_ERROR_ALREADY_EXISTS,
        1008 => DB::DB_ERROR_CANNOT_DROP,
        1022 => DB::DB_ERROR_ALREADY_EXISTS,
        1044 => DB::DB_ERROR_ACCESS_VIOLATION,
        1046 => DB::DB_ERROR_NODBSELECTED,
        1048 => DB::DB_ERROR_CONSTRAINT,
        1049 => DB::DB_ERROR_NOSUCHDB,
        1050 => DB::DB_ERROR_ALREADY_EXISTS,
        1051 => DB::DB_ERROR_NOSUCHTABLE,
        1054 => DB::DB_ERROR_NOSUCHFIELD,
        1061 => DB::DB_ERROR_ALREADY_EXISTS,
        1062 => DB::DB_ERROR_ALREADY_EXISTS,
        1064 => DB::DB_ERROR_SYNTAX,
        1091 => DB::DB_ERROR_NOT_FOUND,
        1100 => DB::DB_ERROR_NOT_LOCKED,
        1136 => DB::DB_ERROR_VALUE_COUNT_ON_ROW,
        1142 => DB::DB_ERROR_ACCESS_VIOLATION,
        1146 => DB::DB_ERROR_NOSUCHTABLE,
        1216 => DB::DB_ERROR_CONSTRAINT,
        1217 => DB::DB_ERROR_CONSTRAINT,
        1356 => DB::DB_ERROR_DIVZERO,
        1451 => DB::DB_ERROR_CONSTRAINT,
        1452 => DB::DB_ERROR_CONSTRAINT,
    ];

    // @var PDO Our PDO connection
    protected $connection = null;

    /**
     * A copy of the last pdostatement object
     * @var PDOStatement
     */
    public $lastStatement = null;

    /**
     * Should data manipulation queries be committed automatically?
     * @var bool
     * @access protected
     */
    protected $autocommit = true;

    /**
     * The quantity of transactions begun
     *
     * @var integer
     * @access private
     */
    private $transaction_opcount = 0;

    /**
     * Set the PDO connection handle in the object
     *
     * @param PDO        $connection A constructed PDO connection handle
     * @return PdoDriver             The constructed Pineapple\DB\Driver\PdoDriver object
     */
    public function setConnectionHandle(PDO $connection)
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * Disconnects from the database server
     *
     * @return bool  TRUE on success, FALSE on failure
     */
    public function disconnect()
    {
        unset($this->connection);
        return true;
    }

    /**
     * Sends a query to the database server
     *
     * @param string  the SQL query string
     *
     * @return mixed  + a PHP result resrouce for successful SELECT queries
     *                + the DB_OK constant for other successful queries
     *                + a Pineapple\DB\Error object on failure
     */
    public function simpleQuery($query)
    {
        $ismanip = $this->_checkManip($query);
        $this->lastQuery = $query;
        $query = $this->modifyQuery($query);

        // query driver options, none by default
        $queryDriverOptions = [];

        if (!$this->connected()) {
            return $this->myRaiseError(DB::DB_ERROR_NODBSELECTED);
        }

        if (!$this->autocommit && $ismanip) {
            if ($this->transaction_opcount === 0) {
                try {
                    $return = $this->connection->beginTransaction();
                    // sqlite supports transactions so this can't be tested right now
                    // @codeCoverageIgnoreStart
                } catch (PDOException $transactionException) {
                    return $this->raiseError(DB::DB_ERROR, null, null, $transactionException->getMessage());
                    // @codeCoverageIgnoreEnd
                }

                if ($return === false) {
                    // sqlite supports transactions so this can't be tested right now
                    // @codeCoverageIgnoreStart
                    return $this->raiseError(
                        DB::ERROR,
                        null,
                        null,
                        self::formatErrorInfo($this->connection->errorInfo())
                    );
                    // @codeCoverageIgnoreEnd
                }
            }
            $this->transaction_opcount++;
        }

        // enable/disable result_buffering in mysql
        // @codeCoverageIgnoreStart
        // @todo test this *thoroughly*
        if (($this->getPlatform() === 'mysql') && !$this->options['result_buffering']) {
            $queryDriverOptions[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = false;
        }
        // @codeCoverageIgnoreEnd

        // prepare the query for execution (we can only inject the unbuffered query parameter on prepared statements)
        try {
            $statement = $this->connection->prepare($query);
        } catch (PDOException $prepareException) {
            return $this->raiseError(DB::DB_ERROR, null, null, $prepareException->getMessage());
        }

        if ($statement === false) {
            return $this->raiseError(
                DB::DB_ERROR,
                null,
                null,
                self::formatErrorInfo($this->connection->errorInfo())
            );
        }

        // execute the query
        try {
            $executeResult = $statement->execute();
        } catch (PDOException $executeException) {
            return $this->raiseError(DB::DB_ERROR, null, null, $executeException->getMessage());
        }

        if ($executeResult === false) {
            return $this->raiseError(
                DB::DB_ERROR,
                null,
                null,
                self::formatErrorInfo($statement->errorInfo())
            );
        }

        // keep this so we can perform rowCount and suchlike later
        $this->lastStatement = $statement;

        // fetch queries should return the result object now
        if (!$ismanip && isset($statement) && ($statement instanceof PDOStatement)) {
            return $statement;
        }

        // ...whilst insert/update/delete just gets a "sure, it went well" result
        return DB::DB_OK;
    }

    /**
     * Move the internal mysql result pointer to the next available result.
     *
     * This method has not been implemented yet.
     *
     * @return false
     * @access public
     */
    public function nextResult()
    {
        return false;
    }

    /**
     * Format a PDO errorInfo block as a legible string
     *
     * @param array $errorInfo The output from PDO/PDOStatement::errorInfo
     * @return string
     */
    private static function formatErrorInfo(array $errorInfo)
    {
        return sprintf(
            'SQLSTATE[%d]: (Driver code %d) %s',
            $errorInfo[0],
            $errorInfo[1],
            $errorInfo[2]
        );
    }

    /**
     * Places a row from the result set into the given array
     *
     * Formating of the array and the data therein are configurable.
     * See Pineapple\DB\Result::fetchInto() for more information.
     *
     * This method is not meant to be called directly.  Use
     * Pineapple\DB\Result::fetchInto() instead.  It can't be declared
     * "protected" because Pineapple\DB\Result is a separate object.
     *
     * @param resource $result    the query result resource
     * @param array    $arr       the referenced array to put the data in
     * @param int      $fetchmode how the resulting array should be indexed
     * @param int      $rownum    the row number to fetch (0 = first row)
     *
     * @return mixed              DB_OK on success, NULL when the end of a
     *                            result set is reached or on failure
     *
     * @see Pineapple\DB\Result::fetchInto()
     */
    public function fetchInto(PDOStatement $result, &$arr, $fetchmode, $rownum = null)
    {
        if ($fetchmode & DB::DB_FETCHMODE_ASSOC) {
            $arr = $result->fetch(PDO::FETCH_ASSOC, null, $rownum);
            if (($this->options['portability'] & DB::DB_PORTABILITY_LOWERCASE) && $arr) {
                $arr = array_change_key_case($arr, CASE_LOWER);
            }
        } else {
            $arr = $result->fetch(PDO::FETCH_NUM);
        }

        if (!$arr) {
            return null;
        }

        if ($this->options['portability'] & DB::DB_PORTABILITY_RTRIM) {
            $this->_rtrimArrayValues($arr);
        }

        if ($this->options['portability'] & DB::DB_PORTABILITY_NULL_TO_EMPTY) {
            $this->_convertNullArrayValuesToEmpty($arr);
        }
        return DB::DB_OK;
    }

    /**
     * Deletes the result set and frees the memory occupied by the result set
     *
     * This method is not meant to be called directly.  Use
     * Pineapple\DB\Result::free() instead.  It can't be declared "protected"
     * because Pineapple\DB\Result is a separate object.
     *
     * @param PDOStatement $result PHP's query result resource
     *
     * @return bool                TRUE on success, FALSE if $result is invalid
     *
     * @see Pineapple\DB\Result::free()
     */
    public function freeResult(PDOStatement &$result = null)
    {
        if ($result === null) {
            return false;
        }
        unset($result);
        return true;
    }

    /**
     * Gets the number of columns in a result set
     *
     * This method is not meant to be called directly.  Use
     * Pineapple\DB\Result::numCols() instead.  It can't be declared
     * "protected" because Pineapple\DB\Result is a separate object.
     *
     * @param resource $result  PHP's query result resource
     *
     * @return int|Error        the number of columns. A Pineapple\DB\Error
     *                          object on failure.
     *
     * @see Pineapple\DB\Result::numCols()
     */
    public function numCols($result)
    {
        $cols = $result->columnCount();
        if (!$cols) {
            return $this->myRaiseError();
        }
        return $cols;
    }

    /**
     * Gets the number of rows in a result set
     *
     * This method is not meant to be called directly.  Use
     * Pineapple\DB\Result::numRows() instead.  It can't be declared "protected"
     * because Pineapple\DB\Result is a separate object.
     *
     * @param resource $result  PHP's query result resource
     *
     * @return int|Error        the number of rows. A Pineapple\DB\Error
     *                          object on failure.
     *
     * @see Pineapple\DB\Result::numRows()
     * @todo This is not easily testable, since not all drivers support this for SELECTs
     * @codeCoverageIgnore
     */
    public function numRows($result)
    {
        $rows = $result->rowCount();
        if ($rows === null) {
            return $this->myRaiseError();
        }
        return $rows;
    }

    /**
     * Enables or disables automatic commits
     *
     * @param bool $onoff  true turns it on, false turns it off
     *
     * @return int|Error   DB_OK on success. A Pineapple\DB\Error object if
     *                     the driver doesn't support auto-committing
     *                     transactions.
     */
    public function autoCommit($onoff = false)
    {
        // XXX if $this->transaction_opcount > 0, we should probably
        // issue a warning here.
        $this->autocommit = $onoff ? true : false;
        return DB::DB_OK;
    }

    /**
     * Commits the current transaction
     *
     * @return int|Error  DB_OK on success. A Pineapple\DB\Error object on
     *                    failure.
     */
    public function commit()
    {
        if ($this->transaction_opcount > 0) {
            if (!$this->connected()) {
                return $this->myRaiseError(DB::DB_ERROR_NODBSELECTED);
            }

            try {
                $commitResult = $this->connection->commit();
                // @todo cannot easily generate a failed transaction commit, don't cover this
                // @codeCoverageIgnoreStart
            } catch (PDOException $commitException) {
                return $this->raiseError(DB::DB_ERROR, null, null, $commitException->getMessage());
                // @codeCoverageIgnoreEnd
            }

            if ($commitResult === false) {
                // @todo cannot easily generate a failed transaction commit, don't cover this
                // @codeCoverageIgnoreStart
                return $this->raiseError(
                    DB::DB_ERROR,
                    null,
                    null,
                    self::formatErrorInfo($this->connection->errorInfo())
                );
                // @codeCoverageIgnoreEnd
            }

            $this->transaction_opcount = 0;
        }
        return DB::DB_OK;
    }

    /**
     * Reverts the current transaction
     *
     * @return int|Error  DB_OK on success. A Pineapple\DB\Error object on
     *                    failure.
     */
    public function rollback()
    {
        if ($this->transaction_opcount > 0) {
            if (!$this->connected()) {
                return $this->myRaiseError(DB::DB_ERROR_NODBSELECTED);
            }

            try {
                $rollbackResult = $this->connection->rollBack();
                // @todo cannot easily generate a failed transaction rollback, don't cover this
                // @codeCoverageIgnoreStart
            } catch (PDOException $rollbackException) {
                return $this->raiseError(DB::DB_ERROR, null, null, $rollbackException->getMessage());
                // @codeCoverageIgnoreEnd
            }

            if ($rollbackResult === false) {
                // @todo cannot easily generate a failed transaction rollback, don't cover this
                // @codeCoverageIgnoreStart
                return $this->raiseError(
                    DB::DB_ERROR,
                    null,
                    null,
                    self::formatErrorInfo($this->connection->errorInfo())
                );
                // @codeCoverageIgnoreEnd
            }

            $this->transaction_opcount = 0;
        }
        return DB::DB_OK;
    }

    /**
     * Determines the number of rows affected by a data maniuplation query
     *
     * 0 is returned for queries that don't manipulate data.
     *
     * @return int|Error  the number of rows. A Pineapple\DB\Error object
     *                    on failure.
     */
    public function affectedRows()
    {
        if (!isset($this->lastStatement) || !($this->lastStatement instanceof PDOStatement)) {
            return $this->myRaiseError();
        }

        if ($this->lastQueryManip) {
            return $this->lastStatement->rowCount();
        }

        return 0;
    }

    /**
     * Returns the next free id in a sequence
     *
     * @param string  $seqName  name of the sequence
     * @param boolean $onDemand when true, the seqence is automatically
     *                          created if it does not exist
     *
     * @return int|Error  the next id number in the sequence.
     *                    A Pineapple\DB\Error object on failure.
     *
     * @see Pineapple\DB\Driver\Common::nextID(),
     *      Pineapple\DB\Driver\Common::getSequenceName(),
     *      Pineapple\DB\Driver\PdoDriver::createSequence(),
     *      Pineapple\DB\Driver\PdoDriver::dropSequence()
     *
     * @deprecated Pineapple retains but does not support this, it's restricted to mysql, and untested
     * @codeCoverageIgnore
     */
    public function nextId($seqName, $onDemand = true)
    {
        /**
         * this method is in here for LEGACY purposes only, and because it does not
         * easily fit into pineapple-compat. only support mysql platform, error if
         * other platform detected.
         */
        if ($this->getPlatform() !== 'mysql') {
            return $this->raiseError(DB::DB_ERROR_UNSUPPORTED);
        }

        $seqName = $this->getSequenceName($seqName);
        do {
            $repeat = 0;
            $result = $this->query("UPDATE {$seqName} SET id = LAST_INSERT_ID(id + 1)");
            if ($result === DB::DB_OK) {
                // COMMON CASE
                $id = $this->connection->lastInsertId();
                if ($id != 0) {
                    return $id;
                }

                // EMPTY SEQ TABLE
                // Sequence table must be empty for some reason,
                // so fill it and return 1
                // Obtain a user-level lock
                $result = $this->getOne("SELECT GET_LOCK('${seqName}_lock', 10)");
                if (DB::isError($result)) {
                    return $this->raiseError($result);
                }
                if ($result == 0) {
                    return $this->myRaiseError(DB::DB_ERROR_NOT_LOCKED);
                }

                // add the default value
                $result = $this->query("REPLACE INTO {$seqName} (id) VALUES (0)");
                if (DB::isError($result)) {
                    return $this->raiseError($result);
                }

                // Release the lock
                $result = $this->getOne("SELECT RELEASE_LOCK('${seqName}_lock')");
                if (DB::isError($result)) {
                    return $this->raiseError($result);
                }
                // We know what the result will be, so no need to try again
                return 1;
            } elseif ($onDemand && DB::isError($result) &&
                $result->getCode() == DB::DB_ERROR_NOSUCHTABLE) {
                // ONDEMAND TABLE CREATION
                $result = $this->createSequence($seqName);

                // Since createSequence initializes the ID to be 1,
                // we do not need to retrieve the ID again (or we will get 2)
                if (DB::isError($result)) {
                    return $this->raiseError($result);
                }

                // First ID of a newly created sequence is 1
                return 1;
            }
        } while ($repeat);

        return $this->raiseError($result);
    }

    /**
     * Creates a new sequence
     *
     * @param string $seqName  name of the new sequence
     *
     * @return int|Error       DB_OK on success. A Pineapple\DB\Error
     *                         object on failure.
     *
     * @see Pineapple\DB\Driver\Common::createSequence(),
     *      Pineapple\DB\Driver\Common::getSequenceName(),
     *      Pineapple\DB\Driver\Pineapple\DB\Driver\PdoDriver::nextID(),
     *      Pineapple\DB\Driver\Pineapple\DB\Driver\PdoDriver::dropSequence()
     *
     * @deprecated Pineapple retains but does not support this, it's restricted to mysql, and untested
     * @codeCoverageIgnore
     */
    public function createSequence($seqName)
    {
        /**
         * this method is in here for LEGACY purposes only, and because it does not
         * easily fit into pineapple-compat. only support mysql platform, error if
         * other platform detected.
         */
        if ($this->getPlatform() !== 'mysql') {
            return $this->raiseError(DB::DB_ERROR_UNSUPPORTED);
        }

        $seqName = $this->getSequenceName($seqName);
        $res = $this->query("CREATE TABLE {$seqName} (id INTEGER UNSIGNED AUTO_INCREMENT NOT NULL, PRIMARY KEY(id))");
        if (DB::isError($res)) {
            return $res;
        }
        // insert yields value 1, nextId call will generate ID 2
        return $this->query("INSERT INTO ${seqName} (id) VALUES (0)");
    }

    /**
     * Deletes a sequence
     *
     * @param string $seqName  name of the sequence to be deleted
     *
     * @return int|Error       DB_OK on success. A Pineapple\DB\Error object
     *                         on failure.
     *
     * @see Pineapple\DB\Driver\Common::dropSequence(),
     *      Pineapple\DB\Driver\Common::getSequenceName(),
     *      Pineapple\DB\Driver\Pineapple\DB\Driver\PdoDriver::nextID(),
     *      Pineapple\DB\Driver\Pineapple\DB\Driver\PdoDriver::createSequence()
     *
     * @deprecated Pineapple retains but does not support this, it's restricted to mysql, and untested
     * @codeCoverageIgnore
     */
    public function dropSequence($seqName)
    {
        /**
         * this method is in here for LEGACY purposes only, and because it does not
         * easily fit into pineapple-compat. only support mysql platform, error if
         * other platform detected.
         */
        if ($this->getPlatform() !== 'mysql') {
            return $this->raiseError(DB::DB_ERROR_UNSUPPORTED);
        }

        return $this->query('DROP TABLE ' . $this->getSequenceName($seqName));
    }

    /**
     * Quotes a string so it can be safely used as a table or column name
     * (WARNING: using names that require this is a REALLY BAD IDEA)
     *
     * WARNING:  Older versions of MySQL can't handle the backtick
     * character (<kbd>`</kbd>) in table or column names.
     *
     * @param string $str  identifier name to be quoted
     *
     * @return string      quoted identifier string
     *
     * @see Pineapple\DB\Driver\Common::quoteIdentifier()
     * @since Method available since Release 1.6.0
     */
    public function quoteIdentifier($str)
    {
        return '`' . str_replace('`', '``', $str) . '`';
    }

    /**
     * Escapes a string according to the current DBMS's standards
     *
     * @param string $str   the string to be escaped
     *
     * @return string|Error the escaped string, or an error
     *
     * @see Pineapple\DB\Driver\Common::quoteSmart()
     * @since Method available since Release 1.6.0
     */
    public function escapeSimple($str)
    {
        /**
         * this requires something of an explanation.
         * DB was not built for PDO, but the database functions for each driver
         * it supported. this used, for example, mysql_real_escape_string,
         * pgsql_escape_string/pgsql_escape_literal, but the product would not
         * be encapsulated in quotes.
         *
         * PDO offers quote(), which _does_ quote the string. but code that
         * uses escapeSimple() expects an escaped string, not a quoted escaped
         * string.
         *
         * we're going to need to strip these quotes. a rundown of the styles:
         * mysql: single quotes, backslash to literal single quotes to escape
         * pgsql: single quotes, literal single quotes are repeated twice
         * sqlite: as pgsql
         *
         * because we're tailored for PDO, we are (supposedly) agnostic to
         * these things. generically, we could look for either " or ' at the
         * beginning and end, and strip those. as a safety net, check length.
         * we could also just strip single quotes.
         */
        switch ($this->getPlatform()) {
            case 'mysql':
            case 'pgsql':
            case 'sqlite':
                $quotedString = $this->connection->quote($str);

                if ($quotedString === false) {
                    // @codeCoverageIgnoreStart
                    // quoting is supported in sqlite so we'll skip testing for it
                    return $this->raiseError(DB::DB_ERROR_UNSUPPORTED);
                    // @codeCoverageIgnoreEnd
                }

                if (preg_match('/^(["\']).*\g1$/', $quotedString) && ((strlen($quotedString) - strlen($str)) >= 2)) {
                    // it's a quoted string, it's 2 or more characters longer, let's strip
                    return preg_replace('/^(["\'])(.*)\g1$/', '$2', $quotedString);
                }

                // no quotes detected or length is insufficiently different to incorporate quotes
                // n.b. the default mode is PDO::PARAM_STR, typically this won't actually be hit.
                // @codeCoverageIgnoreStart
                return $quotedString;
                break;
                // @codeCoverageIgnoreEnd

            // not going to try covering this
            // @codeCoverageIgnoreStart
            default:
                return $this->raiseError(DB::DB_ERROR_UNSUPPORTED);
                break;
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * Adds LIMIT clauses to a query string according to current DBMS standards
     *
     * @param string $query   the query to modify
     * @param int    $from    the row to start to fetching (0 = the first row)
     * @param int    $count   the numbers of rows to fetch
     * @param mixed  $params  array, string or numeric data to be used in
     *                        execution of the statement.  Quantity of items
     *                        passed must match quantity of placeholders in
     *                        query:  meaning 1 placeholder for non-array
     *                        parameters or 1 placeholder per array element.
     *
     * @return string  the query string with LIMIT clauses added
     *
     * @access protected
     */
    protected function modifyLimitQuery($query, $from, $count, $params = [])
    {
        if (DB::isManip($query) || $this->nextQueryManip) {
            // THIS MAKES LITERALLY NO SENSE BUT I AM RETAINING FOR COMPATIBILITY.
            // COMPATIBILITY WHICH LIKELY ISN'T NEEDED.
            // @codeCoverageIgnoreStart
            return $query . " LIMIT $count";
            // @codeCoverageIgnoreEnd
        }

        return $query . " LIMIT $from, $count";
    }

    /**
     * Produces a Pineapple\DB\Error object regarding the current problem
     *
     * @param int $errno  if the error is being manually raised pass a
     *                    DB_ERROR* constant here.  If this isn't passed
     *                    the error information gathered from the DBMS.
     *
     * @return object  the Pineapple\DB\Error object
     *
     * @see Pineapple\DB\Driver\Common::raiseError(),
     *      Pineapple\DB\Driver\PdoDriver::errorNative(), Pineapple\DB\Driver\Common::errorCode()
     */
    public function myRaiseError($errno = null)
    {
        if ($this->connected()) {
            $error = $this->connection->errorInfo();
        } else {
            $error = ['Disconnected', null, 'No active connection'];
        }
        return $this->raiseError(
            $errno,
            null,
            null,
            null,
            $error[0] . ' ** ' . $error[2]
        );
    }

    /**
     * Gets the DBMS' native error code produced by the last query
     *
     * @return int  the DBMS' error code
     */
    public function errorNative()
    {
        return $this->connection->errorCode();
    }

    /**
     * Returns information about a table or a result set
     *
     * @param PDOStatement|string $result Pineapple\DB\Result object from a query or a
     *                                    string containing the name of a table.
     *                                    While this also accepts a query result
     *                                    resource identifier, this behavior is
     *                                    deprecated.
     * @param int                 $mode   a valid tableInfo mode
     *
     * @return mixed   an associative array with the information requested.
     *                 A Pineapple\DB\Error object on failure.
     *
     * @see Pineapple\DB\Driver\Common::setOption()
     */
    public function tableInfo($result, $mode = null)
    {
        if (is_string($result)) {
            // Fix for bug #11580.
            if (!$this->connected()) {
                return $this->myRaiseError(DB::DB_ERROR_NODBSELECTED);
            }

            /**
             * Probably received a table name.
             * Create a result resource identifier.
             * n.b. Retained for compatibility, but this is untestable with sqlite
             */
            // @codeCoverageIgnoreStart
            $id = $this->simpleQuery("SELECT * FROM $result LIMIT 0");
            $got_string = true;
            // @codeCoverageIgnoreEnd
        } elseif (is_object($result) && isset($result->result)) {
            /**
             * Probably received a result object.
             * Extract the result resource identifier.
             */
            $id = $result->result;
            $got_string = false;
        } else {
            return $this->myRaiseError();
        }

        if (!is_object($id) || !($id instanceof PDOStatement)) {
            // not easy to test without triggering a very difficult error
            // @codeCoverageIgnoreStart
            return $this->myRaiseError(DB::DB_ERROR_NEED_MORE_DATA);
            // @codeCoverageIgnoreEnd
        }

        if ($this->options['portability'] & DB::DB_PORTABILITY_LOWERCASE) {
            $case_func = 'strtolower';
        } else {
            $case_func = 'strval';
        }

        $count = $id->columnCount();
        $res = [];

        if ($mode) {
            $res['num_fields'] = $count;
        }

        for ($i = 0; $i < $count; $i++) {
            $tmp = $id->getColumnMeta($i);

            $res[$i] = [
                'table' => $case_func($tmp['table']),
                'name' => $case_func($tmp['name']),
                'type' => isset($tmp['native_type']) ? $tmp['native_type'] : 'unknown',
                'len' => $tmp['len'],
                'flags' => $tmp['flags'],
            ];

            if ($mode & DB::DB_TABLEINFO_ORDER) {
                $res['order'][$res[$i]['name']] = $i;
            }
            if ($mode & DB::DB_TABLEINFO_ORDERTABLE) {
                $res['ordertable'][$res[$i]['table']][$res[$i]['name']] = $i;
            }
        }

        return $res;
    }

    /**
     * Obtains the query string needed for listing a given type of objects
     *
     * @param string $type  the kind of objects you want to retrieve
     *
     * @return string|null  the SQL query string or null if the driver
     *                      doesn't support the object type requested
     *
     * @access protected
     * @deprecated This is deprecated by Pineapple and will be removed in future
     * @see Pineapple\DB\Driver\Common::getListOf()
     * @codeCoverageIgnore
     */
    protected function getSpecialQuery($type)
    {
        if ($this->getPlatform() !== 'mysql') {
            return $this->myRaiseError(DB::DB_ERROR_UNSUPPORTED);
        }

        switch ($type) {
            case 'tables':
                return 'SHOW TABLES';
            case 'users':
                return 'SELECT DISTINCT User FROM mysql.user';
            case 'databases':
                return 'SHOW DATABASES';
            default:
                return null;
        }
    }

    /**
     * Obtain a "rationalised" database major name
     * n.b. we won't test this, in future it would make sense to see the purpose removed.
     *
     * @return string Lower-cased type of database, e.g. "mysql", "pgsql"
     * @codeCoverageIgnore
     */
    private function getPlatform()
    {
        if (!$this->connected()) {
            return $this->raiseError(DB::DB_ERROR_NODBSELECTED);
        }

        // we're not going to support everything
        switch ($name = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            case 'mysql':
            case 'pgsql':
            case 'sqlite':
                // verbatim name
                return $name;
                break;

            default:
                return 'unknown';
                break;
        }
    }
}
