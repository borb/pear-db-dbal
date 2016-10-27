<?php
namespace Pineapple\DB;

use Pineapple\DB\Exception\StatementException;

class StatementContainer
{
    private $statement = null;
    private $freeFunction = null;

    public function __construct($statement = null, $freeFunction = null)
    {
        if ($statement !== null) {
            $this->setStatement($statement, $freeFunction);
        }
    }

    public function getStatement()
    {
        if (!isset($this->statement) || ($this->statement === null)) {
            throw new StatementException('No statement set', StatementException::NO_STATEMENT);
        }

        return $this->statement;
    }

    public function setStatement($statement, $freeFunction = null)
    {
        switch (gettype($statement)) {
            case 'object':
            case 'resource':
            case 'array':
                // this is fine.
                break;

            default:
                throw new StatementException('We do not know how to deal with this type of statement handle', StatementException::UNHANDLED_TYPE);
                break;
        }

        $this->statement = $statement;

        if ($freeFunction !== null) {
            $this->freeFunction = $freeFunction;
        }
    }

    public function freeStatement()
    {
        if (isset($this->statement) && ($this->statement === null)) {
            throw new StatementException('No statement set', StatementException::NO_STATEMENT);
        }

        switch (gettype($this->statement)) {
            case 'object':
            case 'array':
                unset($this->statement);
                break;

            case 'resource':
                if (($this->freeFunction !== null) && is_callable($this->freeFunction)) {
                    call_user_function($this->freeFunction, $this->statement);
                    return;
                }
                unset($this->statement);
                break;

            default:
                throw new StatementException('Stored statement is not a type we are experienced with dealing with', StatementException::UNHANDLED_TYPE);
                break;
        }
    }

    public function getStatementType()
    {
        if (isset($this->statement) && ($this->statement === null)) {
            throw new StatementException('No statement set', StatementException::NO_STATEMENT);
        }

        switch (gettype($this->statement)) {
            case 'object':
                return [
                    'type' => 'object',
                    'class' => get_class($this->statement),
                ];
                break;

            case 'resource':
                return ['type' => 'resource'];
                break;

            case 'array':
                return ['type' => 'array'];
                break;

            default:
                throw new StatementException('Stored statement is not a type we are experienced with dealing with', StatementException::UNHANDLED_TYPE);
                break;
        }
    }
}
