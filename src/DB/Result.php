<?php
namespace Pineapple\DB;

use Pineapple\DB;
use Pineapple\DB\Driver\DriverInterface;
use Pineapple\DB\StatementContainer;

use stdClass;

/**
 * This class implements a wrapper for a DB result set
 *
 * A new instance of this class will be returned by the DB implementation
 * after processing a query that returns data.
 *
 * @category   Database
 * @package    DB
 * @author     Stig Bakken <ssb@php.net>
 * @copyright  1997-2007 The PHP Group
 * @license    http://www.php.net/license/3_0.txt  PHP License 3.0
 * @version    Release: 1.8.2
 * @link       http://pear.php.net/package/DB
 */
class Result
{
    /**
     * Should results be freed automatically when there are no more rows?
     * @var boolean
     * @see DB\Common::$options
     */
    public $autofree;

    /**
     * A reference to the Pineapple\DB\Driver\<driver> object
     * @var object
     */
    public $dbh;

    /**
     * The current default fetch mode
     * @var integer
     * @see DB\Common::$fetchmode
     */
    public $fetchmode;

    /**
     * The name of the class into which results should be fetched when
     * DB_FETCHMODE_OBJECT is in effect
     *
     * @var string
     * @see DB\Common::$fetchModeObjectClass
     */
    public $fetchModeObjectClass;

    /**
     * The number of rows to fetch from a limit query
     * @var integer
     */
    public $limitCount = null;

    /**
     * The row to start fetching from in limit queries
     * @var integer
     */
    public $limitFrom = null;

    /**
     * The execute parameters that created this result
     * @var array
     */
    public $parameters;

    /**
     * The query string that created this result
     *
     * Copied here incase it changes in $dbh, which is referenced
     *
     * @var string
     */
    public $query;

    /** @var StatementContainer The query result created by the driver */
    private $result;

    /**
     * The present row being dealt with
     * @var integer
     */
    public $rowCounter = null;

    /**
     * The prepared statement resource id created by PHP in $dbh
     *
     * This resource is only available when the result set was created using
     * a driver's native execute() method, not PEAR DB's emulated one.
     *
     * Copied here incase it changes in $dbh, which is referenced
     *
     * @var StatementContainer
     */
    public $statement;

    /**
     * This constructor sets the object's properties
     *
     * @param DriverInterface    $dbh     the DB object
     * @param StatementContainer $result  the result resource id
     * @param array              $options an associative array with result options
     */
    public function __construct(DriverInterface $dbh, StatementContainer $result, array $options = [])
    {
        $this->autofree = $dbh->getOption('autofree');
        $this->dbh = $dbh;
        $this->fetchmode = $dbh->getFetchmode();
        $this->fetchModeObjectClass = $dbh->getFetchModeObjectClass();
        $this->parameters = $dbh->getLastParameters();
        $this->query = $dbh->getLastQuery();
        $this->result = $result;
        // @todo check line below. i suspect this is not needed
        $this->statement = null; // empty($dbh->lastStatement) ? null : $dbh->lastStatement;
        foreach ($options as $key => $value) {
            $this->setOption($key, $value);
        }
    }

    /**
     * Set options for the Pineapple\DB\Result object
     *
     * @param string $key    the option to set
     * @param mixed  $value  the value to set the option to
     */
    public function setOption($key, $value = null)
    {
        switch ($key) {
            case 'limit_from':
                $this->limitFrom = $value;
                break;

            case 'limit_count':
                $this->limitCount = $value;
                break;
        }
    }

    /**
     * Fetch a row of data and return it by reference into an array
     *
     * The type of array returned can be controlled either by setting this
     * method's `$fetchmode` parameter or by changing the default fetch mode
     * `setFetchMode()` before calling this method.
     *
     * There are two options for standardizing the information returned
     * from databases, ensuring their values are consistent when changing
     * DBMS's.  These portability options can be turned on when creating a
     * new DB object or by using setOption().
     *
     *   + `DB_PORTABILITY_LOWERCASE`
     *     convert names of fields to lower case
     *
     *   + `DB_PORTABILITY_RTRIM`
     *     right trim the data
     *
     * @param int $fetchmode  the constant indicating how to format the data
     * @param int $rownum     the row number to fetch (index starts at 0)
     * @return mixed          an array or object containing the row's data,
     *                        null when the end of the result set is reached
     *                        or a Pineapple\DB\Error object on failure.
     *
     * @see DB\Common::setOption(), DB\Common::setFetchMode()
     */
    public function fetchRow($fetchmode = DB::DB_FETCHMODE_DEFAULT, $rownum = null)
    {
        // @todo not bitwise ops on a supposedly bitwise fetch mode
        if ($fetchmode === DB::DB_FETCHMODE_DEFAULT) {
            $fetchmode = $this->fetchmode;
        }

        if ($fetchmode === DB::DB_FETCHMODE_OBJECT) {
            $fetchmode = DB::DB_FETCHMODE_ASSOC;
            $objectClass = $this->fetchModeObjectClass;
        }

        if (is_null($rownum) && $this->limitFrom !== null) {
            if ($this->rowCounter === null) {
                $this->rowCounter = $this->limitFrom;
            }
            if ($this->rowCounter >= ($this->limitFrom + $this->limitCount)) {
                if ($this->autofree) {
                    $this->free();
                }
                $tmp = null;
                return $tmp;
            }

            $this->rowCounter++;
        }

        $arr = [];
        $res = $this->dbh->fetchInto($this->result, $arr, $fetchmode, $rownum);

        if ($res === DB::DB_OK) {
            if (isset($objectClass)) {
                // The default mode is specified in the
                // DB\Common::fetchModeObjectClass property
                $arr = ($objectClass == stdClass::class) ?
                    (object) $arr :
                    new $objectClass($arr);
            }
            return $arr;
        }

        if ($res == null && $this->autofree) {
            $this->free();
        }

        return $res;
    }

    /**
     * Fetch a row of data into an array which is passed by reference
     *
     * The type of array returned can be controlled either by setting this
     * method's `$fetchmode` parameter or by changing the default fetch mode
     * `setFetchMode()` before calling this method.
     *
     * There are two options for standardizing the information returned
     * from databases, ensuring their values are consistent when changing
     * DBMS's.  These portability options can be turned on when creating a
     * new DB object or by using setOption().
     *
     *   + `DB_PORTABILITY_LOWERCASE`
     *     convert names of fields to lower case
     *
     *   + `DB_PORTABILITY_RTRIM`
     *     right trim the data
     *
     * @param array &$arr       the variable where the data should be placed
     * @param int   $fetchmode  the constant indicating how to format the data
     * @param int   $rownum     the row number to fetch (index starts at 0)
     * @return mixed            DB_OK if a row is processed, NULL when the end of the
     *                          result set is reached or a Pineapple\DB\Error object on failure
     *
     * @see DB\Common::setOption(), DB\Common::setFetchMode()
     */
    public function fetchInto(&$arr, $fetchmode = DB::DB_FETCHMODE_DEFAULT, $rownum = null)
    {
        // @todo non-bitwise ops on a supposedly bitwise fetch mode
        if ($fetchmode === DB::DB_FETCHMODE_DEFAULT) {
            $fetchmode = $this->fetchmode;
        }
        if ($fetchmode === DB::DB_FETCHMODE_OBJECT) {
            $fetchmode = DB::DB_FETCHMODE_ASSOC;
            $objectClass = $this->fetchModeObjectClass;
        }
        if (is_null($rownum) && $this->limitFrom !== null) {
            if ($this->rowCounter === null) {
                $this->rowCounter = $this->limitFrom;
            }
            if ($this->rowCounter >= ($this->limitFrom + $this->limitCount)) {
                if ($this->autofree) {
                    $this->free();
                }
                return null;
            }

            $this->rowCounter++;
        }
        $res = $this->dbh->fetchInto($this->result, $arr, $fetchmode, $rownum);
        if ($res === DB::DB_OK) {
            if (isset($objectClass)) {
                // default mode specified in the
                // DB\Common::fetchModeObjectClass property
                $arr = ($objectClass == stdClass::class) ?
                    (object) $arr :
                    new $objectClass($arr);
            }
            return DB::DB_OK;
        }
        if ($res == null && $this->autofree) {
            $this->free();
        }
        return $res;
    }

    /**
     * Get the the number of columns in a result set
     *
     * @return int  the number of columns.  A Pineapple\DB\Error object on failure.
     */
    public function numCols()
    {
        return $this->dbh->numCols($this->result);
    }

    /**
     * Get the number of rows in a result set.
     *
     * @return int  the number of rows.  A Pineapple\DB\Error object on failure.
     */
    public function numRows()
    {
        if ($this->dbh->getOption('portability') & DB::DB_PORTABILITY_NUMROWS) {
            $res = $this->dbh->getFeature('prepare') ?
                $this->dbh->query($this->query, $this->parameters) :
                $this->dbh->query($this->query);

            if (DB::isError($res)) {
                return $res;
            }
            $tmp = null;
            $rowCounter = 0;
            while ($res->fetchInto($tmp, DB::DB_FETCHMODE_ORDERED)) {
                $rowCounter++;
            }
            return $rowCounter;
        }

        return $this->dbh->numRows($this->result);
    }

    /**
     * Get the next result if a batch of queries was executed
     *
     * @return bool  true if a new result is available or false if not
     */
    public function nextResult()
    {
        return $this->dbh->nextResult($this->result);
    }

    /**
     * Frees the resources allocated for this result set
     *
     * @return bool  true on success.  A Pineapple\DB\Error object on failure.
     */
    public function free()
    {
        $err = $this->dbh->freeResult($this->result);
        if (DB::isError($err)) {
            return $err;
        }
        unset($this->result);
        unset($this->statement);
        return true;
    }

    /**
     * @see DB\Common::tableInfo()
     * @deprecated Method deprecated some time before Release 1.2
     */
    public function tableInfo($mode = null)
    {
        if (is_string($mode)) {
            return $this->dbh->raiseError(DB::DB_ERROR_NEED_MORE_DATA);
        }
        return $this->dbh->tableInfo($this->result, $mode);
    }

    /**
     * Determine the query string that created this result
     *
     * @return string  the query string
     *
     * @since Method available since Release 1.7.0
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * Get the 'result' StatementContainer from the private property
     *
     * @return StatementContainer
     */
    public function getResult()
    {
        return $this->result;
    }

    /**
     * Tells which row number is currently being processed
     *
     * @return integer  the current row being looked at.  Starts at 1.
     */
    public function getRowCounter()
    {
        return $this->rowCounter;
    }
}
